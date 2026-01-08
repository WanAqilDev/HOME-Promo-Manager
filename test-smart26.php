<?php
/**
 * SMART26 Manual Testing Script
 * Run this in WordPress context to test core functionality
 * 
 * Usage: Place in WordPress root and access via browser
 */

// Load WordPress
require_once __DIR__ . '/../../wp-load.php';

if (!current_user_can('manage_options')) {
    die('Access denied. Admin only.');
}

echo "<html><head><title>SMART26 Test Suite</title>";
echo "<style>body{font-family:monospace;padding:20px;} .pass{color:green;} .fail{color:red;} .test{margin:10px 0;padding:10px;border:1px solid #ccc;}</style>";
echo "</head><body><h1>SMART26 Test Suite</h1>";

$mgr = \HPM\Manager::get_instance();
$tests_passed = 0;
$tests_failed = 0;

function test($name, $condition, $message = '') {
    global $tests_passed, $tests_failed;
    echo "<div class='test'>";
    if ($condition) {
        echo "<span class='pass'>✓ PASS</span> - $name";
        $tests_passed++;
    } else {
        echo "<span class='fail'>✗ FAIL</span> - $name";
        if ($message) echo "<br><em>$message</em>";
        $tests_failed++;
    }
    echo "</div>";
}

echo "<h2>1. Code Validation Tests</h2>";

// Test valid code
$codes = $mgr->s('promo_codes');
if (!empty($codes)) {
    $first_code = array_key_first($codes);
    $result = $mgr->validate_code($first_code);
    test("Valid code accepted", $result['valid'], $result['message']);
} else {
    test("Has promo codes configured", false, "No promo codes found in settings");
}

// Test invalid code
$result = $mgr->validate_code('INVALID-CODE-123');
test("Invalid code rejected", !$result['valid'], "Should reject non-existent code");

// Test empty code
$result = $mgr->validate_code('');
test("Empty code rejected", !$result['valid'], "Should reject empty code");

// Test promo active check
$is_active = $mgr->is_active();
test("Promo period check", is_bool($is_active), "is_active() should return boolean");

echo "<h2>2. Database Method Tests</h2>";

// Test get_code_usage
if (!empty($codes)) {
    $first_code = array_key_first($codes);
    $usage = \HPM\DB::get_code_usage($first_code);
    test("get_code_usage() returns integer", is_int($usage), "Usage: $usage");
    test("Usage is non-negative", $usage >= 0, "Usage should be >= 0");
}

// Test get_code_stats
$stats = \HPM\DB::get_code_stats();
test("get_code_stats() returns array", is_array($stats), "Should return array of code statistics");
test("Stats has correct structure", isset($stats[0]['code']) || empty($stats), "Each stat should have 'code' key");

// Test get_category_stats
$cat_stats = \HPM\DB::get_category_stats();
test("get_category_stats() returns array", is_array($cat_stats), "Should return category breakdown");

// Test total counts
$total = \HPM\DB::count_activations();
$reactivations = \HPM\DB::count_reactivations();
test("count_activations() works", is_int($total), "Total: $total");
test("count_reactivations() works", is_int($reactivations), "Reactivations: $reactivations");

echo "<h2>3. Settings Validation</h2>";

// Test mode setting
$mode = $mgr->s('code_assignment_mode');
test("Has code assignment mode", in_array($mode, ['auto', 'manual']), "Mode: $mode");

// Test promo codes structure
if (!empty($codes)) {
    $has_valid_structure = true;
    foreach ($codes as $code => $config) {
        if (!isset($config['max']) || !isset($config['active'])) {
            $has_valid_structure = false;
            break;
        }
    }
    test("Promo codes have valid structure", $has_valid_structure, "Each code should have 'max' and 'active' keys");
}

// Test total_max calculation
$total_max = $mgr->s('total_max');
$calculated_max = 0;
foreach ($codes as $code => $config) {
    if ($config['active']) {
        $calculated_max += $config['max'];
    }
}
test("total_max matches active codes sum", $total_max == $calculated_max, "Setting: $total_max, Calculated: $calculated_max");

echo "<h2>4. REST API Tests</h2>";

// Test counter endpoint
$response = wp_remote_get(get_rest_url(null, 'promo/v1/counter'));
if (!is_wp_error($response)) {
    $body = json_decode(wp_remote_retrieve_body($response), true);
    test("Counter endpoint responds", !empty($body), "Endpoint should return data");
    test("Has 'active' field", isset($body['active']), "Response should include 'active' status");
    test("Has 'mode' field", isset($body['mode']), "Response should include mode");
    
    if ($mode === 'smart26' || $mode === 'manual') {
        test("SMART26 mode has 'codes' array", isset($body['codes']), "Should have per-code breakdown");
        test("SMART26 mode has 'categories'", isset($body['categories']), "Should have category stats");
    }
} else {
    test("Counter endpoint accessible", false, $response->get_error_message());
}

// Test validation endpoint
if ($mode === 'smart26' || $mode === 'manual') {
    $first_code = !empty($codes) ? array_key_first($codes) : '';
    if ($first_code) {
        $response = wp_remote_post(get_rest_url(null, 'promo/v1/validate'), [
            'body' => json_encode(['code' => $first_code]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            test("Validation endpoint responds", !empty($body), "Endpoint should return validation result");
            test("Has 'valid' field", isset($body['valid']), "Response should include valid status");
            test("Has 'message' field", isset($body['message']), "Response should include message");
        } else {
            test("Validation endpoint accessible", false, $response->get_error_message());
        }
    }
}

echo "<h2>5. Field Configuration Tests</h2>";

// Test required field IDs are set
$required_fields = ['form_id', 'promo_field_id', 'status_field_id', 'daftar_field_id'];
foreach ($required_fields as $field) {
    $value = $mgr->s($field);
    test("$field is configured", !empty($value), "$field = $value");
}

// SMART26-specific fields
$smart26_fields = ['diagnostic_date_field_id', 'lead_status_field_id', 'branch_field_id', 'pasif_date_field_id'];
foreach ($smart26_fields as $field) {
    $value = $mgr->s($field);
    test("$field is configured", !empty($value), "$field = $value (required for SMART26)");
}

echo "<h2>Test Summary</h2>";
echo "<div style='font-size:18px;margin-top:20px;'>";
echo "<strong>Passed:</strong> <span class='pass'>$tests_passed</span> | ";
echo "<strong>Failed:</strong> <span class='fail'>$tests_failed</span> | ";
$total_tests = $tests_passed + $tests_failed;
$percentage = $total_tests > 0 ? round(($tests_passed / $total_tests) * 100, 1) : 0;
echo "<strong>Success Rate:</strong> {$percentage}%";
echo "</div>";

if ($tests_failed === 0) {
    echo "<h3 style='color:green;'>🎉 All tests passed! SMART26 system is operational.</h3>";
} else {
    echo "<h3 style='color:orange;'>⚠️ Some tests failed. Review configuration.</h3>";
}

echo "</body></html>";
