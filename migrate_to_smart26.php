<?php
/**
 * SMART26 Migration Script
 * 
 * Migrates legacy promo codes (tiada, promo24) to FOTY25 for Jan 2026 entries
 * updated during the active promo period (12-14 Jan 2026).
 * 
 * Features:
 * - Dry-run mode (preview changes without committing)
 * - Database backup recommendation
 * - Transaction support with rollback
 * - Detailed statistics and logging
 * 
 * Usage:
 * 1. Navigate to: https://yoursite.com/wp-content/plugins/HOME-Promo-Manager/migrate_to_smart26.php
 * 2. Run dry-run first to preview changes
 * 3. If satisfied, run with execute=1
 */

// Load WordPress
$wp_load_path = __DIR__ . '/../../../wp-load.php';
if (!file_exists($wp_load_path)) {
    die('Error: Cannot find WordPress. Make sure this file is in wp-content/plugins/HOME-Promo-Manager/');
}
require_once($wp_load_path);

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Access denied. Administrator privileges required.', 'Migration Error', ['response' => 403]);
}

use HPM\Manager;
use HPM\DB;

// Get mode from query string
$dry_run = !isset($_GET['execute']) || $_GET['execute'] !== '1';
$mode_label = $dry_run ? '🔍 DRY-RUN MODE (Preview Only)' : '⚡ EXECUTION MODE';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SMART26 Migration Script</title>
    <style>
        body { 
            font-family: 'Courier New', monospace; 
            background: #1e1e1e; 
            color: #d4d4d4; 
            padding: 20px;
            line-height: 1.6;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: #252526; 
            padding: 30px; 
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        h1 { 
            color: #4EC9B0; 
            border-bottom: 3px solid #4EC9B0; 
            padding-bottom: 10px;
        }
        h2 { 
            color: #DCDCAA; 
            margin-top: 30px;
            border-left: 4px solid #DCDCAA;
            padding-left: 10px;
        }
        h3 { color: #569CD6; }
        .success { color: #4EC9B0; font-weight: bold; }
        .warning { color: #CE9178; font-weight: bold; }
        .error { color: #F48771; font-weight: bold; }
        .info { color: #9CDCFE; }
        .highlight { background: #3E3E42; padding: 2px 6px; border-radius: 3px; }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 15px 0;
            background: #1e1e1e;
        }
        th, td { 
            padding: 12px; 
            text-align: left; 
            border: 1px solid #3E3E42; 
        }
        th { 
            background: #2D2D30; 
            color: #4EC9B0; 
            font-weight: bold;
        }
        tr:hover { background: #2D2D30; }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            margin: 10px 5px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s;
            border: 2px solid;
        }
        .btn-execute {
            background: #F48771;
            color: white;
            border-color: #F48771;
        }
        .btn-execute:hover {
            background: #ff6b6b;
            border-color: #ff6b6b;
        }
        .btn-dry-run {
            background: #569CD6;
            color: white;
            border-color: #569CD6;
        }
        .btn-dry-run:hover {
            background: #6eb3f0;
            border-color: #6eb3f0;
        }
        pre {
            background: #1e1e1e;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #4EC9B0;
            overflow-x: auto;
        }
        .stat-box {
            background: #2D2D30;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #DCDCAA;
        }
        .entry-list {
            max-height: 300px;
            overflow-y: auto;
            background: #1e1e1e;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .mode-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            margin: 10px 0;
        }
        .mode-dry-run {
            background: #569CD6;
            color: white;
        }
        .mode-execute {
            background: #F48771;
            color: white;
        }
    </style>
</head>
<body>
<div class="container">

<h1>🚀 SMART26 Migration Script</h1>
<div class="mode-badge <?= $dry_run ? 'mode-dry-run' : 'mode-execute' ?>">
    <?= $mode_label ?>
</div>

<?php

$mgr = Manager::get_instance();
global $wpdb;

echo "<h2>📋 Pre-Migration Checklist</h2>";

// Step 1: Check if is_legacy column exists
echo "<h3>1. Database Schema Check</h3>";
$column_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = %s 
     AND TABLE_NAME = %s 
     AND COLUMN_NAME = 'is_legacy'",
    DB_NAME,
    DB::table_name()
));

if ((int) $column_exists === 0) {
    echo "<div class='warning'>⚠️  Column 'is_legacy' does not exist. Adding now...</div>";
    
    if (!$dry_run) {
        $added = DB::add_legacy_column();
        if ($added) {
            echo "<div class='success'>✅ Successfully added is_legacy column</div>";
        } else {
            echo "<div class='error'>❌ Failed to add is_legacy column. Check error log.</div>";
            echo "<p><a href='?' class='btn btn-dry-run'>← Back</a></p>";
            echo "</div></body></html>";
            exit;
        }
    } else {
        echo "<div class='info'>ℹ️  Will add is_legacy column during execution</div>";
    }
} else {
    echo "<div class='success'>✅ Column 'is_legacy' exists</div>";
}

// Step 2: Count candidates for migration
echo "<h3>2. Migration Candidates Analysis</h3>";

$promo_field_id = (int) $mgr->s('promo_field_id');
$promo_start = '2026-01-12 12:00:00';
$promo_end = '2026-01-14 23:59:59';
$jan_start = '2026-01-01 00:00:00';
$jan_end = '2026-02-01 00:00:00';

// Query for FOTY25 candidates
$candidates_query = $wpdb->prepare(
    "SELECT c.id, c.entry_id, c.promo_code, c.branch, c.user_category, c.is_legacy,
            i.created_at, i.updated_at
     FROM " . DB::table_name() . " c
     JOIN {$wpdb->prefix}frm_items i ON c.entry_id = i.id
     WHERE c.promo_code IN ('tiada', 'promo24', 'promo12', 'Tiada', 'TIADA', 'PROMO24', 'PROMO12')
       AND i.created_at >= %s 
       AND i.created_at < %s
       AND i.updated_at >= %s 
       AND i.updated_at <= %s
     ORDER BY c.id",
    $jan_start,
    $jan_end,
    $promo_start,
    $promo_end
);

$candidates = $wpdb->get_results($candidates_query);
$candidate_count = count($candidates);

echo "<div class='stat-box'>";
echo "<strong>Found:</strong> <span class='highlight'>{$candidate_count}</span> entries to migrate to FOTY25<br>";
echo "<strong>Criteria:</strong> Created in Jan 2026 + Updated during promo (12-14 Jan)<br>";
echo "<strong>Legacy codes:</strong> tiada, promo24, promo12";
echo "</div>";

if ($candidate_count > 0) {
    echo "<h4>Entries to Migrate:</h4>";
    echo "<div class='entry-list'>";
    echo "<table>";
    echo "<tr><th>Entry ID</th><th>Current Code</th><th>Branch</th><th>Created</th><th>Updated</th></tr>";
    foreach ($candidates as $row) {
        echo "<tr>";
        echo "<td>#{$row->entry_id}</td>";
        echo "<td><span class='warning'>{$row->promo_code}</span> → <span class='success'>FOTY25</span></td>";
        echo "<td>{$row->branch}</td>";
        echo "<td>{$row->created_at}</td>";
        echo "<td>{$row->updated_at}</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
}

// Step 3: Count pre-2026 legacy entries
echo "<h3>3. Pre-2026 Legacy Entries</h3>";

$pre_2026_query = $wpdb->prepare(
    "SELECT COUNT(*) 
     FROM " . DB::table_name() . " c
     JOIN {$wpdb->prefix}frm_items i ON c.entry_id = i.id
     WHERE i.created_at < %s
       AND c.is_legacy = 0",
    $jan_start
);

$pre_2026_count = (int) $wpdb->get_var($pre_2026_query);

echo "<div class='stat-box'>";
echo "<strong>Found:</strong> <span class='highlight'>{$pre_2026_count}</span> pre-2026 entries<br>";
echo "<strong>Action:</strong> Mark as is_legacy=1 (keep original codes)";
echo "</div>";

// Step 4: Check current FOTY25 code
echo "<h3>4. FOTY25 Code Configuration</h3>";

$current_codes = $mgr->s('promo_codes') ?: [];
$foty25_exists = isset($current_codes['FOTY25']);

if ($foty25_exists) {
    echo "<div class='warning'>⚠️  FOTY25 code already exists</div>";
    echo "<pre>";
    print_r($current_codes['FOTY25']);
    echo "</pre>";
} else {
    echo "<div class='info'>ℹ️  FOTY25 code will be created with quota = {$candidate_count}</div>";
}

// Step 5: Show migration plan
echo "<h2>📝 Migration Plan</h2>";

echo "<ol>";
echo "<li><strong>Add is_legacy column</strong> (if not exists)</li>";
echo "<li><strong>Create FOTY25 code</strong> with quota = {$candidate_count}</li>";
echo "<li><strong>Migrate {$candidate_count} entries</strong>:";
echo "<ul>";
echo "<li>Update promo_code: legacy → FOTY25</li>";
echo "<li>Set is_legacy = 1</li>";
echo "<li>Update Formidable meta (field {$promo_field_id})</li>";
echo "</ul>";
echo "</li>";
echo "<li><strong>Mark {$pre_2026_count} pre-2026 entries</strong> as legacy</li>";
echo "<li><strong>Show final statistics</strong></li>";
echo "</ol>";

// Dry-run exit point
if ($dry_run) {
    echo "<h2>✋ Dry-Run Complete</h2>";
    echo "<div class='warning'>";
    echo "<p><strong>No changes have been made to the database.</strong></p>";
    echo "<p>Review the migration plan above. If everything looks correct, proceed with execution.</p>";
    echo "</div>";
    
    echo "<div style='margin-top: 30px;'>";
    echo "<a href='?execute=1' class='btn btn-execute' onclick='return confirm(\"⚠️  WARNING: This will modify the database.\\n\\nHave you backed up your database?\\n\\nProceed with migration?\")'>⚡ Execute Migration</a>";
    echo "<a href='?' class='btn btn-dry-run'>🔄 Run Dry-Run Again</a>";
    echo "</div>";
    
    echo "</div></body></html>";
    exit;
}

// ============================================
// EXECUTION MODE
// ============================================

echo "<h2>⚡ Executing Migration...</h2>";

// Start transaction
$wpdb->query('START TRANSACTION');

try {
    // Step 1: Add column if needed (already done above)
    
    // Step 2: Create/Update FOTY25 code
    echo "<h3>Step 1: Configure FOTY25 Code</h3>";
    
    $current_codes['FOTY25'] = [
        'max' => $candidate_count,
        'description' => 'Friend of the Year 2025 - Migrated Jan 2026 Entries',
        'active' => true
    ];
    
    $mgr->update_setting('promo_codes', $current_codes);
    echo "<div class='success'>✅ FOTY25 code configured with quota = {$candidate_count}</div>";
    
    // Step 3: Migrate entries to FOTY25
    echo "<h3>Step 2: Migrate Entries to FOTY25</h3>";
    
    if ($candidate_count > 0) {
        // Update promo table
        $migrate_query = $wpdb->prepare(
            "UPDATE " . DB::table_name() . " c
             JOIN {$wpdb->prefix}frm_items i ON c.entry_id = i.id
             SET c.promo_code = 'FOTY25',
                 c.is_legacy = 1
             WHERE c.promo_code IN ('tiada', 'promo24', 'promo12', 'Tiada', 'TIADA', 'PROMO24', 'PROMO12')
               AND i.created_at >= %s 
               AND i.created_at < %s
               AND i.updated_at >= %s 
               AND i.updated_at <= %s",
            $jan_start,
            $jan_end,
            $promo_start,
            $promo_end
        );
        
        $updated = $wpdb->query($migrate_query);
        echo "<div class='success'>✅ Migrated {$updated} entries in promo table</div>";
        
        // Update Formidable meta
        $meta_query = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}frm_item_metas m
             JOIN " . DB::table_name() . " c ON m.item_id = c.entry_id
             JOIN {$wpdb->prefix}frm_items i ON c.entry_id = i.id
             SET m.meta_value = 'FOTY25'
             WHERE m.field_id = %d
               AND c.promo_code = 'FOTY25'
               AND i.created_at >= %s 
               AND i.created_at < %s
               AND i.updated_at >= %s 
               AND i.updated_at <= %s",
            $promo_field_id,
            $jan_start,
            $jan_end,
            $promo_start,
            $promo_end
        );
        
        $meta_updated = $wpdb->query($meta_query);
        echo "<div class='success'>✅ Updated {$meta_updated} Formidable meta entries</div>";
    }
    
    // Step 4: Mark pre-2026 entries as legacy
    echo "<h3>Step 3: Mark Pre-2026 Entries as Legacy</h3>";
    
    if ($pre_2026_count > 0) {
        $legacy_query = $wpdb->prepare(
            "UPDATE " . DB::table_name() . " c
             JOIN {$wpdb->prefix}frm_items i ON c.entry_id = i.id
             SET c.is_legacy = 1
             WHERE i.created_at < %s
               AND c.is_legacy = 0",
            $jan_start
        );
        
        $legacy_updated = $wpdb->query($legacy_query);
        echo "<div class='success'>✅ Marked {$legacy_updated} pre-2026 entries as legacy</div>";
    }
    
    // Commit transaction
    $wpdb->query('COMMIT');
    echo "<div class='success'><h3>✅ Migration Completed Successfully!</h3></div>";
    
} catch (Exception $e) {
    // Rollback on error
    $wpdb->query('ROLLBACK');
    echo "<div class='error'>";
    echo "<h3>❌ Migration Failed</h3>";
    echo "<p>Error: " . esc_html($e->getMessage()) . "</p>";
    echo "<p>All changes have been rolled back.</p>";
    echo "</div>";
    echo "</div></body></html>";
    exit;
}

// Final statistics
echo "<h2>📊 Final Statistics</h2>";

$code_stats = DB::get_code_stats();
$total_used = 0;
$total_max = 0;

echo "<table>";
echo "<tr><th>Code</th><th>Used</th><th>Max</th><th>Remaining</th><th>Status</th></tr>";

foreach ($current_codes as $code => $config) {
    if (!($config['active'] ?? true)) continue;
    
    $max = (int) ($config['max'] ?? 0);
    $used = 0;
    
    foreach ($code_stats as $stat) {
        if ($stat['promo_code'] === $code) {
            $used = (int) $stat['count'];
            break;
        }
    }
    
    $remaining = max(0, $max - $used);
    $total_used += $used;
    $total_max += $max;
    $status = $used <= $max ? '✅' : '⚠️ OVER';
    
    echo "<tr>";
    echo "<td><strong>{$code}</strong></td>";
    echo "<td>{$used}</td>";
    echo "<td>{$max}</td>";
    echo "<td>{$remaining}</td>";
    echo "<td>{$status}</td>";
    echo "</tr>";
}

echo "<tr style='background: #2D2D30; font-weight: bold;'>";
echo "<td>TOTAL</td>";
echo "<td>{$total_used}</td>";
echo "<td>{$total_max}</td>";
echo "<td>" . ($total_max - $total_used) . "</td>";
echo "<td></td>";
echo "</tr>";
echo "</table>";

// Legacy count
$legacy_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . DB::table_name() . " WHERE is_legacy = 1");
echo "<div class='stat-box'>";
echo "<strong>Total Legacy Entries:</strong> <span class='highlight'>{$legacy_count}</span><br>";
echo "<strong>Total SMART26 Entries:</strong> <span class='highlight'>" . ($total_used - $legacy_count) . "</span>";
echo "</div>";

echo "<div style='margin-top: 30px;'>";
echo "<a href='" . admin_url('admin.php?page=home-promo-manager') . "' class='btn btn-execute'>Go to Plugin Settings →</a>";
echo "</div>";

?>

</div>
</body>
</html>
