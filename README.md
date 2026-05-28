# HOME Promo Manager

A WordPress plugin that turns any promotional campaign into a configuration row.
No code changes needed to run a new promo — just create a campaign in the admin UI.

**Version:** 1.0.0  
**Author:** Wan Aqil Hazim, QCXIS Sdn Bhd  
**Requires PHP:** 7.4+  
**Requires:** Formidable Forms Pro

## Features

- **Generic Campaign Engine** — create, activate, and manage multiple campaigns from the admin UI.
- **Two campaign modes:**
  - *Auto* — discount applied automatically; no code entry by staff.
  - *Manual* — staff types a campaign code; per-code quotas enforced at submit.
- **Eligibility engine** — three participant categories (new, diagnosed, reactivation) determined automatically from Formidable Forms entry data.
- **Atomic slot counter** — race-safe quota enforcement via `INSERT IGNORE` + transaction guard.
- **Real-time counter** — public REST endpoint serves live slot data to the promo page.
- **Admin Campaigns tab** — full CRUD (create, edit, activate, pause, delete) under Settings > HOME Promo Manager.
- **Shortcode** — `[hpm_counter]` displays the live remaining/total slot count.
- **Status log** — plugin-owned Pasif transition log, retained for 2 years (top-3 events per entry kept forever).

## Installation

1. Copy the plugin folder to `wp-content/plugins/`.
2. Activate via the WordPress admin panel.
3. Run `composer install` in the plugin directory to install test dependencies (development only).
4. Configure field IDs under **Settings > HOME Promo Manager > Settings**.

## Creating a Campaign

1. Go to **Settings > HOME Promo Manager > Campaigns**.
2. Click **Add Campaign**.
3. Fill in: name, slug, mode (auto/manual), start/end date (MYT), quota, discount amount.
   - *Auto mode:* enter the `campaign_code` value to be written to the promo field.
   - *Manual mode:* enter `codes_config` JSON, e.g. `{"promo24": 240, "promo12": 240}`.
4. Save as **Draft**, then **Activate** when the campaign goes live.
5. Only one campaign can be active at a time — activating a second one is blocked until the first is ended or paused.

## REST API

| Endpoint | Auth | Description |
|---|---|---|
| `GET /wp-json/promo/v1/counter` | Public | Returns `{used, max, remaining, active}` |
| `GET /wp-json/promo/v1/campaigns` | Admin | List all campaigns |
| `POST /wp-json/promo/v1/campaigns` | Admin | Create campaign |
| `PUT /wp-json/promo/v1/campaigns/{id}` | Admin | Update campaign |
| `DELETE /wp-json/promo/v1/campaigns/{id}` | Admin | Delete campaign (blocked if active) |

## Shortcodes

| Shortcode | Output |
|---|---|
| `[hpm_counter]` | Live `X / Y slot tersisa` counter via REST |

## Technical Notes

- All source files are in `src/`. Entry point is `home-promo-manager.php`.
- DB tables: `wp_home_promo_campaigns`, `wp_home_promo_counted`, `wp_home_promo_active`, `wp_home_promo_status_log`.
- Tables are created/migrated on plugin activation and on version bump.
- Eligibility is evaluated on `frm_after_create_entry` and `frm_after_update_entry` for Form 13.
- Field IDs are stored in `wp_options` (configurable), not hard-coded.
- Test suite: PHPUnit 9 + Mockery. Run with `vendor/bin/phpunit`.

## Support

For issues or feature requests, contact Wan Aqil Hazim, QCXIS Sdn Bhd.
