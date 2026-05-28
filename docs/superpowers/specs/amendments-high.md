# HOME Promo Manager — Campaign Engine Design: High-Severity Amendments

**Date:** 2026-05-28
**Amends:** `2026-05-28-hpm-campaign-engine-design.md`
**Severity:** High
**Status:** Approved

These amendments replace or extend the named sections of the original spec. Where a
section heading is reproduced below, the text under it supersedes the same-named
section in the original document.

---

## Issue 5 — Status check (fail-closed policy)

### Amends section: `Eligibility — 3 Leaf Specs → Status check`

Replace the existing "Status check" subsection with the following:

> ### Status check
>
> All specs require: field 1617 = `"Aktif"` **AND** field 199 = `1`.
>
> **Divergence policy — fail-closed.** If field 1617 and field 199 disagree (one
> indicates active, the other does not), the engine treats the entry as **ineligible**:
>
> - All three leaf specs (`NewSpec`, `DiagnosedSpec`, `ReactivationSpec`) MUST return
>   `false` for this entry on this submit.
> - The dispatcher MUST write a warning via `error_log()` in the exact form:
>   ```
>   [HPM] Status divergence entry_id=<id> field_1617="<label>" field_199=<int> — slot denied
>   ```
> - No row is written to `wp_home_promo_counted`. No value is written to field 3170.
> - The submit is not blocked at the form layer — only the promo claim is denied.
>
> Rationale: a missed eligible entry can be reconciled by HQ from the audit log; a
> fraudulent or corrupt-state promo cannot be retracted once Finance has paid out.
> Fail-closed is the only safe default.

Add a corresponding entry to the **Verification Checklist** (replaces item 11):

> 11. field 1617 = `"Aktif"` and field 199 = `0` (or vice versa) on an otherwise
>     eligible submit → `error_log()` warning written in the documented format, no
>     row in `wp_home_promo_counted`, field 3170 untouched, form submit still succeeds.

---

## Issue 6 — Schema and migration: dbDelta-safe types

### Amends section: `DB Schema → New: wp_home_promo_campaigns`

Replace the table definition with the following. `dbDelta()` cannot reliably parse
`JSON` or `ENUM(...)` column definitions and will attempt redundant `ALTER TABLE`
statements on every request; `JSON` is also unavailable on MySQL < 5.7.8.

> ```sql
> id              INT AUTO_INCREMENT PRIMARY KEY
> name            VARCHAR(120) NOT NULL                                -- "The 6CURE"
> slug            VARCHAR(80) NOT NULL UNIQUE                          -- "6cure-2026"
> status          VARCHAR(20)  NOT NULL DEFAULT 'draft'                -- draft|active|ended|paused (validated in PHP)
> mode            VARCHAR(10)  NOT NULL DEFAULT 'auto'                 -- auto|manual (validated in PHP)
> start_date      DATETIME NOT NULL                                    -- UTC, see Issue 8
> end_date        DATETIME NOT NULL                                    -- UTC, see Issue 8
> quota           INT NOT NULL
> discount_amount DECIMAL(8,2) NOT NULL
> campaign_code   VARCHAR(40) NULL                                     -- auto mode: code written to field 3170
> codes_config    LONGTEXT NULL                                        -- manual mode: JSON string, validated in PHP
> created_at      DATETIME DEFAULT CURRENT_TIMESTAMP                   -- UTC
> updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP  -- UTC
> ```

**Allowed-value enforcement (PHP, not SQL):**

> - `status` MUST be one of `['draft','active','ended','paused']`. Reject the save
>   with an admin error if any other value is submitted.
> - `mode` MUST be one of `['auto','manual']`. Reject the save with an admin error
>   otherwise.
> - `codes_config` MUST round-trip through `json_decode($val, true, 512,
>   JSON_THROW_ON_ERROR)` and produce an array of `string => positive int`.
>   Stored as the canonical re-encoded JSON string (`wp_json_encode()`).

### Amends section: `DB Schema → Migration`

Replace the **Migration** subsection with the following:

> ### Migration
>
> 1. **Table creation** runs via `dbDelta()` inside `DB::install()`, triggered on
>    version-bump detection in `bootstrap.php`. All `CREATE TABLE` statements use
>    dbDelta-safe types only (no `JSON`, no `ENUM(...)`). Safe to re-run.
>
> 2. **Column additions on existing tables** (`wp_home_promo_counted`,
>    `wp_home_promo_reactivations`) MUST NOT be routed through `dbDelta()` for the
>    columns listed below — `dbDelta()` will not detect them correctly when
>    surrounding columns use unsupported types. Instead, run raw
>    `$wpdb->query()` statements guarded by a column-existence check:
>
>    ```php
>    private static function column_exists( string $table, string $column ): bool {
>        global $wpdb;
>        $row = $wpdb->get_var( $wpdb->prepare(
>            "SHOW COLUMNS FROM {$table} LIKE %s", $column
>        ) );
>        return ! empty( $row );
>    }
>
>    if ( ! self::column_exists( $wpdb->prefix . 'home_promo_counted', 'campaign_id' ) ) {
>        $wpdb->query( "ALTER TABLE {$wpdb->prefix}home_promo_counted
>            ADD COLUMN campaign_id INT NULL,
>            ADD UNIQUE KEY uq_entry_campaign (entry_id, campaign_id)" );
>    }
>
>    if ( ! self::column_exists( $wpdb->prefix . 'home_promo_reactivations', 'campaign_id' ) ) {
>        $wpdb->query( "ALTER TABLE {$wpdb->prefix}home_promo_reactivations
>            ADD COLUMN campaign_id INT NULL" );
>    }
>
>    if ( ! self::column_exists( $wpdb->prefix . 'home_promo_reactivations', 'went_pasif_at' ) ) {
>        $wpdb->query( "ALTER TABLE {$wpdb->prefix}home_promo_reactivations
>            ADD COLUMN went_pasif_at DATETIME NULL COMMENT 'UTC'" );
>    }
>    ```
>
> 3. **All new columns are NULL-able** for backwards compatibility with SMART26-era rows.
>
> 4. **No downgrade path**: `DB::install()` only adds; it never drops or renames.

---

## Issue 7 — Security Requirements (new section)

### Adds new top-level section between `Architecture` and `Campaign Activation`

> ## Security Requirements
>
> ### Admin pages (admin.php)
>
> Every admin page callback registered under the Campaigns tab MUST begin with:
>
> ```php
> if ( ! current_user_can( 'manage_options' ) ) {
>     wp_die( __( 'Insufficient permissions.', 'home-promo-manager' ), 403 );
> }
> ```
>
> ### Admin form saves
>
> Every admin form handler (create campaign, edit campaign, activate, pause, end,
> delete) MUST validate a nonce before any DB write:
>
> ```php
> check_admin_referer( 'hpm_campaign_save' );
> ```
>
> Nonces are emitted with `wp_nonce_field( 'hpm_campaign_save' )` inside the form.
> A failed `check_admin_referer()` triggers WordPress's default die-with-403 behaviour.
>
> ### REST endpoints (rest.php)
>
> | Endpoint | Method | `permission_callback` |
> |---|---|---|
> | `/promo/v1/counter` | GET | `__return_true` — **public**, response is aggregate non-sensitive (used / max / remaining / active) |
> | `/promo/v1/campaigns` | GET | `current_user_can('manage_options')` |
> | `/promo/v1/campaigns` | POST | `current_user_can('manage_options')` |
> | `/promo/v1/campaigns/(?P<id>\d+)` | PUT, DELETE | `current_user_can('manage_options')` |
>
> All non-counter endpoints additionally enforce nonce verification via the standard
> WordPress REST `X-WP-Nonce` header (handled by `wp_create_nonce('wp_rest')` on the
> admin JS side).
>
> ### Input sanitisation rules
>
> Every field accepted from admin forms or authenticated REST endpoints MUST be
> sanitised and validated per the table below. Validation failure rejects the entire
> save with an admin notice — partial saves are forbidden.
>
> | Field | Sanitiser | Reject when |
> |---|---|---|
> | `name` | `sanitize_text_field()` | empty after sanitisation |
> | `slug` | `sanitize_title()` | empty result, or duplicate of existing campaign |
> | `status` | whitelist check against `['draft','active','ended','paused']` | not in list |
> | `mode` | whitelist check against `['auto','manual']` | not in list |
> | `start_date`, `end_date` | `DateTime::createFromFormat('Y-m-d H:i:s', $val)` | `false` returned, or `end_date <= start_date` |
> | `quota` | `absint()` | result is `0` |
> | `discount_amount` | `(float) $val` | result `<= 0` |
> | `campaign_code` | `sanitize_text_field()`, then truncate to 40 chars | empty when `mode = 'auto'` |
> | `codes_config` | `json_decode($val, true, 512, JSON_THROW_ON_ERROR)` | exception thrown, or value is not `array<string,positive-int>`; required when `mode = 'manual'` |
>
> ### Output escaping
>
> All campaign field values rendered into admin HTML use `esc_html()`, `esc_attr()`,
> or `esc_url()` as appropriate. Campaign names rendered into JS payloads use
> `wp_json_encode()`.
>
> ### Capability constant
>
> The capability `'manage_options'` is referenced as a single class constant
> `CampaignEngine::CAP = 'manage_options'` so a future filter can override it.

---

## Issue 8 — UTC timezone discipline

### Amends section: `Eligibility — 3 Leaf Specs → DiagnosedSpec` and `ReactivationSpec`

Replace `pasif_days < 90` and `pasif_days ≥ 90` evaluation to be computed in UTC:

> `pasif_days` is computed as:
> ```sql
> TIMESTAMPDIFF(DAY, went_pasif_at, UTC_TIMESTAMP())
> ```
> where `went_pasif_at` is stored as UTC (see "Pasif Date" amendment below). The
> 90-day boundary is therefore independent of MySQL `time_zone` and PHP `date.timezone`
> settings.

### Amends section: `Pasif Date — Conditional Field Problem & Solution → B — Plugin-owned status log`

Replace the parenthetical timestamp note with:

> **B — Plugin-owned status log:**
> When `frm_after_update_entry` detects field 1617 transitioning TO `"Pasif"`, the
> plugin writes:
> ```sql
> INSERT INTO wp_home_promo_status_log
>   (entry_id, from_status, to_status, logged_at)
> VALUES
>   (%d, %s, 'Pasif', UTC_TIMESTAMP())
> ```
> Equivalent PHP path uses `gmdate('Y-m-d H:i:s')`. The `logged_at` column is **UTC**.
> Server `time_zone` changes do not move existing rows.

### Amends section: `DB Schema → New: wp_home_promo_status_log`

Replace with:

> ```sql
> id          INT AUTO_INCREMENT PRIMARY KEY
> entry_id    BIGINT NOT NULL
> from_status VARCHAR(20)
> to_status   VARCHAR(20) NOT NULL
> logged_at   DATETIME NOT NULL                       -- UTC; written via UTC_TIMESTAMP() or gmdate()
> INDEX (entry_id, logged_at)
> ```
>
> Note: `DEFAULT CURRENT_TIMESTAMP` is **not** used because `CURRENT_TIMESTAMP`
> follows the MySQL session `time_zone`. All inserts pass an explicit UTC value.

### Adds new subsection under `DB Schema`: **Timezone discipline**

> ### Timezone discipline
>
> Every `DATETIME` column added by this plugin stores UTC. Specifically:
>
> | Table | Column | Source | Annotation |
> |---|---|---|---|
> | `wp_home_promo_campaigns` | `start_date`, `end_date` | admin form (converted from `wp_timezone()` to UTC on save) | `-- UTC` |
> | `wp_home_promo_campaigns` | `created_at`, `updated_at` | `UTC_TIMESTAMP()` (DEFAULTs replaced with explicit insert) | `-- UTC` |
> | `wp_home_promo_status_log` | `logged_at` | `UTC_TIMESTAMP()` / `gmdate()` | `-- UTC` |
> | `wp_home_promo_reactivations` | `went_pasif_at` | `UTC_TIMESTAMP()` / `gmdate()` | `-- UTC` |
> | `wp_home_promo_counted` | `created_at` (if present) | `UTC_TIMESTAMP()` | `-- UTC` |
>
> **Admin UI conversion:**
> - On display: `wp_date( 'Y-m-d H:i:s', strtotime( $utc_value . ' UTC' ) )` — renders
>   in site timezone via `wp_timezone()`.
> - On save: parse admin input as `wp_timezone()`, then `setTimezone(new DateTimeZone('UTC'))`
>   before persisting.
>
> **Active-window comparison:**
> The check "current server time is within `start_date` / `end_date`" (Campaign
> Activation section) compares all three values in UTC. The PHP path uses
> `gmdate('Y-m-d H:i:s')` for "now".

### Amends section: `Verification Checklist`

Add item 12:

> 12. Set MySQL `time_zone = '+08:00'` and PHP `date.timezone = 'UTC'`; an entry
>     with `went_pasif_at = '2026-02-27 16:00:00'` UTC evaluated on 2026-05-28
>     UTC → `pasif_days = 90` (boundary), classified as `"reactivation"` regardless
>     of MySQL session timezone.

---

## Issue 9 — CampaignEngine cache flush

### Amends section: `Architecture → Key components`

Replace the `src/CampaignEngine.php` row with:

> | `src/CampaignEngine.php` | New. Loads active campaign once per request into a private static cache (`self::$active_campaign`), exposes `claim_slot($ctx)`. Exposes `public static function flush(): void` to clear the cache. |

### Adds new subsection under `Architecture`: **CampaignEngine cache lifecycle**

> ### CampaignEngine cache lifecycle
>
> `CampaignEngine::active()` memoises the active campaign in a private static
> property for the duration of one HTTP request:
>
> ```php
> private static ?Campaign $active_campaign = null;
> private static bool      $loaded          = false;
>
> public static function active(): ?Campaign {
>     if ( ! self::$loaded ) {
>         self::$active_campaign = self::query_active_campaign();
>         self::$loaded          = true;
>     }
>     return self::$active_campaign;
> }
>
> public static function flush(): void {
>     self::$active_campaign = null;
>     self::$loaded          = false;
> }
> ```
>
> **`flush()` MUST be called:**
>
> 1. **At the end of any admin save** that creates, updates, activates, pauses,
>    ends, or deletes a campaign — even if the same HTTP request goes on to render
>    a redirect or admin notice that may indirectly call `active()` again.
> 2. **In PHPUnit `tearDown()`** of every test case that touches `CampaignEngine`,
>    to prevent cross-test bleed of cached state.
> 3. **In any `wp_cache_flush`-equivalent hook the plugin exposes** for site-level
>    cache plugins (future-proofing — out of 1.0.0 scope).
>
> Calling `flush()` is cheap (two property assignments) and idempotent.

### Amends section: `Verification Checklist`

Add item 13:

> 13. In a single admin request: load active campaign (cached), call
>     `CampaignEngine::flush()`, change campaign status in DB, call
>     `CampaignEngine::active()` again → second call reflects the new DB state, not
>     the pre-flush cached value.
