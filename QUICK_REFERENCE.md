# Quick Reference - SMART26 Migration

## URLs

**Dry-Run (Preview)**:
```
https://yoursite.com/wp-content/plugins/HOME-Promo-Manager/migrate_to_smart26.php
```

**Execute Migration**:
```
https://yoursite.com/wp-content/plugins/HOME-Promo-Manager/migrate_to_smart26.php?execute=1
```

**Plugin Settings**:
```
https://yoursite.com/wp-admin/admin.php?page=home-promo-manager
```

---

## Quick Commands

### Backup Database
```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Check Current Counts
```sql
-- All entries by code
SELECT promo_code, COUNT(*) as count, SUM(is_legacy) as legacy_count
FROM wp_home_promo_counted 
GROUP BY promo_code;

-- Jan 2026 entries
SELECT COUNT(*) FROM wp_home_promo_counted c
JOIN wp_frm_items i ON c.entry_id = i.id
WHERE i.created_at >= '2026-01-01' AND i.created_at < '2026-02-01';
```

### Manual Legacy Flag
```sql
-- Mark specific entry as legacy
UPDATE wp_home_promo_counted SET is_legacy = 1 WHERE entry_id = 123;

-- Mark all pre-2026 as legacy
UPDATE wp_home_promo_counted c
JOIN wp_frm_items i ON c.entry_id = i.id
SET c.is_legacy = 1
WHERE i.created_at < '2026-01-01';
```

---

## Validation Flow Summary

```
NEW ENTRY:
  ├─ Legacy code (tiada/promo24)? → ❌ BLOCK
  ├─ Empty code? → ❌ BLOCK
  ├─ Invalid code? → ❌ BLOCK
  ├─ Quota full? → ❌ BLOCK
  └─ Valid code + quota available? → ✅ ALLOW

EDIT ENTRY:
  ├─ Entry exists in promo table?
  │  ├─ Same code? → ✅ ALLOW (no quota check)
  │  └─ Different code? → ❌ BLOCK
  └─ Entry not in promo table? → Process as new

REACTIVATION:
  ├─ Entry already counted?
  │  ├─ Yes → Use existing code, no quota check
  │  └─ No → Validate code + quota
  └─ 90+ days inactive? → Qualify for promo
```

---

## Testing Scenarios

### Scenario 1: Edit Existing Entry (Status Change)
1. Find entry with existing promo code
2. Change status: Aktif → Pasif
3. **Expected**: ✅ Allows update without error

### Scenario 2: Edit Existing Entry (Code Change)
1. Find entry with SMART26-LIVE1
2. Try changing code to SMART26-LIVE2
3. **Expected**: ❌ Error: "Kod promo tidak boleh ditukar"

### Scenario 3: New Registration with Legacy Code
1. Create new entry
2. Set Daftar = Ya
3. Enter code: "tiada"
4. **Expected**: ❌ Error: "Kod promo ini tidak sah"

### Scenario 4: New Registration with Valid Code
1. Create new entry
2. Set Daftar = Ya
3. Enter code: "SMART26-LIVE1"
4. **Expected**: ✅ Validates quota and allows if available

### Scenario 5: Quota Full
1. Fill code quota to max
2. Try new registration with that code
3. **Expected**: ❌ Error: "Kod penuh"
4. Try editing existing entry with same code
5. **Expected**: ✅ Allows edit

### Scenario 6: Reactivate Legacy Entry
1. Find entry with FOTY25, is_legacy=1
2. Change status: Pasif → Aktif (90+ days)
3. **Expected**: ✅ Allows without quota check

---

## Troubleshooting

### Migration Shows 0 Candidates
**Cause**: No entries match criteria
**Check**: 
- Entries created in Jan 2026?
- Updated between 12-14 Jan 2026?
- Have tiada/promo24 code?

### Edit Still Blocked
**Cause**: Entry not in promo table
**Check**: 
```sql
SELECT * FROM wp_home_promo_counted WHERE entry_id = YOUR_ENTRY_ID;
```
**Fix**: Entry needs to be in table first

### Quota Over-Consumed
**Cause**: Entries added before migration
**Check**: 
```sql
SELECT COUNT(*) FROM wp_home_promo_counted WHERE promo_code = 'SMART26-LIVE1';
```
**Fix**: Increase quota or mark old entries as legacy

### FOTY25 Not Showing
**Cause**: Code not active
**Check**: Plugin settings → Promo Codes → FOTY25 active?
**Fix**: Enable FOTY25 in settings

---

## Files Modified

| File | Purpose | Key Changes |
|------|---------|-------------|
| `src/db.php` | Database layer | Added `is_legacy` column, 5 helper functions |
| `src/hooks.php` | Validation & hooks | Rewrote validation, updated reactivation |
| `migrate_to_smart26.php` | Migration script | Dry-run + execute modes, full migration |
| `MIGRATION_STRATEGY.md` | Planning doc | Complete strategy and brainstorming |
| `IMPLEMENTATION_COMPLETE.md` | Summary | Testing checklist and deployment |

---

## Key Settings

| Setting | Field ID | Purpose |
|---------|----------|---------|
| `promo_field_id` | 3170 | Promo code field |
| `status_field_id` | 199 | Aktif/Pasif status |
| `daftar_field_id` | - | Registration trigger |
| `branch_field_id` | - | Branch selection |
| `form_id` | 13 | Target form |

---

## Success Criteria

✅ Migration dry-run shows expected entries
✅ FOTY25 quota = candidate count
✅ Edit existing entry works
✅ Change code on existing fails
✅ New registration with legacy code fails
✅ New registration with SMART26 works
✅ Quota enforcement working
✅ Legacy entries bypassing quota
✅ Reactivation respecting rules

---

**Ready to deploy when all tests pass!**
