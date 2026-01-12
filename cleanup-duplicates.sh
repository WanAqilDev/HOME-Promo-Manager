#!/bin/bash
# HOME Promo Manager - Duplicate Installation Cleanup Script
# Safe removal of old plugin versions while preserving database

set -e  # Exit on error

echo "========================================"
echo "HOME Promo Manager - Cleanup Script"
echo "========================================"
echo ""

# Configuration
WP_PLUGINS_DIR="wp-content/plugins"
KEEP_VERSION="3.1"
BACKUP_DIR="/tmp/hpm-backup-$(date +%Y%m%d-%H%M%S)"

# Check if wp-cli is available
if ! command -v wp &> /dev/null; then
    echo "⚠️  WP-CLI not found. Some steps will need manual execution."
    USE_WPCLI=false
else
    USE_WPCLI=true
fi

echo "Step 1: Creating database backup..."
mkdir -p "$BACKUP_DIR"

if [ "$USE_WPCLI" = true ]; then
    wp db export "$BACKUP_DIR/database-backup.sql"
    echo "✅ Database backup saved to: $BACKUP_DIR/database-backup.sql"
else
    echo "⚠️  Manual backup required! Export these tables via phpMyAdmin:"
    echo "   - home_promo_counted"
    echo "   - home_promo_reactivations"
    read -p "Press Enter when backup is complete..."
fi

echo ""
echo "Step 2: Listing HOME Promo Manager installations..."
cd "$WP_PLUGINS_DIR"
HPM_FOLDERS=$(ls -d HOME-Promo-Manager* 2>/dev/null || true)

if [ -z "$HPM_FOLDERS" ]; then
    echo "❌ No HOME Promo Manager folders found in $WP_PLUGINS_DIR"
    exit 1
fi

echo "Found installations:"
echo "$HPM_FOLDERS" | nl
echo ""

# Identify which folder to keep
KEEP_FOLDER=""
for folder in $HPM_FOLDERS; do
    VERSION_FILE="$folder/home-promo-manager.php"
    if [ -f "$VERSION_FILE" ]; then
        VERSION=$(grep -oP "Version:\s+\K[\d.]+" "$VERSION_FILE" || echo "unknown")
        echo "  $folder => Version: $VERSION"
        
        if [[ "$VERSION" == "$KEEP_VERSION" ]] || [[ "$folder" == "HOME-Promo-Manager" ]]; then
            KEEP_FOLDER="$folder"
        fi
    fi
done

echo ""
if [ -z "$KEEP_FOLDER" ]; then
    echo "⚠️  Could not auto-detect v$KEEP_VERSION folder."
    echo "Available folders:"
    select folder in $HPM_FOLDERS; do
        KEEP_FOLDER="$folder"
        break
    done
fi

echo "✅ Will KEEP: $KEEP_FOLDER"
echo ""

echo "Step 3: Deactivating all HOME Promo Manager instances..."
if [ "$USE_WPCLI" = true ]; then
    for folder in $HPM_FOLDERS; do
        PLUGIN_PATH="$folder/home-promo-manager.php"
        wp plugin deactivate "$folder/home-promo-manager.php" 2>/dev/null || true
    done
    echo "✅ All instances deactivated"
else
    echo "⚠️  Manual deactivation required!"
    echo "   Go to WordPress Admin → Plugins → Deactivate all HOME Promo Manager instances"
    read -p "Press Enter when deactivation is complete..."
fi

echo ""
echo "Step 4: Backing up plugin folders..."
for folder in $HPM_FOLDERS; do
    if [ "$folder" != "$KEEP_FOLDER" ]; then
        cp -r "$folder" "$BACKUP_DIR/"
        echo "  Backed up: $folder"
    fi
done
echo "✅ Backups saved to: $BACKUP_DIR"

echo ""
echo "Step 5: Removing old plugin folders..."
for folder in $HPM_FOLDERS; do
    if [ "$folder" != "$KEEP_FOLDER" ]; then
        echo "  Deleting: $folder"
        rm -rf "$folder"
    fi
done

echo "✅ Old folders removed"

echo ""
echo "Step 6: Ensuring correct folder name..."
if [ "$KEEP_FOLDER" != "HOME-Promo-Manager" ]; then
    echo "  Renaming $KEEP_FOLDER → HOME-Promo-Manager"
    mv "$KEEP_FOLDER" "HOME-Promo-Manager"
    KEEP_FOLDER="HOME-Promo-Manager"
fi

echo "✅ Plugin folder: $KEEP_FOLDER"

echo ""
echo "Step 7: Activating clean installation..."
if [ "$USE_WPCLI" = true ]; then
    wp plugin activate home-promo-manager
    echo "✅ Plugin activated"
else
    echo "⚠️  Manual activation required!"
    echo "   Go to WordPress Admin → Plugins → Activate HOME Promo Manager"
    read -p "Press Enter when activation is complete..."
fi

echo ""
echo "Step 8: Verifying data integrity..."
if [ "$USE_WPCLI" = true ]; then
    echo "Checking quota data..."
    TOTAL_ENTRIES=$(wp db query "SELECT COUNT(*) as count FROM home_promo_counted" --skip-column-names 2>/dev/null || echo "0")
    echo "  Total entries: $TOTAL_ENTRIES"
    
    echo "Checking for duplicates..."
    DUPLICATES=$(wp db query "SELECT COUNT(*) FROM (SELECT entry_id FROM home_promo_counted GROUP BY entry_id HAVING COUNT(*) > 1) as dups" --skip-column-names 2>/dev/null || echo "0")
    
    if [ "$DUPLICATES" -gt 0 ]; then
        echo "⚠️  Found $DUPLICATES duplicate entries!"
        echo "   Run this SQL to remove duplicates:"
        echo "   DELETE t1 FROM home_promo_counted t1"
        echo "   INNER JOIN home_promo_counted t2"
        echo "   WHERE t1.id > t2.id AND t1.entry_id = t2.entry_id;"
    else
        echo "✅ No duplicates found"
    fi
else
    echo "⚠️  Manual verification required!"
    echo "   Run these SQL queries in phpMyAdmin:"
    echo ""
    echo "   -- Check total entries"
    echo "   SELECT COUNT(*) FROM home_promo_counted;"
    echo ""
    echo "   -- Check for duplicates"
    echo "   SELECT entry_id, COUNT(*) as count FROM home_promo_counted"
    echo "   GROUP BY entry_id HAVING count > 1;"
fi

echo ""
echo "========================================"
echo "✅ Cleanup Complete!"
echo "========================================"
echo ""
echo "Summary:"
echo "  Kept: $KEEP_FOLDER (v$KEEP_VERSION)"
echo "  Backups: $BACKUP_DIR"
echo ""
echo "Next Steps:"
echo "  1. Verify plugin works: Settings → HOME Promo Manager"
echo "  2. Check quota counts are correct"
echo "  3. Test REST API: /wp-json/promo/v1/counter"
echo ""
echo "If issues occur, restore from: $BACKUP_DIR"
echo ""
