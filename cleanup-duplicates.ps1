# HOME Promo Manager - Duplicate Installation Cleanup Script (Windows/PowerShell)
# Safe removal of old plugin versions while preserving database

$ErrorActionPreference = "Stop"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "HOME Promo Manager - Cleanup Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Configuration
$WpPluginsDir = "wp-content\plugins"
$KeepVersion = "3.1"
$BackupDir = "hpm-backup-$(Get-Date -Format 'yyyyMMdd-HHmmss')"

Write-Host "IMPORTANT: This script will:" -ForegroundColor Yellow
Write-Host "  1. Backup database tables" -ForegroundColor Yellow
Write-Host "  2. Deactivate all HOME Promo Manager versions" -ForegroundColor Yellow
Write-Host "  3. Delete old plugin folders (keeps v$KeepVersion only)" -ForegroundColor Yellow
Write-Host "  4. Activate clean installation" -ForegroundColor Yellow
Write-Host ""
$confirm = Read-Host "Continue? (yes/no)"
if ($confirm -ne "yes") {
    Write-Host "Aborted." -ForegroundColor Red
    exit
}

# Step 1: Database Backup
Write-Host ""
Write-Host "Step 1: Database Backup" -ForegroundColor Green
Write-Host "----------------------------------------"
New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null

Write-Host "Please backup these tables manually via phpMyAdmin or wp-cli:" -ForegroundColor Yellow
Write-Host "  - home_promo_counted" -ForegroundColor Yellow
Write-Host "  - home_promo_reactivations" -ForegroundColor Yellow
Write-Host ""
Write-Host "Backup directory created: $BackupDir" -ForegroundColor Cyan
$null = Read-Host "Press Enter when backup is complete"

# Step 2: List installations
Write-Host ""
Write-Host "Step 2: Finding HOME Promo Manager installations..." -ForegroundColor Green
Write-Host "----------------------------------------"

if (-not (Test-Path $WpPluginsDir)) {
    Write-Host "ERROR: WordPress plugins directory not found: $WpPluginsDir" -ForegroundColor Red
    Write-Host "Please run this script from your WordPress root directory." -ForegroundColor Red
    exit 1
}

$HpmFolders = Get-ChildItem -Path $WpPluginsDir -Directory | Where-Object { $_.Name -like "HOME-Promo-Manager*" }

if ($HpmFolders.Count -eq 0) {
    Write-Host "ERROR: No HOME Promo Manager folders found in $WpPluginsDir" -ForegroundColor Red
    exit 1
}

Write-Host "Found $($HpmFolders.Count) installation(s):" -ForegroundColor Cyan
$i = 1
$KeepFolder = $null

foreach ($folder in $HpmFolders) {
    $versionFile = Join-Path $folder.FullName "home-promo-manager.php"
    $version = "unknown"
    
    if (Test-Path $versionFile) {
        $content = Get-Content $versionFile -Raw
        if ($content -match 'Version:\s+([\d.]+)') {
            $version = $matches[1]
        }
    }
    
    Write-Host "  [$i] $($folder.Name) => Version: $version" -ForegroundColor White
    
    if ($version -eq $KeepVersion -or $folder.Name -eq "HOME-Promo-Manager") {
        $KeepFolder = $folder
        Write-Host "      ^ This will be KEPT" -ForegroundColor Green
    }
    
    $i++
}

if ($null -eq $KeepFolder) {
    Write-Host ""
    Write-Host "Could not auto-detect v$KeepVersion folder." -ForegroundColor Yellow
    Write-Host "Please select which folder to KEEP:"
    for ($i = 0; $i -lt $HpmFolders.Count; $i++) {
        Write-Host "  [$($i+1)] $($HpmFolders[$i].Name)"
    }
    $selection = Read-Host "Enter number"
    $KeepFolder = $HpmFolders[$selection - 1]
}

Write-Host ""
Write-Host "Will KEEP: $($KeepFolder.Name)" -ForegroundColor Green

# Step 3: Deactivate plugins
Write-Host ""
Write-Host "Step 3: Deactivating plugins..." -ForegroundColor Green
Write-Host "----------------------------------------"
Write-Host "Please deactivate ALL 'HOME Promo Manager' plugins in WordPress admin:" -ForegroundColor Yellow
Write-Host "  1. Go to: WordPress Admin → Plugins" -ForegroundColor Yellow
Write-Host "  2. Click 'Deactivate' under each HOME Promo Manager instance" -ForegroundColor Yellow
$null = Read-Host "Press Enter when deactivation is complete"

# Step 4: Backup folders
Write-Host ""
Write-Host "Step 4: Backing up plugin folders..." -ForegroundColor Green
Write-Host "----------------------------------------"

foreach ($folder in $HpmFolders) {
    if ($folder.FullName -ne $KeepFolder.FullName) {
        $destPath = Join-Path $BackupDir $folder.Name
        Copy-Item -Path $folder.FullName -Destination $destPath -Recurse -Force
        Write-Host "  Backed up: $($folder.Name)" -ForegroundColor Cyan
    }
}

Write-Host "Backups saved to: $BackupDir" -ForegroundColor Green

# Step 5: Remove old folders
Write-Host ""
Write-Host "Step 5: Removing old plugin folders..." -ForegroundColor Green
Write-Host "----------------------------------------"

foreach ($folder in $HpmFolders) {
    if ($folder.FullName -ne $KeepFolder.FullName) {
        Write-Host "  Deleting: $($folder.Name)" -ForegroundColor Yellow
        Remove-Item -Path $folder.FullName -Recurse -Force
    }
}

Write-Host "Old folders removed" -ForegroundColor Green

# Step 6: Rename to standard name
Write-Host ""
Write-Host "Step 6: Ensuring correct folder name..." -ForegroundColor Green
Write-Host "----------------------------------------"

$standardName = Join-Path $WpPluginsDir "HOME-Promo-Manager"
if ($KeepFolder.FullName -ne $standardName) {
    Write-Host "  Renaming $($KeepFolder.Name) → HOME-Promo-Manager" -ForegroundColor Cyan
    if (Test-Path $standardName) {
        Remove-Item $standardName -Recurse -Force
    }
    Rename-Item -Path $KeepFolder.FullName -NewName "HOME-Promo-Manager"
    $KeepFolder = Get-Item $standardName
}

Write-Host "Plugin folder: $($KeepFolder.Name)" -ForegroundColor Green

# Step 7: Activation
Write-Host ""
Write-Host "Step 7: Activating clean installation..." -ForegroundColor Green
Write-Host "----------------------------------------"
Write-Host "Please activate the plugin in WordPress admin:" -ForegroundColor Yellow
Write-Host "  1. Go to: WordPress Admin → Plugins" -ForegroundColor Yellow
Write-Host "  2. Click 'Activate' under HOME Promo Manager (should only show ONCE now)" -ForegroundColor Yellow
$null = Read-Host "Press Enter when activation is complete"

# Step 8: Verification
Write-Host ""
Write-Host "Step 8: Verification checklist..." -ForegroundColor Green
Write-Host "----------------------------------------"
Write-Host "Please verify the following:" -ForegroundColor Yellow
Write-Host ""
Write-Host "  1. Only ONE 'HOME Promo Manager' appears in Plugins page" -ForegroundColor White
Write-Host "  2. Version shows: v$KeepVersion" -ForegroundColor White
Write-Host "  3. Settings page loads: Settings → HOME Promo Manager" -ForegroundColor White
Write-Host "  4. Quota counts are correct (check against your records)" -ForegroundColor White
Write-Host "  5. REST API works: yoursite.com/wp-json/promo/v1/counter" -ForegroundColor White
Write-Host ""

Write-Host "Run this SQL to check for duplicate entries:" -ForegroundColor Cyan
Write-Host @"
SELECT entry_id, COUNT(*) as count 
FROM home_promo_counted 
GROUP BY entry_id 
HAVING count > 1;
"@ -ForegroundColor Gray

Write-Host ""
Write-Host "If duplicates found, remove with:" -ForegroundColor Cyan
Write-Host @"
DELETE t1 FROM home_promo_counted t1
INNER JOIN home_promo_counted t2
WHERE t1.id > t2.id AND t1.entry_id = t2.entry_id;
"@ -ForegroundColor Gray

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Cleanup Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Summary:" -ForegroundColor White
Write-Host "  Kept: $($KeepFolder.Name) (v$KeepVersion)" -ForegroundColor Green
Write-Host "  Backups: $BackupDir" -ForegroundColor Cyan
Write-Host ""
Write-Host "If issues occur, restore from backup folder." -ForegroundColor Yellow
Write-Host ""
