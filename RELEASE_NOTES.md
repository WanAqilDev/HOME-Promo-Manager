# Release Notes

## Version 1.4.4 (December 9, 2025)

### 🔧 Bug Fixes
- **Auto-Updater**: Fixed GitHub auto-updater to properly detect and install updates from WordPress admin
  - Corrected plugin slug handling for WordPress compatibility
  - Added proper folder management during updates
  - Improved plugin reactivation after update
- **Field Value Retrieval**: Enhanced debug logging with data type information
- **Transient Caching**: Added 1-hour cache for remote version checks to reduce API calls

### 📝 Changes
- Added extensive debug logging with `[HPM Updater]` prefix
- Improved error handling for GitHub API requests

---

## Version 1.4.3 (December 8, 2025)

### 🐛 Critical Bug Fix
- **Serialized Data Handling**: Fixed issue where status field was returning serialized empty arrays (`'a:0:{}'`) instead of actual values
  - Updated `ff_get_entry_meta()` to automatically detect and unserialize Formidable Forms field data
  - Now properly handles checkbox, radio button, and select fields stored as PHP serialized arrays
  - Extracts first value from arrays for single-value fields

### 📈 Improvements
- Enhanced debug logging to show field IDs and data types
- Better error reporting for field value queries

---

## Version 1.4.2 (December 8, 2025)

### ✨ New Feature
- **GitHub Auto-Updater**: Added automatic update functionality
  - Update plugin directly from WordPress admin panel
  - No more manual FTP uploads required
  - Shows recent commits as changelog
  - Checks GitHub every 12 hours for new versions

### 🔧 Implementation
- Created `src/updater.php` with full WordPress updater integration
- Added `GitHub Plugin URI` header to plugin metadata
- Automatic version detection from GitHub repository

---

## Version 1.4.1 (December 8, 2025)

### 🔄 Reactivation System Improvements
- Implemented form-load based qualification checking
- Added `frm_setup_edit_fields_vars` hook for Form 41 (edit form)
- Stores qualification status in transients for reliable tracking
- Enhanced reactivation flow with better timing

### 🗄️ Database
- Added automatic table creation on plugin initialization
- Improved reactivation audit logging
- Better handling of edge cases

### 🐛 Bug Fixes
- Fixed timing issues with status change detection
- Resolved duplicate closing brace syntax errors
- Fixed namespace issues with Formidable Forms classes

---

## Earlier Versions

### Version 1.4.0
- Enhanced reactivation tracking with dedicated database table
- Implemented comprehensive debug logging throughout codebase
- Added custom helpers for Formidable Forms meta operations
- Created utils.php with `ff_get_entry_meta()` and `ff_update_entry_meta()`
- GitHub repository cleanup and synchronization

---

## Installation

### From GitHub (Recommended)
1. Download: [Latest Release](https://github.com/WanAqilDev/HOME-Promo-Manager/releases/latest)
2. Upload to `/wp-content/plugins/` directory
3. Extract and activate from WordPress admin

### From WordPress Admin (with auto-updater)
1. Plugins → Installed Plugins
2. Look for "HOME Promo Manager" update notification
3. Click "Update Now"

---

## Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- Formidable Forms (Free or Pro)

## Support
- GitHub Issues: [Report a bug](https://github.com/WanAqilDev/HOME-Promo-Manager/issues)
- Repository: [HOME-Promo-Manager](https://github.com/WanAqilDev/HOME-Promo-Manager)
