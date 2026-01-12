# Release Notes v0.3.2 (Critical Fix)
**Release Date:** January 12, 2026  
**Type:** Critical Bug Fix  
**Git Tag:** v0.3.2

## Critical Fix: Multiple Plugin Installation Issue

### 🚨 What Was Fixed
**Problem:** Users experiencing **multiple HOME Promo Manager versions** active simultaneously (v1.9, v2.0, v3.0, v3.1 folders coexisting in `wp-content/plugins/`), causing:
- Duplicate plugin activations
- Conflicting quota counts
- Database entry duplication
- Settings page errors

**Solution:** Automatic cleanup on plugin activation + manual cleanup tools provided.

## What's New

### 1. **Auto-Cleanup on Activation**
Plugin now automatically removes old residual files when activated:
- Old test scripts (debug_active_check.php, test-smart26.php, etc.)
- Obsolete documentation (13 old .md files)
- Deprecated source files (src/test-harness.php)

**Trigger:** Activating plugin via WordPress admin → Plugins → Activate

### 2. **Version Display in Admin UI**
**NEW:** Version badge now visible in settings page header

**Location:** Settings → HOME Promo Manager (top-right corner)  
**Display:** Blue badge showing current version (e.g., "v0.3.2")  
**Benefit:** Instantly verify which version is active without checking Plugins page

### 3. **Cleanup Scripts for Multiple Installations**
**NEW:** Two scripts to safely remove duplicate plugin folders

#### cleanup-duplicates.sh (Linux/macOS/SSH)
```bash
cd /path/to/wordpress
bash wp-content/plugins/HOME-Promo-Manager/cleanup-duplicates.sh
```

#### cleanup-duplicates.ps1 (Windows/PowerShell)
```powershell
cd C:\path\to\wordpress
.\wp-content\plugins\HOME-Promo-Manager\cleanup-duplicates.ps1
```

**Features:**
- Database backup before any changes
- Detects all HOME-Promo-Manager* folders
- Auto-identifies v0.3.2 folder to keep
- Deactivates all versions
- Deletes old folders (backs up first)
- Renames to standard "HOME-Promo-Manager" folder name
- Activates clean single installation
- Validates data integrity (duplicate detection)

### 4. **Updated Cleanup Guide**
**File:** `WORDPRESS_CLEANUP_GUIDE.md`

**New Sections:**
- Emergency duplicate installation removal
- Step-by-step folder identification
- SQL queries for duplicate entry detection
- Database backup procedures
- Troubleshooting for wrong quota counts

## Technical Changes

### Modified Files
1. **home-promo-manager.php**
   - Version: `0.3.1` → `0.3.2`
   - Added `register_activation_hook()` for auto-cleanup
   - Removes 13 obsolete files on activation

2. **src/admin.php**
   - Added version badge to page header
   - Displays `HOME_PROMO_MANAGER_VERSION` constant
   - Styled with Dashicons plugin icon

3. **cleanup-duplicates.sh** (New)
   - Bash script for Linux/macOS/WP-CLI environments
   - Auto-detects duplicate folders
   - Validates database integrity

4. **cleanup-duplicates.ps1** (New)
   - PowerShell script for Windows
   - Interactive folder selection
   - Manual verification steps

5. **WORDPRESS_CLEANUP_GUIDE.md** (Updated)
   - Emergency duplicate removal section
   - SQL queries for duplicate detection
   - Three cleanup methods documented

### Code Highlights

#### Auto-Cleanup Hook
```php
register_activation_hook(__FILE__, function() {
    $old_files = [
        'debug_active_check.php',
        'test-smart26.php',
        'test-edge-cases.php',
        'run-tests.php',
        'src/test-harness.php',
        // ... 8 more old .md files
    ];
    
    foreach ($old_files as $file) {
        $file_path = HOME_PROMO_MANAGER_DIR . $file;
        if (file_exists($file_path)) {
            @unlink($file_path);
        }
    }
});
```

#### Version Badge (Admin UI)
```php
<span style="background: #2271b1; color: white; padding: 6px 14px; border-radius: 4px;">
    <span class="dashicons dashicons-admin-plugins"></span>
    v<?php echo HOME_PROMO_MANAGER_VERSION; ?>
</span>
```

## Upgrade Instructions

### Scenario 1: Single Installation (Normal Update)
**From v0.3.0/0.3.1 → v0.3.2**
1. WordPress admin → Dashboard → Updates
2. Click "Update Now" next to HOME Promo Manager
3. Plugin auto-updates from GitHub
4. Activation hook cleans up old files automatically

### Scenario 2: Multiple Installations (Critical Fix)
**Problem:** Multiple HOME-Promo-Manager folders exist

**Step-by-Step Fix:**
1. **Backup Database First!**
   ```sql
   SELECT * INTO OUTFILE '/tmp/hpm_backup.csv' FROM home_promo_counted;
   ```
   Or use phpMyAdmin: Export → Select tables → Go

2. **Run Cleanup Script:**
   - Linux/SSH: `bash cleanup-duplicates.sh`
   - Windows: `.\cleanup-duplicates.ps1`
   - Manual: Follow `WORDPRESS_CLEANUP_GUIDE.md`

3. **Verify Single Installation:**
   - Check Plugins page → Only ONE "HOME Promo Manager" listed
   - Version shows v0.3.2

4. **Check Data Integrity:**
   ```sql
   -- Should return 0 rows (no duplicates)
   SELECT entry_id, COUNT(*) as count 
   FROM home_promo_counted 
   GROUP BY entry_id 
   HAVING count > 1;
   ```

5. **Remove Duplicates (if found):**
   ```sql
   DELETE t1 FROM home_promo_counted t1
   INNER JOIN home_promo_counted t2
   WHERE t1.id > t2.id AND t1.entry_id = t2.entry_id;
   ```

### Scenario 3: Manual Cleanup (No Scripts)
1. FTP/File Manager to `wp-content/plugins/`
2. Identify all `HOME-Promo-Manager*` folders:
   - `HOME-Promo-Manager/` (v3.2)
   - `HOME-Promo-Manager-old/` (v1.9/2.0)
   - etc.
3. Deactivate ALL in WordPress → Plugins
4. Delete all folders EXCEPT the v0.3.2 one
5. Rename remaining folder to `HOME-Promo-Manager`
6. Activate plugin

## Testing Checklist

- [ ] Only ONE "HOME Promo Manager" appears in Plugins page
- [ ] Version badge shows "v0.3.2" in Settings page
- [ ] Quota counts match expected values
- [ ] No duplicate entries in database (SQL check)
- [ ] REST API works: `/wp-json/promo/v1/counter`
- [ ] Old files removed (debug_active_check.php, test-smart26.php, etc.)
- [ ] Inline quota editing still works (from v0.3.1)

## Breaking Changes
**None** - Backward compatible with v0.3.0/0.3.1

## Known Issues
- **Old Folders Remain:** Activation hook only deletes files, not duplicate plugin folders (use cleanup scripts for that)
- **Manual Deactivation:** Scripts require manual plugin deactivation on Windows (WP-CLI not always available)
- **Database Duplicates:** If entries duplicated before cleanup, manual SQL required to remove

## Rollback Instructions
If issues occur:
```bash
cd wp-content/plugins/HOME-Promo-Manager
git checkout v0.3.1
```
Or reinstall v0.3.1 from [GitHub Releases](https://github.com/WanAqilDev/HOME-Promo-Manager/releases/tag/v0.3.1)

## Support
- **Critical Issue:** Use cleanup scripts in plugin root directory
- **Duplicate Entries:** Run SQL queries in `WORDPRESS_CLEANUP_GUIDE.md`
- **GitHub Issues:** https://github.com/WanAqilDev/HOME-Promo-Manager/issues

---

**Full Changelog:** [v0.3.1...v0.3.2](https://github.com/WanAqilDev/HOME-Promo-Manager/compare/v0.3.1...v0.3.2)

**Files Added:**
- `cleanup-duplicates.sh`
- `cleanup-duplicates.ps1`
- `RELEASE_NOTES_v0.3.2.md`

**Files Modified:**
- `home-promo-manager.php` (version bump + activation hook)
- `src/admin.php` (version badge)
- `WORDPRESS_CLEANUP_GUIDE.md` (emergency section)
