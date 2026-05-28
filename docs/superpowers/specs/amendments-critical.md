# HOME Promo Manager — Critical Amendments to Campaign Engine Design

**Amends:** `2026-05-28-hpm-campaign-engine-design.md`
**Date:** 2026-05-28
**Status:** Approved — supersedes the referenced sections of the original spec

The following sections REPLACE or EXTEND the same-named sections in the original design
spec. Where a section is marked **REPLACES**, the original text is fully superseded.
Where marked **ADDS**, the text is additive.

---

## Campaign Activation — REPLACES

**Hybrid model** — a campaign is live when BOTH conditions are true:
1. It is the campaign currently pointed to by the `wp_home_promo_active` pointer row
2. Current server time is within `start_date` / `end_date`

The campaign's own `status` column is retained as an admin-facing label
(`draft` / `active` / `ended` / `paused`) but is **not** the source of truth for
"which campaign is live right now". The pointer table is.

**One active campaign at a time — enforced atomically at the DB layer.** The
app-level "SELECT then INSERT" guard is removed. Instead, a single-row pointer
table `wp_home_promo_active` holds the id of the campaign currently active.
A UNIQUE constraint on the pointer key prevents two rows from co-existing, and
all transitions are performed as a single `UPDATE` against the pointer row,
inside a transaction.

### Activation flow

Activating campaign `C` from the admin UI:

```sql
START TRANSACTION;

-- 1. Claim the pointer atomically. If another campaign is currently active,
--    swap to C in one statement. The WHERE clause means only one of two
--    concurrent activators succeeds; the other sees affected_rows = 0.
UPDATE wp_home_promo_active
   SET campaign_id = :new_id,
       activated_at = NOW(),
       activated_by = :user_id
 WHERE singleton = 1
   AND (campaign_id IS NULL OR campaign_id <> :new_id);

-- 2. If affected_rows = 0, either the pointer already points to :new_id
--    (idempotent re-activate, OK) or a concurrent activator won the race.
--    Re-SELECT to disambiguate.

-- 3. Update label columns for both campaigns.
UPDATE wp_home_promo_campaigns SET status = 'active' WHERE id = :new_id;
UPDATE wp_home_promo_campaigns SET status = 'paused'
 WHERE id <> :new_id AND status = 'active';

COMMIT;
```

If step 1 returns `affected_rows = 0` and the pointer's `campaign_id` is some
other id `X != :new_id`, the admin save is rejected with the error
*"Campaign #X is already active. Deactivate it first."* The check is performed
by reading the pointer row, not by scanning the campaigns table.

### Deactivation flow

```sql
UPDATE wp_home_promo_active
   SET campaign_id = NULL, activated_at = NOW(), activated_by = :user_id
 WHERE singleton = 1 AND campaign_id = :old_id;
```

`affected_rows = 0` here means another admin already deactivated it — treat
as success (idempotent).

### Engine read path

`CampaignEngine::get_active()` (cached per request) reads:

```sql
SELECT c.*
  FROM wp_home_promo_active a
  JOIN wp_home_promo_campaigns c ON c.id = a.campaign_id
 WHERE a.singleton = 1
   AND NOW() BETWEEN c.start_date AND c.end_date;
```

Returns null if the pointer is empty or the date window has not opened / has
closed. The `status` column is **not** consulted at the hook hot path.

---

## DB Schema — ADDS

### New: `wp_home_promo_active`

A single-row pointer table. The `singleton` column is constrained so only one
row can ever exist, and `campaign_id` is unique so no two campaigns can be
pointed to simultaneously.

```sql
singleton    TINYINT(1) NOT NULL DEFAULT 1
campaign_id  INT NULL
activated_at DATETIME NULL
activated_by BIGINT NULL                       -- WP user id of the admin who flipped it
PRIMARY KEY (singleton)
UNIQUE KEY uq_active_campaign (campaign_id)
CONSTRAINT chk_singleton CHECK (singleton = 1)
```

The row is seeded on `DB::install()`:

```sql
INSERT IGNORE INTO wp_home_promo_active (singleton, campaign_id) VALUES (1, NULL);
```

`dbDelta()` does not handle `CHECK` constraints cleanly across MySQL versions —
seed the row and rely on the `PRIMARY KEY (singleton)` to prevent duplicates.
On MySQL ≥ 8.0.16 the `CHECK` is enforced; on older versions it is parsed and
ignored, and the PK alone still guarantees uniqueness.

---

## Idempotency & Re-fire Policy — REPLACES

`frm_after_update_entry` fires on every form save. The engine must be idempotent
**and** atomic — quota consumption and the field 3170 write must succeed or fail
together.

### Ordering rules

1. **Early bail**: before any eligibility check, query `wp_home_promo_counted`
   for `(entry_id, campaign_id)`. If a row exists → return immediately. No
   spec runs, no transaction is opened, no field write.
2. **Eligibility check**: run the `OrSpecification` against `$ctx`. If no leaf
   matches, return. No transaction is opened.
3. **Atomic claim** (only reached if eligible and no prior slot exists): open a
   `$wpdb` transaction and perform the counted INSERT and the field 3170 write
   inside it. See `claim_slot()` below.

### `CampaignEngine::claim_slot($ctx)` — atomic transaction

```php
$wpdb->query('START TRANSACTION');

try {
    // (a) For manual mode only — re-check per-code quota INSIDE the transaction.
    //     See "Manual Mode Per-Code Quota Enforcement" below.

    // (b) Atomic INSERT. UNIQUE KEY (entry_id, campaign_id) means a concurrent
    //     duplicate submit gets affected_rows = 0 here and we abort cleanly.
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}home_promo_counted
           (entry_id, campaign_id, promo_code, category, counted_at)
         VALUES (%d, %d, %s, %s, NOW())",
        $ctx->entry_id, $campaign->id, $code_to_write, $category
    ));

    if ($inserted !== 1) {
        // Duplicate — another request won. Not an error.
        $wpdb->query('ROLLBACK');
        return ['status' => 'duplicate'];
    }

    // (c) Field 3170 write. If this fails for ANY reason — Formidable API error,
    //     DB write rejected, exception — we rollback the INSERT so the slot is
    //     returned to the pool.
    $field_ok = $manager->write_promo_field($ctx->entry_id, $code_to_write);
    if (!$field_ok) {
        $wpdb->query('ROLLBACK');
        error_log("HPM: field 3170 write failed for entry {$ctx->entry_id}, rolled back slot");
        return ['status' => 'field_write_failed'];
    }

    $wpdb->query('COMMIT');
    return ['status' => 'claimed', 'category' => $category];

} catch (\Throwable $e) {
    $wpdb->query('ROLLBACK');
    error_log("HPM: exception during claim_slot, rolled back: " . $e->getMessage());
    return ['status' => 'error'];
}
```

### Guarantees

- **Quota cannot be consumed without a promo code being written.** If the field
  3170 write fails, the INSERT is rolled back. The next eligible submit for
  this entry retries the full sequence.
- **One slot per `(entry_id, campaign_id)`.** Two concurrent requests racing
  the same entry: one wins `INSERT IGNORE` (`affected_rows=1`), the other
  loses (`affected_rows=0`) and rolls back its no-op transaction. Field 3170
  is written exactly once.
- **Field 3170 is never overwritten on subsequent saves** — the early-bail
  step (1) returns before the transaction is opened.

### `wp_home_promo_counted` engine requirement

The table MUST be InnoDB (or any transactional engine). `dbDelta()` calls in
`DB::install()` must specify `ENGINE=InnoDB`. If a SMART26-era install left the
table on MyISAM, `DB::install()` runs `ALTER TABLE ... ENGINE=InnoDB` once,
guarded by an `information_schema.TABLES` check.

---

## Pre-Hook Snapshot — REPLACES the "A — Pre-hook snapshot" subsection of "Pasif Date — Conditional Field Problem & Solution"

**A — Pre-hook snapshot with DB fallback:**

`frm_pre_update_entry` reads field 1698 from `frm_item_metas` *before*
Formidable processes the submit (server-side, reads from DB not `$_POST`) and
stashes the value in `static $snapshot[$entry_id]` on the hook dispatcher class.
This covers the standard UI-driven update path.

However, `frm_pre_update_entry` is **not guaranteed to fire** on every code path
that reaches `frm_after_update_entry`. Known cases where the pre-hook is skipped:

- Formidable's REST API entry updates
- Programmatic `FrmEntry::update()` calls from other plugins / theme code
- WP-CLI operations (`wp frm entry update`)
- Formidable's own background processing in some workflows

To make the snapshot reliable, `frm_after_update_entry` adds a fallback read:

```php
// Inside the after_update hook, before building $ctx
if (!isset(self::$snapshot[$entry_id])
    || self::$snapshot[$entry_id] === self::SENTINEL_UNSET) {

    $pasif_field_id = Manager::get_field_id('pasif_date');
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value
           FROM {$wpdb->prefix}frm_item_metas
          WHERE item_id = %d AND field_id = %d
          LIMIT 1",
        $entry_id, $pasif_field_id
    ));
    self::$snapshot[$entry_id] = $value; // may be null — that's fine
}
```

This is **one extra SELECT only on the fallback path** — the normal UI submit
path still uses the value already captured by `frm_pre_update_entry` and incurs
no extra query.

`self::SENTINEL_UNSET` is a sentinel constant (e.g. the string `"\0HPM_UNSET"`)
that distinguishes "pre-hook never ran" from "pre-hook ran and read null". Plain
`isset()` cannot distinguish these because `isset()` returns false for null
values stored in the array.

The eligibility priority order in the parent section is unchanged: log first,
then snapshot (now reliably populated), then null → "new".

---

## Manual Mode Per-Code Quota Enforcement — REPLACES the "Manual mode" subsection of "Campaign Modes"

### Manual mode (e.g. SMART26)

- Staff types a promo code into the promo field (field 3170) on the Formidable form
- `codes_config` JSON on the campaign row: `{"promo24": 240, "promo12": 240}` —
  named codes, each with a per-code slot quota; codes are shared across eligible
  entries

### Two-layer enforcement

The validate hook is for **UX only**. The transaction is the **only** enforcement
point. A user-facing error from validate is fast and friendly; the transactional
check is what guarantees correctness under concurrent load.

**Layer 1 — `frm_validate_entry` (UX, non-authoritative):**

- **Skip entirely** if `(entry_id, campaign_id)` already in
  `wp_home_promo_counted` (unrelated field edits on already-counted entries
  pass through freely — no block)
- Otherwise: check submitted code exists in `codes_config` AND
  `SELECT COUNT(*) FROM wp_home_promo_counted WHERE campaign_id=? AND promo_code=?`
  is less than the code's quota
- On fail: add Formidable validation error, block submit with message
- **This check is racy by design** — two staff submitting the same code's last
  remaining slot can both pass. That race is closed by Layer 2.

**Layer 2 — inside `CampaignEngine::claim_slot()` transaction (authoritative):**

Step (a) of `claim_slot()` (referenced above) is, for manual mode:

```php
// Inside the START TRANSACTION block, BEFORE the INSERT IGNORE.
// In InnoDB REPEATABLE READ, this SELECT participates in the transaction's
// snapshot. With the INSERT immediately following, two concurrent transactions
// will serialize on the unique key conflict or on row-level locks taken by
// the INSERT itself.
$used = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*)
       FROM {$wpdb->prefix}home_promo_counted
      WHERE campaign_id = %d AND promo_code = %s
      FOR UPDATE",
    $campaign->id, $code_to_write
));

$quota = $codes_config[$code_to_write] ?? 0;
if ($used >= $quota) {
    $wpdb->query('ROLLBACK');
    return ['status' => 'code_quota_exhausted', 'code' => $code_to_write];
}
```

`FOR UPDATE` takes row-level locks on the matching rows (and gap locks under
REPEATABLE READ), forcing concurrent transactions claiming the same code to
serialize. The first transaction sees `used = quota - 1`, inserts, commits;
the second transaction blocks on the locks, re-reads `used = quota`, and rolls
back.

### Why both layers are required

- **Layer 1 alone is racy.** Two simultaneous submits at `quota - 1` both pass
  validate before either inserts.
- **Layer 2 alone is correct but produces a poor UX** — staff fills the form,
  clicks submit, gets a generic "could not claim" error after a round-trip.
- **Both layers together**: the common case (no contention) shows a friendly
  validation error before submit; the rare race case is caught by the
  transactional check and rolled back cleanly with no partial state. The
  `code_quota_exhausted` return from Layer 2 is surfaced back to the user via
  an admin notice or a redirected error page.

### Eligibility specs are still required

The code check is an additional gate on top of the `OrSpecification`, not a
bypass. An ineligible entry with a valid code is still rejected by the specs.

---

## Verification Checklist — ADDS

Append the following to the existing checklist:

12. Two browsers simultaneously activate two different campaigns → exactly one
    succeeds, the other gets *"Campaign #X is already active"*. Pointer table
    holds exactly one `campaign_id`.
13. Simulate field 3170 write failure (mock `frm_update_entry_meta` to return
    false) → `wp_home_promo_counted` has no row for the entry, quota counter
    unchanged. Re-submit the same entry → claim succeeds.
14. Programmatic `FrmEntry::update()` call from a test plugin (no UI, no
    pre-hook) → `frm_after_update_entry` fallback SELECT populates the
    snapshot, eligibility resolves correctly, slot is claimed.
15. Manual mode race: two parallel requests submit the same code when only one
    slot remains for that code → exactly one `wp_home_promo_counted` row is
    written, the other request returns `code_quota_exhausted` and writes no
    field 3170 value.
16. After a forced rollback (issues 13 or 15), `SHOW ENGINE INNODB STATUS` shows
    no orphaned locks; the next request on the same entry / code proceeds
    normally.
