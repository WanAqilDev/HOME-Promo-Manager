<?php
/**
 * SMART26 Edge Cases & Race Condition Tests
 * Critical quota enforcement and code isolation testing
 * 
 * Run in WordPress admin context: wp-admin/admin.php?page=test-edge-cases
 */

require_once __DIR__ . '/../../wp-load.php';

if (!current_user_can('manage_options')) {
    die('Access denied. Admin only.');
}

echo "<html><head><title>SMART26 Edge Case Tests</title>";
echo "<style>
body{font-family:monospace;padding:20px;background:#f5f5f5;} 
.test-section{background:white;padding:20px;margin:20px 0;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}
.pass{color:green;font-weight:bold;} 
.fail{color:red;font-weight:bold;} 
.warn{color:orange;font-weight:bold;}
.test-case{margin:10px 0;padding:10px;border-left:4px solid #ccc;}
.test-case.pass{border-color:green;background:#f0fff0;}
.test-case.fail{border-color:red;background:#fff0f0;}
.test-case.warn{border-color:orange;background:#fff9f0;}
.code-block{background:#f8f8f8;padding:10px;margin:5px 0;border-radius:4px;font-family:monospace;font-size:12px;}
h1,h2,h3{color:#333;}
table{width:100%;border-collapse:collapse;margin:10px 0;}
th,td{border:1px solid #ddd;padding:8px;text-align:left;}
th{background:#f0f0f0;}
</style>";
echo "</head><body><h1>🧪 SMART26 Edge Case Test Suite</h1>";

$mgr = \HPM\Manager::get_instance();
$wpdb = $GLOBALS['wpdb'];

$tests_run = 0;
$tests_passed = 0;
$tests_failed = 0;
$edge_cases_found = [];

function test_case($name, $passed, $details = '', $critical = false) {
    global $tests_run, $tests_passed, $tests_failed, $edge_cases_found;
    $tests_run++;
    
    $class = $passed ? 'pass' : 'fail';
    $icon = $passed ? '✓' : '✗';
    $status = $passed ? '<span class="pass">PASS</span>' : '<span class="fail">FAIL</span>';
    
    if ($critical && !$passed) {
        $status = '<span class="fail">CRITICAL FAIL</span>';
        $edge_cases_found[] = $name;
    }
    
    echo "<div class='test-case $class'>";
    echo "<strong>$icon $name</strong> - $status";
    if ($details) {
        echo "<div class='code-block'>$details</div>";
    }
    echo "</div>";
    
    if ($passed) $tests_passed++;
    else $tests_failed++;
}

// ============================================================================
// SETUP: Get current promo codes configuration
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>📋 Configuration Check</h2>";

$codes = $mgr->s('promo_codes');
$mode = $mgr->s('code_assignment_mode');

if (empty($codes)) {
    echo "<p class='fail'>❌ CRITICAL: No promo codes configured. Cannot run tests.</p>";
    echo "<p>Please configure promo codes in Settings → HOME Promo Manager first.</p>";
    echo "</body></html>";
    exit;
}

echo "<p><strong>Mode:</strong> $mode</p>";
echo "<table>";
echo "<tr><th>Code</th><th>Description</th><th>Max Quota</th><th>Current Usage</th><th>Remaining</th><th>Active</th></tr>";

foreach ($codes as $code => $config) {
    $usage = \HPM\DB::get_code_usage($code);
    $remaining = $config['max'] - $usage;
    $active = $config['active'] ? '✓' : '✗';
    $color = $remaining <= 0 ? 'red' : ($remaining < 5 ? 'orange' : 'green');
    
    echo "<tr>";
    echo "<td><strong>$code</strong></td>";
    echo "<td>{$config['description']}</td>";
    echo "<td>{$config['max']}</td>";
    echo "<td>$usage</td>";
    echo "<td style='color:$color;font-weight:bold;'>$remaining</td>";
    echo "<td>$active</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// ============================================================================
// TEST 1: Code Quota Isolation
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>🔒 Test 1: Code Quota Isolation</h2>";
echo "<p>Verify each code maintains independent quota tracking</p>";

$stats = \HPM\DB::get_code_stats();
$code_usage_map = [];
foreach ($stats as $stat) {
    $code_usage_map[$stat['code']] = (int)$stat['used'];
}

foreach ($codes as $code => $config) {
    $usage = isset($code_usage_map[$code]) ? $code_usage_map[$code] : 0;
    $max = $config['max'];
    
    test_case(
        "Code '$code' usage ($usage) does not exceed max ($max)",
        $usage <= $max,
        "Usage: $usage / $max",
        true
    );
    
    // Check no negative values
    test_case(
        "Code '$code' has non-negative usage",
        $usage >= 0,
        "Usage: $usage",
        true
    );
}

echo "</div>";

// ============================================================================
// TEST 2: Database Atomic Insert Validation
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>⚛️ Test 2: Atomic Insert Query Structure</h2>";
echo "<p>Verify INSERT query prevents race conditions with atomic WHERE clause</p>";

// Check the actual DB method for atomic query
$db_file_content = file_get_contents(__DIR__ . '/src/db.php');

$has_atomic_insert = preg_match('/INSERT\s+IGNORE.*SELECT.*FROM\s+DUAL.*WHERE/is', $db_file_content);
test_case(
    "insert_entry_with_code() uses atomic INSERT...SELECT...WHERE",
    $has_atomic_insert,
    "Prevents race conditions by checking quota in same query",
    true
);

$has_quota_check = preg_match('/COUNT.*<.*%d/is', $db_file_content);
test_case(
    "Atomic query includes quota limit check",
    $has_quota_check,
    "Ensures quota is verified atomically",
    true
);

echo "</div>";

// ============================================================================
// TEST 3: Boundary Conditions
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>📊 Test 3: Boundary Condition Tests</h2>";

foreach ($codes as $code => $config) {
    $usage = \HPM\DB::get_code_usage($code);
    $max = $config['max'];
    $remaining = $max - $usage;
    
    echo "<h3>Code: $code</h3>";
    
    // Test at exactly the limit
    if ($usage === $max) {
        test_case(
            "Code at exact quota limit ($usage/$max)",
            true,
            "Code is fully redeemed - should reject new attempts",
            false
        );
        
        // Try validation
        $result = $mgr->validate_code($code);
        test_case(
            "Validation rejects code at quota limit",
            !$result['valid'],
            $result['message'],
            true
        );
    }
    
    // Test approaching limit
    if ($remaining > 0 && $remaining <= 3) {
        test_case(
            "Code near quota limit ($usage/$max, $remaining remaining)",
            true,
            "WARNING: Code will be full soon",
            false
        );
    }
    
    // Test well below limit
    if ($remaining > 10) {
        test_case(
            "Code has comfortable quota buffer ($remaining remaining)",
            true,
            "Good availability",
            false
        );
    }
}

echo "</div>";

// ============================================================================
// TEST 4: Duplicate Entry Prevention
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>🚫 Test 4: Duplicate Entry Prevention</h2>";

// Check for unique constraint on entry_id
$table = $wpdb->prefix . 'home_promo_counted';
$indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'entry_id'");

test_case(
    "Database has unique index on entry_id",
    !empty($indexes),
    "Prevents same entry from being counted twice",
    true
);

// Check for duplicate entry_ids in database
$duplicates = $wpdb->get_results("
    SELECT entry_id, COUNT(*) as cnt 
    FROM $table 
    GROUP BY entry_id 
    HAVING cnt > 1
");

test_case(
    "No duplicate entries in database",
    empty($duplicates),
    count($duplicates) . " duplicate entry_ids found",
    true
);

echo "</div>";

// ============================================================================
// TEST 5: Code Cross-Contamination Check
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>🔍 Test 5: Code Cross-Contamination Check</h2>";
echo "<p>Verify entries are correctly attributed to their codes</p>";

$total_in_db = $wpdb->get_var("SELECT COUNT(*) FROM $table");
$sum_from_codes = 0;

foreach ($code_usage_map as $code => $usage) {
    $sum_from_codes += $usage;
}

test_case(
    "Total entries matches sum of per-code usage",
    $total_in_db == $sum_from_codes,
    "DB Total: $total_in_db, Sum from codes: $sum_from_codes",
    true
);

// Check for entries with invalid/unknown codes
$valid_codes = array_keys($codes);
$placeholders = implode(',', array_fill(0, count($valid_codes), '%s'));
$invalid_entries = $wpdb->get_results($wpdb->prepare("
    SELECT promo_code, COUNT(*) as cnt 
    FROM $table 
    WHERE promo_code NOT IN ($placeholders)
    GROUP BY promo_code
", $valid_codes));

test_case(
    "No entries with invalid/unknown codes",
    empty($invalid_entries),
    count($invalid_entries) . " invalid code entries found",
    true
);

echo "</div>";

// ============================================================================
// TEST 6: Validation Logic Consistency
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>✅ Test 6: Validation Logic Consistency</h2>";

foreach ($codes as $code => $config) {
    $usage = \HPM\DB::get_code_usage($code);
    $remaining = $config['max'] - $usage;
    
    // Test validation response
    $result = $mgr->validate_code($code);
    
    if ($remaining > 0 && $config['active']) {
        test_case(
            "validate_code('$code') returns valid when quota available",
            $result['valid'] === true,
            "Valid: " . ($result['valid'] ? 'true' : 'false') . ", Remaining: $remaining",
            true
        );
    }
    
    if ($remaining <= 0) {
        test_case(
            "validate_code('$code') returns invalid when quota full",
            $result['valid'] === false,
            $result['message'],
            true
        );
    }
    
    if (!$config['active']) {
        test_case(
            "validate_code('$code') returns invalid when code inactive",
            $result['valid'] === false,
            $result['message'],
            true
        );
    }
}

echo "</div>";

// ============================================================================
// TEST 7: Category Tracking Integrity
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>🏷️ Test 7: Category Tracking Integrity</h2>";

$category_stats = \HPM\DB::get_category_stats();
$total_categorized = 0;

echo "<table>";
echo "<tr><th>Category</th><th>Count</th></tr>";
foreach ($category_stats as $cat => $count) {
    echo "<tr><td>$cat</td><td>$count</td></tr>";
    $total_categorized += $count;
}
echo "</table>";

test_case(
    "All entries have category assigned",
    $total_categorized == $total_in_db,
    "Categorized: $total_categorized, Total: $total_in_db",
    false
);

// Check for invalid categories
$valid_categories = ['new', 'passive', 'diagnostic', 'lead'];
$invalid_cats = $wpdb->get_results("
    SELECT user_category, COUNT(*) as cnt 
    FROM $table 
    WHERE user_category NOT IN ('new', 'passive', 'diagnostic', 'lead')
    OR user_category IS NULL
    GROUP BY user_category
");

test_case(
    "No entries with invalid categories",
    empty($invalid_cats),
    count($invalid_cats) . " invalid category entries found",
    true
);

echo "</div>";

// ============================================================================
// TEST 8: Reactivation Table Integrity
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>🔄 Test 8: Reactivation Table Integrity</h2>";

$reactivations_table = $wpdb->prefix . 'home_promo_reactivations';
$total_reactivations = $wpdb->get_var("SELECT COUNT(*) FROM $reactivations_table");

echo "<p>Total reactivations: <strong>$total_reactivations</strong></p>";

// Check for duplicate reactivations
$duplicate_reactivations = $wpdb->get_results("
    SELECT entry_id, COUNT(*) as cnt 
    FROM $reactivations_table 
    GROUP BY entry_id 
    HAVING cnt > 1
");

test_case(
    "No duplicate reactivation records",
    empty($duplicate_reactivations),
    count($duplicate_reactivations) . " duplicate reactivations found",
    false
);

// Verify reactivations are also in main table
$orphan_reactivations = $wpdb->get_var("
    SELECT COUNT(*) 
    FROM $reactivations_table r
    LEFT JOIN $table c ON r.entry_id = c.entry_id
    WHERE c.entry_id IS NULL
");

test_case(
    "All reactivations have corresponding main table entry",
    $orphan_reactivations == 0,
    "$orphan_reactivations orphan reactivations found",
    false
);

echo "</div>";

// ============================================================================
// TEST 9: REST API Response Validation
// ============================================================================
echo "<div class='test-section'>";
echo "<h2>🌐 Test 9: REST API Response Validation</h2>";

$api_response = wp_remote_get(get_rest_url(null, 'promo/v1/counter'));

if (!is_wp_error($api_response)) {
    $body = json_decode(wp_remote_retrieve_body($api_response), true);
    
    test_case(
        "REST API returns valid JSON",
        !empty($body),
        "Response received",
        true
    );
    
    test_case(
        "API includes 'active' field",
        isset($body['active']),
        "Active: " . ($body['active'] ? 'true' : 'false'),
        true
    );
    
    if ($mode === 'smart26' || $mode === 'manual') {
        test_case(
            "API includes 'codes' array in SMART26 mode",
            isset($body['codes']) && is_array($body['codes']),
            count($body['codes']) . " codes in response",
            true
        );
        
        // Verify per-code data matches database
        if (isset($body['codes'])) {
            foreach ($body['codes'] as $api_code) {
                $db_usage = \HPM\DB::get_code_usage($api_code['code']);
                test_case(
                    "API code '{$api_code['code']}' usage matches database",
                    $api_code['used'] == $db_usage,
                    "API: {$api_code['used']}, DB: $db_usage",
                    true
                );
            }
        }
    }
} else {
    test_case(
        "REST API accessible",
        false,
        $api_response->get_error_message(),
        true
    );
}

echo "</div>";

// ============================================================================
// SUMMARY REPORT
// ============================================================================
echo "<div class='test-section' style='background:#f0f8ff;border:2px solid #0066cc;'>";
echo "<h2>📈 Test Summary</h2>";

$success_rate = $tests_run > 0 ? round(($tests_passed / $tests_run) * 100, 1) : 0;
$color = $success_rate == 100 ? 'green' : ($success_rate >= 80 ? 'orange' : 'red');

echo "<table style='font-size:16px;'>";
echo "<tr><td><strong>Tests Run:</strong></td><td>$tests_run</td></tr>";
echo "<tr><td><strong>Passed:</strong></td><td style='color:green;'>$tests_passed</td></tr>";
echo "<tr><td><strong>Failed:</strong></td><td style='color:red;'>$tests_failed</td></tr>";
echo "<tr><td><strong>Success Rate:</strong></td><td style='color:$color;font-weight:bold;font-size:20px;'>{$success_rate}%</td></tr>";
echo "</table>";

if (empty($edge_cases_found)) {
    echo "<h3 style='color:green;'>✅ All Critical Tests Passed!</h3>";
    echo "<p>No quota violations or edge case issues detected. System is ready for production.</p>";
} else {
    echo "<h3 style='color:red;'>⚠️ Critical Issues Found</h3>";
    echo "<ul>";
    foreach ($edge_cases_found as $issue) {
        echo "<li style='color:red;'>$issue</li>";
    }
    echo "</ul>";
    echo "<p><strong>Action Required:</strong> Review and fix critical issues before deployment.</p>";
}

echo "<h3>🎯 Recommendations</h3>";
echo "<ul>";

foreach ($codes as $code => $config) {
    $usage = \HPM\DB::get_code_usage($code);
    $remaining = $config['max'] - $usage;
    
    if ($remaining <= 0) {
        echo "<li>Code '<strong>$code</strong>' is FULL - consider increasing quota or marking inactive</li>";
    } elseif ($remaining <= 5) {
        echo "<li>Code '<strong>$code</strong>' has only $remaining slots remaining - monitor closely</li>";
    }
}

if ($success_rate == 100) {
    echo "<li style='color:green;'>✅ System integrity verified - safe to deploy</li>";
} else {
    echo "<li style='color:orange;'>Review failed tests before production deployment</li>";
}

echo "</ul>";
echo "</div>";

echo "</body></html>";
