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

// Register admin menu and settings
add_action('admin_menu', function () {
    add_options_page(
        'HOME Promo Manager',
        'HOME Promo Manager',
        'manage_options',
        'home-promo-manager',
        '\\HPM\\render_admin_page'
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
        // Keep existing codes if not updated
        $out['promo_codes'] = $defaults['promo_codes'] ?? [
            'SMART26-LIVE1' => ['max' => 50, 'description' => 'Live Session 1', 'active' => true],
            'SMART26-LIVE2' => ['max' => 50, 'description' => 'Live Session 2', 'active' => true],
            'SMART26-LIVE3' => ['max' => 50, 'description' => 'Live Session 3', 'active' => true],
            'SMART26-LIVE4' => ['max' => 50, 'description' => 'Live Session 4', 'active' => true],
        ];
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
 * Render the settings page
 */
function render_admin_page()
{
    if (!current_user_can('manage_options'))
        wp_die('Insufficient permissions');

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
    <div class="wrap">
        <h1>HOME Promo Manager</h1>

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
        $count = $mgr->get_count();
        $max = (int) $opts['max'];
        $percent = $max > 0 ? min(100, ($count / $max) * 100) : 0;
        $is_active = $mgr->is_active();
        $status_text = $is_active ? 'Active' : 'Inactive';
        $status_class = $is_active ? 'hpm-status-active' : 'hpm-status-inactive';
        $reactivations = DB::count_reactivations();
        $tier1 = (int) $opts['tier1_max'];
        $current_tier = ($count < $tier1) ? 'Tier 1' : 'Tier 2';

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
                <h3>Current Tier</h3>
                <div class="hpm-stat"><?php echo $current_tier; ?></div>
                <p>Tier 1 Limit: <?php echo $tier1; ?></p>
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

        <!-- SMART26: Code Assignment Mode Toggle -->
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
                        <button type="button" onclick="document.getElementById('mode_auto').checked=true; this.closest('form').querySelector('[name=\"home_promo_manager_settings[code_assignment_mode]\"]').value='auto'; alert('Mode changed to Auto-Assign. Click Save Changes below.');" class="button">Switch to Auto</button>
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
                        <button type="button" onclick="document.getElementById('mode_manual').checked=true; this.closest('form').querySelector('[name=\"home_promo_manager_settings[code_assignment_mode]\"]').value='manual'; alert('Mode changed to SMART26. Click Save Changes below.');" class="button">Switch to SMART26</button>
                    <?php else: ?>
                        <strong style="color: #2271b1;">✓ Active</strong>
                    <?php endif; ?>
                </div>
            </div>

            <input type="hidden" name="home_promo_manager_settings[code_assignment_mode]" value="<?php echo esc_attr($current_mode); ?>" />
        </div>

        <!-- SMART26: Dynamic Code Management Section -->
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
                        <th style="width: 200px;">Code</th>
                        <th>Description</th>
                        <th style="width: 80px;">Used</th>
                        <th style="width: 80px;">Max</th>
                        <th style="width: 100px;">Remaining</th>
                        <th style="width: 200px;">Progress</th>
                        <th style="width: 100px;">Status</th>
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
                        <td><?php echo esc_html($config['description'] ?? ''); ?></td>
                        <td><?php echo $usage; ?></td>
                        <td><?php echo $max; ?></td>
                        <td><strong><?php echo $remaining; ?></strong></td>
                        <td>
                            <div style="background: #f0f0f1; height: 24px; border-radius: 12px; overflow: hidden;">
                                <div style="background: <?php echo $bar_color; ?>; height: 100%; width: <?php echo $percent; ?>%; transition: width 0.3s;"></div>
                            </div>
                            <small><?php echo number_format($percent, 1); ?>%</small>
                        </td>
                        <td>
                            <span class="dashicons dashicons-<?php echo $active ? 'yes-alt' : 'archive'; ?>" style="color: <?php echo $active ? '#00a32a' : '#dba617'; ?>;"></span>
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

        <form method="post" action="options.php">
            <?php settings_fields('hpm_settings_group');
            do_settings_sections('hpm_settings_group'); ?>
            
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
        <?php
}