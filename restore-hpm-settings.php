<?php
/**
 * HOME Promo Manager - Settings Restoration Script
 * 
 * This script analyzes your home_promo_counted table and auto-generates
 * the plugin settings based on actual redemption data.
 * 
 * USAGE:
 * 1. Upload this file to your WordPress root directory
 * 2. Visit: yoursite.com/restore-hpm-settings.php
 * 3. Copy the generated SQL and run it in phpMyAdmin
 * 4. Delete this file after restoration
 */

// Load WordPress
require_once 'wp-load.php';

if (!current_user_can('manage_options')) {
    die('ERROR: You must be logged in as administrator to run this script.');
}

global $wpdb;

echo "<h1>HOME Promo Manager - Settings Restoration</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    pre { background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #2271b1; color: white; }
    .success { background: #d4edda; padding: 10px; border: 1px solid #c3e6cb; color: #155724; margin: 10px 0; }
    .warning { background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; color: #856404; margin: 10px 0; }
</style>";

// Step 1: Analyze current data
echo "<h2>Step 1: Current Redemption Data</h2>";

$query = "SELECT 
    promo_code,
    COUNT(*) as current_usage,
    GROUP_CONCAT(DISTINCT user_category) as categories,
    GROUP_CONCAT(DISTINCT branch) as branches
FROM {$wpdb->prefix}home_promo_counted
GROUP BY promo_code
ORDER BY promo_code";

$results = $wpdb->get_results($query);

if (empty($results)) {
    echo '<div class="warning">⚠️ No redemption data found in home_promo_counted table!</div>';
    exit;
}

echo "<table>";
echo "<tr><th>Promo Code</th><th>Current Usage</th><th>Suggested Max</th><th>Categories</th><th>Branches</th></tr>";

$codes_config = [];
foreach ($results as $row) {
    $current = (int)$row->current_usage;
    // Suggested max = current + 20% buffer, rounded up to nearest 10
    $suggested = ceil(($current * 1.2) / 10) * 10;
    
    echo "<tr>";
    echo "<td><strong>{$row->promo_code}</strong></td>";
    echo "<td>{$current}</td>";
    echo "<td>{$suggested}</td>";
    echo "<td>" . ($row->categories ?: 'N/A') . "</td>";
    echo "<td>" . ($row->branches ?: 'N/A') . "</td>";
    echo "</tr>";
    
    // Build config array
    $codes_config[$row->promo_code] = [
        'max' => $suggested,
        'description' => str_replace('SMART26-', '', $row->promo_code),
        'active' => true,
        'is_legacy' => false
    ];
}
echo "</table>";

// Step 2: Generate settings array
echo "<h2>Step 2: Generated Settings</h2>";

$settings = [
    'form_id' => '13',
    'promo_field_id' => '3170',
    'status_field_id' => '199',
    'pasif_date_field_id' => '3172',
    'branch_field_id' => '3171',
    'code_assignment_mode' => 'manual',
    'promo_codes' => $codes_config,
    'debug_mode' => '0',
    'timezone' => 'Asia/Kuala_Lumpur',
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'max_redemptions' => (string)array_sum(array_column($codes_config, 'max')),
    'tier1_max' => '50'
];

$serialized = serialize($settings);
$escaped = $wpdb->prepare('%s', $serialized);

echo '<div class="success">✅ Settings generated successfully!</div>';

// Step 3: Show restoration SQL
echo "<h2>Step 3: Restoration SQL</h2>";
echo "<p>Copy and run this SQL in phpMyAdmin (or use the auto-restore button below):</p>";

$sql = "DELETE FROM {$wpdb->prefix}options WHERE option_name = 'home_promo_manager_settings';
INSERT INTO {$wpdb->prefix}options (option_name, option_value, autoload) 
VALUES ('home_promo_manager_settings', {$escaped}, 'yes');";

echo "<pre>" . htmlspecialchars($sql) . "</pre>";

// Step 4: Auto-restore button
echo "<h2>Step 4: Auto-Restore (One-Click)</h2>";

if (isset($_POST['auto_restore'])) {
    // Delete old settings
    $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name = 'home_promo_manager_settings'");
    
    // Insert new settings
    $result = $wpdb->insert(
        $wpdb->prefix . 'options',
        [
            'option_name' => 'home_promo_manager_settings',
            'option_value' => $serialized,
            'autoload' => 'yes'
        ],
        ['%s', '%s', '%s']
    );
    
    if ($result) {
        echo '<div class="success">
            ✅ <strong>Settings restored successfully!</strong><br><br>
            Next steps:<br>
            1. Go to Settings → HOME Promo Manager<br>
            2. Verify all codes are shown with correct quotas<br>
            3. Check the dashboard shows correct usage counts<br>
            4. Delete this file (restore-hpm-settings.php) from your server<br>
        </div>';
    } else {
        echo '<div class="warning">⚠️ Error restoring settings. Please use the SQL method above.</div>';
    }
} else {
    echo '<form method="post">';
    echo '<button type="submit" name="auto_restore" style="background: #2271b1; color: white; border: none; padding: 15px 30px; font-size: 16px; cursor: pointer; border-radius: 4px;">
        🔄 Auto-Restore Settings Now
    </button>';
    echo '</form>';
    echo '<p style="color: #666; font-size: 14px;">This will restore your plugin settings with the codes and quotas shown above.</p>';
}

// Step 5: Summary
echo "<h2>Summary</h2>";
echo "<ul>";
echo "<li><strong>Total Codes Found:</strong> " . count($codes_config) . "</li>";
echo "<li><strong>Total Current Usage:</strong> " . array_sum(array_column($results, 'current_usage')) . "</li>";
echo "<li><strong>Total Max Quota:</strong> " . array_sum(array_column($codes_config, 'max')) . "</li>";
echo "</ul>";

echo "<hr>";
echo "<p style='color: #666; font-size: 12px;'>After restoration, <strong>delete this file</strong> from your server for security.</p>";
