# WordPress Plugin Cleanup Guide v0.3.1

## Overview
This guide helps remove residual files from previous versions (1.9, 2.0, etc.) when updating from older installations to v0.3.1+.

## Important: Before Cleanup
1. **Backup your database** - especially `home_promo_counted` and `home_promo_reactivations` tables
2. **Deactivate the plugin** in WordPress admin (Plugins → Deactivate HOME Promo Manager)

## Files to Remove from WordPress

### Location
Navigate to: `wp-content/plugins/HOME-Promo-Manager/`

### Obsolete Files (Safe to Delete)
These files were present in v1.9/2.0 but removed in v0.3.0+:

**Old Test Scripts:**
- `debug_active_check.php`
- `test-smart26.php`
- `test-edge-cases.php`
- `run-tests.php`

**Old Documentation:**
- `RELEASE_NOTES.md` (superseded by version-specific files)
- `ADMIN_UI_ENHANCEMENTS.md`
- `CODEBASE_SWEEP_REPORT.md`
- `EDGE_CASE_PROTECTION.md`
- `PARTICIPANT_TESTING_GUIDE.md`
- `PR_SUMMARY.md`
- `TEST_HARNESS_QUICK_START.md`
- `TEST_HARNESS_VALIDATION_GUIDE.md`
- `RELEASE_AUTOMATION.md`

**Deprecated Source Files:**
- `src/test-harness.php` (if exists)
- Any `*.backup` or `*.old` files

## Cleanup Steps

### Option 1: Automatic (Recommended)
1. In WordPress admin, go to **Plugins → Installed Plugins**
2. Find **HOME Promo Manager** → Click **Delete**
3. Go to **Plugins → Add New → Upload Plugin**
4. Upload the latest v0.3.1 ZIP from [GitHub Releases](https://github.com/WanAqilDev/HOME-Promo-Manager/releases/tag/v0.3.1)
5. Activate the plugin

**Note:** This preserves database tables (`home_promo_counted`, `home_promo_reactivations`) and settings.

### Option 2: Manual File Removal
1. Connect via FTP/File Manager to `wp-content/plugins/HOME-Promo-Manager/`
2. Delete each file listed above
3. In WordPress admin, go to **Dashboard → Updates**
4. Click **Check Again** to trigger GitHub auto-update
5. Update to v0.3.1

### Option 3: SSH/Terminal (Advanced)
```bash
cd /path/to/wordpress/wp-content/plugins/HOME-Promo-Manager

# Remove old test scripts
rm -f debug_active_check.php test-smart26.php test-edge-cases.php run-tests.php

# Remove old documentation
rm -f RELEASE_NOTES.md ADMIN_UI_ENHANCEMENTS.md CODEBASE_SWEEP_REPORT.md \
      EDGE_CASE_PROTECTION.md PARTICIPANT_TESTING_GUIDE.md PR_SUMMARY.md \
      TEST_HARNESS_QUICK_START.md TEST_HARNESS_VALIDATION_GUIDE.md \
      RELEASE_AUTOMATION.md

# Remove deprecated source files
rm -f src/test-harness.php

# Clean up any backup files
rm -f *.backup *.old
```

## Current File Structure (v0.3.1)
After cleanup, your plugin directory should contain:

```
HOME-Promo-Manager/
├── home-promo-manager.php       # Main plugin file
├── composer.json
├── phpunit.xml
├── migrate_to_smart26.php       # Migration script
├── README.md
├── SMART26_IMPLEMENTATION_PLAN.md
├── WORDPRESS_UPDATE_READY.md
├── WORDPRESS_CLEANUP_GUIDE.md   # This file
├── assets/
│   └── js/
│       └── tailwindcss.js
├── src/
│   ├── Manager.php
│   ├── Validator.php
│   ├── db.php
│   ├── hooks.php
│   ├── rest.php
│   ├── admin.php              # ✅ Now with inline quota editing
│   ├── templates.php
│   ├── shortcodes.php
│   ├── utils.php
│   ├── bootstrap.php
│   └── updater.php
├── template/
│   └── promo-page.php
└── tests/                     # For development only
    ├── bootstrap.php
    ├── test-manager.php
    └── test-db.php
```

## Verification
After cleanup, verify:
1. Plugin appears as **v0.3.1** in WordPress admin
2. Settings page loads without errors
3. Promo codes table displays with **editable quota/description fields**
4. REST API responds: `yoursite.com/wp-json/promo/v1/counter`

## Troubleshooting

### Plugin Shows Wrong Version
- Clear WordPress object cache: **wp cache flush** (if using caching plugin)
- Deactivate → Reactivate plugin

### Settings Page Shows Errors
- Check `wp-content/debug.log` for errors
- Verify all files in `src/` directory exist
- Re-upload plugin from GitHub

### Database Tables Missing
Run migration manually:
```bash
wp eval-file wp-content/plugins/HOME-Promo-Manager/migrate_to_smart26.php
```

## Support
For issues: [GitHub Issues](https://github.com/WanAqilDev/HOME-Promo-Manager/issues)
