# Release Automation Guide

## 🚀 GitHub Actions Release Workflow

The repository now includes automated release packaging via GitHub Actions.

---

## How It Works

### Automatic Trigger (Tag-based)
When you push a version tag (e.g., `v0.2.0`), GitHub Actions automatically:

1. ✅ Builds production-ready plugin package
2. ✅ Installs Composer dependencies (production only)
3. ✅ Creates ZIP archive excluding dev files
4. ✅ Generates SHA256 and MD5 checksums
5. ✅ Creates GitHub Release with auto-generated notes
6. ✅ Uploads package and checksums as release assets

### Manual Trigger (Workflow Dispatch)
You can also trigger releases manually from GitHub web interface.

---

## 📦 What Gets Packaged

### Included Files
- All `src/` files (core plugin code)
- `template/` directory (promo page template)
- `assets/` directory (JS, CSS, images)
- `vendor/` directory (Composer production dependencies)
- Main plugin file: `home-promo-manager.php`

### Excluded Files (Dev-only)
- `.git/` and `.github/` directories
- `tests/` directory
- `test-*.php` files
- `run-tests.php`
- `debug_*.php` files
- `.phpunit.result.cache`
- `phpunit.xml`
- `composer.json` and `composer.lock`
- All `.md` documentation files
- `node_modules/`

---

## 🏷️ Creating a New Release

### Method 1: Command Line (Recommended)

```bash
# 1. Ensure you're on master with latest changes
git checkout master
git pull origin master

# 2. Update version in home-promo-manager.php
# Edit the file to change version number

# 3. Commit version bump
git add home-promo-manager.php
git commit -m "chore: Bump version to X.X.X"
git push origin master

# 4. Create and push tag
git tag -a vX.X.X -m "Release vX.X.X - Description"
git push origin vX.X.X

# 5. Wait for GitHub Actions to complete
# Check: https://github.com/WanAqilDev/HOME-Promo-Manager/actions
```

### Method 2: GitHub Web Interface

1. Go to repository: https://github.com/WanAqilDev/HOME-Promo-Manager
2. Click "Actions" tab
3. Select "Create Release Package" workflow
4. Click "Run workflow"
5. Enter version number (e.g., `0.2.0`)
6. Click "Run workflow" button

---

## 📋 Release Checklist

### Pre-Release
- [ ] All tests passing (`vendor/bin/phpunit`)
- [ ] Version updated in `home-promo-manager.php`
- [ ] RELEASE_NOTES.md updated (optional)
- [ ] All changes committed and pushed to master
- [ ] No uncommitted changes in working directory

### Creating Release
- [ ] Create version tag: `git tag -a vX.X.X -m "Release vX.X.X"`
- [ ] Push tag: `git push origin vX.X.X`
- [ ] Monitor GitHub Actions: https://github.com/WanAqilDev/HOME-Promo-Manager/actions
- [ ] Wait for workflow to complete (~2-3 minutes)

### Post-Release
- [ ] Verify release created: https://github.com/WanAqilDev/HOME-Promo-Manager/releases
- [ ] Download and test ZIP package
- [ ] Verify checksums match
- [ ] Test installation on clean WordPress instance
- [ ] Update WordPress.org (if applicable)

---

## 🔍 Monitoring Workflow

### View Workflow Progress
1. Go to: https://github.com/WanAqilDev/HOME-Promo-Manager/actions
2. Click on the running workflow
3. Watch real-time logs for each step

### Workflow Steps
1. **Checkout code** - Clones repository
2. **Get version** - Extracts version from tag
3. **Setup PHP** - Installs PHP 7.4
4. **Install Composer dependencies** - Production packages only
5. **Create plugin package** - Builds ZIP with excluded files
6. **Create Release Notes** - Auto-generates release description
7. **Create GitHub Release** - Publishes release with assets
8. **Upload artifacts** - Stores ZIP for 90 days

### Success Indicators
- ✅ All steps show green checkmarks
- ✅ Release appears in Releases page
- ✅ ZIP file is downloadable
- ✅ Checksums files present (SHA256, MD5)

---

## 📥 Release Assets

Each release includes:

### 1. Plugin ZIP
**Filename**: `home-promo-manager-X.X.X.zip`
- Production-ready WordPress plugin
- Ready for direct upload to WordPress
- No dev dependencies included

### 2. SHA256 Checksum
**Filename**: `home-promo-manager-X.X.X.zip.sha256`
- Verify file integrity
- Usage: `sha256sum -c home-promo-manager-X.X.X.zip.sha256`

### 3. MD5 Checksum
**Filename**: `home-promo-manager-X.X.X.zip.md5`
- Alternative integrity check
- Usage: `md5sum -c home-promo-manager-X.X.X.zip.md5`

---

## 🔧 Troubleshooting

### Workflow Fails
**Problem**: GitHub Actions workflow shows red X

**Solutions**:
1. Check workflow logs for specific error
2. Verify `composer.json` is valid
3. Ensure all required files exist
4. Re-run workflow from Actions tab

### Missing Release
**Problem**: Tag pushed but no release created

**Solutions**:
1. Check Actions tab for workflow status
2. Verify tag format is `vX.X.X` (with 'v' prefix)
3. Check GitHub permissions (GITHUB_TOKEN)
4. Manually trigger via workflow_dispatch

### Package Size Too Large
**Problem**: ZIP file exceeds size limits

**Solutions**:
1. Check excluded files in workflow
2. Remove unused vendor dependencies
3. Optimize image assets
4. Consider splitting into modules

### Wrong Files in Package
**Problem**: Dev files or missing files in ZIP

**Solutions**:
1. Update `.github/workflows/release.yml`
2. Modify `--exclude` patterns in rsync command
3. Test locally before tagging

---

## 🎯 Example: v0.2.0 Release

### What Happened (Jan 8, 2026)

```bash
# 1. Switched to master
git checkout master
git pull origin master

# 2. Created workflow file
# .github/workflows/release.yml created

# 3. Committed workflow
git add .github/workflows/release.yml
git commit -m "ci: Add GitHub Actions workflow for automated release packaging"
git push origin master

# 4. Created and pushed tag
git tag -a v0.2.0 -m "Release v0.2.0 - SMART26 Dynamic Multi-Code System"
git push origin v0.2.0
```

### Result
- ✅ Workflow triggered automatically
- ✅ Package built and uploaded
- ✅ Release created: https://github.com/WanAqilDev/HOME-Promo-Manager/releases/tag/v0.2.0
- ✅ Assets available for download

---

## 📚 Additional Resources

### GitHub Actions Documentation
- [Workflow syntax](https://docs.github.com/en/actions/using-workflows/workflow-syntax-for-github-actions)
- [Creating releases](https://docs.github.com/en/repositories/releasing-projects-on-github/managing-releases-in-a-repository)
- [Artifacts](https://docs.github.com/en/actions/using-workflows/storing-workflow-data-as-artifacts)

### WordPress Plugin Distribution
- [WordPress Plugin Directory](https://wordpress.org/plugins/)
- [Plugin readme.txt requirements](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [WordPress SVN Guide](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)

---

## 🔄 Version Numbering

### Semantic Versioning (SemVer)
Format: `MAJOR.MINOR.PATCH` (e.g., `0.2.0`)

- **MAJOR**: Breaking changes, incompatible API changes
- **MINOR**: New features, backward-compatible
- **PATCH**: Bug fixes, backward-compatible

### Examples
- `0.1.10` → `0.2.0` - New features (SMART26 system)
- `0.2.0` → `0.2.1` - Bug fix
- `0.2.0` → `0.3.0` - New features
- `0.2.0` → `1.0.0` - First stable release

---

## ✅ Current Status

**Latest Release**: v0.2.0  
**Release Date**: January 8, 2026  
**Workflow**: Automated via GitHub Actions  
**Package Location**: https://github.com/WanAqilDev/HOME-Promo-Manager/releases

Ready for production deployment! 🚀
