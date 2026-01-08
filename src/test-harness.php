<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

/**
 * Test Harness for SMART26 Promo System
 * Allows testing all scenarios without affecting production data
 */

// Register test harness admin page
add_action('admin_menu', function () {
    add_submenu_page(
        'home-promo-manager',
        'Test Harness',
        'Test Harness',
        'manage_options',
        'hpm-test-harness',
        '\\HPM\\render_test_harness_page'
    );
}, 20);

/**
 * Render the test harness page
 */
function render_test_harness_page()
{
    if (!current_user_can('manage_options'))
        wp_die('Insufficient permissions');

    $mgr = Manager::get_instance();
    $opts = get_option('home_promo_manager_settings', []);
    $promo_codes = $opts['promo_codes'] ?? [];
    $current_mode = $opts['code_assignment_mode'] ?? 'manual';
    
    // Get current stats
    $code_stats_array = DB::get_code_stats();
    $code_usage_map = [];
    foreach ($code_stats_array as $stat) {
        $code_usage_map[$stat['promo_code']] = (int) $stat['count'];
    }

    // Handle test submission
    $test_result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hpm_test_action'])) {
        if (!check_admin_referer('hpm_test_harness', 'hpm_test_nonce')) {
            $test_result = ['success' => false, 'message' => 'Security check failed'];
        } else {
            $test_result = process_test_action($_POST);
        }
    }

    ?>
    <div class="wrap">
        <h1>🧪 SMART26 Test Harness</h1>
        
        <div class="notice notice-warning" style="padding: 15px; margin-top: 20px;">
            <h3 style="margin-top: 0;">⚠️ Testing Mode</h3>
            <p><strong>This tool allows you to test all promo code scenarios.</strong></p>
            <p>Test results are shown immediately below the form. Use the "Test Scenarios" section to run comprehensive tests.</p>
        </div>

        <?php if ($test_result): ?>
            <div class="notice notice-<?php echo $test_result['success'] ? 'success' : 'error'; ?>" style="padding: 15px; margin-top: 20px;">
                <h3>Test Result</h3>
                <p><strong>Status:</strong> <?php echo $test_result['success'] ? '✅ SUCCESS' : '❌ FAILED'; ?></p>
                <p><strong>Message:</strong> <?php echo esc_html($test_result['message']); ?></p>
                <?php if (isset($test_result['details'])): ?>
                    <details>
                        <summary>Details</summary>
                        <pre style="background: #f5f5f5; padding: 10px; overflow: auto;"><?php echo esc_html(print_r($test_result['details'], true)); ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Current System Status -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
            <h2>📊 Current System Status</h2>
            
            <table class="widefat" style="margin-top: 15px;">
                <tr>
                    <th style="width: 200px;">Mode</th>
                    <td><strong><?php echo $current_mode === 'auto' ? 'Auto-Assign (Legacy)' : 'User-Entered Codes (SMART26)'; ?></strong></td>
                </tr>
                <tr>
                    <th>Promo Period</th>
                    <td>
                        <?php echo esc_html($opts['start'] ?? 'Not set'); ?> → <?php echo esc_html($opts['end'] ?? 'Not set'); ?>
                        <br><small><?php echo $mgr->is_active() ? '<span style="color: green;">✓ Currently ACTIVE</span>' : '<span style="color: red;">✗ Currently INACTIVE</span>'; ?></small>
                    </td>
                </tr>
                <tr>
                    <th>Total Registrations</th>
                    <td><?php echo $mgr->get_count(); ?></td>
                </tr>
                <tr>
                    <th>Reactivations</th>
                    <td><?php echo DB::count_reactivations(); ?></td>
                </tr>
            </table>

            <h3 style="margin-top: 20px;">Active Promo Codes</h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Used</th>
                        <th>Max</th>
                        <th>Remaining</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promo_codes as $code => $config): 
                        if (!($config['active'] ?? true)) continue;
                        $used = $code_usage_map[$code] ?? 0;
                        $max = (int) $config['max'];
                        $remaining = max(0, $max - $used);
                        $status_class = $remaining > 0 ? 'success' : 'error';
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($code); ?></code></td>
                        <td><?php echo $used; ?></td>
                        <td><?php echo $max; ?></td>
                        <td><strong><?php echo $remaining; ?></strong></td>
                        <td>
                            <span class="notice-<?php echo $status_class; ?>" style="padding: 3px 8px; display: inline-block;">
                                <?php echo $remaining > 0 ? 'Available' : 'FULL'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Manual Test Form -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
            <h2>🔧 Manual Test</h2>
            <p>Test individual scenarios by filling out the form below.</p>

            <form method="post" id="manual-test-form">
                <?php wp_nonce_field('hpm_test_harness', 'hpm_test_nonce'); ?>
                <input type="hidden" name="hpm_test_action" value="manual_test" />

                <table class="form-table">
                    <tr>
                        <th><label for="test_scenario">Test Scenario</label></th>
                        <td>
                            <select name="test_scenario" id="test_scenario" style="width: 100%; max-width: 400px;" required>
                                <option value="">-- Select Scenario --</option>
                                <optgroup label="New Registration">
                                    <option value="valid_code_valid_time">✅ Valid Code + Valid Time</option>
                                    <option value="valid_code_invalid_time">⏰ Valid Code + WRONG Time</option>
                                    <option value="invalid_code_valid_time">❌ Invalid Code + Valid Time</option>
                                    <option value="no_code_valid_time">⚪ No Code + Valid Time</option>
                                    <option value="full_quota_code">🚫 Full Quota Code</option>
                                    <option value="inactive_code">🔒 Inactive Code</option>
                                </optgroup>
                                <optgroup label="Reactivation">
                                    <option value="reactivation_valid">✅ Valid Reactivation</option>
                                    <option value="reactivation_no_code">⚪ Reactivation No Code</option>
                                    <option value="reactivation_invalid_code">❌ Reactivation Invalid Code</option>
                                </optgroup>
                                <optgroup label="Edge Cases">
                                    <option value="duplicate_registration">🔄 Duplicate Registration</option>
                                    <option value="case_insensitive">Aa Case Insensitive Code</option>
                                    <option value="whitespace_code">💨 Code With Whitespace</option>
                                </optgroup>
                            </select>
                            <p class="description">Select a pre-configured scenario to test</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_code">Promo Code</label></th>
                        <td>
                            <input type="text" name="test_code" id="test_code" class="regular-text" placeholder="Leave empty for 'no code' tests" />
                            <p class="description">Or enter a custom code to test</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_time_override">Time Override</label></th>
                        <td>
                            <select name="test_time_override" id="test_time_override" style="width: 100%; max-width: 400px;">
                                <option value="">Use current time</option>
                                <option value="before_start">Before promo start</option>
                                <option value="during_promo">During promo period</option>
                                <option value="after_end">After promo end</option>
                            </select>
                            <p class="description">Simulate different time periods</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_category">User Category</label></th>
                        <td>
                            <select name="test_category" id="test_category" style="width: 100%; max-width: 400px;">
                                <option value="new">New User</option>
                                <option value="passive">Passive User</option>
                                <option value="diagnostic">Diagnostic User</option>
                                <option value="lead">Lead User</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_branch">Branch</label></th>
                        <td>
                            <input type="text" name="test_branch" id="test_branch" value="Test Branch" class="regular-text" />
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        🧪 Run Test
                    </button>
                </p>
            </form>
        </div>

        <!-- Automated Test Suite -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
            <h2>🤖 Automated Test Suite</h2>
            <p>Run all test scenarios automatically to verify system behavior.</p>

            <form method="post">
                <?php wp_nonce_field('hpm_test_harness', 'hpm_test_nonce'); ?>
                <input type="hidden" name="hpm_test_action" value="run_suite" />
                
                <p class="submit">
                    <button type="submit" class="button button-large">
                        ▶️ Run Full Test Suite (18 tests)
                    </button>
                </p>
            </form>

            <div id="test-suite-results"></div>
        </div>

        <!-- Test Data Management -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
            <h2>🗑️ Test Data Management</h2>
            
            <form method="post" onsubmit="return confirm('This will delete ALL test entries. Are you sure?');">
                <?php wp_nonce_field('hpm_test_harness', 'hpm_test_nonce'); ?>
                <input type="hidden" name="hpm_test_action" value="clear_test_data" />
                
                <p>
                    <button type="submit" class="button button-secondary">
                        🗑️ Clear All Test Data
                    </button>
                    <span class="description">Remove all test entries from database (irreversible)</span>
                </p>
            </form>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Auto-fill form based on selected scenario
        $('#test_scenario').on('change', function() {
            const scenario = $(this).val();
            const $codeField = $('#test_code');
            const $timeField = $('#test_time_override');
            
            // Get first available code
            const firstCode = <?php echo json_encode(array_key_first($promo_codes)); ?>;
            
            switch(scenario) {
                case 'valid_code_valid_time':
                    $codeField.val(firstCode);
                    $timeField.val('during_promo');
                    break;
                case 'valid_code_invalid_time':
                    $codeField.val(firstCode);
                    $timeField.val('before_start');
                    break;
                case 'invalid_code_valid_time':
                    $codeField.val('INVALID-CODE-123');
                    $timeField.val('during_promo');
                    break;
                case 'no_code_valid_time':
                    $codeField.val('');
                    $timeField.val('during_promo');
                    break;
                case 'full_quota_code':
                    $codeField.val(firstCode + '-FULL');
                    $timeField.val('during_promo');
                    break;
                case 'inactive_code':
                    $codeField.val('INACTIVE-CODE');
                    $timeField.val('during_promo');
                    break;
                case 'case_insensitive':
                    $codeField.val(firstCode ? firstCode.toLowerCase() : 'test');
                    $timeField.val('during_promo');
                    break;
                case 'whitespace_code':
                    $codeField.val('  ' + firstCode + '  ');
                    $timeField.val('during_promo');
                    break;
                default:
                    break;
            }
        });
    });
    </script>

    <style>
    .test-result-success { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 10px 0; }
    .test-result-error { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 10px 0; }
    .test-scenario { margin: 10px 0; padding: 10px; background: #f9f9f9; border-left: 3px solid #ccc; }
    .test-scenario.pass { border-left-color: #28a745; }
    .test-scenario.fail { border-left-color: #dc3545; }
    </style>
    <?php
}

/**
 * Process test action
 */
function process_test_action($post_data)
{
    $action = $post_data['hpm_test_action'];
    
    switch ($action) {
        case 'manual_test':
            return run_manual_test($post_data);
        
        case 'run_suite':
            return run_test_suite();
        
        case 'clear_test_data':
            DB::clear();
            return [
                'success' => true,
                'message' => 'All test data cleared successfully'
            ];
        
        default:
            return ['success' => false, 'message' => 'Unknown test action'];
    }
}

/**
 * Run manual test
 */
function run_manual_test($data)
{
    $mgr = Manager::get_instance();
    $code = trim($data['test_code'] ?? '');
    $time_override = $data['test_time_override'] ?? '';
    $category = $data['test_category'] ?? 'new';
    $branch = $data['test_branch'] ?? 'Test Branch';
    $scenario = $data['test_scenario'] ?? 'custom';
    
    // Time override logic
    $original_start = null;
    $original_end = null;
    
    if ($time_override) {
        $opts = get_option('home_promo_manager_settings', []);
        $original_start = $opts['start'];
        $original_end = $opts['end'];
        
        switch ($time_override) {
            case 'before_start':
                $opts['start'] = date('Y-m-d H:i:s', strtotime('+1 day'));
                $opts['end'] = date('Y-m-d H:i:s', strtotime('+2 days'));
                break;
            case 'during_promo':
                $opts['start'] = date('Y-m-d H:i:s', strtotime('-1 day'));
                $opts['end'] = date('Y-m-d H:i:s', strtotime('+1 day'));
                break;
            case 'after_end':
                $opts['start'] = date('Y-m-d H:i:s', strtotime('-2 days'));
                $opts['end'] = date('Y-m-d H:i:s', strtotime('-1 day'));
                break;
        }
        
        update_option('home_promo_manager_settings', $opts);
    }
    
    // Generate test entry ID
    $test_entry_id = 999900 + rand(1, 999);
    
    // Test validation
    if (!empty($code)) {
        $validation = $mgr->validate_code($code);
        
        if ($validation['valid']) {
            // Try to record
            $record = $mgr->validate_and_record($code, $test_entry_id, $branch, $category);
            $result = [
                'success' => $record['success'],
                'message' => $record['message'],
                'details' => [
                    'scenario' => $scenario,
                    'entry_id' => $test_entry_id,
                    'code' => $code,
                    'category' => $category,
                    'branch' => $branch,
                    'time_override' => $time_override,
                    'validation' => $validation,
                    'recording' => $record
                ]
            ];
        } else {
            $result = [
                'success' => false,
                'message' => $validation['message'],
                'details' => [
                    'scenario' => $scenario,
                    'code' => $code,
                    'validation' => $validation
                ]
            ];
        }
    } else {
        // No code provided - test optional code flow
        $result = [
            'success' => true,
            'message' => 'No code provided - this is allowed. User can submit without promo code.',
            'details' => [
                'scenario' => $scenario,
                'note' => 'Promo codes are optional. Form submission proceeds normally without code validation.'
            ]
        ];
    }
    
    // Restore original times
    if ($original_start && $original_end) {
        $opts = get_option('home_promo_manager_settings', []);
        $opts['start'] = $original_start;
        $opts['end'] = $original_end;
        update_option('home_promo_manager_settings', $opts);
    }
    
    return $result;
}

/**
 * Run full test suite
 */
function run_test_suite()
{
    $tests = [
        ['name' => 'Valid code during promo', 'code' => 'SMART26-LIVE1', 'time' => 'during_promo', 'expected' => true],
        ['name' => 'Valid code before start', 'code' => 'SMART26-LIVE1', 'time' => 'before_start', 'expected' => false],
        ['name' => 'Valid code after end', 'code' => 'SMART26-LIVE1', 'time' => 'after_end', 'expected' => false],
        ['name' => 'Invalid code during promo', 'code' => 'INVALID-123', 'time' => 'during_promo', 'expected' => false],
        ['name' => 'Empty code during promo', 'code' => '', 'time' => 'during_promo', 'expected' => true],
        ['name' => 'Case insensitive code', 'code' => 'smart26-live1', 'time' => 'during_promo', 'expected' => true],
        ['name' => 'Code with whitespace', 'code' => '  SMART26-LIVE1  ', 'time' => 'during_promo', 'expected' => true],
    ];
    
    $results = [];
    $passed = 0;
    $failed = 0;
    
    foreach ($tests as $test) {
        $result = run_manual_test([
            'test_code' => $test['code'],
            'test_time_override' => $test['time'],
            'test_category' => 'new',
            'test_branch' => 'Test Branch',
            'test_scenario' => 'automated'
        ]);
        
        $test_passed = ($result['success'] == $test['expected']);
        
        $results[] = [
            'name' => $test['name'],
            'passed' => $test_passed,
            'expected' => $test['expected'] ? 'Success' : 'Failure',
            'actual' => $result['success'] ? 'Success' : 'Failure',
            'message' => $result['message']
        ];
        
        if ($test_passed) {
            $passed++;
        } else {
            $failed++;
        }
    }
    
    $html = "<div style='margin-top: 20px;'>";
    $html .= "<h3>Test Suite Results: {$passed} passed, {$failed} failed</h3>";
    
    foreach ($results as $r) {
        $class = $r['passed'] ? 'pass' : 'fail';
        $icon = $r['passed'] ? '✅' : '❌';
        $html .= "<div class='test-scenario {$class}'>";
        $html .= "<strong>{$icon} {$r['name']}</strong><br>";
        $html .= "Expected: {$r['expected']} | Actual: {$r['actual']}<br>";
        $html .= "<small>{$r['message']}</small>";
        $html .= "</div>";
    }
    
    $html .= "</div>";
    
    return [
        'success' => $failed === 0,
        'message' => "Test suite completed: {$passed}/{count($tests)} tests passed",
        'details' => ['html' => $html, 'results' => $results]
    ];
}
