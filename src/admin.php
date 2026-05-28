<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

/**
 * Admin settings page for HOME Promo Manager
 *
 * - Registers settings under 'home_promo_manager_settings' option.
 * - Renders settings UI (Settings > HOME Promo Manager).
 * - Provides manual Clear Counted Entries button (with nonce).
 */

// AJAX handler for realtime stats updates
add_action('wp_ajax_hpm_get_realtime_stats', function() {
    $code_stats_array = DB::get_code_stats();
    $mgr = Manager::get_instance();
    $promo_codes = $mgr->s('promo_codes') ?: [];
    
    // Convert array to associative map
    $code_usage_map = [];
    foreach ($code_stats_array as $stat) {
        $code_usage_map[$stat['promo_code']] = (int) $stat['count'];
    }
    
    $codes_data = [];
    $total_used = 0;
    $total_max = 0;
    
    foreach ($promo_codes as $code => $config) {
        if (!($config['active'] ?? true)) {
            continue;
        }
        
        $used = $code_usage_map[$code] ?? 0;
        $max = (int) ($config['max'] ?? 0);
        $remaining = max(0, $max - $used);
        $percentage = $max > 0 ? ($used / $max) * 100 : 0;
        
        $codes_data[$code] = [
            'used' => $used,
            'max' => $max,
            'remaining' => $remaining,
            'percentage' => $percentage
        ];
        
        $total_used += $used;
        $total_max += $max;
    }
    
    $response = [
        'codes' => $codes_data,
        'total' => [
            'used' => $total_used,
            'max' => $total_max,
            'remaining' => max(0, $total_max - $total_used),
            'percentage' => $total_max > 0 ? ($total_used / $total_max) * 100 : 0
        ]
    ];
    
    wp_send_json_success($response);
});

// Register admin menu and settings
add_action('admin_menu', function () {
    add_options_page(
        'HOME Promo Manager',
        'Promo Manager',
        CampaignEngine::CAP,
        'home-promo-manager',
        'HPM\\hpm_render_admin_page'
    );
});

add_action('admin_init', function () {
    register_setting('hpm_settings_group', 'home_promo_manager_settings', [
        'sanitize_callback' => '\\HPM\\sanitize_settings'
    ]);
});

/**
 * Sanitize incoming settings array (SMART26 compatible)
 *
 * @param array $input
 * @return array sanitized
 */
function sanitize_settings($input)
{
    $defaults = get_option('home_promo_manager_settings', []);
    $out = [];

    // Campaign timing
    $out['start'] = sanitize_text_field($input['start'] ?? ($defaults['start'] ?? '2026-01-12 12:00:00'));
    $out['end'] = sanitize_text_field($input['end'] ?? ($defaults['end'] ?? '2026-01-14 11:59:00'));
    $out['timezone'] = sanitize_text_field($input['timezone'] ?? ($defaults['timezone'] ?? 'Asia/Kuala_Lumpur'));
    $out['debug_mode'] = isset($input['debug_mode']) ? (bool) $input['debug_mode'] : false;

    // Form field IDs
    $out['form_id'] = isset($input['form_id']) ? absint($input['form_id']) : absint($defaults['form_id'] ?? 13);
    $out['promo_field_id'] = isset($input['promo_field_id']) ? absint($input['promo_field_id']) : absint($defaults['promo_field_id'] ?? 3170);
    $out['daftar_field_id'] = isset($input['daftar_field_id']) ? absint($input['daftar_field_id']) : absint($defaults['daftar_field_id'] ?? 196);
    $out['daftar_trigger_value'] = sanitize_text_field($input['daftar_trigger_value'] ?? ($defaults['daftar_trigger_value'] ?? 'Ya'));
    $out['status_field_id'] = isset($input['status_field_id']) ? absint($input['status_field_id']) : absint($defaults['status_field_id'] ?? 199);
    $out['pasif_date_field_id'] = isset($input['pasif_date_field_id']) ? absint($input['pasif_date_field_id']) : absint($defaults['pasif_date_field_id'] ?? 1698);
    
    // SMART26: New eligibility fields
    $out['diagnostic_date_field_id'] = isset($input['diagnostic_date_field_id']) ? absint($input['diagnostic_date_field_id']) : absint($defaults['diagnostic_date_field_id'] ?? 0);
    $out['lead_status_field_id'] = isset($input['lead_status_field_id']) ? absint($input['lead_status_field_id']) : absint($defaults['lead_status_field_id'] ?? 0);
    $out['branch_field_id'] = isset($input['branch_field_id']) ? absint($input['branch_field_id']) : absint($defaults['branch_field_id'] ?? 0);
    $out['passive_threshold_days'] = isset($input['passive_threshold_days']) ? absint($input['passive_threshold_days']) : absint($defaults['passive_threshold_days'] ?? 90);

    // SMART26: Code assignment mode
    $out['code_assignment_mode'] = sanitize_text_field($input['code_assignment_mode'] ?? ($defaults['code_assignment_mode'] ?? 'manual'));
    if (!in_array($out['code_assignment_mode'], ['auto', 'manual'])) {
        $out['code_assignment_mode'] = 'manual'; // Default to manual if invalid
    }

    // SMART26: Dynamic promo codes (new system)
    if (isset($input['promo_codes']) && is_array($input['promo_codes'])) {
        $out['promo_codes'] = [];
        foreach ($input['promo_codes'] as $code => $config) {
            $sanitized_code = sanitize_text_field($code);
            if (!empty($sanitized_code)) {
                $out['promo_codes'][$sanitized_code] = [
                    'max' => isset($config['max']) ? absint($config['max']) : 50,
                    'description' => sanitize_text_field($config['description'] ?? ''),
                    'active' => isset($config['active']) ? (bool) $config['active'] : true,
                ];
            }
        }
    } else {
        // Keep existing codes if not updated - use shared defaults from DB class
        $out['promo_codes'] = $defaults['promo_codes'] ?? DB::get_default_promo_codes();
    }

    // SMART26: Pricing
    $out['base_price'] = isset($input['base_price']) ? floatval($input['base_price']) : floatval($defaults['base_price'] ?? 200.00);
    $out['discount_amount'] = isset($input['discount_amount']) ? floatval($input['discount_amount']) : floatval($defaults['discount_amount'] ?? 52.00);
    $out['final_price'] = isset($input['final_price']) ? floatval($input['final_price']) : floatval($defaults['final_price'] ?? 148.00);
    
    // Calculate total max from all active codes
    $total_max = 0;
    foreach ($out['promo_codes'] as $config) {
        if ($config['active']) {
            $total_max += $config['max'];
        }
    }
    $out['total_max'] = $total_max;

    // Legacy fields (backward compatibility - keep but mark as deprecated)
    $out['max'] = isset($input['max']) ? absint($input['max']) : absint($defaults['max'] ?? 480);
    $out['tier1_max'] = isset($input['tier1_max']) ? absint($input['tier1_max']) : absint($defaults['tier1_max'] ?? 240);
    $out['code_tier1'] = sanitize_text_field($input['code_tier1'] ?? ($defaults['code_tier1'] ?? 'promo24'));
    $out['code_tier2'] = sanitize_text_field($input['code_tier2'] ?? ($defaults['code_tier2'] ?? 'promo12'));

    $out['admin_email'] = sanitize_email($input['admin_email'] ?? ($defaults['admin_email'] ?? get_option('admin_email')));

    // Return sanitized array
    return $out;
}

/**
 * Render the settings tab content (no outer wrap — provided by tab router)
 */
function hpm_render_settings_tab(): void
{
    hpm_admin_guard();

    // Load current settings (ensures defaults)
    $opts = get_option('home_promo_manager_settings', []);
    $defaults = [
        'start' => '2025-12-01 12:00:00',
        'end' => '2025-12-24 23:59:00',
        'timezone' => 'Asia/Kuala_Lumpur',
        'debug_mode' => false,
        'form_id' => 13,
        'promo_field_id' => 3170,
        'daftar_field_id' => 196,
        'daftar_trigger_value' => 'Ya',
        'status_field_id' => 0,
        'pasif_date_field_id' => 0,
        'max' => 480,
        'tier1_max' => 240,
        'code_tier1' => 'promo24',
        'code_tier2' => 'promo12',
        'admin_email' => get_option('admin_email'),
    ];
    $opts = wp_parse_args($opts, $defaults);

    // Handle manual Clear Counted Entries button POST (nonce check)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hpm_clear_count'])) {
        if (!check_admin_referer('hpm_manual_ops', 'hpm_manual_nonce')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        } else {
            DB::clear();
            echo '<div class="notice notice-success"><p>Counted entries cleared.</p></div>';
        }
    }

    // Handle Create Promo Page
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hpm_create_page'])) {
        if (!check_admin_referer('hpm_create_page', 'hpm_create_page_nonce')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        } else {
            // Check if page already exists to avoid duplicates
            $existing_pages = get_posts([
                'post_type' => 'page',
                'meta_key' => '_wp_page_template',
                'meta_value' => 'promo-page.php',
                'post_status' => 'publish'
            ]);

            if (empty($existing_pages)) {
                $page_id = wp_insert_post([
                    'post_title' => 'Promo Countdown',
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'page_template' => 'promo-page.php'
                ]);

                if ($page_id && !is_wp_error($page_id)) {
                    // Force template meta just in case
                    update_post_meta($page_id, '_wp_page_template', 'promo-page.php');
                    echo '<div class="notice notice-success"><p>Promo Page created successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to create page.</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning"><p>Promo Page already exists.</p></div>';
            }
        }
    }

    ?>
    <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0;">Settings</h2>
            <span style="background: #2271b1; color: white; padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                v<?php echo esc_html(HOME_PROMO_MANAGER_VERSION); ?>
            </span>
        </div>

        <style>
            .hpm-dashboard {
                display: flex;
                gap: 20px;
                margin-bottom: 20px;
                align-items: stretch;
            }

            .hpm-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 15px;
                border-radius: 4px;
                flex: 1;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
            }

            .hpm-card h3 {
                margin-top: 0;
                color: #1d2327;
                font-size: 1.1em;
            }

            .hpm-stat {
                font-size: 2em;
                font-weight: bold;
                color: #2271b1;
            }

            .hpm-progress {
                background: #f0f0f1;
                height: 20px;
                border-radius: 10px;
                overflow: hidden;
                margin-top: 10px;
            }

            .hpm-bar {
                background: #2271b1;
                height: 100%;
                transition: width 0.3s ease;
            }

            .hpm-status-active {
                color: #00a32a;
            }

            .hpm-status-inactive {
                color: #d63638;
            }
        </style>

        <?php
        $mgr = Manager::get_instance();
        $current_mode = $opts['code_assignment_mode'] ?? 'manual';
        
        // Get stats based on mode
        if ($current_mode === 'manual') {
            // SMART26 mode: sum all active codes
            $promo_codes = $opts['promo_codes'] ?? [];
            $total_max = 0;
            foreach ($promo_codes as $config) {
                if ($config['active'] ?? true) {
                    $total_max += (int)$config['max'];
                }
            }
            $count = $mgr->get_count(); // Total used
            $max = $total_max;
            
            // Get last code used for "current tier" display
            global $wpdb;
            $table = DB::table_name();
            $last_code = $wpdb->get_var("SELECT promo_code FROM {$table} ORDER BY entry_id DESC LIMIT 1");
            $current_tier = $last_code ? $last_code : 'No registrations yet';
        } else {
            // Legacy mode
            $count = $mgr->get_count();
            $max = (int) $opts['max'];
            $tier1 = (int) $opts['tier1_max'];
            $current_tier = ($count < $tier1) ? 'Tier 1' : 'Tier 2';
        }
        
        $percent = $max > 0 ? min(100, ($count / $max) * 100) : 0;
        $is_active = $mgr->is_active();
        $status_text = $is_active ? 'Active' : 'Inactive';
        $status_class = $is_active ? 'hpm-status-active' : 'hpm-status-inactive';
        $reactivations = DB::count_reactivations();

        // Check for existing promo page
        $promo_pages = get_posts([
            'post_type' => 'page',
            'meta_key' => '_wp_page_template',
            'meta_value' => 'promo-page.php',
            'post_status' => 'publish',
            'numberposts' => 1
        ]);
        $promo_page_url = !empty($promo_pages) ? get_permalink($promo_pages[0]->ID) : null;
        ?>

        <div class="hpm-dashboard">
            <div class="hpm-card">
                <h3>Status</h3>
                <div class="hpm-stat <?php echo $status_class; ?>"><?php echo $status_text; ?></div>
                <p><?php echo $opts['start']; ?> - <?php echo $opts['end']; ?></p>
            </div>
            <div class="hpm-card">
                <h3>Slots Used</h3>
                <div class="hpm-stat"><?php echo $count; ?> / <?php echo $max; ?></div>
                <div class="hpm-progress">
                    <div class="hpm-bar" style="width: <?php echo $percent; ?>%;"></div>
                </div>
            </div>
            <div class="hpm-card">
                <h3><?php echo $current_mode === 'manual' ? 'Last Code Used' : 'Current Tier'; ?></h3>
                <div class="hpm-stat" style="<?php echo $current_mode === 'manual' ? 'font-size: 1.2em;' : ''; ?>"><?php echo esc_html($current_tier); ?></div>
                <p><?php echo $current_mode === 'manual' ? 'Most recent registration' : 'Tier 1 Limit: ' . ($tier1 ?? 0); ?></p>
            </div>
            <div class="hpm-card">
                <h3>Reactivations</h3>
                <div class="hpm-stat"><?php echo $reactivations; ?></div>
                <p>Returning Users</p>
            </div>
            <div class="hpm-card">
                <h3>Promo Page</h3>
                <?php if ($promo_page_url): ?>
                    <div class="hpm-stat" style="font-size: 1.2em; margin-bottom: 10px;">
                        <a href="<?php echo esc_url($promo_page_url); ?>" target="_blank" class="button button-primary">View
                            Page</a>
                    </div>
                    <p>Template: Promo Countdown 12.12</p>
                <?php else: ?>
                    <form method="post">
                        <?php wp_nonce_field('hpm_create_page', 'hpm_create_page_nonce'); ?>
                        <button type="submit" name="hpm_create_page" class="button button-primary">Create Page</button>
                    </form>
                    <p>Auto-create page with template</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Code Assignment Mode Toggle (legacy — kept for reference, hidden in new UI) -->
        <?php if (false): ?>
        <div class="hpm-mode-toggle" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
            <h2 style="margin-top: 0;">Code Assignment Mode</h2>
            
            <?php $current_mode = $opts['code_assignment_mode'] ?? 'manual'; ?>
            
            <div style="background: <?php echo $current_mode === 'manual' ? '#e7f5fe' : '#fff4e5'; ?>; border-left: 4px solid <?php echo $current_mode === 'manual' ? '#2271b1' : '#dba617'; ?>; padding: 15px; margin-bottom: 15px;">
                <strong>Current Mode: <?php echo $current_mode === 'auto' ? 'Auto-Assign (Legacy)' : 'User-Entered Codes (SMART26)'; ?></strong>
                <p style="margin: 10px 0 0 0;">
                    <?php if ($current_mode === 'auto'): ?>
                        Codes are automatically assigned based on tier thresholds. Users do not enter codes manually.
                    <?php else: ?>
                        Users must enter valid promo codes during registration. Codes are validated against the list below.
                    <?php endif; ?>
                </p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div style="border: 2px solid <?php echo $current_mode === 'auto' ? '#2271b1' : '#ddd'; ?>; padding: 15px; border-radius: 8px; background: <?php echo $current_mode === 'auto' ? '#f0f6fc' : '#fff'; ?>;">
                    <h3 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-<?php echo $current_mode === 'auto' ? 'yes-alt' : 'marker'; ?>" style="color: <?php echo $current_mode === 'auto' ? '#2271b1' : '#999'; ?>;"></span>
                        Auto-Assign (Legacy)
                    </h3>
                    <ul style="margin: 10px 0; padding-left: 20px; color: #666;">
                        <li>Automatic tier-based assignment</li>
                        <li>Uses tier1_max threshold</li>
                        <li>Code: promo24 → promo12</li>
                        <li>No user input required</li>
                    </ul>
                    <?php if ($current_mode !== 'auto'): ?>
                        <button type="button" data-toggle-mode="auto" class="button hpm-mode-toggle-btn">Switch to Auto</button>
                    <?php else: ?>
                        <strong style="color: #2271b1;">✓ Active</strong>
                    <?php endif; ?>
                </div>

                <div style="border: 2px solid <?php echo $current_mode === 'manual' ? '#2271b1' : '#ddd'; ?>; padding: 15px; border-radius: 8px; background: <?php echo $current_mode === 'manual' ? '#f0f6fc' : '#fff'; ?>;">
                    <h3 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-<?php echo $current_mode === 'manual' ? 'yes-alt' : 'marker'; ?>" style="color: <?php echo $current_mode === 'manual' ? '#2271b1' : '#999'; ?>;"></span>
                        User-Entered Codes (SMART26)
                    </h3>
                    <ul style="margin: 10px 0; padding-left: 20px; color: #666;">
                        <li>Users enter promo codes manually</li>
                        <li>Per-code quota tracking</li>
                        <li>Dynamic code management</li>
                        <li>4-category eligibility validation</li>
                    </ul>
                    <?php if ($current_mode !== 'manual'): ?>
                        <button type="button" data-toggle-mode="manual" class="button hpm-mode-toggle-btn">Switch to SMART26</button>
                    <?php else: ?>
                        <strong style="color: #2271b1;">✓ Active</strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; // end mode toggle legacy section ?>

        <!-- Dynamic Code Management (legacy — removed in v1.0 — campaigns managed via Campaigns tab) -->
        <?php if (false): ?>
        <div class="hpm-code-management" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
            <h2 style="margin-top: 0;">Promo Codes Management (SMART26)</h2>
            
            <?php
            $code_stats = DB::get_code_stats();
            $code_usage_map = [];
            foreach ($code_stats as $stat) {
                $code_usage_map[$stat['promo_code']] = (int) $stat['count'];
            }
            
            $promo_codes = $opts['promo_codes'] ?? [];
            ?>
            
            <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th style="width: 180px;">Code</th>
                        <th>Description</th>
                        <th style="width: 70px;">Used</th>
                        <th style="width: 70px;">Max</th>
                        <th style="width: 90px;">Remaining</th>
                        <th style="width: 180px;">Progress</th>
                        <th style="width: 90px;">Status</th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="hpm-codes-list">
                    <?php foreach ($promo_codes as $code => $config): 
                        $usage = $code_usage_map[$code] ?? 0;
                        $max = $config['max'];
                        $remaining = max(0, $max - $usage);
                        $percent = $max > 0 ? ($usage / $max) * 100 : 0;
                        $active = $config['active'] ?? true;
                        
                        if ($percent >= 100) {
                            $bar_color = '#d63638';
                        } elseif ($percent >= 80) {
                            $bar_color = '#dba617';
                        } else {
                            $bar_color = '#00a32a';
                        }
                    ?>
                    <tr data-code="<?php echo esc_attr($code); ?>">
                        <td><strong><?php echo esc_html($code); ?></strong></td>
                        <td class="hpm-editable-desc" data-code="<?php echo esc_attr($code); ?>" contenteditable="true" style="cursor: text; border: 1px solid transparent; padding: 8px; border-radius: 3px;" title="Click to edit description"><?php echo esc_html($config['description'] ?? ''); ?></td>
                        <td class="code-used"><?php echo $usage; ?></td>
                        <td class="hpm-editable-quota" data-code="<?php echo esc_attr($code); ?>" data-usage="<?php echo $usage; ?>" contenteditable="true" style="cursor: text; border: 1px solid transparent; padding: 8px; border-radius: 3px; font-weight: bold;" title="Click to edit quota (min: current usage)"><?php echo $max; ?></td>
                        <td class="code-remaining"><strong><?php echo $remaining; ?></strong></td>
                        <td>
                            <div style="background: #f0f0f1; height: 24px; border-radius: 12px; overflow: hidden;">
                                <div style="background: <?php echo $bar_color; ?>; height: 100%; width: <?php echo $percent; ?>%; transition: width 0.3s;"></div>
                            </div>
                            <small><?php echo number_format($percent, 1); ?>%</small>
                        </td>
                        <td>
                            <span class="dashicons dashicons-<?php echo $active ? 'yes-alt' : 'archive'; ?>" style="color: <?php echo $active ? '#00a32a' : '#dba617'; ?>;"></span>
                            <strong style="color: <?php echo $active ? '#00a32a' : '#dba617'; ?>;"><?php echo $active ? 'Active' : 'Inactive'; ?></strong>
                        </td>
                        <td>
                            <button type="button" class="button button-small hpm-toggle-code" data-code="<?php echo esc_attr($code); ?>" data-active="<?php echo $active ? '1' : '0'; ?>" style="margin-bottom: 4px; width: 100%;">
                                <span class="dashicons dashicons-<?php echo $active ? 'visibility' : 'hidden'; ?>" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                <?php echo $active ? 'Deactivate' : 'Activate'; ?>
                            </button>
                            <button type="button" class="button button-small button-link-delete hpm-delete-code" data-code="<?php echo esc_attr($code); ?>" <?php echo $usage > 0 ? 'disabled title="Cannot delete code with existing redemptions"' : ''; ?> style="width: 100%;">
                                <span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 4px;">
                <h3 style="margin-top: 0;">Add/Edit Promo Code</h3>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label for="hpm_new_code_name" style="display: block; font-weight: 600; margin-bottom: 5px;">Code Name</label>
                        <input type="text" id="hpm_new_code_name" placeholder="SMART26-LIVE5" style="width: 100%;" />
                    </div>
                    <div>
                        <label for="hpm_new_code_desc" style="display: block; font-weight: 600; margin-bottom: 5px;">Description</label>
                        <input type="text" id="hpm_new_code_desc" placeholder="Live Session 5" style="width: 100%;" />
                    </div>
                    <div>
                        <label for="hpm_new_code_max" style="display: block; font-weight: 600; margin-bottom: 5px;">Max Quota</label>
                        <input type="number" id="hpm_new_code_max" value="50" min="1" style="width: 100%;" />
                    </div>
                </div>

                <button type="button" id="hpm-add-code-btn" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span> Add Code (Save below to persist)
                </button>
            </div>
        </div>
        <?php endif; // end legacy sections ?>

        <form method="post" action="options.php" id="hpm-settings-form">
            <?php settings_fields('hpm_settings_group');
            do_settings_sections('hpm_settings_group'); ?>
            
            <!-- Code assignment mode hidden field -->
            <input type="hidden" id="hpm-mode-field" name="home_promo_manager_settings[code_assignment_mode]" value="<?php echo esc_attr($current_mode); ?>" />
            
            <!-- Dynamic codes as hidden fields -->
            <?php foreach ($promo_codes as $code => $config): ?>
                <input type="hidden" name="home_promo_manager_settings[promo_codes][<?php echo esc_attr($code); ?>][max]" value="<?php echo esc_attr($config['max']); ?>" />
                <input type="hidden" name="home_promo_manager_settings[promo_codes][<?php echo esc_attr($code); ?>][description]" value="<?php echo esc_attr($config['description'] ?? ''); ?>" />
                <input type="hidden" name="home_promo_manager_settings[promo_codes][<?php echo esc_attr($code); ?>][active]" value="<?php echo $config['active'] ? '1' : '0'; ?>" />
            <?php endforeach; ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                            <th scope="row"><label for="hpm_start">Promo START (Asia/Kuala_Lumpur)</label></th>
                            <td>
                                <input name="home_promo_manager_settings[start]" type="text" id="hpm_start"
                                    value="<?php echo esc_attr($opts['start']); ?>" class="regular-text" />
                                <p class="description">Format: YYYY-MM-DD HH:MM:SS</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_end">Promo END (Asia/Kuala_Lumpur)</label></th>
                            <td>
                                <input name="home_promo_manager_settings[end]" type="text" id="hpm_end"
                                    value="<?php echo esc_attr($opts['end']); ?>" class="regular-text" />
                                <p class="description">Format: YYYY-MM-DD HH:MM:SS</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_timezone">Timezone</label></th>
                            <td>
                                <select name="home_promo_manager_settings[timezone]" id="hpm_timezone">
                                    <?php
                                    $tzlist = \DateTimeZone::listIdentifiers();
                                    foreach ($tzlist as $tz) {
                                        $selected = ($opts['timezone'] === $tz) ? 'selected' : '';
                                        echo "<option value='{$tz}' {$selected}>{$tz}</option>";
                                    }
                                    ?>
                                </select>
                                <p class="description">Timezone for Start/End times.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_form">Form ID</label></th>
                            <td><input name="home_promo_manager_settings[form_id]" type="number" id="hpm_form"
                                    value="<?php echo esc_attr($opts['form_id']); ?>" class="small-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_promo_field">Promo Field ID</label></th>
                            <td><input name="home_promo_manager_settings[promo_field_id]" type="number" id="hpm_promo_field"
                                    value="<?php echo esc_attr($opts['promo_field_id']); ?>" class="small-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_daftar_field">Daftar Field ID</label></th>
                            <td><input name="home_promo_manager_settings[daftar_field_id]" type="number"
                                    id="hpm_daftar_field" value="<?php echo esc_attr($opts['daftar_field_id']); ?>"
                                    class="small-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_daftar_trigger">Daftar Trigger Value</label></th>
                            <td>
                                <input name="home_promo_manager_settings[daftar_trigger_value]" type="text"
                                    id="hpm_daftar_trigger" value="<?php echo esc_attr($opts['daftar_trigger_value']); ?>"
                                    class="regular-text" />
                                <p class="description">Value to check against (e.g., 'Ya', 'Yes', '1').</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_status_field">Status Field ID</label></th>
                            <td>
                                <input name="home_promo_manager_settings[status_field_id]" type="number"
                                    id="hpm_status_field" value="<?php echo esc_attr($opts['status_field_id']); ?>"
                                    class="small-text" />
                                <p class="description">Field ID for client status (aktif=1, pasif=2). Example: 199</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_pasif_field">Pasif Date Field ID</label></th>
                            <td>
                                <input name="home_promo_manager_settings[pasif_date_field_id]" type="number"
                                    id="hpm_pasif_field" value="<?php echo esc_attr($opts['pasif_date_field_id']); ?>"
                                    class="small-text" />
                                <p class="description">Field ID for the date when client became pasif. Example: 1698</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_max">Max Slots</label></th>
                            <td><input name="home_promo_manager_settings[max]" type="number" id="hpm_max"
                                    value="<?php echo esc_attr($opts['max']); ?>" class="small-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_tier1">Tier1 Max</label></th>
                            <td><input name="home_promo_manager_settings[tier1_max]" type="number" id="hpm_tier1"
                                    value="<?php echo esc_attr($opts['tier1_max']); ?>" class="small-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_code1">Tier1 Code</label></th>
                            <td><input name="home_promo_manager_settings[code_tier1]" type="text" id="hpm_code1"
                                    value="<?php echo esc_attr($opts['code_tier1']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_code2">Tier2 Code</label></th>
                            <td><input name="home_promo_manager_settings[code_tier2]" type="text" id="hpm_code2"
                                    value="<?php echo esc_attr($opts['code_tier2']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_email">Admin Email</label></th>
                            <td><input name="home_promo_manager_settings[admin_email]" type="email" id="hpm_email"
                                    value="<?php echo esc_attr($opts['admin_email']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hpm_debug">Debug Mode</label></th>
                            <td>
                                <label>
                                    <input name="home_promo_manager_settings[debug_mode]" type="checkbox" id="hpm_debug"
                                        value="1" <?php checked(1, $opts['debug_mode']); ?> />
                                    Enable debug logging to error_log
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <h2>Manual Operations</h2>
            <form method="post">
                <?php wp_nonce_field('hpm_manual_ops', 'hpm_manual_nonce'); ?>
                <p>
                    <button type="submit" name="hpm_clear_count" class="button">Clear counted entries</button>
                    <span class="description">Use this to reset counted entries (for testing or after promo).</span>
                </p>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Toggle Mode Functionality - FIXED
            $('.hpm-mode-toggle-btn').on('click', function(e) {
                e.preventDefault();
                const mode = $(this).data('toggle-mode');
                const hiddenField = $('#hpm-mode-field');
                
                if (hiddenField.length && mode) {
                    hiddenField.val(mode);
                    const msg = mode === 'auto' ? 
                        'Switch to Auto-Assign (Legacy) mode?' :
                        'Switch to SMART26 (User-Entered Codes) mode?';
                    
                    if (confirm(msg + '\n\nClick OK to save and reload the page.')) {
                        $('#hpm-settings-form').submit();
                    }
                }
            });

            // Add Code Button Functionality
            $('#hpm-add-code-btn').on('click', function() {
                const codeName = $('#hpm_new_code_name').val().trim().toUpperCase();
                const codeDesc = $('#hpm_new_code_desc').val().trim();
                const codeMax = parseInt($('#hpm_new_code_max').val()) || 50;

                if (!codeName) {
                    alert('Please enter a code name');
                    return;
                }

                if (!/^[A-Z0-9\-]+$/i.test(codeName)) {
                    alert('Code name can only contain letters, numbers, and hyphens');
                    return;
                }

                // Check for duplicate
                if ($('[name*="[promo_codes][' + codeName + ']"]').length > 0) {
                    alert('Code "' + codeName + '" already exists!');
                    return;
                }

                // Create hidden fields for new code
                const form = $('#hpm-settings-form');
                $('<input>').attr({
                    type: 'hidden',
                    name: 'home_promo_manager_settings[promo_codes][' + codeName + '][max]',
                    value: codeMax
                }).appendTo(form);
                $('<input>').attr({
                    type: 'hidden',
                    name: 'home_promo_manager_settings[promo_codes][' + codeName + '][description]',
                    value: codeDesc
                }).appendTo(form);
                $('<input>').attr({
                    type: 'hidden',
                    name: 'home_promo_manager_settings[promo_codes][' + codeName + '][active]',
                    value: '1'
                }).appendTo(form);

                // Add row to visible table
                const newRow = `
                    <tr data-code="${codeName}" style="background: #fffbcc;">
                        <td><strong>${codeName}</strong> <span style="color: #d63638; font-size: 11px;">⚠ PENDING</span></td>
                        <td>${codeDesc}</td>
                        <td class="code-used">0</td>
                        <td>${codeMax}</td>
                        <td class="code-remaining"><strong>${codeMax}</strong></td>
                        <td>
                            <div style="background: #f0f0f1; height: 24px; border-radius: 12px; overflow: hidden;">
                                <div class="code-progress-bar" style="background: #00a32a; height: 100%; width: 0%; transition: width 0.3s;"></div>
                            </div>
                            <small class="code-percentage">0.0%</small>
                        </td>
                        <td>
                            <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                            <strong style="color: #d63638;">PENDING SAVE</strong>
                        </td>
                        <td>
                            <button type="button" class="button button-small" disabled>⚠ Save First</button>
                        </td>
                    </tr>
                `;
                $('#hpm-codes-list').append(newRow);

                // Clear form
                $('#hpm_new_code_name').val('');
                $('#hpm_new_code_desc').val('');
                $('#hpm_new_code_max').val('50');

                alert('Code "' + codeName + '" added!\n\n⚠ Click "Save Settings" below to activate it.');
            });

            // Inline Edit Description
            $('.hpm-editable-desc').on('focus', function() {
                $(this).css('border-color', '#2271b1');
                $(this).data('original-value', $(this).text());
            }).on('blur', function() {
                $(this).css('border-color', 'transparent');
                const code = $(this).data('code');
                const newDesc = $(this).text().trim();
                const originalValue = $(this).data('original-value');
                
                if (newDesc !== originalValue) {
                    $('[name="home_promo_manager_settings[promo_codes][' + code + '][description]"]').val(newDesc);
                    $(this).css('background', '#ffffcc');
                    setTimeout(() => {
                        $(this).css('background', '');
                    }, 2000);
                }
            }).on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $(this).blur();
                }
            });

            // Inline Edit Quota with Validation
            $('.hpm-editable-quota').on('focus', function() {
                $(this).css('border-color', '#2271b1');
                $(this).data('original-value', $(this).text());
            }).on('blur', function() {
                $(this).css('border-color', 'transparent');
                const code = $(this).data('code');
                const usage = parseInt($(this).data('usage')) || 0;
                let newQuota = parseInt($(this).text().trim());
                const originalValue = parseInt($(this).data('original-value'));
                
                // Validate: must be a number and >= current usage
                if (isNaN(newQuota) || newQuota < 1) {
                    alert('Invalid quota! Must be a positive number.');
                    $(this).text(originalValue);
                    return;
                }
                
                if (newQuota < usage) {
                    alert('Quota cannot be less than current usage (' + usage + ')!\n\nCurrent redemptions: ' + usage);
                    $(this).text(originalValue);
                    return;
                }
                
                if (newQuota !== originalValue) {
                    // Update hidden field
                    $('[name="home_promo_manager_settings[promo_codes][' + code + '][max]"]').val(newQuota);
                    
                    // Update remaining count
                    const remaining = newQuota - usage;
                    $(this).closest('tr').find('.code-remaining strong').text(remaining);
                    
                    // Update progress bar
                    const percent = usage > 0 ? (usage / newQuota) * 100 : 0;
                    const bar = $(this).closest('tr').find('.code-progress-bar');
                    bar.css('width', percent + '%');
                    
                    // Update bar color
                    let color = '#00a32a';
                    if (percent >= 100) color = '#d63638';
                    else if (percent >= 80) color = '#dba617';
                    bar.css('background', color);
                    
                    $(this).closest('tr').find('.code-percentage').text(percent.toFixed(1) + '%');
                    
                    // Visual feedback
                    $(this).css('background', '#ffffcc');
                    setTimeout(() => {
                        $(this).css('background', '');
                    }, 2000);
                    
                    alert('Quota for "' + code + '" updated to ' + newQuota + '!\n\n⚠ Click "Save Settings" to persist this change.');
                }
            }).on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $(this).blur();
                }
                // Allow only numbers, backspace, delete, arrows
                if (!((e.key >= '0' && e.key <= '9') || e.key === 'Backspace' || e.key === 'Delete' || e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Tab')) {
                    e.preventDefault();
                }
            });

            // Toggle Code Active/Inactive
            $(document).on('click', '.hpm-toggle-code', function() {
                const code = $(this).data('code');
                const currentActive = $(this).data('active') === '1' || $(this).data('active') === 1;
                const newActive = !currentActive;
                
                // Update hidden field
                $('[name="home_promo_manager_settings[promo_codes][' + code + '][active]"]').val(newActive ? '1' : '0');
                
                // Update button state
                $(this).data('active', newActive ? '1' : '0');
                $(this).find('.dashicons').removeClass('dashicons-visibility dashicons-hidden')
                    .addClass(newActive ? 'dashicons-visibility' : 'dashicons-hidden');
                $(this).html(
                    '<span class="dashicons dashicons-' + (newActive ? 'visibility' : 'hidden') + '" style="font-size: 14px; width: 14px; height: 14px;"></span> ' +
                    (newActive ? 'Deactivate' : 'Activate')
                );
                
                // Update status column
                const row = $(this).closest('tr');
                const statusCell = row.find('td:nth-child(7)');
                statusCell.html(
                    '<span class="dashicons dashicons-' + (newActive ? 'yes-alt' : 'archive') + '" style="color: ' + (newActive ? '#00a32a' : '#dba617') + ';"></span>' +
                    '<strong style="color: ' + (newActive ? '#00a32a' : '#dba617') + ';">' + (newActive ? 'Active' : 'Inactive') + '</strong>'
                );
                
                alert('Code "' + code + '" will be ' + (newActive ? 'activated' : 'deactivated') + ' when you click "Save Settings".');
            });

            // Delete Code
            $(document).on('click', '.hpm-delete-code', function() {
                if ($(this).prop('disabled')) {
                    alert('Cannot delete codes that have existing redemptions.\n\nDeactivate the code instead to prevent new registrations.');
                    return;
                }
                
                const code = $(this).data('code');
                
                if (!confirm('Delete code "' + code + '"?\n\nThis action cannot be undone.')) {
                    return;
                }
                
                // Remove hidden fields
                $('[name^="home_promo_manager_settings[promo_codes][' + code + ']"]').remove();
                
                // Remove table row
                $(this).closest('tr').fadeOut(300, function() {
                    $(this).remove();
                });
                
                alert('Code "' + code + '" removed!\n\nClick "Save Settings" to persist this change.');
            });

            // Realtime Stats Update (every 5 seconds)
            let realtimeUpdateInterval;
            
            function updateRealtimeStats() {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'hpm_get_realtime_stats'
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            const stats = response.data;
                            
                            // Update each code row
                            $.each(stats.codes, function(code, data) {
                                const row = $('tr[data-code="' + code + '"]');
                                if (row.length) {
                                    row.find('.code-used').text(data.used);
                                    row.find('.code-remaining strong').text(data.remaining);
                                    row.find('.code-percentage').text(data.percentage.toFixed(1) + '%');
                                    
                                    // Update progress bar
                                    const bar = row.find('.code-progress-bar');
                                    bar.css('width', data.percentage + '%');
                                    
                                    // Update bar color
                                    let color = '#00a32a';
                                    if (data.percentage >= 100) color = '#d63638';
                                    else if (data.percentage >= 80) color = '#dba617';
                                    bar.css('background', color);
                                }
                            });
                            
                            // Update dashboard if present
                            if (stats.total) {
                                $('.hpm-dashboard .hpm-card:nth-child(2) .hpm-stat').text(stats.total.used + ' / ' + stats.total.max);
                                $('.hpm-dashboard .hpm-card:nth-child(2) .hpm-bar').css('width', stats.total.percentage + '%');
                            }
                        }
                    },
                    error: function() {
                        console.log('[HPM] Realtime update failed');
                    }
                });
            }
            
            // Start realtime updates
            realtimeUpdateInterval = setInterval(updateRealtimeStats, 5000);
            
            // Initial update
            setTimeout(updateRealtimeStats, 1000);
        });
        </script>
        <?php
}

// =========================================================================
// Tab router + Campaigns tab — added for Campaign Engine v1.0
// =========================================================================

function hpm_admin_guard(): void {
    if (!current_user_can(CampaignEngine::CAP)) {
        wp_die(__('Insufficient permissions.', 'home-promo-manager'), 403);
    }
}

function hpm_render_admin_page(): void {
    hpm_admin_guard();
    $tab = sanitize_text_field($_GET['tab'] ?? 'campaigns');
    echo '<div class="wrap"><h1>HOME Promo Manager</h1>';
    echo '<nav class="nav-tab-wrapper">';
    echo '<a href="?page=home-promo-manager&tab=campaigns" class="nav-tab'
        . ($tab === 'campaigns' ? ' nav-tab-active' : '') . '">Campaigns</a>';
    echo '<a href="?page=home-promo-manager&tab=settings" class="nav-tab'
        . ($tab === 'settings' ? ' nav-tab-active' : '') . '">Settings</a>';
    echo '</nav>';
    if ($tab === 'campaigns') hpm_render_campaigns_tab();
    else hpm_render_settings_tab();
    echo '</div>';
}

function hpm_render_campaigns_tab(): void {
    global $wpdb;
    hpm_admin_guard();
    hpm_handle_campaign_actions();

    $campaigns = $wpdb->get_results(
        "SELECT c.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted cc WHERE cc.campaign_id = c.id) AS used_count,
                a.campaign_id AS is_pointed
           FROM {$wpdb->prefix}home_promo_campaigns c
      LEFT JOIN {$wpdb->prefix}home_promo_active a ON a.singleton = 1
          ORDER BY c.id DESC"
    );

    echo '<h2>Campaigns</h2>';
    echo '<a href="?page=home-promo-manager&tab=campaigns&action=new" class="page-title-action">Add New</a>';
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
    echo '<th>Name</th><th>Slug</th><th>Mode</th><th>Status</th><th>Dates (UTC)</th><th>Quota</th><th>Used</th><th>Actions</th>';
    echo '</tr></thead><tbody>';
    foreach ((array) $campaigns as $c) {
        $is_active_ptr = ($c->is_pointed == $c->id);
        $live_badge    = $is_active_ptr ? '<strong>[LIVE]</strong> ' : '';
        printf(
            '<tr><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s – %s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
            $live_badge,
            esc_html($c->name),
            esc_html($c->slug),
            esc_html($c->mode),
            esc_html($c->status),
            esc_html(wp_date('Y-m-d H:i', strtotime($c->start_date . ' UTC'))),
            esc_html(wp_date('Y-m-d H:i', strtotime($c->end_date   . ' UTC'))),
            (int) $c->quota,
            (int) $c->used_count,
            hpm_campaign_actions_html((int) $c->id, $c->status, $is_active_ptr)
        );
    }
    echo '</tbody></table>';

    $action = sanitize_text_field($_GET['action'] ?? '');
    if ($action === 'new') hpm_render_campaign_form(null);
    if ($action === 'edit') {
        $id  = (int) ($_GET['campaign_id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}home_promo_campaigns WHERE id = %d", $id
        ));
        if ($row) hpm_render_campaign_form($row);
    }
}

function hpm_campaign_actions_html(int $id, string $status, bool $is_active_ptr): string {
    $base = '?page=home-promo-manager&tab=campaigns';
    $html = "<a href='{$base}&action=edit&campaign_id={$id}'>Edit</a> | ";
    if (!$is_active_ptr) {
        $html .= "<a href='{$base}&action=activate&campaign_id={$id}'>Activate</a> | ";
    } else {
        $html .= "<a href='{$base}&action=deactivate&campaign_id={$id}'>Deactivate</a> | ";
    }
    $html .= "<a href='{$base}&action=delete&campaign_id={$id}' onclick='return confirm(\"Delete?\")'>Delete</a>";
    return $html;
}

function hpm_render_campaign_form(?object $c): void {
    $is_edit = ($c !== null);
    $action  = $is_edit ? 'save_edit' : 'save_new';
    echo '<hr><h3>' . ($is_edit ? 'Edit Campaign' : 'New Campaign') . '</h3>';
    echo '<form method="post" action="?page=home-promo-manager&tab=campaigns">';
    wp_nonce_field('hpm_campaign_save');
    echo '<input type="hidden" name="hpm_action" value="' . esc_attr($action) . '">';
    if ($is_edit) {
        echo '<input type="hidden" name="campaign_id" value="' . (int) $c->id . '">';
    }
    $sd = $is_edit ? wp_date('Y-m-d H:i:s', strtotime($c->start_date . ' UTC')) : '';
    $ed = $is_edit ? wp_date('Y-m-d H:i:s', strtotime($c->end_date   . ' UTC')) : '';
    ?>
    <table class="form-table">
      <tr><th>Name</th><td><input name="name" value="<?= esc_attr($c->name ?? '') ?>" class="regular-text" required></td></tr>
      <tr><th>Slug</th><td><input name="slug" value="<?= esc_attr($c->slug ?? '') ?>" class="regular-text"></td></tr>
      <tr><th>Mode</th><td>
        <select name="mode">
          <option value="auto"   <?= ($c->mode ?? '') === 'auto'   ? 'selected' : '' ?>>Auto</option>
          <option value="manual" <?= ($c->mode ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
        </select>
      </td></tr>
      <tr><th>Start Date (site tz)</th><td><input type="datetime-local" name="start_date" value="<?= esc_attr(str_replace(' ', 'T', $sd)) ?>"></td></tr>
      <tr><th>End Date (site tz)</th><td><input type="datetime-local" name="end_date" value="<?= esc_attr(str_replace(' ', 'T', $ed)) ?>"></td></tr>
      <tr><th>Quota</th><td><input type="number" name="quota" value="<?= (int)($c->quota ?? 0) ?>" min="1"></td></tr>
      <tr><th>Discount (RM)</th><td><input type="number" name="discount_amount" value="<?= esc_attr($c->discount_amount ?? '') ?>" step="0.01" min="0.01" max="999999.99"></td></tr>
      <tr><th>Campaign Code (auto)</th><td><input name="campaign_code" value="<?= esc_attr($c->campaign_code ?? '') ?>" maxlength="40"></td></tr>
      <tr><th>Codes Config (manual, JSON)</th><td><textarea name="codes_config" rows="3" cols="50"><?= esc_textarea($c->codes_config ?? '') ?></textarea></td></tr>
    </table>
    <p><button type="submit" class="button button-primary">Save Campaign</button></p>
    </form>
    <?php
}

function hpm_handle_campaign_actions(): void {
    global $wpdb;
    $hpm_action = sanitize_text_field($_POST['hpm_action'] ?? $_GET['action'] ?? '');
    if (!$hpm_action) return;
    if (!in_array($hpm_action, ['save_new','save_edit','activate','deactivate','delete'], true)) return;

    hpm_admin_guard();
    check_admin_referer('hpm_campaign_save');

    if ($hpm_action === 'save_new' || $hpm_action === 'save_edit') {
        $data  = hpm_sanitise_campaign_fields($_POST);
        $error = hpm_validate_campaign_fields($data, $hpm_action === 'save_edit' ? (int)($_POST['campaign_id'] ?? 0) : null);
        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            return;
        }
        if ($hpm_action === 'save_new') {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}home_promo_campaigns
                   (name,slug,status,mode,start_date,end_date,quota,discount_amount,campaign_code,codes_config,created_at,updated_at)
                 VALUES (%s,%s,'draft',%s,%s,%s,%d,%f,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                $data['name'], $data['slug'], $data['mode'],
                $data['start_date'], $data['end_date'],
                $data['quota'], $data['discount_amount'],
                $data['campaign_code'], $data['codes_config']
            ));
        } else {
            $id = (int) ($_POST['campaign_id'] ?? 0);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}home_promo_campaigns
                    SET name=%s,slug=%s,mode=%s,start_date=%s,end_date=%s,
                        quota=%d,discount_amount=%f,campaign_code=%s,codes_config=%s,
                        updated_at=UTC_TIMESTAMP()
                  WHERE id=%d",
                $data['name'], $data['slug'], $data['mode'],
                $data['start_date'], $data['end_date'],
                $data['quota'], $data['discount_amount'],
                $data['campaign_code'], $data['codes_config'], $id
            ));
            CampaignEngine::flush();
        }
        echo '<div class="notice notice-success"><p>Campaign saved.</p></div>';
        return;
    }

    $campaign_id = (int) ($_GET['campaign_id'] ?? 0);
    $user_id     = get_current_user_id();

    if ($hpm_action === 'activate') {
        $result = CampaignEngine::activate($campaign_id, $user_id);
        if ($result['status'] === 'conflict') {
            echo '<div class="notice notice-error"><p>'
                . esc_html("Campaign #{$result['conflict_id']} is already active. Deactivate it first.")
                . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Campaign activated.</p></div>';
        }
    }

    if ($hpm_action === 'deactivate') {
        CampaignEngine::deactivate($campaign_id, $user_id);
        echo '<div class="notice notice-success"><p>Campaign deactivated.</p></div>';
    }

    if ($hpm_action === 'delete') {
        $pointed = (int) $wpdb->get_var(
            "SELECT campaign_id FROM {$wpdb->prefix}home_promo_active WHERE singleton=1"
        );
        if ($pointed === $campaign_id) {
            echo '<div class="notice notice-error"><p>Cannot delete the active campaign. Deactivate first.</p></div>';
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}home_promo_campaigns WHERE id = %d", $campaign_id
        ));
        CampaignEngine::flush();
        echo '<div class="notice notice-success"><p>Campaign deleted.</p></div>';
    }
}

function hpm_sanitise_campaign_fields(array $post): array {
    $mode             = sanitize_text_field($post['mode'] ?? '');
    $raw_codes_config = $post['codes_config'] ?? '';
    $codes_config_encoded = null;
    if ($mode === 'manual' && !empty($raw_codes_config)) {
        try {
            $decoded = json_decode($raw_codes_config, true, 512, JSON_THROW_ON_ERROR);
            $codes_config_encoded = wp_json_encode($decoded);
        } catch (\JsonException $e) {
            $codes_config_encoded = '__INVALID_JSON__';
        }
    }

    $sd_input = sanitize_text_field(str_replace('T', ' ', $post['start_date'] ?? ''));
    $ed_input = sanitize_text_field(str_replace('T', ' ', $post['end_date'] ?? ''));
    $sd_utc   = hpm_local_to_utc($sd_input);
    $ed_utc   = hpm_local_to_utc($ed_input);

    return [
        'name'            => sanitize_text_field($post['name'] ?? ''),
        'slug'            => sanitize_title($post['slug'] ?? sanitize_text_field($post['name'] ?? '')),
        'mode'            => $mode,
        'start_date'      => $sd_utc,
        'end_date'        => $ed_utc,
        'quota'           => absint($post['quota'] ?? 0),
        'discount_amount' => (float) ($post['discount_amount'] ?? 0),
        'campaign_code'   => ($mode === 'auto') ? substr(sanitize_text_field($post['campaign_code'] ?? ''), 0, 40) : null,
        'codes_config'    => ($mode === 'manual') ? $codes_config_encoded : null,
    ];
}

function hpm_local_to_utc(string $local_datetime): string {
    try {
        $dt = new \DateTime($local_datetime, wp_timezone());
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        return '';
    }
}

function hpm_validate_campaign_fields(array $data, ?int $edit_id): ?string {
    global $wpdb;

    if (empty($data['name']))                           return 'Name is required.';

    $slug = $data['slug'];
    if (empty($slug))                                   return 'Slug could not be generated. Please enter a manual slug using Latin characters.';
    if (strlen($slug) < 3)                              return 'Slug must be at least 3 characters long.';
    if (strlen($slug) > 80)                             return 'Slug must be 80 characters or fewer.';

    $dup = $edit_id
        ? $wpdb->prepare("SELECT id FROM {$wpdb->prefix}home_promo_campaigns WHERE slug=%s AND id<>%d", $slug, $edit_id)
        : $wpdb->prepare("SELECT id FROM {$wpdb->prefix}home_promo_campaigns WHERE slug=%s", $slug);
    if ($wpdb->get_var($dup))                           return 'A campaign with this slug already exists.';

    if (!in_array($data['mode'], ['auto','manual'], true)) return 'Invalid mode.';
    if (empty($data['start_date']))                     return 'Start date is required.';
    if (empty($data['end_date']))                       return 'End date is required.';
    if ($data['end_date'] <= $data['start_date'])       return 'End date must be after start date.';
    if ($data['quota'] === 0)                           return 'Quota must be at least 1.';
    if ($data['discount_amount'] <= 0)                  return 'Discount must be greater than 0.';
    if ($data['discount_amount'] > 999999.99)           return 'Discount exceeds maximum (RM 999,999.99).';

    if ($data['mode'] === 'auto' && empty($data['campaign_code'])) {
        return 'Campaign code is required for auto mode.';
    }
    if ($data['mode'] === 'manual') {
        if ($data['codes_config'] === '__INVALID_JSON__') return 'Codes config must be valid JSON.';
        if (empty($data['codes_config']))                return 'Codes config is required for manual mode.';
    }
    if ($data['mode'] === 'auto' && !empty($data['codes_config'])) {
        return 'Codes config must be empty for auto mode.';
    }
    if ($data['mode'] === 'manual' && !empty($data['campaign_code'])) {
        return 'Campaign code must be empty for manual mode.';
    }

    return null;
}