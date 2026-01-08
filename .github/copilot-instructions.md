# HOME Promo Manager - AI Coding Instructions

## Project Overview
WordPress plugin managing promotional campaigns with time-limited slots, SMART26 multi-code system, and Formidable Forms integration. Tracks new registrations and reactivations with real-time counters exposed via REST API.

## Architecture

### Core Components
- **Manager.php**: Singleton managing settings, promo state, activation/reactivation logic
- **DB.php**: Database layer with two tables (`home_promo_counted`, `home_promo_reactivations`) - SMART26 schema with code tracking
- **hooks.php**: Formidable Forms event wiring (`frm_after_create_entry`, `frm_after_update_entry`, `frm_pre_update_entry`)
- **rest.php**: Public endpoint `/wp-json/promo/v1/counter` for real-time multi-code slot data
- **utils.php**: Formidable abstraction layer (`ff_get_field_value_robust`, `ff_update_entry_meta`, `ff_get_entry_meta`)
- **admin.php**: Dynamic code management UI with real-time stats and quota control
- **Validator.php**: Multi-category eligibility checking (new/passive/diagnostic/lead)

### Data Flow
1. **New Registration (SMART26)**: User enters code → `frm_validate_entry` checks validity → `frm_after_create_entry` → `Validator::validate_code()` → `Manager::validate_and_record()` → `DB::insert_entry_with_code()` → assigns code + tracks category/branch
2. **Reactivation**: `frm_pre_update_entry` captures old status → `frm_after_update_entry` detects status change `2→1` + 90+ day pasif → `Manager::record_reactivation()` → validates user-entered code → logs to both tables
3. **Auto-Pasif Date**: Status change to `2` auto-sets `pasif_date_field_id` to today (only if empty)

### SMART26 Code System (Multi-Code)
**Dynamic Configuration** - Codes managed via admin UI:
```php
'promo_codes' => [
    'SMART26-LIVE1' => ['max' => 50, 'description' => 'Live Session 1', 'active' => true],
    'SMART26-LIVE2' => ['max' => 50, 'description' => 'Live Session 2', 'active' => true],
    // Admin can add/edit unlimited codes dynamically
]
```
- Per-code quota tracking via `DB::get_code_usage($code)`
- Total max auto-calculated from all active codes
- Admin UI prevents reducing quota below current usage
- Cannot delete codes with existing redemptions (soft delete)

### Database Schema (SMART26)
```sql
CREATE TABLE home_promo_counted (
    entry_id BIGINT UNIQUE,
    promo_code VARCHAR(50),      -- User-entered code
    branch VARCHAR(100),          -- Branch selection
    user_category VARCHAR(50),    -- new/passive/diagnostic/lead
    eligibility_verified TINYINT  -- Validation flag
);
```

## Critical Conventions

### Formidable Integration Pattern
Always use utils.php helpers instead of direct Formidable APIs for testability:
```php
// CORRECT
$value = ff_get_field_value_robust($entry_id, $field_id);
ff_update_entry_meta($entry_id, $field_id, 'new_value');

// WRONG (bypasses abstraction)
FrmEntryMeta::get_entry_meta_by_field($entry_id, $field_id);
```

### Timezone Handling
All datetime operations use configured timezone (default: `Asia/Kuala_Lumpur`):
```php
$tz = new \DateTimeZone($mgr->s('timezone') ?: 'Asia/Kuala_Lumpur');
$now = new \DateTimeImmutable('now', $tz);
```

### Hook Priority Patterns
- `frm_pre_update_entry` at priority 5 (captures OLD values before Formidable updates)
- `frm_after_update_entry` at priority 5 (processes changes after update)
- Auto-pasif date logic must run before reactivation check

### Settings Access
```php
$mgr = Manager::get_instance();
$form_id = $mgr->s('form_id');        // Form 13 by default
$promo_field = $mgr->s('promo_field_id'); // 3170
$status_field = $mgr->s('status_field_id'); // 199
```

## Development Workflows

### Running Tests
```bash
composer install
vendor/bin/phpunit
```
Tests use mocked WordPress/Formidable functions in `tests/bootstrap.php`.

### Debugging
Enable debug mode in plugin settings → check `wp-content/debug.log`:
```php
if ($mgr->s('debug_mode')) {
    error_log('[HPM-DEBUG] Your message here');
}
```

### Testing Reactivation Logic
1. Create entry in Form 13 with status='2' (Pasif), pasif_date > 90 days ago
2. Update status to '1' (Aktif)
3. Verify: promo code assigned, `home_promo_reactivations` has new row, counter incremented

### GitHub Auto-Updates
Plugin uses `src/updater.php` for automatic updates from GitHub releases. Version must match `HOME_PROMO_MANAGER_VERSION` constant and GitHub tag.

## File Organization

```
src/
├── bootstrap.php    # Legacy entry (mostly replaced by main plugin file)
├── Manager.php      # Core business logic
├── db.php          # Database schema & queries
├── hooks.php       # Formidable event handlers
├── rest.php        # REST API endpoint
├── shortcodes.php  # [promo_countdown], [promo_realtime_counter]
├── admin.php       # Settings page UI
├── templates.php   # Admin template rendering
├── utils.php       # Formidable abstraction layer
└── updater.php     # GitHub auto-update handler

template/
└── promo-page.php  # Standalone landing page with live API integration
```

## Common Pitfalls

1. **Never bypass utils.php helpers** - Tests mock these, not Formidable directly
2. **Check promo period first** - `Manager::is_active()` before slot operations
3. **Database tables auto-create** - `DB::maybe_create_tables()` runs on every `init`
4. **Unique constraint on entry_id** - Prevent duplicate activations in `home_promo_counted`
5. **90-day reactivation threshold** - Hardcoded in hooks.php reactivation logic
6. **Serialized field values** - `ff_get_field_value_robust()` handles unserialization

## Formidable Forms Integration Errors

### Serialized Data Issues
Formidable stores multi-value fields (checkboxes, radio) as serialized arrays in `frm_item_metas`:
```php
// Database value: a:1:{i:0;s:2:"Ya";}
// utils.php handles this via @unserialize() and reset()
$value = ff_get_field_value_robust($entry_id, $field_id); // Returns 'Ya'
```
**Error Pattern**: Direct `$wpdb->get_var()` returns serialized string instead of actual value
**Solution**: Always use `ff_get_field_value_robust()` which tries 3 fallback methods

### Transient Expiry in Hook Chain
`frm_pre_update_entry` captures old values in 5-minute transient, `frm_after_update_entry` reads it:
```php
set_transient('hpm_prev_meta_' . $entry_id, $prev_data, 300); // 5 min expiry
```
**Error Pattern**: If hooks execute >5min apart (rare), transient expires, reactivation fails silently
**Debug**: Check `[HPM-DEBUG] No previous meta found (transient missing/expired)`

### Partial Registration Edge Case
When user creates entry with status='2' (Pasif) immediately, `created_at == pasif_date`:
```php
// Special handling in hooks.php line 211-221
if ($created_dt->format('Y-m-d') === $dt->format('Y-m-d')) {
    $is_partial = true; // Bypass 90-day check
}
```
**Error Pattern**: Partial registrations blocked by 90-day threshold
**Solution**: Compare creation date with pasif date, allow same-day activation

### Duplicate Reactivation Prevention
```php
if (DB::has_reactivation($entry_id)) {
    // Skip - already processed
}
```
**Error Pattern**: Multiple rapid status toggles can create duplicate promo codes
**Solution**: Check reactivation table before processing

## Git Workflow

**CRITICAL**: Do NOT commit or push changes to git unless:
1. Explicitly prompted to do so by the user
2. It's the end of the working day and user requests it

Always test and refine changes locally first. Wait for user confirmation before committing.

## Key External Dependencies

- **Formidable Forms Pro**: Required for `frm_*` hooks and entry meta
- **WordPress REST API**: Powers real-time counter endpoint
- **PHPUnit 9.x**: Testing framework (dev only)
