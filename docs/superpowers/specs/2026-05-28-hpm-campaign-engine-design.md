# HOME Promo Manager — Generic Campaign Engine Design

**Date:** 2026-05-28
**Version target:** 1.0.0
**Status:** Approved — amended post Opus 4.7 review (Critical + High + Medium/Low)

---

## Context

HOME Promo Manager is being redesigned from a hard-coded SMART26 plugin into a reusable
Campaign Promo Engine. Any future promotion — 6CURE, Merdeka 2026, Raya 2027 — becomes a
configuration row, not a code change. The first campaign on the new engine is **The 6CURE**
(6–12 Jun 2026, 330 slots, RM33 auto-apply discount).

The eligibility rule is fixed across all campaigns: a slot is awarded to a client who is
either a new registration or an existing client whose `daftar` field transitions to "Ya"
(with pasif history distinguishing diagnosed from reactivation). Campaign config (dates,
quota, discount, mode) lives in the DB. The engine is invariant.

---

## Architecture

```
Admin UI (Campaigns tab)
    ↓ reads/writes
wp_home_promo_campaigns table  +  wp_home_promo_active (pointer)
    ↓ active campaign loaded by
CampaignEngine.php  ← cached per request (static property + flush())
    ↓ called by
hooks.php (Formidable hook dispatcher)
    ↓ eligibility checked by
Eligibility.php (OrSpecification + 3 leaf specs)
    ↓ slot recorded in
wp_home_promo_counted  +  wp_home_promo_status_log
```

### Key components

| File | Role |
|---|---|
| `src/CampaignEngine.php` | New. Loads active campaign once per request into a static cache. Exposes `get_active()`, `claim_slot($ctx)`, and `public static function flush(): void` to clear the cache. |
| `src/Eligibility.php` | New. `OrSpecification` wrapping `NewSpec`, `DiagnosedSpec`, `ReactivationSpec` |
| `src/hooks.php` | Refactored. Pre-hook snapshot + `$ctx` normalisation. Removes all SMART26 logic. |
| `src/Manager.php` | Simplified. Removes tier/code logic, delegates to `CampaignEngine` |
| `src/db.php` | Extended. Adds new tables, alters existing tables. Migration via dbDelta + raw ALTER. |
| `src/admin.php` | Adds Campaigns tab. Removes Code Management tab. |
| `src/rest.php` | Simplifies `/counter` endpoint. Removes `/validate`. |
| `src/shortcodes.php` | Removes code-entry popups. Updates counter display. |
| `template/promo-page.php` | Generic mockup (poster TBD). No code-entry UI. |
| `home-promo-manager.php` | Version bump to 1.0.0 |

### CampaignEngine cache lifecycle

`CampaignEngine::get_active()` memoises the active campaign in a private static property for the duration of one HTTP request:

```php
private static ?Campaign $active_campaign = null;
private static bool      $loaded          = false;

public static function get_active(): ?Campaign {
    if ( ! self::$loaded ) {
        self::$active_campaign = self::query_active_campaign();
        self::$loaded          = true;
    }
    return self::$active_campaign;
}

public static function flush(): void {
    self::$active_campaign = null;
    self::$loaded          = false;
}
```

`flush()` MUST be called:
1. At the end of any admin save that creates, updates, activates, pauses, ends, or deletes a campaign
2. In PHPUnit `tearDown()` of every test case that touches `CampaignEngine`

Calling `flush()` is cheap (two property assignments) and idempotent.

---

## Security Requirements

### Admin pages (admin.php)

Every admin page callback registered under the Campaigns tab MUST begin with:

```php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( __( 'Insufficient permissions.', 'home-promo-manager' ), 403 );
}
```

The capability is referenced as the class constant `CampaignEngine::CAP = 'manage_options'` so a future filter can override it.

### Admin form saves

Every admin form handler (create, edit, activate, pause, end, delete campaign) MUST validate a nonce before any DB write:

```php
check_admin_referer( 'hpm_campaign_save' );
```

### REST endpoints (rest.php)

| Endpoint | Method | `permission_callback` |
|---|---|---|
| `/promo/v1/counter` | GET | `__return_true` — **public**, returns non-sensitive aggregate only |
| `/promo/v1/campaigns` | GET, POST | `current_user_can('manage_options')` |
| `/promo/v1/campaigns/(?P<id>\d+)` | PUT, DELETE | `current_user_can('manage_options')` |

All non-counter endpoints enforce nonce via `X-WP-Nonce` header.

### Input sanitisation rules

Every field accepted from admin forms or authenticated REST endpoints MUST be sanitised. Validation failure rejects the entire save — no partial saves.

| Field | Sanitiser | Reject when |
|---|---|---|
| `name` | `sanitize_text_field()` | empty after sanitisation |
| `slug` | `sanitize_title()` | empty result, < 3 chars, > 80 chars, or duplicate |
| `status` | whitelist `['draft','active','ended','paused']` | not in list |
| `mode` | whitelist `['auto','manual']` | not in list |
| `start_date`, `end_date` | `DateTime::createFromFormat('Y-m-d H:i:s', $val)` | false returned, or `end_date <= start_date` |
| `quota` | `absint()` | result is 0 |
| `discount_amount` | `(float) $val` | result <= 0 or > 999999.99 |
| `campaign_code` | `sanitize_text_field()`, truncate to 40 chars | empty when `mode = 'auto'` |
| `codes_config` | `json_decode($val, true, 512, JSON_THROW_ON_ERROR)` | exception thrown, or not `array<string,positive-int>`; required when `mode = 'manual'` |

### Output escaping

All campaign field values rendered into admin HTML use `esc_html()`, `esc_attr()`, or `esc_url()`. Campaign names in JS payloads use `wp_json_encode()`.

---

## Campaign Activation

**Hybrid model** — a campaign is live when BOTH conditions are true:
1. It is the campaign currently pointed to by the `wp_home_promo_active` pointer row
2. Current UTC time is within `start_date` / `end_date`

The campaign's own `status` column is an admin-facing label (`draft`/`active`/`ended`/`paused`) but is **not** the source of truth for "which campaign is live right now". The pointer table is.

**One active campaign at a time — enforced atomically at the DB layer.** The app-level "SELECT then check" guard is replaced by a single-row pointer table `wp_home_promo_active` with a UNIQUE constraint. All transitions are a single `UPDATE` inside a transaction.

### Activation flow

```sql
START TRANSACTION;

UPDATE wp_home_promo_active
   SET campaign_id = :new_id,
       activated_at = UTC_TIMESTAMP(),
       activated_by = :user_id
 WHERE singleton = 1
   AND campaign_id IS NULL;

-- affected_rows = 0 means EITHER the pointer already points to :new_id (idempotent
-- success) OR another campaign is currently active. Re-SELECT the pointer row to
-- disambiguate before committing or rejecting.

UPDATE wp_home_promo_campaigns SET status = 'active' WHERE id = :new_id;
UPDATE wp_home_promo_campaigns SET status = 'paused'
 WHERE id <> :new_id AND status = 'active';

COMMIT;
```

If `affected_rows = 0`, re-SELECT `campaign_id` from `wp_home_promo_active WHERE singleton = 1`:
- If `campaign_id = :new_id` → treat as idempotent success (no error, commit any status-column updates).
- If `campaign_id = X` where `X != :new_id` (including any non-NULL other id) → reject the admin save with: *"Campaign #X is already active. Deactivate it first."* The activation transaction is rolled back; the operator must explicitly deactivate the current campaign first.

### Deactivation flow

```sql
START TRANSACTION;

UPDATE wp_home_promo_active
   SET campaign_id = NULL, activated_at = UTC_TIMESTAMP(), activated_by = :user_id
 WHERE singleton = 1 AND campaign_id = :old_id;

UPDATE wp_home_promo_campaigns
   SET status = 'paused'
 WHERE id = :old_id
   AND status = 'active';

COMMIT;
```

`affected_rows = 0` on the pointer UPDATE = already deactivated — treat as success (idempotent). The status-column UPDATE is also idempotent: if the row is already non-`'active'`, it is a no-op.

### Engine read path

`CampaignEngine::get_active()` reads:

```sql
SELECT c.*
  FROM wp_home_promo_active a
  JOIN wp_home_promo_campaigns c ON c.id = a.campaign_id
 WHERE a.singleton = 1
   AND UTC_TIMESTAMP() BETWEEN c.start_date AND c.end_date;
```

The `status` column is **not** consulted on the hook hot path.

---

## Slug Validation Rules

Campaign slugs are generated in the admin save handler via `sanitize_title($name)` or from the operator's manual input, then must satisfy all of:

1. **Non-empty** — `sanitize_title()` returns empty for purely non-Latin input. If empty: *"Slug could not be generated. Please enter a manual slug using Latin characters."*
2. **Minimum 3 characters** — reject with: *"Slug must be at least 3 characters long."*
3. **Maximum 80 characters** — reject (do NOT truncate, truncation can collide): *"Slug must be 80 characters or fewer."*
4. **Unique** in `wp_home_promo_campaigns.slug` — surfaced as: *"A campaign with this slug already exists."*

All four checks run before the INSERT/UPDATE. The admin form preserves the operator's input on rejection.

---

## Eligibility — 3 Leaf Specs

All three specs are combined with `OrSpecification`. All return a category string on pass, or `false` on fail. The category is stored in the slot record for Finance reporting.

### NewSpec
- Event: `created` OR `updated`
- Conditions:
  - field 196 (`daftar`) is now "Ya"
  - No `went_pasif_at` record in `wp_home_promo_status_log` for this entry (no pasif history)
  - No prior slot: `(entry_id, campaign_id)` not in `wp_home_promo_counted`
- Category: `"new"`

### DiagnosedSpec
- Event: `updated`
- Conditions:
  - field 196 changed to "Ya" (`prev_daftar ≠ 'Ya'`, `daftar = 'Ya'`)
  - Has `went_pasif_at` record (has pasif history)
  - `TIMESTAMPDIFF(DAY, went_pasif_at, UTC_TIMESTAMP()) < 90`
  - No prior slot for this `(entry_id, campaign_id)`
- Category: `"diagnosed"`

### ReactivationSpec
- Event: `updated`
- Conditions:
  - field 196 changed to "Ya"
  - Has `went_pasif_at` record
  - `TIMESTAMPDIFF(DAY, went_pasif_at, UTC_TIMESTAMP()) >= 90`
  - No prior slot for this `(entry_id, campaign_id)`
- Category: `"reactivation"`

### Status check — fail-closed

All specs require: field 1617 = `"Aktif"` **AND** field 199 = `1`.

**Divergence policy — fail-closed.** If field 1617 and field 199 disagree, the engine treats the entry as **ineligible**:
- All three leaf specs return `false` for this entry on this submit
- The dispatcher writes a warning: `[HPM] Status divergence entry_id=<id> field_1617="<label>" field_199=<int> — slot denied`
- No row written to `wp_home_promo_counted`. No value written to field 3170.
- The form submit is NOT blocked — only the promo claim is denied.

Rationale: a missed eligible entry can be reconciled from the audit log; a corrupt-state promo cannot be retracted once Finance has paid out.

### Null pasif_days fallback

If `went_pasif_at` is null AND field 1698 snapshot is null (no pasif history in either source), treat as `"new"` **and set the slot's `source` column to `'legacy_default'`**. The Finance report surfaces this flag so HQ can audit these claims separately.

---

## Pasif Date — Conditional Field Problem & Solution

Field 1698 (pasif date) is conditionally hidden in Formidable when field 1617 → "Aktif". If "Clear hidden fields" is enabled, field 1698 is wiped on the same submit we need it.

**Solution — A + B combined:**

**A — Pre-hook snapshot with DB fallback:**
`frm_pre_update_entry` reads each tracked field (1617, 199, 196, 1698) from `frm_item_metas` *before* Formidable processes the submit and stashes the values in `static $snapshot[$entry_id][$field_id]` — keyed first by entry id, then by field id. `self::SENTINEL_UNSET` (e.g. `"\0HPM_UNSET"`) distinguishes "hook never ran for this (entry, field)" from "hook ran and read null" — plain `isset()` cannot distinguish these.

`frm_pre_update_entry` is **not guaranteed to fire** on all update paths (Formidable REST API, `FrmEntry::update()` calls, WP-CLI). Fallback inside `frm_after_update_entry`, per field:

```php
$field_id = Manager::get_field_id('pasif_date');
if (!isset(self::$snapshot[$entry_id][$field_id])
    || self::$snapshot[$entry_id][$field_id] === self::SENTINEL_UNSET) {
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
          WHERE item_id = %d AND field_id = %d LIMIT 1",
        $entry_id, $field_id
    ));
    self::$snapshot[$entry_id][$field_id] = $value;
}
```

One extra SELECT per missing field only on the fallback path.

**B — Plugin-owned status log:**
When `frm_after_update_entry` detects field 1617 transitioning TO `"Pasif"`, the plugin writes:

```sql
INSERT INTO wp_home_promo_status_log (entry_id, from_status, to_status, logged_at)
VALUES (%d, %s, 'Pasif', UTC_TIMESTAMP())
```

PHP path uses `gmdate('Y-m-d H:i:s')`. The `logged_at` column is always **UTC**.

**Priority at eligibility check time:**
1. Read `went_pasif_at` from `wp_home_promo_status_log` (most recent row for entry)
2. Fall back to snapshot of field 1698 if log is empty (pre-deploy entries)
3. If both null → treat as no pasif history → `"new"` with `source='legacy_default'`

---

## Entry Context Object (`$ctx`)

Built by `hooks.php` using pre-hook snapshot values + post-submit values:

```php
$ctx->event             // 'created' | 'updated'
$ctx->entry_id          // Formidable entry ID
$ctx->daftar            // field 196 — new value
$ctx->prev_daftar       // field 196 — value before submit (null on create)
$ctx->status            // field 199 — new value
$ctx->prev_status       // field 199 — value before submit
$ctx->status_label      // field 1617 — new value
$ctx->prev_status_label // field 1617 — value before submit
$ctx->pasif_days        // computed: TIMESTAMPDIFF(DAY, went_pasif_at, UTC_TIMESTAMP())
```

### Source-of-truth constraint for `prev_*` values

All `prev_*` fields MUST be sourced from a direct `$wpdb` SELECT against `frm_item_metas` performed inside `frm_pre_update_entry` **before** Formidable applies submitted values. Stored in `self::$snapshot[$entry_id][$field_id]` — a two-level array keyed first by entry id, then by field id.

`prev_*` fields MUST NOT be sourced from:
- `$_POST` or `$_REQUEST` (contain new submitted values)
- Formidable's `$values['item_meta']` array (also contains new values)
- `FrmAppHelper::get_param` or any input-layer proxy

On `created` events there is no prior DB state; all `prev_*` fields are `null`.

---

## Idempotency & Re-fire Policy

`frm_after_update_entry` fires on every form save. The engine must be idempotent **and** atomic.

### Ordering rules

1. **Early bail**: before any eligibility check, query `wp_home_promo_counted` for `(entry_id, campaign_id)`. If exists → return immediately. No spec runs, no transaction opened.
2. **Eligibility check**: run `OrSpecification` against `$ctx`. If no leaf matches, return. No transaction opened.
3. **Atomic claim** (only reached if eligible and no prior slot): open a `$wpdb` transaction — INSERT counted row + write field 3170 — commit only if both succeed.

### `CampaignEngine::claim_slot($ctx)` — atomic transaction

```php
$wpdb->query('START TRANSACTION');
try {
    // Manual mode only — re-check per-code quota inside transaction (Layer 2)
    if ($campaign->mode === 'manual') {
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
              WHERE campaign_id = %d AND promo_code = %s FOR UPDATE",
            $campaign->id, $code_to_write
        ));
        if ($used >= $codes_config[$code_to_write]) {
            $wpdb->query('ROLLBACK');
            return ['status' => 'code_quota_exhausted', 'code' => $code_to_write];
        }
    }

    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}home_promo_counted
           (entry_id, campaign_id, promo_code, category, source, counted_at)
         VALUES (%d, %d, %s, %s, %s, UTC_TIMESTAMP())",
        $ctx->entry_id, $campaign->id, $code_to_write, $category, $source
    ));

    if ($inserted !== 1) {
        $wpdb->query('ROLLBACK');
        return ['status' => 'duplicate'];
    }

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

- **Quota cannot be consumed without a promo code being written.** If field 3170 write fails, the INSERT is rolled back. Next eligible submit retries the full sequence.
- **One slot per `(entry_id, campaign_id)`.** Concurrent requests: one wins `INSERT IGNORE`, the other gets `affected_rows=0` and rolls back cleanly.
- **Field 3170 is never overwritten** on subsequent saves — early bail returns before the transaction opens.

### InnoDB requirement

`wp_home_promo_counted` MUST be InnoDB. `DB::install()` specifies `ENGINE=InnoDB` and runs `ALTER TABLE ... ENGINE=InnoDB` once (guarded by `information_schema.TABLES` check) for SMART26-era MyISAM installs.

### `is_active()` evaluation point

`is_active()` is evaluated **exactly once per request** at the start of `claim_slot()` via the static cache. The boolean is the binding value for the entire request — a campaign expiring mid-request remains "active" for that request only. The engine MUST NOT re-read or re-evaluate the date window after the first `claim_slot()` call.

---

## Campaign Modes

A campaign is in exactly one mode, fixed at creation. The engine branches off `$campaign->mode` only — never off the populated-ness of either column.

### Auto mode (e.g. The 6CURE)
- No code entry required from staff
- `frm_validate_entry`: no code check
- On eligible submit: claim slot → write `campaign_code` value (e.g. `"6CURE"`) to field 3170 silently via `frm_update_field_value()`

### Manual mode (e.g. SMART26)
- Staff types a promo code into field 3170 on the Formidable form
- `codes_config` LONGTEXT (JSON string): `{"promo24": 240, "promo12": 240}` — named codes, per-code quotas, shared across eligible entries

**Two-layer enforcement:**

*Layer 1 — `frm_validate_entry` (UX, non-authoritative):*
- **Skip entirely** if `(entry_id, campaign_id)` already in `wp_home_promo_counted`
- Otherwise: check code exists in `codes_config` + `SELECT COUNT(*) WHERE campaign_id=? AND promo_code=?` < quota
- On fail: add Formidable validation error, block submit with message
- This check is **intentionally racy** — two simultaneous submits at `quota-1` can both pass. Race is closed by Layer 2.

*Layer 2 — inside `claim_slot()` transaction (authoritative):*
`SELECT COUNT(*) ... FOR UPDATE` before INSERT forces serialisation via InnoDB row/gap locks. First transaction sees `used = quota-1`, inserts, commits. Second blocks, re-reads `used = quota`, rolls back with `code_quota_exhausted`.

**Field 3170 integrity re-write (both modes):**
If `(entry_id, campaign_id)` is already in `wp_home_promo_counted`, `frm_after_update_entry` reads the original code from the counted row and re-writes it to field 3170 via `FrmEntryMeta::update_entry_meta()` whenever the submitted value differs (including blank). Silent — no validation error, no admin notice. Protects against accidental clearing. This applies regardless of mode: in Auto mode the original code is the campaign's `campaign_code`; in Manual mode it is whichever code the operator originally entered for that entry.

**Reentrancy guard (mandatory):**
Writing field 3170 from inside `frm_after_update_entry` can itself re-trigger `frm_after_update_entry` depending on which Formidable API is used. The spec mandates:

1. **API choice.** The field 3170 write MUST use `FrmEntryMeta::update_entry_meta()` directly. `FrmEntry::update()` MUST NOT be used for this write — it re-fires the full entry-update hook chain and would cause infinite recursion.
2. **Static reentrancy flag.** Before any field 3170 write (whether the initial claim write or the integrity re-write), the hook handler MUST set `self::$writing_field[$entry_id] = true`. At the top of `frm_after_update_entry`, the handler MUST check this flag and `return` immediately if already set for the current `$entry_id`. The flag MUST be cleared (via `unset(self::$writing_field[$entry_id])`) in a `finally` block after the write completes, regardless of success or failure.

These two safeguards are belt-and-braces: (1) avoids the documented re-fire path, (2) protects against any future Formidable change or third-party plugin that re-issues `frm_after_update_entry` during meta writes.

**Mode-exclusive columns:**

| `mode` | `campaign_code` | `codes_config` |
|---|---|---|
| `'auto'` | Required, non-empty (e.g. `"6CURE"`) | MUST be NULL |
| `'manual'` | MUST be NULL | Required, non-empty JSON object |

`CampaignEngine::get_active()` asserts this constraint and throws `\RuntimeException` on violation.

**Eligibility specs are still required** — the code check is an additional gate on top of `OrSpecification`, not a bypass.

---

## Edge Case Policies

| Scenario | Policy |
|---|---|
| Campaign `end_date` passes during mid-flight submit | Honour the claim — `is_active()` is evaluated once at request start |
| Client status reverts to Pasif after promo issued | No revocation — promo stands. Finance report reflects issued state. |
| Unrelated field edit on form 13 (auto mode) | Safe — `prev_daftar === daftar`, no spec fires; if entry is already counted, field 3170 is re-asserted from the counted row if blanked |
| Unrelated field edit on form 13 (manual mode) | Safe — early bail if `(entry_id, campaign_id)` already counted; field 3170 re-asserted if blanked |
| Pre-deploy entries: no log, no field 1698 value | Default to "new" with `source='legacy_default'`. Finance filters and displays these separately as "provisional new" entries. |
| Slot claimed via null-pasif-days fallback | `source='legacy_default'` written to counted row. Finance report must surface this. |

---

## DB Schema

### New: `wp_home_promo_active`

Single-row pointer table. `singleton` PK prevents multiple rows; `UNIQUE KEY uq_active_campaign (campaign_id)` prevents two campaigns being pointed to simultaneously.

```sql
singleton    TINYINT(1) NOT NULL DEFAULT 1
campaign_id  INT NULL
activated_at DATETIME NULL                         -- UTC
activated_by BIGINT NULL                           -- WP user id
PRIMARY KEY (singleton)
UNIQUE KEY uq_active_campaign (campaign_id)
```

Seeded on `DB::install()`:
```sql
INSERT IGNORE INTO wp_home_promo_active (singleton, campaign_id) VALUES (1, NULL);
```

### New: `wp_home_promo_campaigns`

```sql
id              INT AUTO_INCREMENT PRIMARY KEY
name            VARCHAR(120) NOT NULL
slug            VARCHAR(80)  NOT NULL UNIQUE
status          VARCHAR(20)  NOT NULL DEFAULT 'draft'    -- draft|active|ended|paused (PHP-validated)
mode            VARCHAR(10)  NOT NULL DEFAULT 'auto'     -- auto|manual (PHP-validated)
start_date      DATETIME NOT NULL                        -- UTC
end_date        DATETIME NOT NULL                        -- UTC
quota           INT NOT NULL
discount_amount DECIMAL(8,2) NOT NULL                    -- max RM 999,999.99
campaign_code   VARCHAR(40)  NULL                        -- auto mode only
codes_config    LONGTEXT     NULL                        -- manual mode only; JSON string
created_at      DATETIME                                 -- UTC; set explicitly via UTC_TIMESTAMP()
updated_at      DATETIME                                 -- UTC; set explicitly on update
```

`codes_config` is stored as LONGTEXT (not JSON type) for dbDelta compatibility. PHP validates with `json_decode(..., JSON_THROW_ON_ERROR)` and re-encodes with `wp_json_encode()`.
`status` and `mode` are VARCHAR (not ENUM) for dbDelta compatibility. PHP enforces allowed values via whitelist.

### New: `wp_home_promo_status_log`

```sql
id          INT AUTO_INCREMENT PRIMARY KEY
entry_id    BIGINT NOT NULL
from_status VARCHAR(20)                            -- 'unknown' for backfill rows
to_status   VARCHAR(20) NOT NULL
logged_at   DATETIME NOT NULL                      -- UTC; written via UTC_TIMESTAMP() or gmdate()
INDEX (entry_id, logged_at)
```

`DEFAULT CURRENT_TIMESTAMP` is not used — `CURRENT_TIMESTAMP` follows MySQL session `time_zone`. All inserts pass an explicit UTC value.

#### Retention policy

WP-Cron hook `hpm_status_log_cleanup` (daily, registered on plugin activation, unscheduled on deactivation). Per run: for every `entry_id`, keep the most recent 3 rows OR all rows newer than 2 years — whichever set is larger. Delete the rest.

On MySQL < 8.0 / MariaDB < 10.2 (no `ROW_NUMBER()`): fall back to a per-entry PHP loop with LIMIT deletes.

### Altered: `wp_home_promo_counted`

```sql
-- Add columns:
campaign_id INT NULL DEFAULT NULL              -- FK to wp_home_promo_campaigns.id
source      VARCHAR(20) NULL DEFAULT 'live'   -- 'live' | 'legacy_default' | NULL (SMART26 era)

-- Add unique key:
UNIQUE KEY uq_entry_campaign (entry_id, campaign_id)

-- Add indexes:
INDEX idx_campaign (campaign_id)              -- supports per-campaign quota count query
INDEX idx_campaign_code (campaign_id, promo_code)  -- supports Manual mode Layer 2 `WHERE campaign_id=? AND promo_code=? FOR UPDATE`; without it the FOR UPDATE degrades to a wider range/gap lock
```

`source` column semantics:

| Value | Meaning |
|---|---|
| `'live'` | Normal flow — pasif history conclusively known. Category is trustworthy. |
| `'legacy_default'` | Pasif history unknown at claim time. Category defaulted to "new". Finance treats as provisional. |
| `NULL` | Pre-1.0.0 SMART26 row. Untouched by the engine. |

### Altered: `wp_home_promo_reactivations`

```sql
-- Add columns:
campaign_id   INT NULL DEFAULT NULL
went_pasif_at DATETIME NULL                    -- UTC
```

All new columns are NULL-able for backwards compatibility with SMART26-era rows.

### Timezone discipline

Every `DATETIME` column added by this plugin stores UTC:

| Table | Column | UTC source |
|---|---|---|
| `wp_home_promo_campaigns` | `start_date`, `end_date` | admin input converted from `wp_timezone()` to UTC on save |
| `wp_home_promo_campaigns` | `created_at`, `updated_at` | `UTC_TIMESTAMP()` |
| `wp_home_promo_status_log` | `logged_at` | `UTC_TIMESTAMP()` / `gmdate('Y-m-d H:i:s')` |
| `wp_home_promo_reactivations` | `went_pasif_at` | `UTC_TIMESTAMP()` / `gmdate()` |
| `wp_home_promo_active` | `activated_at` | `UTC_TIMESTAMP()` |

Admin UI display: `wp_date('Y-m-d H:i:s', strtotime($utc_value . ' UTC'))` — renders in site timezone via `wp_timezone()`.

### Migration

1. **Table creation** via `dbDelta()` in `DB::install()` on version-bump detection in `bootstrap.php`. All CREATE TABLE statements use dbDelta-safe types only (no `JSON`, no `ENUM`).

2. **Column additions on existing tables** run as raw `$wpdb->query()` guarded by a column-existence check:

```php
private static function column_exists(string $table, string $column): bool {
    global $wpdb;
    return ! empty($wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s", $column
    )));
}

if (!self::column_exists($wpdb->prefix . 'home_promo_counted', 'campaign_id')) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}home_promo_counted
        ADD COLUMN campaign_id INT NULL,
        ADD COLUMN source VARCHAR(20) NULL DEFAULT 'live',
        ADD UNIQUE KEY uq_entry_campaign (entry_id, campaign_id),
        ADD INDEX idx_campaign (campaign_id),
        ADD INDEX idx_campaign_code (campaign_id, promo_code)");
}

if (!self::column_exists($wpdb->prefix . 'home_promo_reactivations', 'went_pasif_at')) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}home_promo_reactivations
        ADD COLUMN campaign_id INT NULL,
        ADD COLUMN went_pasif_at DATETIME NULL COMMENT 'UTC'");
}
```

3. **One-time Pasif backfill** (runs once per site, gated by `home_promo_manager_db_version` option): populates `wp_home_promo_status_log` for all form 13 entries currently in "Pasif" status that have no log row. Uses field 1698 value as `logged_at`; falls back to sentinel `1970-01-01 00:00:00` when field 1698 is empty (forces `pasif_days >= 90` → "reactivation" — the safer default). Chunked at 1000 entries per batch; **each 1000-entry chunk is wrapped in its own transaction** (not a single transaction spanning all chunks). This keeps InnoDB log/undo buffers bounded on large sites and lets the migration resume cleanly if interrupted between chunks.

4. All new columns are NULL-able. No downgrade path — `DB::install()` only adds, never drops or renames.

---

## Field IDs (stored in wp_options, not hard-coded)

Stored under `home_promo_manager_settings`. No field ID appears as a literal in any PHP class.

### Autoload policy

Registered with `autoload = 'no'`:

```php
if (get_option('home_promo_manager_settings') === false) {
    add_option('home_promo_manager_settings', $defaults, '', 'no');
} else {
    // Defensive: flip prior installs that may have set autoload='yes'
    global $wpdb;
    $wpdb->update($wpdb->options,
        ['autoload' => 'no'],
        ['option_name' => 'home_promo_manager_settings']
    );
    wp_cache_delete('home_promo_manager_settings', 'options');
    wp_cache_delete('alloptions', 'options');
}
```

Reads via `get_option()` only inside: the plugin settings admin page render handler, and the hook dispatcher's `init()` method (memoised on the dispatcher instance).

| Setting key | Field | Value |
|---|---|---|
| `form_id` | Form 13 | 13 |
| `daftar_field_id` | daftar (Ya/Tidak) | 196 |
| `status_field_id` | status numeric | 199 |
| `status_label_field_id` | status label | 1617 |
| `pasif_date_field_id` | pasif date | 1698 |
| `promo_field_id` | promo code output | 3170 |

---

## Promo Page / Countdown Template

`template/promo-page.php` — generic mockup until campaign poster is available:
- No code-entry input field, no popup
- Campaign name, countdown timer, realtime slot counter (`X / [quota] slot tersisa`)
- "Diskaun RM[discount_amount] akan dikenakan secara automatik" badge
- Slot counter + countdown pull from `GET /promo/v1/counter`
- Hero/poster: placeholder `<div>` — replace with final artwork when supplied

---

## Verification Checklist

1. Create draft campaign → `status='draft'`, no slots claimed on any form submit
2. Activate campaign → pointer table updated; try activating a second → blocked with *"Campaign #X is already active"*
3. Two browsers simultaneously activate two different campaigns → exactly one succeeds, pointer holds exactly one `campaign_id`
4. Submit Form 13 with `daftar=Ya`, no pasif history → slot claimed, category `"new"`, `source='live'`
5. Update existing entry: field 196 → Ya, `went_pasif_at` < 90 days UTC → category `"diagnosed"`
6. Update existing entry: field 196 → Ya, `went_pasif_at` ≥ 90 days UTC → category `"reactivation"`
7. Edit unrelated field on already-counted entry (auto mode) → submit succeeds, no second slot claimed
8. Manual mode: already-counted entry submits with field 3170 blanked → submit succeeds, field 3170 restored to original code, no new slot
9. Fill to quota → next eligible submit silently skips, quota stays at max
10. Manual mode: valid code → slot claimed; invalid/exhausted code → blocked with Formidable error
11. Manual mode race: two parallel requests submit same code with one slot remaining → exactly one `wp_home_promo_counted` row, other returns `code_quota_exhausted`
12. `GET /promo/v1/counter` → `{"used": N, "max": 330, "remaining": M, "active": true}`
13. Admin Campaigns tab → correct used/quota display per campaign
14. field 1617 = `"Aktif"` and field 199 = `0` (or vice versa) on otherwise eligible submit → `error_log()` warning in documented format, no slot claimed, form submit still succeeds
15. Simulate field 3170 write failure → `wp_home_promo_counted` has no row, quota unchanged. Re-submit → claim succeeds
16. Programmatic `FrmEntry::update()` (no pre-hook) → fallback SELECT populates snapshot, eligibility resolves correctly
17. Pre-deploy entry with field 1617 = "Pasif" → after install, `wp_home_promo_status_log` has backfill row. Re-running `DB::install()` does not duplicate it
18. MySQL `time_zone = '+08:00'`: entry with `went_pasif_at` UTC 90 days ago → classified as `"reactivation"` regardless of session timezone
19. `CampaignEngine::flush()` called → next `get_active()` call reflects new DB state, not pre-flush cache
20. `hpm_status_log_cleanup` cron fires → rows older than 2 years deleted except where doing so drops below 3 retained rows per entry
21. Null pasif history fallback → slot claimed with `source='legacy_default'`; Finance report flags it separately
22. Unauthenticated POST to `/promo/v1/campaigns` returns HTTP 401/403; admin GET with valid nonce succeeds.
23. Admin form submitted without nonce (or with invalid nonce) → `check_admin_referer()` kills request, no DB write.
24. Campaign name with only non-Latin characters → slug is rejected with operator-facing error, form state preserved.
25. Slug < 3 characters or > 80 characters → rejected with operator-facing error.
26. Duplicate slug on create or edit → rejected with operator-facing error.
27. Create `mode='auto'` campaign with `codes_config` populated → rejected. Create `mode='manual'` with `campaign_code` → rejected.
28. `discount_amount` submitted as `0`, negative, or `> 999999.99` → rejected with admin error.
29. After plugin activation on a fresh install, `SELECT autoload FROM wp_options WHERE option_name='home_promo_manager_settings'` returns `'no'`.
30. After upgrading from a legacy install (autoload was 'yes'), defensive flip sets autoload to 'no' and cache is cleared.
31. Deactivate an already-deactivated campaign → treated as success (idempotent, no error).
32. Session timezone test (Issue 8) covers both < 90 day branch (diagnosed) and ≥ 90 day branch (reactivation).
