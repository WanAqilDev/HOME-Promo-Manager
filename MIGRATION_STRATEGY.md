# SMART26 Migration & Edit Protection Strategy

## Overview
Implement dual-track system: legacy entries (grandfathered) vs new SMART26 entries with proper edit protection.

---

## 1. Database Schema Changes

### Add `is_legacy` Column
```sql
ALTER TABLE wp_home_promo_counted 
ADD COLUMN is_legacy TINYINT(1) DEFAULT 0 AFTER eligibility_verified;

-- Index for performance
ALTER TABLE wp_home_promo_counted 
ADD INDEX idx_legacy (is_legacy);
```

### Purpose:
- `is_legacy = 1`: Entry from pre-SMART26 era, bypass quota validation
- `is_legacy = 0`: New SMART26 entry, enforce strict quota

---

## 2. FOTY25 Migration Criteria

### Who Gets Migrated to FOTY25?
**Criteria (ALL must match)**:
1. ✅ Entry created in January 2026 (`created_at >= '2026-01-01' AND created_at < '2026-02-01'`)
2. ✅ Entry updated during promo period (`updated_at >= '2026-01-12 12:00:00' AND updated_at <= '2026-01-14 23:59:59'`)
3. ✅ Current promo code is legacy (`promo_code IN ('tiada', 'promo24', 'promo12', 'Tiada', 'TIADA', 'PROMO24', 'PROMO12')`)

### Who Does NOT Get Migrated?
- ❌ Entries from 2025 or earlier (keep original codes)
- ❌ Jan 2026 entries NOT updated during promo period
- ❌ Entries already with SMART26 codes

### Migration SQL:
```sql
-- Step 1: Get count for FOTY25 quota
SELECT COUNT(*) as foty25_count
FROM wp_home_promo_counted c
JOIN wp_frm_items i ON c.entry_id = i.id
WHERE c.promo_code IN ('tiada', 'promo24', 'promo12', 'Tiada', 'TIADA', 'PROMO24', 'PROMO12')
  AND i.created_at >= '2026-01-01' 
  AND i.created_at < '2026-02-01'
  AND i.updated_at >= '2026-01-12 12:00:00' 
  AND i.updated_at <= '2026-01-14 23:59:59';

-- Step 2: Mark as legacy and migrate to FOTY25
UPDATE wp_home_promo_counted c
JOIN wp_frm_items i ON c.entry_id = i.id
SET c.promo_code = 'FOTY25',
    c.is_legacy = 1
WHERE c.promo_code IN ('tiada', 'promo24', 'promo12', 'Tiada', 'TIADA', 'PROMO24', 'PROMO12')
  AND i.created_at >= '2026-01-01' 
  AND i.created_at < '2026-02-01'
  AND i.updated_at >= '2026-01-12 12:00:00' 
  AND i.updated_at <= '2026-01-14 23:59:59';

-- Step 3: Mark pre-2026 entries as legacy (keep original codes)
UPDATE wp_home_promo_counted c
JOIN wp_frm_items i ON c.entry_id = i.id
SET c.is_legacy = 1
WHERE i.created_at < '2026-01-01';

-- Step 4: Also update Formidable entry meta with new code
UPDATE wp_frm_item_metas m
JOIN wp_home_promo_counted c ON m.item_id = c.entry_id
JOIN wp_frm_items i ON c.entry_id = i.id
SET m.meta_value = 'FOTY25'
WHERE m.field_id = 3170  -- promo_field_id from settings
  AND c.promo_code = 'FOTY25'
  AND i.created_at >= '2026-01-01' 
  AND i.created_at < '2026-02-01'
  AND i.updated_at >= '2026-01-12 12:00:00' 
  AND i.updated_at <= '2026-01-14 23:59:59';
```

---

## 3. Validation Flow Chart

```
Entry Submission
    │
    ├──> Is promo active?
    │    ├─ No → Allow (no promo logic)
    │    └─ Yes → Continue
    │
    ├──> Get entry_id from submission
    │
    ├──> Does entry exist in wp_home_promo_counted?
    │    │
    │    ├─ YES (EDIT MODE)
    │    │   │
    │    │   ├──> Get old_code from database
    │    │   ├──> Get new_code from submission
    │    │   │
    │    │   ├──> Is old_code === new_code?
    │    │   │    ├─ YES → ALLOW (no code change)
    │    │   │    │         Skip quota validation
    │    │   │    │         Allow status update
    │    │   │    │         Allow branch update
    │    │   │    │         Allow other field updates
    │    │   │    │
    │    │   │    └─ NO → BLOCK
    │    │   │              Error: "Kod promo tidak boleh ditukar"
    │    │   │              Prevent submission
    │    │   │
    │    │   └──> Is entry is_legacy = 1?
    │    │        ├─ YES → Extra bypass (historical data protection)
    │    │        └─ NO → Normal edit rules
    │    │
    │    └─ NO (NEW REGISTRATION)
    │        │
    │        ├──> Check if code is legacy
    │        │    ├─ YES → REJECT
    │        │    │         Error: "Kod promo lama tidak sah"
    │        │    └─ NO → Continue
    │        │
    │        ├──> Check if Daftar = Ya
    │        │    ├─ NO → Allow (not registering)
    │        │    └─ YES → Continue
    │        │
    │        ├──> Validate code exists in settings
    │        │    ├─ NO → Error: "Kod tidak sah"
    │        │    └─ YES → Continue
    │        │
    │        ├──> Validate code is active
    │        │    ├─ NO → Error: "Kod tidak aktif"
    │        │    └─ YES → Continue
    │        │
    │        ├──> Check quota (ATOMIC)
    │        │    ├─ FULL → Error: "Kod penuh"
    │        │    └─ Available → Continue
    │        │
    │        └──> ALLOW & RECORD
    │             Insert into wp_home_promo_counted
    │             Set is_legacy = 0
```

---

## 4. Code Management (ALL Codes Adjustable)

### Settings Structure:
```php
'promo_codes' => [
    'FOTY25' => [
        'max' => 25,  // Auto-set to migration count, then editable
        'description' => 'Friend of the Year 2025 - Grandfathered',
        'active' => true,
        'is_legacy_pool' => true  // Special flag, won't show in promo page
    ],
    'SMART26-LIVE1' => [
        'max' => 50,
        'description' => 'Live Session 1',
        'active' => true
    ],
    'SMART26-LIVE2' => [
        'max' => 50,
        'description' => 'Live Session 2',
        'active' => true
    ],
    // ... unlimited codes
]
```

### Admin UI Features:
1. **Edit Quota**:
   - Inline input field
   - Validation: `new_quota >= current_usage`
   - Error if trying to reduce below usage

2. **Edit Description**:
   - Inline text input
   - No restrictions

3. **Toggle Active**:
   - Checkbox
   - Deactivating prevents NEW usage
   - Doesn't affect existing entries

4. **Delete Code**:
   - Only if `current_usage = 0`
   - Confirmation dialog
   - Permanent removal

5. **Code Name Edit**:
   - ⚠️ **DISABLED** - Too risky
   - Would require updating all entries
   - Could break historical data

---

## 5. Field Update Permissions

### Status Update (No Quota Check):
**Conditions to allow**:
1. ✅ Entry exists in `wp_home_promo_counted`
2. ✅ Promo code unchanged
3. ✅ Only status field modified

**Fields Allowed**:
- `status_field_id` (199): Aktif/Pasif status
- `pasif_date_field_id`: Auto-set on status → Pasif
- Other metadata (comments, notes, etc.)

### Promo Code Changes:
**Rule**: ❌ **BLOCKED** during promo period

**Implementation**:
```php
if ($entry_exists && $old_code !== $new_code) {
    $errors['field' . $promo_field] = 'Kod promo tidak boleh ditukar selepas pendaftaran.';
}
```

### Branch (admincawangan) Updates:
**Rule**: ✅ **ALLOWED** 

**Current branch field**: `branch_field_id` from settings

**Who can edit**:
- Admin (full access)
- Outlet admin (admincawangan role)

### Other Formidable Fields:
User can update any field EXCEPT `promo_code` if:
- Entry exists
- Not changing promo code
- Has appropriate permissions

---

## 6. Database Helper Functions

### Functions to Add to `DB` Class:

```php
/**
 * Check if entry exists in promo tracking table
 */
public static function entry_exists($entry_id) {
    global $wpdb;
    $table = self::table_name();
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE entry_id = %d",
        (int) $entry_id
    ));
    return (int) $count > 0;
}

/**
 * Get entry data from promo table
 */
public static function get_entry_data($entry_id) {
    global $wpdb;
    $table = self::table_name();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE entry_id = %d",
        (int) $entry_id
    ), ARRAY_A);
}

/**
 * Check if entry is legacy
 */
public static function is_legacy_entry($entry_id) {
    global $wpdb;
    $table = self::table_name();
    $is_legacy = $wpdb->get_var($wpdb->prepare(
        "SELECT is_legacy FROM {$table} WHERE entry_id = %d",
        (int) $entry_id
    ));
    return (int) $is_legacy === 1;
}

/**
 * Update entry's is_legacy flag
 */
public static function set_legacy_flag($entry_id, $is_legacy = true) {
    global $wpdb;
    $table = self::table_name();
    return $wpdb->update(
        $table,
        ['is_legacy' => $is_legacy ? 1 : 0],
        ['entry_id' => (int) $entry_id],
        ['%d'],
        ['%d']
    );
}
```

---

## 7. Migration Script Structure

### File: `migrate_to_smart26.php`

**Steps**:
1. ✅ Add `is_legacy` column to table
2. ✅ Count entries matching FOTY25 criteria
3. ✅ Create/update FOTY25 code in settings with proper quota
4. ✅ Migrate promo_code: tiada/promo24 → FOTY25 (Jan 2026 + updated during promo)
5. ✅ Set is_legacy = 1 for migrated entries
6. ✅ Set is_legacy = 1 for pre-2026 entries (keep original codes)
7. ✅ Update Formidable entry meta with new codes
8. ✅ Show statistics before/after
9. ✅ Dry-run mode (preview changes without committing)

---

## 8. Validation Logic Updates

### `hooks.php` - `frm_validate_entry` Filter

**New Logic**:
```php
// Priority 10 - Main validation
add_filter('frm_validate_entry', function ($errors, $values) {
    $mgr = Manager::get_instance();
    $form_id = !empty($values['form_id']) ? (int) $values['form_id'] : 0;
    $entry_id = !empty($values['id']) ? (int) $values['id'] : 0;
    
    if ($form_id !== (int) $mgr->s('form_id')) {
        return $errors;
    }

    if (!$mgr->is_active()) {
        return $errors;
    }

    $promo_field = (string) $mgr->s('promo_field_id');
    $new_code = !empty($values['item_meta'][$promo_field]) ? trim($values['item_meta'][$promo_field]) : '';
    
    // ============================================
    // EDIT MODE: Entry exists in promo table
    // ============================================
    if ($entry_id && DB::entry_exists($entry_id)) {
        $entry_data = DB::get_entry_data($entry_id);
        $old_code = $entry_data['promo_code'];
        
        // Rule 1: If code unchanged, allow (status update or other fields)
        if ($old_code === $new_code) {
            return $errors; // ALLOW - no validation needed
        }
        
        // Rule 2: Block code changes
        if (!isset($errors['field' . $promo_field])) {
            $errors['field' . $promo_field] = '';
        }
        $errors['field' . $promo_field] = 'Kod promo tidak boleh ditukar selepas pendaftaran.';
        return $errors;
    }
    
    // ============================================
    // NEW REGISTRATION MODE
    // ============================================
    
    // Check if user wants to register
    $daftar_field = (string) $mgr->s('daftar_field_id');
    $trigger_val = $mgr->s('daftar_trigger_value') ?: 'Ya';
    $daftar_val = !empty($values['item_meta'][$daftar_field]) ? $values['item_meta'][$daftar_field] : '';

    if ($daftar_val !== $trigger_val) {
        return $errors; // Not registering
    }
    
    // Block legacy codes for NEW registrations
    $legacy_codes = ['tiada', 'promo24', 'promo12', 'Tiada', 'TIADA', 'PROMO24', 'PROMO12'];
    if (in_array($new_code, $legacy_codes, true)) {
        if (!isset($errors['field' . $promo_field])) {
            $errors['field' . $promo_field] = '';
        }
        $errors['field' . $promo_field] = 'Kod promo ini tidak sah untuk pendaftaran baru. Sila gunakan kod SMART26.';
        return $errors;
    }
    
    // Require code for new registrations
    if (empty($new_code)) {
        if (!isset($errors['field' . $promo_field])) {
            $errors['field' . $promo_field] = '';
        }
        $errors['field' . $promo_field] = 'Sila masukkan kod promo SMART26.';
        return $errors;
    }
    
    // Validate code (quota check happens here)
    $validation = $mgr->validate_code($new_code);
    
    if (!$validation['valid']) {
        if (!isset($errors['field' . $promo_field])) {
            $errors['field' . $promo_field] = '';
        }
        $errors['field' . $promo_field] = $validation['message'];
    }
    
    return $errors;
}, 10, 2);
```

---

## 9. Role Permissions (admincawangan)

### Check User Role:
```php
function hpm_can_edit_branch() {
    return current_user_can('administrator') || current_user_can('admincawangan');
}
```

### Field Protection:
- Formidable Forms has built-in field permissions
- Can restrict `promo_field_id` to read-only via conditional logic
- Allow branch field edit for admincawangan role

**Implementation**: 
- Use Formidable conditional logic
- Or add custom filter: `frm_field_permission`

---

## 10. Reactivation Handling

### Current Flow:
1. User changes status from Pasif (2) → Aktif (1)
2. Check pasif_date > 90 days
3. Record reactivation
4. Assign promo code

### Updated Flow with is_legacy:
1. User changes status 2 → 1
2. Check if entry exists in promo table
   - YES: Use existing code (don't reassign)
   - NO: Check 90-day rule
3. If new reactivation, check is_legacy
   - Legacy: Allow without quota check
   - New: Enforce quota

---

## 11. Admin UI Enhancements

### Settings Page - Code Management Table

**Columns**:
1. Code Name (read-only)
2. Description (editable inline)
3. Quota (editable, min = current usage)
4. Used (read-only, live count)
5. Remaining (calculated)
6. Active (toggle)
7. Actions (Delete if used=0)

**JavaScript**:
- Inline editing with AJAX save
- Real-time validation
- Confirmation dialogs

---

## 12. Testing Checklist

### Before Migration:
- [ ] Count total entries
- [ ] Count tiada/promo24 entries
- [ ] Count Jan 2026 entries updated during promo
- [ ] Backup database

### After Migration:
- [ ] Verify FOTY25 count matches expected
- [ ] Verify is_legacy flags set correctly
- [ ] Verify pre-2026 entries unchanged
- [ ] Test edit existing entry (should allow)
- [ ] Test change code on existing (should block)
- [ ] Test new registration with legacy code (should block)
- [ ] Test new registration with SMART26 code (should allow)
- [ ] Test quota enforcement
- [ ] Test status update without code change

### Edge Cases:
- [ ] Entry with FOTY25, try to change to SMART26
- [ ] Entry with SMART26, try to change to FOTY25
- [ ] Full quota code, try to edit existing entry
- [ ] Legacy entry, change status multiple times
- [ ] admincawangan role permissions

---

## 13. Rollback Plan

If migration fails:

```sql
-- Restore from backup
-- OR manually revert:

-- Remove is_legacy column
ALTER TABLE wp_home_promo_counted DROP COLUMN is_legacy;

-- Restore original codes from backup
-- (Requires backup taken before migration)

-- Remove FOTY25 code from settings
-- (Via admin UI or direct option update)
```

---

## 14. Questions & Decisions

### Answered:
✅ Migration scope: Jan 2026 + updated during promo only  
✅ Legacy flag: Yes, add to database  
✅ Edit blocking: Block code changes, allow status  
✅ Quota: All codes adjustable  
✅ Admin permissions: Allow admincawangan to update branches  

### Pending Clarification:
1. **FOTY25 display**: Should it appear on promo page for NEW users?
   - Recommendation: Hide from promo page (is_legacy_pool = true)

2. **Reactivation quota**: Should reactivations count toward code quota?
   - Current: Yes, they consume a slot
   - Alternative: Separate reactivation pool?

3. **Entry meta sync**: Should we sync Formidable meta when migrating?
   - Recommendation: Yes, update wp_frm_item_metas to reflect FOTY25

4. **Dry-run mode**: Should migration script have preview mode?
   - Recommendation: Yes, show what WOULD change without committing

---

## 15. Implementation Order

1. **Phase 1: Database** (Day 1)
   - Add is_legacy column
   - Add DB helper functions
   - Test queries

2. **Phase 2: Migration Script** (Day 1)
   - Write migration SQL
   - Add dry-run mode
   - Test on staging

3. **Phase 3: Validation Logic** (Day 2)
   - Update frm_validate_entry
   - Add entry existence checks
   - Test edit blocking

4. **Phase 4: Admin UI** (Day 2)
   - Add inline editing for quota/description
   - Add delete functionality
   - Test permissions

5. **Phase 5: Testing** (Day 3)
   - Full regression test
   - Edge case testing
   - Performance testing

6. **Phase 6: Deployment** (Day 3)
   - Backup production
   - Run migration
   - Monitor logs
   - Verify counts

---

## Ready to Proceed?

Once you confirm this strategy, I'll implement:
1. Database schema update
2. Migration script (with dry-run)
3. Updated validation logic
4. DB helper functions
5. Admin UI enhancements

**Please review and confirm**:
- Migration criteria looks correct?
- Validation flow makes sense?
- Any other edge cases to consider?
