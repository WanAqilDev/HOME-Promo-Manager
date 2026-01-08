<?php
/**
 * SMART26 Standalone Test Suite
 * Tests core functionality without WordPress context
 */

echo "╔════════════════════════════════════════════════════╗\n";
echo "║     SMART26 IMPLEMENTATION TEST SUITE             ║\n";
echo "╚════════════════════════════════════════════════════╝\n\n";

$tests_passed = 0;
$tests_failed = 0;
$test_details = [];

function test($name, $condition, $expected = '', $actual = '') {
    global $tests_passed, $tests_failed, $test_details;
    
    if ($condition) {
        echo "✓ PASS - $name\n";
        $tests_passed++;
        $test_details[] = ['name' => $name, 'status' => 'pass'];
    } else {
        echo "✗ FAIL - $name\n";
        if ($expected && $actual !== '') {
            echo "  Expected: $expected\n";
            echo "  Got: $actual\n";
        }
        $tests_failed++;
        $test_details[] = ['name' => $name, 'status' => 'fail', 'expected' => $expected, 'actual' => $actual];
    }
}

echo "═══════════════════════════════════════════════════\n";
echo "1. FILE STRUCTURE TESTS\n";
echo "═══════════════════════════════════════════════════\n";

$required_files = [
    'home-promo-manager.php' => 'Main plugin file',
    'src/Manager.php' => 'Manager class',
    'src/db.php' => 'Database class',
    'src/Validator.php' => 'Validator class',
    'src/hooks.php' => 'Formidable hooks',
    'src/rest.php' => 'REST API',
    'src/admin.php' => 'Admin UI',
    'src/utils.php' => 'Utility functions',
    'template/promo-page.php' => 'Landing page template'
];

foreach ($required_files as $file => $desc) {
    test("$desc exists", file_exists($file), $file, 'missing');
}

echo "\n═══════════════════════════════════════════════════\n";
echo "2. NAMESPACE CONSISTENCY TESTS\n";
echo "═══════════════════════════════════════════════════\n";

$namespace_files = [
    'src/Manager.php',
    'src/db.php', 
    'src/Validator.php',
    'src/hooks.php',
    'src/rest.php',
    'src/admin.php'
];

foreach ($namespace_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $has_hpm_namespace = preg_match('/namespace\s+HPM;/', $content);
        $has_wrong_namespace = preg_match('/namespace\s+HomePromoManager;/', $content);
        
        test("$file uses HPM namespace", $has_hpm_namespace && !$has_wrong_namespace, 'namespace HPM;', 
            $has_wrong_namespace ? 'namespace HomePromoManager;' : 'no namespace');
    }
}

echo "\n═══════════════════════════════════════════════════\n";
echo "3. FUNCTION DEFINITION TESTS\n";
echo "═══════════════════════════════════════════════════\n";

$utils_content = file_exists('src/utils.php') ? file_get_contents('src/utils.php') : '';
$required_functions = [
    'ff_get_field_value_robust' => 'Formidable field getter',
    'ff_update_entry_meta' => 'Formidable meta updater',
    'ff_get_entry_meta' => 'Formidable meta getter'
];

foreach ($required_functions as $func => $desc) {
    $pattern = '/function\s+' . preg_quote($func) . '\s*\(/';
    test("$desc defined", preg_match($pattern, $utils_content), $func, 'not found');
}

echo "\n═══════════════════════════════════════════════════\n";
echo "4. CLASS METHOD TESTS\n";
echo "═══════════════════════════════════════════════════\n";

// Manager class methods
if (file_exists('src/Manager.php')) {
    $manager_content = file_get_contents('src/Manager.php');
    
    $manager_methods = [
        'validate_code' => 'Code validation method',
        'validate_and_record' => 'SMART26 recording method',
        'record_reactivation' => 'Reactivation method',
        'is_active' => 'Promo period check',
        'get_instance' => 'Singleton instance'
    ];
    
    foreach ($manager_methods as $method => $desc) {
        $pattern = '/(public|private|protected)\s+(static\s+)?function\s+' . preg_quote($method) . '\s*\(/';
        test("Manager::$method - $desc", preg_match($pattern, $manager_content), $method, 'not found');
    }
}

// DB class methods
if (file_exists('src/db.php')) {
    $db_content = file_get_contents('src/db.php');
    
    $db_methods = [
        'insert_entry_with_code' => 'SMART26 entry insertion',
        'get_code_usage' => 'Code usage counter',
        'get_code_stats' => 'Code statistics',
        'get_category_stats' => 'Category breakdown',
        'has_reactivation' => 'Reactivation check'
    ];
    
    foreach ($db_methods as $method => $desc) {
        $pattern = '/(public|private|protected)\s+(static\s+)?function\s+' . preg_quote($method) . '\s*\(/';
        test("DB::$method - $desc", preg_match($pattern, $db_content), $method, 'not found');
    }
}

echo "\n═══════════════════════════════════════════════════\n";
echo "5. HOOK INTEGRATION TESTS\n";
echo "═══════════════════════════════════════════════════\n";

if (file_exists('src/hooks.php')) {
    $hooks_content = file_get_contents('src/hooks.php');
    
    $required_hooks = [
        'frm_validate_entry' => 'Pre-submission validation',
        'frm_after_create_entry' => 'New registration handler',
        'frm_after_update_entry' => 'Reactivation detector',
        'frm_pre_update_entry' => 'Previous state capture',
        'frm_pre_create_entry' => 'Default value setter'
    ];
    
    foreach ($required_hooks as $hook => $desc) {
        $pattern = '/add_(filter|action)\s*\(\s*[\'"]' . preg_quote($hook) . '[\'"]/';
        test("Hook: $hook - $desc", preg_match($pattern, $hooks_content), $hook, 'not registered');
    }
}

echo "\n═══════════════════════════════════════════════════\n";
echo "6. REST API ENDPOINT TESTS\n";
echo "═══════════════════════════════════════════════════\n";

if (file_exists('src/rest.php')) {
    $rest_content = file_get_contents('src/rest.php');
    
    test("Counter endpoint registered", 
        strpos($rest_content, 'promo/v1') !== false && strpos($rest_content, 'counter') !== false,
        '/promo/v1/counter', 'not found');
        
    test("Validation endpoint registered",
        strpos($rest_content, 'validate') !== false,
        '/promo/v1/validate', 'not found');
    
    test("REST routes use register_rest_route",
        preg_match('/register_rest_route\s*\(/', $rest_content),
        'register_rest_route()', 'not found');
}

echo "\n═══════════════════════════════════════════════════\n";
echo "7. ADMIN UI TESTS\n";
echo "═══════════════════════════════════════════════════\n";

if (file_exists('src/admin.php')) {
    $admin_content = file_get_contents('src/admin.php');
    
    test("Has sanitize_settings function",
        preg_match('/function\s+sanitize_settings/', $admin_content),
        'sanitize_settings', 'not found');
    
    test("Has promo_codes handling",
        strpos($admin_content, 'promo_codes') !== false,
        'promo_codes field', 'not found');
    
    test("Has code_assignment_mode toggle",
        strpos($admin_content, 'code_assignment_mode') !== false,
        'mode toggle', 'not found');
}

echo "\n═══════════════════════════════════════════════════\n";
echo "8. PLUGIN BOOTSTRAP TESTS\n";
echo "═══════════════════════════════════════════════════\n";

if (file_exists('home-promo-manager.php')) {
    $main_content = file_get_contents('home-promo-manager.php');
    
    test("Validator.php included in bootstrap",
        strpos($main_content, "require_once __DIR__ . '/src/Validator.php'") !== false,
        "require Validator.php", 'not found');
    
    test("All core files included",
        strpos($main_content, 'utils.php') !== false &&
        strpos($main_content, 'db.php') !== false &&
        strpos($main_content, 'Manager.php') !== false &&
        strpos($main_content, 'hooks.php') !== false,
        'all core files', 'some missing');
    
    test("Plugin constants defined",
        preg_match('/define\s*\(\s*[\'"]HOME_PROMO_MANAGER_/', $main_content),
        'HOME_PROMO_MANAGER_*', 'not found');
}

echo "\n═══════════════════════════════════════════════════\n";
echo "9. TEMPLATE FILE TESTS\n";
echo "═══════════════════════════════════════════════════\n";

if (file_exists('template/promo-page.php')) {
    $template_content = file_get_contents('template/promo-page.php');
    
    test("Template uses REST API",
        strpos($template_content, 'get_rest_url') !== false &&
        strpos($template_content, 'promo/v1/counter') !== false,
        'REST API integration', 'not found');
    
    test("Template checks Manager class",
        strpos($template_content, "class_exists('\\HPM\\Manager')") !== false,
        'Manager class check', 'not found');
    
    test("Template has countdown clock",
        strpos($template_content, 'flipClock') !== false,
        'flipClock element', 'not found');
    
    test("Template has live stats",
        strpos($template_content, 'updateRealtimeStats') !== false,
        'real-time stats function', 'not found');
}

echo "\n═══════════════════════════════════════════════════\n";
echo "10. SYNTAX VALIDATION\n";
echo "═══════════════════════════════════════════════════\n";

$php_files = glob('src/*.php');
$php_files[] = 'home-promo-manager.php';
$php_files[] = 'template/promo-page.php';

foreach ($php_files as $file) {
    if (file_exists($file)) {
        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $return_var);
        $result = implode("\n", $output);
        test("$file syntax valid", $return_var === 0 && strpos($result, 'No syntax errors') !== false,
            'No syntax errors', $return_var !== 0 ? 'Syntax error' : 'Unknown');
    }
}

echo "\n╔════════════════════════════════════════════════════╗\n";
echo "║              TEST SUMMARY                         ║\n";
echo "╚════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  Passed: " . str_pad($tests_passed, 3) . " ✓\n";
echo "  Failed: " . str_pad($tests_failed, 3) . " ✗\n";
echo "  Total:  " . str_pad($tests_passed + $tests_failed, 3) . "\n";
echo "\n";

$total_tests = $tests_passed + $tests_failed;
$percentage = $total_tests > 0 ? round(($tests_passed / $total_tests) * 100, 1) : 0;
echo "  Success Rate: {$percentage}%\n\n";

if ($tests_failed === 0) {
    echo "🎉 ALL TESTS PASSED! SMART26 system is ready.\n\n";
    exit(0);
} else {
    echo "⚠️  SOME TESTS FAILED. Review the output above.\n\n";
    echo "Failed Tests:\n";
    foreach ($test_details as $detail) {
        if ($detail['status'] === 'fail') {
            echo "  - {$detail['name']}\n";
        }
    }
    echo "\n";
    exit(1);
}
