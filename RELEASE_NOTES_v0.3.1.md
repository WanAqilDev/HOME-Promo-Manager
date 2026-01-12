# Release Notes v0.3.1 (Hotfix)
**Release Date:** January 2024  
**Type:** Hotfix  
**Git Tag:** v0.3.1

## What's New

### ✨ Inline Quota Editing
**NEW:** Edit promo code quotas directly in the settings table without forms!

**Features:**
- Click max quota field to edit (validates >= current usage)
- Click description field to edit (press Enter to save)
- Real-time validation prevents quota below active redemptions
- Visual feedback: yellow highlight on successful edit
- Progress bars update instantly when quota changes
- Keyboard support: Enter to save, Escape to cancel

**Usage:**
1. Navigate to **Settings → HOME Promo Manager → Code Management**
2. Click on any **Max Quota** or **Description** cell
3. Type new value (quota: numbers only, minimum = current usage)
4. Press Enter or click away to save
5. Click **Save Settings** at bottom to persist changes

**Example:**
```
Before: SMART26-LIVE1 has 25/50 redemptions
Action: Click "50" → type "100" → Enter
Result: 25/100 shown, "Save Settings" reminder appears
```

### 📋 WordPress Cleanup Guide
**NEW:** Documentation for removing residual files from v1.9/2.0 installations

**File:** `WORDPRESS_CLEANUP_GUIDE.md`

**Covers:**
- List of obsolete files to remove (16 files from old versions)
- 3 cleanup methods: Automatic (plugin delete/reinstall), Manual FTP, SSH
- Expected file structure after cleanup
- Troubleshooting common issues

**Why This Matters:**
- Prevents confusion from outdated documentation
- Removes unused test scripts
- Cleaner plugin directory improves performance
- Easier debugging with current files only

## Technical Changes

### Modified Files
1. **home-promo-manager.php**
   - Version bumped: `0.3.0` → `0.3.1`
   - Constant updated: `HOME_PROMO_MANAGER_VERSION` → `'0.3.1'`

2. **src/admin.php**
   - Added `contenteditable` to quota and description table cells
   - Implemented jQuery handlers for inline editing
   - Added validation: quota >= current usage
   - Added visual feedback on edit (yellow highlight)
   - Real-time updates to remaining slots and progress bars
   - Number-only keyboard restriction for quota field

3. **WORDPRESS_CLEANUP_GUIDE.md** (New File)
   - Complete file removal checklist
   - 3 cleanup methods with step-by-step instructions
   - Expected v0.3.1 file structure
   - Verification steps and troubleshooting

### Code Highlights

#### Inline Editing Logic (admin.php)
```javascript
// Quota validation on blur
if (newQuota < usage) {
    alert('Quota cannot be less than current usage (' + usage + ')!');
    $(this).text(originalValue);
    return;
}

// Update hidden field for form submission
$('[name="home_promo_manager_settings[promo_codes][' + code + '][max]"]').val(newQuota);

// Update remaining count display
const remaining = newQuota - usage;
$(this).closest('tr').find('.code-remaining strong').text(remaining);
```

#### Validation Features
- **Number-only input:** Prevents non-numeric characters during typing
- **Minimum check:** Quota cannot go below current redemptions
- **Positive integer:** Rejects 0 or negative values
- **Enter key:** Quick save with keyboard
- **Original value restore:** ESC or invalid input reverts changes

## Upgrade Instructions

### From v0.3.0 → v0.3.1
**Automatic Update (Recommended):**
1. Go to **Dashboard → Updates** in WordPress admin
2. Click **Check Again** to detect v0.3.1
3. Click **Update Now** next to HOME Promo Manager
4. Plugin will auto-update from GitHub

**Manual Update:**
1. Download [v0.3.1 ZIP](https://github.com/WanAqilDev/HOME-Promo-Manager/archive/refs/tags/v0.3.1.zip)
2. In WordPress: **Plugins → Add New → Upload Plugin**
3. Choose ZIP file → **Install Now**
4. Click **Replace current with uploaded**

### From v1.9/2.0 → v0.3.1
**Important:** Follow `WORDPRESS_CLEANUP_GUIDE.md` after updating to remove residual files.

1. **Backup database tables:**
   - `home_promo_counted`
   - `home_promo_reactivations`

2. **Update plugin** (choose one):
   - Delete old version → install v0.3.1 ZIP
   - Use auto-update if configured

3. **Run migration:**
   ```bash
   wp eval-file wp-content/plugins/HOME-Promo-Manager/migrate_to_smart26.php
   ```

4. **Clean up old files:**
   - Follow cleanup guide to remove 16 obsolete files

5. **Verify settings:**
   - Check promo codes display correctly
   - Test inline editing (click quota field)
   - Verify REST API: `yoursite.com/wp-json/promo/v1/counter`

## Testing Checklist

Before deploying to production:
- [ ] Inline quota editing works (validation prevents < usage)
- [ ] Description editing works
- [ ] Progress bars update correctly
- [ ] "Save Settings" button persists changes
- [ ] REST API returns updated quotas
- [ ] Old test files removed (if upgrading from v1.9/2.0)
- [ ] Plugin shows v0.3.1 in admin

## Breaking Changes
**None** - This is a backward-compatible hotfix.

## Bug Fixes
- **Fixed:** Quota field was display-only (no edit capability)
- **Fixed:** No way to update code descriptions after creation
- **Fixed:** Users had to delete and recreate codes to change quota

## Known Limitations
- **Inline editing requires JavaScript** - Falls back to Add/Edit form if JS disabled
- **Save reminder:** Users must click "Save Settings" after inline edits
- **No undo:** Once quota changed and saved, requires manual correction

## Rollback Instructions
If issues occur, rollback to v0.3.0:
```bash
cd wp-content/plugins/HOME-Promo-Manager
git checkout v0.3.0
```

Or reinstall v0.3.0 from [GitHub Releases](https://github.com/WanAqilDev/HOME-Promo-Manager/releases/tag/v0.3.0).

## Support
- **Issues:** https://github.com/WanAqilDev/HOME-Promo-Manager/issues
- **Docs:** See `WORDPRESS_CLEANUP_GUIDE.md` for cleanup help
- **Migration:** See `migrate_to_smart26.php` for SMART26 upgrade

---

**Full Changelog:** [v0.3.0...v0.3.1](https://github.com/WanAqilDev/HOME-Promo-Manager/compare/v0.3.0...v0.3.1)
