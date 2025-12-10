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
 * Sanitize incoming settings array
 *
 * @param array $input
 * @return array sanitized
 */
function sanitize_settings($input)
{
    $defaults = get_option('home_promo_manager_settings', []);
    $out = [];

    $out['start'] = sanitize_text_field($input['start'] ?? ($defaults['start'] ?? '2025-12-01 12:00:00'));
    $out['end'] = sanitize_text_field($input['end'] ?? ($defaults['end'] ?? '2025-12-24 23:59:00'));
    $out['timezone'] = sanitize_text_field($input['timezone'] ?? ($defaults['timezone'] ?? 'Asia/Kuala_Lumpur'));

    $out['form_id'] = isset($input['form_id']) ? absint($input['form_id']) : absint($defaults['form_id'] ?? 13);
    $out['promo_field_id'] = isset($input['promo_field_id']) ? absint($input['promo_field_id']) : absint($defaults['promo_field_id'] ?? 3170);
    $out['daftar_field_id'] = isset($input['daftar_field_id']) ? absint($input['daftar_field_id']) : absint($defaults['daftar_field_id'] ?? 196);
    $out['daftar_trigger_value'] = sanitize_text_field($input['daftar_trigger_value'] ?? ($defaults['daftar_trigger_value'] ?? 'Ya'));

    $out['status_field_id'] = isset($input['status_field_id']) ? absint($input['status_field_id']) : absint($defaults['status_field_id'] ?? 209);
    $out['pasif_date_field_id'] = isset($input['pasif_date_field_id']) ? absint($input['pasif_date_field_id']) : absint($defaults['pasif_date_field_id'] ?? 1698);

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

    ?>
    <div class="wrap">
        <h1>HOME Promo Manager</h1>
        <form method="post" action="options.php">
            <?php settings_fields('hpm_settings_group');
            do_settings_sections('hpm_settings_group'); ?>
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
                        <td><input name="home_promo_manager_settings[daftar_field_id]" type="number" id="hpm_daftar_field"
                                value="<?php echo esc_attr($opts['daftar_field_id']); ?>" class="small-text" /></td>
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
                            <input name="home_promo_manager_settings[status_field_id]" type="number" id="hpm_status_field"
                                value="<?php echo esc_attr($opts['status_field_id']); ?>" class="small-text" />
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