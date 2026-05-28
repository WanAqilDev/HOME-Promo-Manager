# HOME Promo Manager — Campaign Engine Design: Medium & Low-Severity Amendments

**Date:** 2026-05-28
**Amends:** `2026-05-28-hpm-campaign-engine-design.md`
**Severity:** Medium (Issues 10–15), Low (Issues 16–20)
**Status:** Approved

These amendments replace or extend the named sections of the original spec. Where a
section heading is reproduced below, the text under it supersedes or supplements the
same-named section in the original document.

---

## Issue 10 — Backfill `wp_home_promo_status_log` for pre-deploy Pasif entries

### Amends section: `DB Schema → Migration`

Replace the **Migration** subsection with the following:

> ### Migration
>
> All schema changes run via `dbDelta()` inside `DB::install()`, triggered on version
> bump detection in `bootstrap.php`. Safe to re-run — `dbDelta()` only adds missing
> columns/tables.
>
> **One-time Pasif backfill (runs once per site, on the version bump to 1.0.0):**
>
> After `dbDelta()` completes, `DB::install()` MUST run a one-time backfill that
> populates `wp_home_promo_status_log` for pre-deploy entries that went Pasif before
> the plugin owned the transition timestamp. Without this, those entries appear to
> have no pasif history and fall into the legacy default branch (see Issue 11).
>
> **Backfill algorithm (idempotent, gated by a version flag in `wp_options`):**
>
> 1. Read the `home_promo_manager_db_version` option. If `>= '1.0.0-backfill'`, skip.
> 2. Select all form 13 entries where field 1617 currently = `"Pasif"` AND no row
>    exists in `wp_home_promo_status_log` for that `entry_id` with `to_status='Pasif'`:
>    ```sql
>    SELECT m1617.item_id   AS entry_id,
>           m1698.meta_value AS pasif_date
>    FROM   {$wpdb->prefix}frm_item_metas m1617
>    LEFT   JOIN {$wpdb->prefix}frm_item_metas m1698
>             ON m1698.item_id = m1617.item_id AND m1698.field_id = 1698
>    LEFT   JOIN {$wpdb->prefix}home_promo_status_log l
>             ON l.entry_id = m1617.item_id AND l.to_status = 'Pasif'
>    WHERE  m1617.field_id   = 1617
>    AND    m1617.meta_value = 'Pasif'
>    AND    l.id IS NULL;
>    ```
> 3. For each row, INSERT one backfill record:
>    ```sql
>    INSERT INTO {$wpdb->prefix}home_promo_status_log
>      (entry_id, from_status, to_status, logged_at)
>    VALUES
>      (:entry_id, 'unknown', 'Pasif',
>       COALESCE(:pasif_date, '1970-01-01 00:00:00'));
>    ```
>    - `from_status` is the literal string `'unknown'` — distinguishes backfill rows
>      from live transitions in audit queries.
>    - `logged_at` uses field 1698's value when present; falls back to the sentinel
>      `1970-01-01 00:00:00` when field 1698 is empty. The sentinel is detectable
>      and forces `pasif_days >= 90` → `"reactivation"` category, which is the safer
>      default than `"diagnosed"` for unknown-age pasif history.
> 4. After successful completion, write `update_option('home_promo_manager_db_version',
>    '1.0.0-backfill', false)`. Subsequent installs skip step 2 entirely.
>
> The backfill is wrapped in a single transaction and chunked at 1000 entries per
> batch to avoid `max_allowed_packet` limits on shared hosting.

Add a corresponding entry to the **Verification Checklist**:

> 12. Pre-deploy entry with field 1617 = `"Pasif"` and field 1698 = `"2025-01-15"`
>     → after install, `wp_home_promo_status_log` has a row with `from_status='unknown'`,
>     `to_status='Pasif'`, `logged_at='2025-01-15 00:00:00'`. Re-running `DB::install()`
>     does not duplicate it.

---

## Issue 11 — Audit flag for legacy-default "new" classifications

### Amends section: `DB Schema → Altered: wp_home_promo_counted`

Replace the **Altered: `wp_home_promo_counted`** subsection with the following:

> ### Altered: `wp_home_promo_counted`
>
> Add columns:
>
> ```sql
> campaign_id INT NULL                              -- FK to wp_home_promo_campaigns.id
> source      VARCHAR(20) NULL DEFAULT 'live'       -- 'live' | 'legacy_default' | NULL (SMART26 era)
> ```
>
> Add unique key: `UNIQUE KEY uq_entry_campaign (entry_id, campaign_id)`
> Add index: `INDEX idx_campaign (campaign_id)` (see Issue 14)
>
> **`source` column semantics:**
>
> | Value | Meaning |
> |---|---|
> | `'live'` | Normal flow — pasif history was conclusively known (log row present OR field 1698 had a value) at claim time. Category is trustworthy. |
> | `'legacy_default'` | Pasif history was unknown at claim time (no log row, no field 1698 value). Category defaulted to `"new"` under the documented fallback. Finance must treat these as provisional. |
> | `NULL` | Pre-1.0.0 SMART26 row. Untouched by the engine. |
>
> The engine MUST set `source = 'legacy_default'` whenever the `"new"` category is
> reached via the null-pasif-days fallback in `Eligibility.php` (the third bullet of
> the "Priority at eligibility check time" list). All other claims set
> `source = 'live'`.

### Amends section: `Eligibility — 3 Leaf Specs → Null pasif_days fallback`

Replace the **Null pasif_days fallback** subsection with the following:

> ### Null pasif_days fallback
>
> If `went_pasif_at` is null AND field 1698 snapshot is null (no pasif history in
> either source), treat as `"new"` AND set the slot's `source` column to
> `'legacy_default'`. The Finance report MUST surface this flag so HQ can audit
> these claims separately.

### Amends section: Finance reporting (new note in `Edge Case Policies`)

Append a new row to the **Edge Case Policies** table:

> | Slot claimed via null-pasif-days fallback | `source='legacy_default'` written. Finance report filters and displays these separately as "provisional new" entries. |

---

## Issue 12 — Re-write field 3170 after update for already-counted entries

### Amends section: `Campaign Modes → Manual mode`

Replace the **Manual mode** subsection with the following:

> ### Manual mode (e.g. SMART26)
>
> - Staff types a promo code into the promo field (field 3170) on the Formidable form.
> - `codes_config` JSON on the campaign row: `{"promo24": 240, "promo12": 240}` — named
>   codes, each with a per-code slot quota; codes are shared across eligible entries.
> - **`frm_validate_entry` guard:**
>   - **Skip entirely** if `(entry_id, campaign_id)` already in `wp_home_promo_counted`
>     (unrelated field edits on already-counted entries pass through freely — no block).
>   - Otherwise: check submitted code exists in `codes_config` + per-code quota not
>     exhausted.
>   - On fail: add Formidable validation error, block submit with message.
> - **`frm_after_update_entry` integrity re-write (Manual mode only):**
>   - If `(entry_id, campaign_id)` is already in `wp_home_promo_counted`, the
>     dispatcher MUST read the original `code` value from the counted row and
>     re-write it to field 3170 via `FrmEntryMeta::update_entry_meta()` whenever the
>     submitted value of field 3170 differs from the counted code (including blank).
>   - This protects against accidental clearing or editing of field 3170 on an
>     already-counted entry without the cost of locking the field in Formidable.
>   - The re-write is silent — no validation error, no admin notice.
>   - In Auto mode, field 3170 is already written-once on first claim and the existing
>     "Never overwritten" rule (see Idempotency) handles this. The re-write is
>     intentionally only added for Manual mode where staff can type into field 3170.

Add a corresponding entry to the **Verification Checklist**:

> 13. Manual mode: already-counted entry submits with field 3170 blanked → submit
>     succeeds (validate skipped), `frm_after_update_entry` restores field 3170 to
>     the originally-counted code, no new slot claimed.

---

## Issue 13 — `prev_*` value source constraint

### Amends section: `Entry Context Object ($ctx)`

Replace the **Entry Context Object** section with the following:

> ## Entry Context Object (`$ctx`)
>
> Built by `hooks.php` using pre-hook snapshot values + post-submit values:
>
> ```php
> $ctx->event           // 'created' | 'updated'
> $ctx->entry_id        // Formidable entry ID
> $ctx->daftar          // field 196 — new value
> $ctx->prev_daftar     // field 196 — value before submit (null on create)
> $ctx->status          // field 199 — new value
> $ctx->prev_status     // field 199 — value before submit
> $ctx->status_label    // field 1617 — new value
> $ctx->prev_status_label // field 1617 — value before submit
> $ctx->pasif_days      // computed: days since went_pasif_at → fallback field 1698 snapshot
> ```
>
> ### Source-of-truth constraint for `prev_*` values
>
> All `prev_*` fields on `$ctx` MUST be sourced from a direct `$wpdb` SELECT against
> `{$wpdb->prefix}frm_item_metas` (filtered by `item_id` and the relevant `field_id`),
> performed inside `frm_pre_update_entry` **before** Formidable has applied the
> submitted values.
>
> - Implementation: the hook dispatcher's `pre_update_entry($values, $entry_id)`
>   method issues a single SELECT for all relevant `field_id`s in one query and
>   stores the result in `self::$snapshot[$entry_id]`, keyed by field id.
> - `frm_after_update_entry` reads from `self::$snapshot[$entry_id]` to populate
>   every `prev_*` field on `$ctx`.
>
> The `prev_*` fields MUST NOT be sourced from:
>
> - `$_POST` or `$_REQUEST` (these contain the new, submitted values)
> - Formidable's `$values['item_meta']` array passed into `frm_pre_update_entry` or
>   `frm_after_update_entry` (also contains new values)
> - Any function that proxies Formidable's input layer (e.g. `FrmAppHelper::get_param`)
>
> Rationale: only the DB read pre-update reflects the committed previous state. Any
> input-layer source returns the submission under evaluation, collapsing all
> transition detection (`prev_daftar !== daftar`) to false-negatives.
>
> On `created` events there is no prior DB state; all `prev_*` fields are `null`.

---

## Issue 14 — Index on `campaign_id` in `wp_home_promo_counted`

### Amends section: `DB Schema → Altered: wp_home_promo_counted`

(Already incorporated above under Issue 11. Restated here for explicit traceability.)

Add the following INDEX to `wp_home_promo_counted`, in addition to the existing
`UNIQUE KEY uq_entry_campaign (entry_id, campaign_id)`:

> ```sql
> INDEX idx_campaign (campaign_id)
> ```
>
> Rationale: the per-campaign quota count query —
>
> ```sql
> SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted WHERE campaign_id = :id
> ```
>
> — runs on every counter REST hit and every quota check inside `claim_slot()`. The
> existing composite unique key has `entry_id` as its leading column and cannot
> serve a `campaign_id`-only predicate without a full scan. The dedicated
> single-column index keeps the count query at O(log n) lookup of the matching
> leaf range.

---

## Issue 15 — Retention policy for `wp_home_promo_status_log`

### Amends section: `DB Schema → New: wp_home_promo_status_log`

Append the following retention policy to the **`wp_home_promo_status_log`** section:

> ### Retention policy
>
> A WP-Cron hook `hpm_status_log_cleanup` is registered in `bootstrap.php` and
> scheduled daily (`wp_schedule_event(time(), 'daily', 'hpm_status_log_cleanup')`)
> on plugin activation. The hook MUST be unscheduled on plugin deactivation.
>
> Each run executes the following retention rule:
>
> **For every distinct `entry_id`, keep the most recent 3 rows OR all rows newer
> than 2 years — whichever set is larger. Delete everything else.**
>
> Implementation sketch (single statement, chunked at 5000 entry_ids per cron tick
> to bound wall time on large sites):
>
> ```sql
> DELETE l FROM {$wpdb->prefix}home_promo_status_log l
> JOIN (
>   SELECT id
>   FROM (
>     SELECT id,
>            entry_id,
>            logged_at,
>            ROW_NUMBER() OVER (PARTITION BY entry_id ORDER BY logged_at DESC) AS rn
>     FROM   {$wpdb->prefix}home_promo_status_log
>   ) ranked
>   WHERE rn > 3
>     AND logged_at < (NOW() - INTERVAL 2 YEAR)
> ) victims ON victims.id = l.id;
> ```
>
> Notes:
> - Rationale: a row older than 2 years that is still among an entry's 3 most
>   recent transitions remains forensically useful (it's the latest known pasif
>   transition); deleting it would re-trigger the legacy default path (Issue 11).
> - The cron job is idempotent and safe to run repeatedly.
> - On hosts where the MySQL version predates `ROW_NUMBER()` (MySQL < 8.0,
>   MariaDB < 10.2), the cleanup MUST fall back to a per-entry_id PHP loop with
>   `LIMIT` deletes.

Add a corresponding entry to the **Verification Checklist**:

> 14. `hpm_status_log_cleanup` cron fires → rows older than 2 years are deleted
>     except where doing so would drop the entry_id below 3 retained rows.

---

## Issue 16 — `campaign_code` vs `codes_config` mode exclusivity

### Amends section: `DB Schema → New: wp_home_promo_campaigns`

Append the following mode-exclusivity note to the **`wp_home_promo_campaigns`**
section:

> ### Mode-exclusive columns
>
> `campaign_code` and `codes_config` are mutually exclusive and bound to `mode`:
>
> | `mode` | `campaign_code` | `codes_config` |
> |---|---|---|
> | `'auto'`   | Required, non-empty (e.g. `"6CURE"`). Written silently to field 3170 on every successful claim. | MUST be `NULL`. |
> | `'manual'` | MUST be `NULL`. | Required, non-empty JSON object mapping code → integer quota (e.g. `{"promo24": 240, "promo12": 240}`). |
>
> Enforcement:
> - Admin save handler in `admin.php` MUST validate this constraint and reject
>   the save with a per-field admin error otherwise.
> - `CampaignEngine::load_active()` MUST assert this on load and throw a
>   `\RuntimeException` if violated — corrupt campaign data, not a recoverable
>   state.

### Amends section: `Campaign Modes`

Insert the following preamble at the top of the **Campaign Modes** section, before
the **Auto mode** subsection:

> A campaign is in exactly one mode. The mode is fixed at creation and determines
> which of `campaign_code` / `codes_config` is populated (see DB Schema). The
> engine's behaviour branches off `$campaign->mode` only — never off the
> populated-ness of either column.

---

## Issue 17 — `discount_amount` representable range

### Amends section: `DB Schema → New: wp_home_promo_campaigns`

Append the following note to the `discount_amount` column description:

> `discount_amount DECIMAL(8,2) NOT NULL` — max representable value is
> **RM 999,999.99**. Sufficient for all foreseeable Malaysian promo amounts. The
> admin save handler MUST reject values outside `[0.00, 999999.99]` with a
> per-field validation error. Negative values are not supported.

---

## Issue 18 — Slug generation: empty result and length bounds

### Amends section: `Campaign Activation` (and admin save validation)

Add the following **Slug validation rules** subsection immediately after the
existing **Campaign Activation** section:

> ## Slug validation rules
>
> Campaign slugs are generated in the admin save handler. If the operator leaves
> the slug field blank, the handler calls `sanitize_title( $name )` to derive one
> from the campaign name; otherwise the operator's manual value is sanitised the
> same way.
>
> After sanitisation, the resulting slug MUST satisfy ALL of:
>
> 1. **Non-empty.** `sanitize_title()` returns an empty string for purely
>    non-Latin input (Arabic, Chinese, Tamil, etc.). If the result is empty,
>    reject the save with the admin error:
>    > "Slug could not be generated. Please enter a manual slug using Latin
>    > characters."
> 2. **Minimum length 3 characters.** Reject with: "Slug must be at least 3
>    characters long."
> 3. **Maximum length 80 characters.** Truncation is NOT performed — reject with:
>    "Slug must be 80 characters or fewer." (Truncation can collide with existing
>    slugs and is surprising to the operator.)
> 4. **Unique** in `wp_home_promo_campaigns.slug` (already enforced by the
>    `UNIQUE` constraint; surfaced as the admin error "A campaign with this slug
>    already exists.").
>
> All four checks run before the INSERT/UPDATE statement is issued. The admin
> form preserves the operator's input on rejection.

---

## Issue 19 — `wp_options` autoload setting

### Amends section: `Field IDs (stored in wp_options, not hard-coded)`

Replace the **Field IDs** section's storage paragraph with the following (the field
table itself is unchanged):

> ## Field IDs (stored in wp_options, not hard-coded)
>
> Stored under the option key `home_promo_manager_settings`. No field ID appears
> as a literal in any PHP class — all reads go through
> `Manager::get_field_id('daftar')` or equivalent.
>
> ### Autoload policy
>
> The option MUST be registered with `autoload = 'no'`. WordPress otherwise
> loads every autoloaded option into memory on every page request, including
> the public front-end and non-promo admin pages where the field map is never
> consulted.
>
> Registration (in `DB::install()` on first activation, and verified on every
> version-bump migration):
>
> ```php
> if ( get_option( 'home_promo_manager_settings' ) === false ) {
>     add_option( 'home_promo_manager_settings', $defaults, '', 'no' );
> } else {
>     // Defensive: if a prior install set autoload='yes', flip it.
>     global $wpdb;
>     $wpdb->update(
>         $wpdb->options,
>         [ 'autoload' => 'no' ],
>         [ 'option_name' => 'home_promo_manager_settings' ]
>     );
>     wp_cache_delete( 'home_promo_manager_settings', 'options' );
>     wp_cache_delete( 'alloptions', 'options' );
> }
> ```
>
> Reads MUST use `get_option( 'home_promo_manager_settings' )` (which honours
> the per-option autoload flag), called only inside:
> - the plugin settings admin page render handler, and
> - the hook dispatcher's `init()` method (one read per request, memoised on
>   the dispatcher instance).
>
> Field table (unchanged):
>
> | Setting key | Field | Value |
> |---|---|---|
> | `form_id` | Form 13 | 13 |
> | `daftar_field_id` | daftar (Ya/Tidak) | 196 |
> | `status_field_id` | status numeric | 199 |
> | `status_label_field_id` | status label | 1617 |
> | `pasif_date_field_id` | pasif date | 1698 |
> | `promo_field_id` | promo code output | 3170 |

---

## Issue 20 — `is_active()` evaluation point

### Amends section: `Idempotency & Re-fire Policy`

Append the following subsection to **Idempotency & Re-fire Policy**:

> ### `is_active()` evaluation point
>
> `Campaign::is_active()` is evaluated **exactly once per request**, at the start
> of `CampaignEngine::claim_slot( $ctx )`, against the request-scoped active
> campaign cached by `CampaignEngine::load_active()`.
>
> - The boolean result is stored on the engine instance for the remainder of the
>   request and is the single source of truth used by every downstream check
>   (eligibility specs, quota count, INSERT into `wp_home_promo_counted`).
> - A campaign that expires (`end_date` crosses `NOW()`) **during** the lifetime
>   of an already-started request remains "active" for the duration of that
>   request only.
> - A campaign that activates (`start_date` crosses `NOW()`) during the lifetime
>   of an already-started request remains "inactive" for the duration of that
>   request only.
>
> This is the documented mechanism behind the existing edge-case policy:
>
> > "Campaign end_date passes during mid-flight submit — Honour the claim;
> > eligibility gate is at hook time, not at INSERT time."
>
> Implementation: `CampaignEngine` is constructed once per request and holds the
> `Campaign` object plus the cached `$is_active` boolean. The engine MUST NOT
> re-read `wp_home_promo_campaigns` or re-evaluate the date window after the
> first call to `claim_slot()` within the same request.
