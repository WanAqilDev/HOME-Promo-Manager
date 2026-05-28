# HOME Promo Manager — Generic Campaign Engine Design

**Date:** 2026-05-28
**Version target:** 1.0.0
**Status:** Approved

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
wp_home_promo_campaigns table
    ↓ active campaign loaded by
CampaignEngine.php  ← cached per request
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
| `src/CampaignEngine.php` | New. Loads active campaign once per request, exposes `claim_slot($ctx)` |
| `src/Eligibility.php` | New. `OrSpecification` wrapping `NewSpec`, `DiagnosedSpec`, `ReactivationSpec` |
| `src/hooks.php` | Refactored. Pre-hook snapshot + `$ctx` normalisation. Removes all SMART26 logic. |
| `src/Manager.php` | Simplified. Removes tier/code logic, delegates to `CampaignEngine` |
| `src/db.php` | Extended. Adds new tables, alters existing tables via `dbDelta()` |
| `src/admin.php` | Adds Campaigns tab. Removes Code Management tab. |
| `src/rest.php` | Simplifies `/counter` endpoint. Removes `/validate`. |
| `src/shortcodes.php` | Removes code-entry popups. Updates counter display. |
| `template/promo-page.php` | Generic mockup (poster TBD). No code-entry UI. |
| `home-promo-manager.php` | Version bump to 1.0.0 |

---

## Campaign Activation

**Hybrid model** — a campaign is live when BOTH conditions are true:
1. `status = 'active'` (manually set by HQ in admin)
2. Current server time is within `start_date` / `end_date`

**One active campaign at a time** — enforced by app-level guard at save time: saving a
campaign as `active` checks for any existing `status='active'` row and blocks with an admin
error if one exists.

---

## Eligibility — 3 Leaf Specs

All three specs are combined with `OrSpecification`. All return a category string on pass,
or `false` on fail. The category is stored in the slot record for Finance reporting.

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
  - `pasif_days < 90`
  - No prior slot for this `(entry_id, campaign_id)`
- Category: `"diagnosed"`

### ReactivationSpec
- Event: `updated`
- Conditions:
  - field 196 changed to "Ya"
  - Has `went_pasif_at` record
  - `pasif_days ≥ 90`
  - No prior slot for this `(entry_id, campaign_id)`
- Category: `"reactivation"`

### Status check
All specs require: field 1617 = `"Aktif"` **AND** field 199 = `1`.
If these two disagree, log a warning via `error_log()` and skip — do not silently accept
potentially corrupt data.

### Null pasif_days fallback
If `went_pasif_at` is null AND field 1698 snapshot is null (no pasif history in either
source), treat as `"new"`. Document as known limitation for pre-deploy entries.

---

## Pasif Date — Conditional Field Problem & Solution

Field 1698 (pasif date) is conditionally hidden in Formidable when field 1617 → "Aktif".
If "Clear hidden fields" is enabled, field 1698 is wiped on the same submit we need it.

**Solution — A + B combined:**

**A — Pre-hook snapshot:**
`frm_pre_update_entry` reads field 1698 from the DB *before* Formidable processes the
submit (server-side, reads from DB not `$_POST`). Stashed in `static $snapshot[$entry_id]`
on the hook dispatcher class. Covers all pre-deploy entries that have a value in field 1698.

**B — Plugin-owned status log:**
When `frm_after_update_entry` detects field 1617 transitioning TO `"Pasif"`, the plugin
writes `(entry_id, from_status, to_status='Pasif', logged_at=NOW())` to
`wp_home_promo_status_log`. Server-owned timestamp — immune to form conditional wipes.

**Priority at eligibility check time:**
1. Read `went_pasif_at` from `wp_home_promo_status_log` (most recent row for entry)
2. Fall back to snapshot of field 1698 if log is empty (pre-deploy entries)
3. If both null → treat as no pasif history → `"new"`

---

## Entry Context Object (`$ctx`)

Built by `hooks.php` using pre-hook snapshot values + post-submit values:

```php
$ctx->event           // 'created' | 'updated'
$ctx->entry_id        // Formidable entry ID
$ctx->daftar          // field 196 — new value
$ctx->prev_daftar     // field 196 — value before submit (null on create)
$ctx->status          // field 199 — new value
$ctx->prev_status     // field 199 — value before submit
$ctx->status_label    // field 1617 — new value
$ctx->prev_status_label // field 1617 — value before submit
$ctx->pasif_days      // computed: days since went_pasif_at → fallback field 1698 snapshot
```

---

## Idempotency & Re-fire Policy

`frm_after_update_entry` fires on every form save. The engine must be idempotent:

1. **Early bail**: before any eligibility check, query `wp_home_promo_counted` for
   `(entry_id, campaign_id)`. If exists → return immediately. No spec runs, no field write.
2. **Atomic INSERT**: `INSERT IGNORE INTO wp_home_promo_counted ... ON DUPLICATE KEY ...`
   — if two concurrent submits race to the same (entry_id, campaign_id), exactly one wins.
3. **Field 3170**: written once on the first successful claim. Never overwritten.

**Unique key on `wp_home_promo_counted`**: `UNIQUE KEY (entry_id, campaign_id)` — one slot
per person per campaign. Same person can be counted in multiple campaigns across different
periods.

---

## Campaign Modes

### Auto mode (e.g. The 6CURE)
- No code entry required from staff
- `frm_validate_entry`: no code check
- On eligible submit: claim slot → write `campaign_code` value (e.g. `"6CURE"`) to
  field 3170 silently via `frm_update_field_value()`

### Manual mode (e.g. SMART26)
- Staff types a promo code into the promo field (field 3170) on the Formidable form
- `codes_config` JSON on the campaign row: `{"promo24": 240, "promo12": 240}`
  — named codes, each with a per-code slot quota; codes are shared across eligible entries
- `frm_validate_entry` guard:
  - **Skip entirely** if `(entry_id, campaign_id)` already in `wp_home_promo_counted`
    (unrelated field edits on already-counted entries pass through freely — no block)
  - Otherwise: check submitted code exists in `codes_config` + per-code quota not exhausted
  - On fail: add Formidable validation error, block submit with message
- Eligibility specs still required — the code check is an additional gate, not a bypass

---

## Edge Case Policies

| Scenario | Policy |
|---|---|
| Campaign end_date passes during mid-flight submit | Honour the claim — eligibility gate is at hook time, not at INSERT time |
| Client status reverts to Pasif after promo issued | No revocation — promo stands. Finance report reflects issued state. |
| Unrelated field edit on form 13 (auto mode) | Safe — `prev_daftar === daftar`, no spec fires |
| Unrelated field edit on form 13 (manual mode) | Safe — early bail if `(entry_id, campaign_id)` already counted |
| Pre-deploy entries: no log, no field 1698 value | Default to "new" category. Known limitation, documented. |

---

## DB Schema

### New: `wp_home_promo_campaigns`

```sql
id              INT AUTO_INCREMENT PRIMARY KEY
name            VARCHAR(120) NOT NULL          -- "The 6CURE"
slug            VARCHAR(80) NOT NULL UNIQUE    -- "6cure-2026"
status          ENUM('draft','active','ended','paused') DEFAULT 'draft'
mode            ENUM('auto','manual') NOT NULL DEFAULT 'auto'
start_date      DATETIME NOT NULL
end_date        DATETIME NOT NULL
quota           INT NOT NULL
discount_amount DECIMAL(8,2) NOT NULL
campaign_code   VARCHAR(40)                    -- auto mode: code written to field 3170
codes_config    JSON                           -- manual mode: {"code": quota_int, ...}
created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### New: `wp_home_promo_status_log`

```sql
id          INT AUTO_INCREMENT PRIMARY KEY
entry_id    BIGINT NOT NULL
from_status VARCHAR(20)
to_status   VARCHAR(20) NOT NULL
logged_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
INDEX (entry_id, logged_at)
```

### Altered: `wp_home_promo_counted`
Add column: `campaign_id INT NULL` (FK to `wp_home_promo_campaigns.id`)
Add unique key: `UNIQUE KEY uq_entry_campaign (entry_id, campaign_id)`

### Altered: `wp_home_promo_reactivations`
Add column: `campaign_id INT NULL`
Add column: `went_pasif_at DATETIME NULL`

All new columns are NULL-able for backwards compatibility with SMART26 era rows.

### Migration
All changes run via `dbDelta()` inside `DB::install()`, triggered on version bump detection
in `bootstrap.php`. Safe to re-run — `dbDelta()` only adds missing columns/tables.

---

## Field IDs (stored in wp_options, not hard-coded)

Stored under `home_promo_manager_settings` option key. No field ID appears as a literal
in any PHP class — all read via `Manager::get_field_id('daftar')` or equivalent.

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
2. Try activating two campaigns simultaneously → second blocked with admin error
3. Activate campaign → submit Form 13 with `daftar=Ya`, no pasif history → slot claimed, category `"new"`
4. Update existing entry: field 196 → Ya, `went_pasif_at` < 90 days → category `"diagnosed"`
5. Update existing entry: field 196 → Ya, `went_pasif_at` ≥ 90 days → category `"reactivation"`
6. Edit unrelated field on already-counted entry → submit succeeds, no second slot claimed
7. Fill to quota → next eligible submit silently skips, quota stays at max
8. Manual mode: valid code → slot claimed; invalid/exhausted code → blocked with Formidable error
9. `GET /promo/v1/counter` → `{"used": N, "max": 330, "remaining": M, "active": true}`
10. Admin Campaigns tab → correct used/quota display per campaign
11. field 1617 and field 199 disagree → `error_log()` warning written, slot not claimed
