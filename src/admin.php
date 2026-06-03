<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

/**
 * Admin UI for HOME Promo Manager
 * Tabs: Campaigns | Settings | Logs | Debug
 */

// ---------------------------------------------------------------------------
// AJAX: realtime stats (existing)
// ---------------------------------------------------------------------------
add_action('wp_ajax_hpm_get_realtime_stats', function () {
    if (!current_user_can(CampaignEngine::CAP)) {
        wp_send_json_error('Insufficient permissions.', 403);
    }
    check_ajax_referer('hpm_realtime_stats');

    $code_stats_array = DB::get_code_stats();
    $mgr              = Manager::get_instance();
    $promo_codes      = $mgr->s('promo_codes') ?: [];

    $code_usage_map = [];
    foreach ($code_stats_array as $stat) {
        $code_usage_map[$stat['promo_code']] = (int) $stat['count'];
    }

    $codes_data = [];
    $total_used = 0;
    $total_max  = 0;

    foreach ($promo_codes as $code => $config) {
        if (!($config['active'] ?? true)) continue;
        $used       = $code_usage_map[$code] ?? 0;
        $max        = (int) ($config['max'] ?? 0);
        $remaining  = max(0, $max - $used);
        $percentage = $max > 0 ? ($used / $max) * 100 : 0;
        $codes_data[$code] = compact('used', 'max', 'remaining', 'percentage');
        $total_used += $used;
        $total_max  += $max;
    }

    wp_send_json_success([
        'codes' => $codes_data,
        'total' => [
            'used'       => $total_used,
            'max'        => $total_max,
            'remaining'  => max(0, $total_max - $total_used),
            'percentage' => $total_max > 0 ? ($total_used / $total_max) * 100 : 0,
        ],
    ]);
});

// ---------------------------------------------------------------------------
// AJAX: save debug mode toggle
// ---------------------------------------------------------------------------
add_action('wp_ajax_hpm_save_debug_toggle', function () {
    if (!current_user_can(CampaignEngine::CAP)) {
        wp_send_json_error('Insufficient permissions.', 403);
    }
    check_ajax_referer('hpm_debug_toggle', '_nonce');
    $enabled  = !empty($_POST['enabled']) && $_POST['enabled'] === '1';
    $opts     = get_option('home_promo_manager_settings', []);
    $opts['debug_mode'] = $enabled;
    update_option('home_promo_manager_settings', $opts);
    wp_send_json_success(['debug_mode' => $enabled]);
});

// ---------------------------------------------------------------------------
// AJAX: eligibility tester
// ---------------------------------------------------------------------------
add_action('wp_ajax_hpm_test_eligibility', function () {
    if (!current_user_can(CampaignEngine::CAP)) {
        wp_send_json_error('Insufficient permissions.', 403);
    }
    check_ajax_referer('hpm_test_eligibility', '_nonce');

    $entry_id = absint($_POST['entry_id'] ?? 0);
    if (!$entry_id) {
        wp_send_json_error('Invalid entry ID.', 400);
    }

    $opts            = get_option('home_promo_manager_settings', []);
    $daftar_field    = (int) ($opts['daftar_field_id']        ?? 196);
    $trigger_value   = $opts['daftar_trigger_value']          ?? 'Ya';
    $pasif_threshold = (int) ($opts['passive_threshold_days'] ?? 90);

    global $wpdb;

    $daftar = \ff_get_field_value_robust($entry_id, $daftar_field);

    // Most recent Pasif transition
    $went_pasif_at = $wpdb->get_var($wpdb->prepare(
        "SELECT logged_at FROM {$wpdb->prefix}home_promo_status_log
          WHERE entry_id = %d AND to_status = 'Pasif'
          ORDER BY logged_at DESC LIMIT 1",
        $entry_id
    )) ?: null;

    // Sentinel date from backfill = no real pasif date
    if ($went_pasif_at === '1970-01-01 00:00:00') {
        $went_pasif_at = null;
    }

    $pasif_days = null;
    if ($went_pasif_at !== null) {
        $diff       = (new \DateTime('now', new \DateTimeZone('UTC')))
                          ->diff(new \DateTime($went_pasif_at, new \DateTimeZone('UTC')));
        $pasif_days = (int) $diff->days;
    }

    // prev_daftar='' is intentional: the tester cannot reconstruct the historical field value
    // before an update, so we assume a fresh transition (optimistic/permissive result).
    $ctx = (object) [
        'event'           => 'updated',
        'daftar'          => $daftar,
        'daftar_trigger'  => $trigger_value,
        'prev_daftar'     => '',
        'went_pasif_at'   => $went_pasif_at,
        'pasif_days'      => $pasif_days,
        'pasif_threshold' => $pasif_threshold,
    ];

    $specs   = [
        'NewSpec'          => new NewSpec(),
        'DiagnosedSpec'    => new DiagnosedSpec(),
        'ReactivationSpec' => new ReactivationSpec(),
    ];
    $results = [];
    foreach ($specs as $name => $spec) {
        $outcome   = $spec->isSatisfied($ctx);
        $results[] = [
            'name'     => $name,
            'passed'   => $outcome !== false,
            'category' => $outcome !== false ? $outcome : null,
        ];
    }

    wp_send_json_success([
        'ctx'   => (array) $ctx,
        'specs' => $results,
    ]);
});

// ---------------------------------------------------------------------------
// Register admin menu + settings
// ---------------------------------------------------------------------------
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
        'sanitize_callback' => '\\HPM\\sanitize_settings',
    ]);
});

// ---------------------------------------------------------------------------
// Settings sanitizer (unchanged)
// ---------------------------------------------------------------------------
function sanitize_settings($input)
{
    $defaults = get_option('home_promo_manager_settings', []);
    $out      = [];

    $out['start']               = sanitize_text_field($input['start']               ?? ($defaults['start']               ?? '2026-01-12 12:00:00'));
    $out['end']                 = sanitize_text_field($input['end']                 ?? ($defaults['end']                 ?? '2026-01-14 11:59:00'));
    $out['timezone']            = sanitize_text_field($input['timezone']            ?? ($defaults['timezone']            ?? 'Asia/Kuala_Lumpur'));
    $out['debug_mode']          = isset($input['debug_mode']) ? (bool) $input['debug_mode'] : false;

    $out['form_id']             = isset($input['form_id'])             ? absint($input['form_id'])             : absint($defaults['form_id']             ?? 13);
    $out['promo_field_id']      = isset($input['promo_field_id'])      ? absint($input['promo_field_id'])      : absint($defaults['promo_field_id']      ?? 3170);
    $out['daftar_field_id']     = isset($input['daftar_field_id'])     ? absint($input['daftar_field_id'])     : absint($defaults['daftar_field_id']     ?? 196);
    $out['daftar_trigger_value']= sanitize_text_field($input['daftar_trigger_value'] ?? ($defaults['daftar_trigger_value'] ?? 'Ya'));
    $out['status_field_id']     = isset($input['status_field_id'])     ? absint($input['status_field_id'])     : absint($defaults['status_field_id']     ?? 199);
    $out['pasif_date_field_id'] = isset($input['pasif_date_field_id']) ? absint($input['pasif_date_field_id']) : absint($defaults['pasif_date_field_id'] ?? 1698);

    $out['diagnostic_date_field_id'] = isset($input['diagnostic_date_field_id']) ? absint($input['diagnostic_date_field_id']) : absint($defaults['diagnostic_date_field_id'] ?? 0);
    $out['lead_status_field_id']     = isset($input['lead_status_field_id'])     ? absint($input['lead_status_field_id'])     : absint($defaults['lead_status_field_id']     ?? 0);
    $out['branch_field_id']          = isset($input['branch_field_id'])          ? absint($input['branch_field_id'])          : absint($defaults['branch_field_id']          ?? 0);
    $out['passive_threshold_days']   = isset($input['passive_threshold_days'])   ? absint($input['passive_threshold_days'])   : absint($defaults['passive_threshold_days']   ?? 90);

    $out['code_assignment_mode'] = sanitize_text_field($input['code_assignment_mode'] ?? ($defaults['code_assignment_mode'] ?? 'manual'));
    if (!in_array($out['code_assignment_mode'], ['auto', 'manual'])) {
        $out['code_assignment_mode'] = 'manual';
    }

    if (isset($input['promo_codes']) && is_array($input['promo_codes'])) {
        $out['promo_codes'] = [];
        foreach ($input['promo_codes'] as $code => $config) {
            $sanitized_code = sanitize_text_field($code);
            if (!empty($sanitized_code)) {
                $out['promo_codes'][$sanitized_code] = [
                    'max'         => isset($config['max']) ? absint($config['max']) : 50,
                    'description' => sanitize_text_field($config['description'] ?? ''),
                    'active'      => isset($config['active']) ? (bool) $config['active'] : true,
                ];
            }
        }
    } else {
        $out['promo_codes'] = $defaults['promo_codes'] ?? DB::get_default_promo_codes();
    }

    $out['base_price']      = isset($input['base_price'])      ? floatval($input['base_price'])      : floatval($defaults['base_price']      ?? 200.00);
    $out['discount_amount'] = isset($input['discount_amount']) ? floatval($input['discount_amount']) : floatval($defaults['discount_amount'] ?? 52.00);
    $out['final_price']     = isset($input['final_price'])     ? floatval($input['final_price'])     : floatval($defaults['final_price']     ?? 148.00);

    $total_max = 0;
    foreach ($out['promo_codes'] as $config) {
        if ($config['active']) $total_max += $config['max'];
    }
    $out['total_max'] = $total_max;

    // Legacy
    $out['max']        = isset($input['max'])        ? absint($input['max'])                             : absint($defaults['max']        ?? 480);
    $out['tier1_max']  = isset($input['tier1_max'])  ? absint($input['tier1_max'])                       : absint($defaults['tier1_max']  ?? 240);
    $out['code_tier1'] = sanitize_text_field($input['code_tier1'] ?? ($defaults['code_tier1'] ?? 'promo24'));
    $out['code_tier2'] = sanitize_text_field($input['code_tier2'] ?? ($defaults['code_tier2'] ?? 'promo12'));

    $out['admin_email'] = sanitize_email($input['admin_email'] ?? ($defaults['admin_email'] ?? get_option('admin_email')));

    return $out;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function hpm_admin_guard(): void
{
    if (!current_user_can(CampaignEngine::CAP)) {
        wp_die(__('Insufficient permissions.', 'home-promo-manager'), 403);
    }
}

function hpm_pagination_links(int $current, int $total_pages, array $extra_params): string
{
    $base = admin_url('options-general.php?' . http_build_query(
        array_merge(['page' => 'home-promo-manager', 'tab' => 'logs'], $extra_params)
    ));
    if ($total_pages <= 1) return '';
    $html = '<div class="tablenav bottom"><div class="tablenav-pages">'
          . '<span class="displaying-num">' . number_format_i18n($total_pages) . ' pages</span> ';
    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i === $current) {
            $html .= "<span class='page-numbers current'>{$i}</span> ";
        } else {
            $url   = esc_url(add_query_arg('log_page', $i, $base));
            $html .= "<a href='{$url}' class='page-numbers'>{$i}</a> ";
        }
    }
    return $html . '</div></div>';
}

// ---------------------------------------------------------------------------
// Tab router
// ---------------------------------------------------------------------------
function hpm_render_admin_page(): void
{
    hpm_admin_guard();
    $tab  = sanitize_text_field($_GET['tab'] ?? 'campaigns');
    $tabs = [
        'campaigns' => 'Campaigns',
        'settings'  => 'Settings',
        'logs'      => 'Logs',
        'debug'     => 'Debug',
    ];
    echo '<div class="wrap"><h1>HOME Promo Manager</h1>';
    echo '<nav class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $active = ($tab === $slug) ? ' nav-tab-active' : '';
        printf(
            '<a href="?page=home-promo-manager&tab=%s" class="nav-tab%s">%s</a>',
            esc_attr($slug), $active, esc_html($label)
        );
    }
    echo '</nav>';
    match ($tab) {
        'settings' => hpm_render_settings_tab(),
        'logs'     => hpm_render_logs_tab(),
        'debug'    => hpm_render_debug_tab(),
        default    => hpm_render_campaigns_tab(),
    };
    echo '</div>';
}

// ---------------------------------------------------------------------------
// Tab: Campaigns
// ---------------------------------------------------------------------------
function hpm_render_campaigns_tab(): void
{
    global $wpdb;
    hpm_admin_guard();
    hpm_handle_campaign_actions();

    // ── Active Spotlight ────────────────────────────────────────────────────
    $active = CampaignEngine::get_active();
    if ($active) {
        $used       = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted WHERE campaign_id = %d",
            $active->id
        ));
        $remaining  = max(0, $active->quota - $used);
        $fill_pct   = $active->quota > 0 ? min(100, round($used / $active->quota * 100)) : 0;
        $cat_stats  = DB::get_category_stats_for_campaign($active->id);
        $react_cnt  = DB::count_reactivations_for_campaign($active->id);

        $cat_map    = [];
        foreach ($cat_stats as $row) {
            $cat_map[$row['user_category']] = (int) $row['count'];
        }

        $bar_color = '#2271b1';
        if ($fill_pct >= 100) $bar_color = '#d63638';
        elseif ($fill_pct >= 80) $bar_color = '#dba617';

        $tz      = get_option('timezone_string') ?: 'UTC';
        $fmt     = 'j M Y, H:i';
        $start_f = wp_date($fmt, strtotime($active->start_date . ' UTC'));
        $end_f   = wp_date($fmt, strtotime($active->end_date . ' UTC'));

        $cat_colors = [
            'new'          => ['bg' => '#d7f7e3', 'color' => '#00a32a'],
            'diagnosed'    => ['bg' => '#fef3d7', 'color' => '#996800'],
            'reactivation' => ['bg' => '#e8f0fb', 'color' => '#2271b1'],
            'ineligible'   => ['bg' => '#fce8e8', 'color' => '#d63638'],
        ];
        ?>
        <div style="background:#f0f6fc; border:1px solid #c3c4c7; border-left:4px solid #00a32a;
                    border-radius:4px; padding:18px 20px; margin-bottom:20px;">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:14px;">
                <div>
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:4px;">
                        <span style="font-size:17px; font-weight:600;"><?php echo esc_html($active->name); ?></span>
                        <span style="background:#d7f7e3; color:#00a32a; border:1px solid #9de0b4;
                                     font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px;
                                     text-transform:uppercase; letter-spacing:.05em;">● Live</span>
                    </div>
                    <div style="font-size:12px; color:#646970;">
                        <?php echo esc_html($start_f); ?> — <?php echo esc_html($end_f); ?>
                    </div>
                </div>
                <div style="display:flex; gap:8px;">
                    <?php
                    $edit_url = esc_url(admin_url(
                        'options-general.php?page=home-promo-manager&tab=campaigns&action=edit&campaign_id=' . $active->id
                    ));
                    ?>
                    <button class="button button-small hpm-edit-btn"
                        data-id="<?php echo (int) $active->id; ?>"
                        data-name="<?php echo esc_attr($active->name); ?>"
                        data-slug="<?php echo esc_attr($active->slug); ?>"
                        data-mode="<?php echo esc_attr($active->mode); ?>"
                        data-start="<?php echo esc_attr(str_replace(' ', 'T', wp_date('Y-m-d H:i:s', strtotime($active->start_date . ' UTC')))); ?>"
                        data-end="<?php echo esc_attr(str_replace(' ', 'T', wp_date('Y-m-d H:i:s', strtotime($active->end_date . ' UTC')))); ?>"
                        data-quota="<?php echo (int) $active->quota; ?>"
                        data-discount="<?php echo esc_attr($active->discount_amount); ?>"
                        data-campaign-code="<?php echo esc_attr($active->campaign_code ?? ''); ?>"
                        data-codes-config="<?php echo htmlspecialchars($active->codes_config ?? '', ENT_QUOTES); ?>">
                        ✏ Edit
                    </button>
                    <?php
                    $deact_url = esc_url(wp_nonce_url(
                        admin_url("options-general.php?page=home-promo-manager&tab=campaigns&action=deactivate&campaign_id={$active->id}"),
                        "hpm_campaign_deactivate_{$active->id}"
                    ));
                    ?>
                    <a href="<?php echo $deact_url; ?>" class="button button-small"
                       style="color:#d63638; border-color:#d63638;"
                       onclick="return confirm('Deactivate this campaign?');">⏹ Deactivate</a>
                </div>
            </div>

            <!-- Stats row -->
            <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:14px;">
                <?php
                $stats = [
                    ['val' => $used,       'lbl' => 'Used'],
                    ['val' => $active->quota, 'lbl' => 'Total Slots'],
                    ['val' => $remaining,  'lbl' => 'Remaining', 'color' => '#00a32a'],
                    ['val' => $react_cnt,  'lbl' => 'Reactivations'],
                    ['val' => 'RM ' . number_format((float) $active->discount_amount, 2), 'lbl' => 'Discount'],
                ];
                foreach ($stats as $s):
                    $color_style = isset($s['color']) ? 'color:' . $s['color'] . ';' : 'color:#2271b1;';
                ?>
                <div style="background:#fff; border:1px solid #e8eaeb; border-radius:4px;
                            padding:10px; text-align:center;">
                    <div style="font-size:22px; font-weight:700; <?php echo $color_style; ?>">
                        <?php echo esc_html($s['val']); ?>
                    </div>
                    <div style="font-size:11px; color:#646970; margin-top:2px;"><?php echo esc_html($s['lbl']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Fill bar -->
            <div style="background:#f0f0f1; height:10px; border-radius:5px; overflow:hidden; margin-bottom:6px;">
                <div style="background:<?php echo $bar_color; ?>; height:100%; width:<?php echo $fill_pct; ?>%;
                            border-radius:5px; transition:width .3s;"></div>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:11px; color:#646970; margin-bottom:12px;">
                <span>0</span>
                <span style="font-weight:600; color:<?php echo $bar_color; ?>;"><?php echo $fill_pct; ?>% filled</span>
                <span><?php echo (int) $active->quota; ?></span>
            </div>

            <!-- Category pills -->
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php foreach ($cat_colors as $cat => $style):
                    $count = $cat_map[$cat] ?? 0;
                    if ($count === 0) continue;
                ?>
                <span style="background:<?php echo $style['bg']; ?>; color:<?php echo $style['color']; ?>;
                             padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600;">
                    ● <?php echo ucfirst($cat); ?> &nbsp;<?php echo $count; ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    } else {
        echo '<div class="notice notice-warning inline"><p>No active campaign. Activate one from the table below.</p></div>';
    }

    // ── Campaigns table ─────────────────────────────────────────────────────
    $campaigns = $wpdb->get_results(
        "SELECT c.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted cc WHERE cc.campaign_id = c.id) AS used_count,
                a.campaign_id AS is_pointed
           FROM {$wpdb->prefix}home_promo_campaigns c
      LEFT JOIN {$wpdb->prefix}home_promo_active a ON a.singleton = 1
          ORDER BY c.id DESC"
    );
    ?>
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
        <strong>All Campaigns</strong>
        <button id="hpm-add-campaign-btn" class="page-title-action">+ Add Campaign</button>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Name</th><th>Mode</th><th>Status</th><th>Dates</th>
                <th>Quota</th><th>Used</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ((array) $campaigns as $c):
            $is_live   = ($c->is_pointed == $c->id);
            $live_html = $is_live
                ? '<span style="background:#d7f7e3;color:#00a32a;border:1px solid #9de0b4;font-size:10px;
                                font-weight:700;padding:1px 6px;border-radius:20px;margin-left:6px;">LIVE</span>'
                : '';
            $status_html = match($c->status) {
                'draft'  => '<span style="color:#646970;">Draft</span>',
                'active' => '<span style="color:#00a32a;">● Active</span>',
                'ended'  => '<span style="color:#d63638;">Ended</span>',
                default  => esc_html($c->status),
            };
            $start_d = wp_date('j M Y', strtotime($c->start_date . ' UTC'));
            $end_d   = wp_date('j M Y', strtotime($c->end_date   . ' UTC'));
        ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($c->name); ?></strong><?php echo $live_html; ?>
                </td>
                <td><code style="font-size:11px;"><?php echo esc_html($c->mode); ?></code></td>
                <td><?php echo $status_html; ?></td>
                <td style="font-size:12px;color:#646970;"><?php echo esc_html($start_d); ?> – <?php echo esc_html($end_d); ?></td>
                <td><?php echo (int) $c->quota; ?></td>
                <td><strong><?php echo (int) $c->used_count; ?></strong></td>
                <td><?php echo hpm_campaign_actions_html((int) $c->id, $c->status, $is_live, $c); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php hpm_render_campaign_modal(); ?>
    <?php
}

function hpm_campaign_actions_html(int $id, string $status, bool $is_active_ptr, object $c): string
{
    $base = admin_url('options-general.php?page=home-promo-manager&tab=campaigns');

    $edit_data = sprintf(
        'data-id="%d" data-name="%s" data-slug="%s" data-mode="%s" '
        . 'data-start="%s" data-end="%s" data-quota="%d" '
        . 'data-discount="%s" data-campaign-code="%s" data-codes-config="%s"',
        $id,
        esc_attr($c->name),
        esc_attr($c->slug),
        esc_attr($c->mode),
        esc_attr(str_replace(' ', 'T', wp_date('Y-m-d H:i:s', strtotime($c->start_date . ' UTC')))),
        esc_attr(str_replace(' ', 'T', wp_date('Y-m-d H:i:s', strtotime($c->end_date   . ' UTC')))),
        (int) $c->quota,
        esc_attr($c->discount_amount),
        esc_attr($c->campaign_code ?? ''),
        htmlspecialchars($c->codes_config ?? '', ENT_QUOTES)
    );

    $html = "<button class='button button-small hpm-edit-btn' {$edit_data}>Edit</button> ";

    if (!$is_active_ptr) {
        $activate_url = esc_url(wp_nonce_url(
            add_query_arg(['action' => 'activate', 'campaign_id' => $id], $base),
            "hpm_campaign_activate_{$id}"
        ));
        $html .= "| <a href='{$activate_url}' style='color:#00a32a;'>Activate</a> ";
    } else {
        $deactivate_url = esc_url(wp_nonce_url(
            add_query_arg(['action' => 'deactivate', 'campaign_id' => $id], $base),
            "hpm_campaign_deactivate_{$id}"
        ));
        $html .= "| <a href='{$deactivate_url}' style='color:#dba617;'
                     onclick='return confirm(\"Deactivate?\")'>Deactivate</a> ";
    }

    $delete_url = esc_url(wp_nonce_url(
        add_query_arg(['action' => 'delete', 'campaign_id' => $id], $base),
        "hpm_campaign_delete_{$id}"
    ));
    if ($is_active_ptr) {
        $html .= "| <span style='color:#c3c4c7;cursor:not-allowed;' title='Deactivate first'>Delete</span>";
    } else {
        $html .= "| <a href='{$delete_url}' style='color:#d63638;'
                     onclick='return confirm(\"Delete this campaign?\")'>Delete</a>";
    }

    return $html;
}

function hpm_render_campaign_modal(): void
{
    ?>
    <!-- Campaign modal (shared for Add + Edit) -->
    <div id="hpm-campaign-modal"
         style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999;
                overflow-y:auto; padding:30px 0;">
        <div style="background:#fff; width:620px; max-width:95%; margin:0 auto; border-radius:4px;
                    box-shadow:0 10px 40px rgba(0,0,0,.3); position:relative;">

            <div style="padding:16px 20px; border-bottom:1px solid #c3c4c7;
                        display:flex; align-items:center; justify-content:space-between;">
                <h2 id="hpm-modal-title" style="font-size:16px; font-weight:600; margin:0;">Add Campaign</h2>
                <button id="hpm-modal-close" type="button"
                        style="background:none;border:none;font-size:22px;cursor:pointer;
                               color:#646970;line-height:1;padding:0 4px;">&times;</button>
            </div>

            <div style="padding:20px;">
                <form method="post"
                      action="<?php echo esc_url(admin_url('options-general.php?page=home-promo-manager&tab=campaigns')); ?>"
                      id="hpm-campaign-form">
                    <?php wp_nonce_field('hpm_campaign_save'); ?>
                    <input type="hidden" name="hpm_action" id="hpm-modal-action" value="save_new">
                    <input type="hidden" name="campaign_id" id="hpm-modal-campaign-id" value="">

                    <table class="form-table">
                        <tr>
                            <th><label for="hpm-f-name">Name</label></th>
                            <td><input type="text" name="name" id="hpm-f-name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="hpm-f-slug">Slug</label></th>
                            <td>
                                <input type="text" name="slug" id="hpm-f-slug" class="regular-text">
                                <p class="description">Leave blank to auto-generate from name.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="hpm-f-mode">Mode</label></th>
                            <td>
                                <select name="mode" id="hpm-f-mode">
                                    <option value="auto">Auto</option>
                                    <option value="manual">Manual</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Start Date</th>
                            <td><input type="datetime-local" name="start_date" id="hpm-f-start" required></td>
                        </tr>
                        <tr>
                            <th>End Date</th>
                            <td><input type="datetime-local" name="end_date" id="hpm-f-end" required></td>
                        </tr>
                        <tr>
                            <th><label for="hpm-f-quota">Quota</label></th>
                            <td><input type="number" name="quota" id="hpm-f-quota" min="1" class="small-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="hpm-f-discount">Discount (RM)</label></th>
                            <td><input type="number" name="discount_amount" id="hpm-f-discount" min="0.01" step="0.01" class="small-text" required></td>
                        </tr>
                        <tr id="hpm-row-campaign-code">
                            <th><label for="hpm-f-code">Campaign Code</label></th>
                            <td>
                                <input type="text" name="campaign_code" id="hpm-f-code" maxlength="40" class="regular-text">
                                <p class="description">Required for Auto mode.</p>
                            </td>
                        </tr>
                        <tr id="hpm-row-codes-config" style="display:none;">
                            <th><label for="hpm-f-config">Codes Config (JSON)</label></th>
                            <td>
                                <textarea name="codes_config" id="hpm-f-config" rows="4" class="large-text"
                                    placeholder='{"CODE1":{"max":50},"CODE2":{"max":50}}'></textarea>
                                <p class="description">Required for Manual mode.</p>
                            </td>
                        </tr>
                    </table>

                    <div style="padding-top:12px; border-top:1px solid #c3c4c7; display:flex;
                                justify-content:flex-end; gap:8px; background:#f9f9f9;
                                margin:20px -20px -20px; padding:14px 20px;">
                        <button type="button" id="hpm-modal-cancel" class="button">Cancel</button>
                        <button type="submit" class="button button-primary">Save Campaign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function($) {
        const $modal  = $('#hpm-campaign-modal');
        const $form   = $('#hpm-campaign-form');
        const $title  = $('#hpm-modal-title');
        const $action = $('#hpm-modal-action');
        const $campId = $('#hpm-modal-campaign-id');

        function hpmSyncMode(mode) {
            if (mode === 'manual') {
                $('#hpm-row-campaign-code').hide();
                $('#hpm-row-codes-config').show();
            } else {
                $('#hpm-row-campaign-code').show();
                $('#hpm-row-codes-config').hide();
            }
        }

        function openModal() { $modal.fadeIn(150); }
        function closeModal() { $modal.fadeOut(150); }

        // Add Campaign
        $('#hpm-add-campaign-btn').on('click', function() {
            $form[0].reset();
            $title.text('Add Campaign');
            $action.val('save_new');
            $campId.val('');
            hpmSyncMode('auto');
            openModal();
        });

        // Edit Campaign — read data-* attrs
        $(document).on('click', '.hpm-edit-btn', function(e) {
            e.preventDefault();
            const d = $(this).data();
            $title.text('Edit Campaign');
            $action.val('save_edit');
            $campId.val(d.id);
            $('#hpm-f-name').val(d.name);
            $('#hpm-f-slug').val(d.slug);
            $('#hpm-f-mode').val(d.mode);
            $('#hpm-f-start').val(d.start);
            $('#hpm-f-end').val(d.end);
            $('#hpm-f-quota').val(d.quota);
            $('#hpm-f-discount').val(d.discount);
            $('#hpm-f-code').val(d.campaignCode || '');
            $('#hpm-f-config').val($(this).attr('data-codes-config') || '');
            hpmSyncMode(d.mode);
            openModal();
        });

        // Close handlers
        $('#hpm-modal-close, #hpm-modal-cancel').on('click', closeModal);
        $modal.on('click', function(e) { if (e.target === this) closeModal(); });
        $(document).on('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

        // Mode toggle
        $('#hpm-f-mode').on('change', function() { hpmSyncMode($(this).val()); });

        // Sync on init (page reload after failed submit may preserve form state)
        hpmSyncMode($('#hpm-f-mode').val() || 'auto');

    }(jQuery));
    </script>
    <?php
}

function hpm_handle_campaign_actions(): void
{
    global $wpdb;
    $hpm_action = sanitize_text_field($_POST['hpm_action'] ?? $_GET['action'] ?? '');
    if (!$hpm_action) return;
    if (!in_array($hpm_action, ['save_new', 'save_edit', 'activate', 'deactivate', 'delete'], true)) return;

    hpm_admin_guard();
    if (in_array($hpm_action, ['save_new', 'save_edit'], true)) {
        check_admin_referer('hpm_campaign_save');
    } else {
        $campaign_id_for_nonce = (int) ($_GET['campaign_id'] ?? 0);
        check_admin_referer("hpm_campaign_{$hpm_action}_{$campaign_id_for_nonce}");
    }

    if ($hpm_action === 'save_new' || $hpm_action === 'save_edit') {
        $data  = hpm_sanitise_campaign_fields($_POST);
        $error = hpm_validate_campaign_fields($data, $hpm_action === 'save_edit' ? (int) ($_POST['campaign_id'] ?? 0) : null);
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
            if (!$id) {
                echo '<div class="notice notice-error"><p>Invalid campaign ID.</p></div>';
                return;
            }
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

function hpm_sanitise_campaign_fields(array $post): array
{
    $mode             = sanitize_text_field($post['mode'] ?? '');
    $raw_codes_config = $post['codes_config'] ?? '';
    $codes_config_encoded = null;
    if ($mode === 'manual' && !empty($raw_codes_config)) {
        try {
            $decoded              = json_decode($raw_codes_config, true, 512, JSON_THROW_ON_ERROR);
            $codes_config_encoded = wp_json_encode($decoded);
        } catch (\JsonException $e) {
            $codes_config_encoded = '__INVALID_JSON__';
        }
    }

    $sd_input = sanitize_text_field(str_replace('T', ' ', $post['start_date'] ?? ''));
    $ed_input = sanitize_text_field(str_replace('T', ' ', $post['end_date']   ?? ''));
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

function hpm_local_to_utc(string $local_datetime): string
{
    try {
        $dt = new \DateTime($local_datetime, wp_timezone());
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        return '';
    }
}

function hpm_validate_campaign_fields(array $data, ?int $edit_id): ?string
{
    global $wpdb;

    if (empty($data['name']))                              return 'Name is required.';

    $slug = $data['slug'];
    if (empty($slug))                                      return 'Slug could not be generated. Please enter a manual slug using Latin characters.';
    if (strlen($slug) < 3)                                 return 'Slug must be at least 3 characters long.';
    if (strlen($slug) > 80)                                return 'Slug must be 80 characters or fewer.';

    $dup = $edit_id
        ? $wpdb->prepare("SELECT id FROM {$wpdb->prefix}home_promo_campaigns WHERE slug=%s AND id<>%d", $slug, $edit_id)
        : $wpdb->prepare("SELECT id FROM {$wpdb->prefix}home_promo_campaigns WHERE slug=%s", $slug);
    if ($wpdb->get_var($dup))                              return 'A campaign with this slug already exists.';

    if (!in_array($data['mode'], ['auto', 'manual'], true)) return 'Invalid mode.';
    if (empty($data['start_date']))                        return 'Start date is required.';
    if (empty($data['end_date']))                          return 'End date is required.';
    if ($data['end_date'] <= $data['start_date'])          return 'End date must be after start date.';
    if ($data['quota'] === 0)                              return 'Quota must be at least 1.';
    if ($data['discount_amount'] <= 0)                     return 'Discount must be greater than 0.';
    if ($data['discount_amount'] > 999999.99)              return 'Discount exceeds maximum (RM 999,999.99).';

    if ($data['mode'] === 'auto' && empty($data['campaign_code']))   return 'Campaign code is required for auto mode.';
    if ($data['mode'] === 'manual') {
        if ($data['codes_config'] === '__INVALID_JSON__')            return 'Codes config must be valid JSON.';
        if (empty($data['codes_config']))                            return 'Codes config is required for manual mode.';
    }
    if ($data['mode'] === 'auto'   && !empty($data['codes_config'])) return 'Codes config must be empty for auto mode.';
    if ($data['mode'] === 'manual' && !empty($data['campaign_code'])) return 'Campaign code must be empty for manual mode.';

    return null;
}

// ---------------------------------------------------------------------------
// Tab: Settings
// ---------------------------------------------------------------------------
function hpm_render_settings_tab(): void
{
    hpm_admin_guard();

    $opts     = get_option('home_promo_manager_settings', []);
    $defaults = [
        'start'                    => '2025-12-01 12:00:00',
        'end'                      => '2025-12-24 23:59:00',
        'timezone'                 => 'Asia/Kuala_Lumpur',
        'debug_mode'               => false,
        'form_id'                  => 13,
        'promo_field_id'           => 3170,
        'daftar_field_id'          => 196,
        'daftar_trigger_value'     => 'Ya',
        'status_field_id'          => 199,
        'pasif_date_field_id'      => 1698,
        'diagnostic_date_field_id' => 0,
        'lead_status_field_id'     => 0,
        'branch_field_id'          => 0,
        'passive_threshold_days'   => 90,
        'code_assignment_mode'     => 'manual',
        'base_price'               => 200.00,
        'discount_amount'          => 52.00,
        'final_price'              => 148.00,
        'max'                      => 480,
        'tier1_max'                => 240,
        'code_tier1'               => 'promo24',
        'code_tier2'               => 'promo12',
        'admin_email'              => get_option('admin_email'),
    ];
    $opts = wp_parse_args($opts, $defaults);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hpm_clear_count'])) {
        if (!check_admin_referer('hpm_manual_ops', 'hpm_manual_nonce')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        } else {
            DB::clear();
            echo '<div class="notice notice-success"><p>Counted entries cleared.</p></div>';
        }
    }
    ?>
    <div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="margin:0;">Settings</h2>
            <span style="background:#2271b1; color:#fff; padding:4px 12px; border-radius:4px;
                         font-size:12px; font-weight:600;">
                v<?php echo esc_html(HOME_PROMO_MANAGER_VERSION); ?>
            </span>
        </div>

        <form method="post" action="options.php" id="hpm-settings-form">
            <?php settings_fields('hpm_settings_group'); ?>

            <?php
            // ── Campaign Timing ──────────────────────────────────────────
            echo '<details class="hpm-settings-group" open><summary><strong>📅 Campaign Timing</strong></summary>';
            echo '<table class="form-table" role="presentation"><tbody>';
            ?>
            <tr>
                <th><label for="hpm_start">Promo Start <span style="font-weight:400;color:#646970;">(site timezone)</span></label></th>
                <td>
                    <input name="home_promo_manager_settings[start]" type="text" id="hpm_start"
                           value="<?php echo esc_attr($opts['start']); ?>" class="regular-text">
                    <p class="description">Format: YYYY-MM-DD HH:MM:SS</p>
                </td>
            </tr>
            <tr>
                <th><label for="hpm_end">Promo End <span style="font-weight:400;color:#646970;">(site timezone)</span></label></th>
                <td>
                    <input name="home_promo_manager_settings[end]" type="text" id="hpm_end"
                           value="<?php echo esc_attr($opts['end']); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="hpm_timezone">Timezone</label></th>
                <td>
                    <select name="home_promo_manager_settings[timezone]" id="hpm_timezone">
                        <?php foreach (\DateTimeZone::listIdentifiers() as $tz): ?>
                            <option value="<?php echo esc_attr($tz); ?>" <?php selected($opts['timezone'], $tz); ?>>
                                <?php echo esc_html($tz); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php echo '</tbody></table></details>'; ?>

            <?php
            // ── Form Fields ──────────────────────────────────────────────
            echo '<details class="hpm-settings-group" open style="margin-top:12px;"><summary><strong>📋 Form Fields</strong></summary>';
            echo '<table class="form-table" role="presentation"><tbody>';
            $field_rows = [
                ['form_id',                  'Form ID',                  'Formidable form ID.',                           'number'],
                ['promo_field_id',           'Promo Field ID',           'Field that holds the promo code input.',         'number'],
                ['daftar_field_id',          'Daftar Field ID',          'Registration intent field.',                    'number'],
                ['daftar_trigger_value',     'Daftar Trigger Value',     'e.g. "Ya", "Yes", "1".',                        'text'],
                ['status_field_id',          'Status Field ID',          'aktif=1, pasif=2. Example: 199',                'number'],
                ['pasif_date_field_id',      'Pasif Date Field ID',      'Date when client became pasif. Example: 1698',  'number'],
                ['diagnostic_date_field_id', 'Diagnostic Date Field ID', 'Field for diagnostic date. 0 = not used.',     'number'],
                ['lead_status_field_id',     'Lead Status Field ID',     '0 = not used.',                                 'number'],
                ['branch_field_id',          'Branch Field ID',          '0 = not used.',                                 'number'],
            ];
            foreach ($field_rows as [$key, $label, $desc, $type]):
                $val = $opts[$key] ?? '';
            ?>
            <tr>
                <th><label for="hpm_<?php echo $key; ?>"><?php echo esc_html($label); ?></label></th>
                <td>
                    <input name="home_promo_manager_settings[<?php echo $key; ?>]"
                           type="<?php echo $type; ?>" id="hpm_<?php echo $key; ?>"
                           value="<?php echo esc_attr($val); ?>"
                           class="<?php echo $type === 'number' ? 'small-text' : 'regular-text'; ?>">
                    <?php if ($desc): ?>
                        <p class="description"><?php echo esc_html($desc); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php echo '</tbody></table></details>'; ?>

            <?php
            // ── Eligibility ──────────────────────────────────────────────
            echo '<details class="hpm-settings-group" open style="margin-top:12px;"><summary><strong>✅ Eligibility</strong></summary>';
            echo '<table class="form-table" role="presentation"><tbody>';
            ?>
            <tr>
                <th><label for="hpm_passive_threshold_days">Passive Threshold (days)</label></th>
                <td>
                    <input name="home_promo_manager_settings[passive_threshold_days]" type="number"
                           id="hpm_passive_threshold_days"
                           value="<?php echo esc_attr($opts['passive_threshold_days']); ?>" class="small-text" min="1">
                    <p class="description">Days pasif to qualify as Reactivation vs Diagnosed.</p>
                </td>
            </tr>
            <?php echo '</tbody></table></details>'; ?>

            <?php
            // ── Pricing ──────────────────────────────────────────────────
            echo '<details class="hpm-settings-group" open style="margin-top:12px;"><summary><strong>💰 Pricing</strong></summary>';
            echo '<table class="form-table" role="presentation"><tbody>';
            $price_rows = [
                ['base_price',      'Base Price (RM)'],
                ['discount_amount', 'Discount Amount (RM)'],
                ['final_price',     'Final Price (RM)'],
            ];
            foreach ($price_rows as [$key, $label]):
            ?>
            <tr>
                <th><label for="hpm_<?php echo $key; ?>"><?php echo esc_html($label); ?></label></th>
                <td>
                    <input name="home_promo_manager_settings[<?php echo $key; ?>]"
                           type="number" id="hpm_<?php echo $key; ?>"
                           value="<?php echo esc_attr($opts[$key]); ?>" step="0.01" min="0" class="small-text">
                </td>
            </tr>
            <?php endforeach; ?>
            <?php echo '</tbody></table></details>'; ?>

            <?php
            // ── Admin ────────────────────────────────────────────────────
            echo '<details class="hpm-settings-group" open style="margin-top:12px;"><summary><strong>👤 Admin</strong></summary>';
            echo '<table class="form-table" role="presentation"><tbody>';
            ?>
            <tr>
                <th><label for="hpm_email">Admin Email</label></th>
                <td>
                    <input name="home_promo_manager_settings[admin_email]" type="email" id="hpm_email"
                           value="<?php echo esc_attr($opts['admin_email']); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="hpm_code_assignment_mode">Code Assignment Mode</label></th>
                <td>
                    <select name="home_promo_manager_settings[code_assignment_mode]" id="hpm_code_assignment_mode">
                        <option value="manual" <?php selected($opts['code_assignment_mode'], 'manual'); ?>>Manual (SMART26)</option>
                        <option value="auto"   <?php selected($opts['code_assignment_mode'], 'auto');   ?>>Auto (Legacy)</option>
                    </select>
                    <p class="description">Manual = users enter promo codes. Auto = tier-based auto-assign.</p>
                </td>
            </tr>
            <tr>
                <th><label for="hpm_debug">Debug Mode</label></th>
                <td>
                    <label>
                        <input name="home_promo_manager_settings[debug_mode]" type="checkbox" id="hpm_debug"
                               value="1" <?php checked(1, $opts['debug_mode']); ?>>
                        Enable debug logging to error_log
                    </label>
                    <p class="description">Can also be toggled from the <a href="?page=home-promo-manager&tab=debug">Debug tab</a> without a page reload.</p>
                </td>
            </tr>
            <?php echo '</tbody></table></details>'; ?>

            <?php
            // ── Legacy (collapsed) ───────────────────────────────────────
            echo '<details class="hpm-settings-group" id="hpm-legacy-section" style="margin-top:12px;">';
            echo '<summary><strong>🗄 Legacy Fields</strong> '
               . '<span style="background:#fce8e8;color:#d63638;font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;">DEPRECATED</span></summary>';
            echo '<div class="notice notice-warning inline" style="margin:12px 0 0;"><p>These fields are kept for backward compatibility only. New campaigns should use the Campaigns tab.</p></div>';
            echo '<table class="form-table" role="presentation"><tbody>';
            $legacy_rows = [
                ['max',       'Max Slots'],
                ['tier1_max', 'Tier 1 Max'],
                ['code_tier1','Tier 1 Code'],
                ['code_tier2','Tier 2 Code'],
            ];
            foreach ($legacy_rows as [$key, $label]):
                $type = in_array($key, ['max','tier1_max']) ? 'number' : 'text';
            ?>
            <tr>
                <th style="color:#646970;"><?php echo esc_html($label); ?></th>
                <td>
                    <input name="home_promo_manager_settings[<?php echo $key; ?>]"
                           type="<?php echo $type; ?>"
                           value="<?php echo esc_attr($opts[$key]); ?>"
                           class="<?php echo $type === 'number' ? 'small-text' : 'regular-text'; ?>">
                </td>
            </tr>
            <?php endforeach; ?>
            <?php echo '</tbody></table></details>'; ?>

            <script>
            (function() {
                const el  = document.getElementById('hpm-legacy-section');
                if (!el) return;
                const key = 'hpm_legacy_open';
                if (localStorage.getItem(key) === '1') el.open = true;
                el.addEventListener('toggle', function() {
                    localStorage.setItem(key, el.open ? '1' : '0');
                });
            }());
            </script>

            <style>
            .hpm-settings-group { border:1px solid #e8eaeb; border-radius:4px; overflow:hidden; }
            .hpm-settings-group > summary {
                background:#f6f7f7; padding:10px 16px; cursor:pointer;
                border-bottom:1px solid #e8eaeb; user-select:none;
                display:flex; align-items:center; gap:8px;
            }
            .hpm-settings-group > summary:hover { background:#f0f0f1; }
            .hpm-settings-group > .form-table { padding:0 6px; }
            </style>

            <p style="margin-top:16px;"><?php submit_button('Save Settings', 'primary', 'submit', false); ?></p>
        </form>

        <hr>
        <h2>Manual Operations</h2>
        <form method="post">
            <?php wp_nonce_field('hpm_manual_ops', 'hpm_manual_nonce'); ?>
            <p>
                <button type="submit" name="hpm_clear_count" class="button"
                        onclick="return confirm('Clear all counted entries? This cannot be undone.');">
                    Clear Counted Entries
                </button>
                <span class="description">Resets the slot counter (for testing or after a promo cycle).</span>
            </p>
        </form>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// Tab: Logs
// ---------------------------------------------------------------------------
function hpm_render_logs_tab(): void
{
    global $wpdb;
    hpm_admin_guard();

    $per_page   = 25;
    $log_page   = max(1, (int) ($_GET['log_page']  ?? 1));
    $log_entry  = isset($_GET['log_entry']) && $_GET['log_entry'] !== ''
                  ? (int) $_GET['log_entry'] : null;

    $total       = DB::count_status_log($log_entry);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $log_page    = min($log_page, $total_pages);
    $rows        = DB::get_status_log_page($log_page, $per_page, $log_entry);

    $reacts = $wpdb->get_results(
        "SELECT entry_id, old_status, new_status, pasif_date, reactivated_at, promo_code
           FROM {$wpdb->prefix}home_promo_reactivations
          ORDER BY reactivated_at DESC LIMIT 200",
        ARRAY_A
    ) ?: [];
    ?>
    <h2>Status Log</h2>

    <!-- Filter form -->
    <form method="get" style="margin-bottom:12px; display:flex; align-items:center; gap:8px;">
        <input type="hidden" name="page" value="home-promo-manager">
        <input type="hidden" name="tab" value="logs">
        <label style="font-size:13px;">Filter by Entry ID:</label>
        <input type="number" name="log_entry" value="<?php echo esc_attr($log_entry ?? ''); ?>"
               style="width:130px;" min="1">
        <button type="submit" class="button">Filter</button>
        <?php if ($log_entry): ?>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=home-promo-manager&tab=logs')); ?>"
               class="button">Clear</a>
        <?php endif; ?>
        <span style="margin-left:auto; color:#646970; font-size:12px;">
            <?php echo number_format_i18n($total); ?> total rows
        </span>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:100px;">Entry ID</th>
                <th style="width:130px;">From</th>
                <th style="width:130px;">To</th>
                <th>Logged At</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="4" style="text-align:center;color:#646970;padding:20px;">No log entries found.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><code><?php echo (int) $row['entry_id']; ?></code></td>
                <td style="color:#646970;"><?php echo $row['from_status'] ? esc_html($row['from_status']) : '—'; ?></td>
                <td>
                    <?php
                    $to = esc_html($row['to_status']);
                    $color = match($row['to_status']) {
                        'Aktif'  => '#00a32a',
                        'Pasif'  => '#2271b1',
                        default  => '#1d2327',
                    };
                    echo "<span style='color:{$color}; font-weight:600;'>{$to}</span>";
                    ?>
                </td>
                <td style="font-family:monospace; color:#646970; font-size:12px;">
                    <?php echo esc_html($row['logged_at']); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php
    echo hpm_pagination_links($log_page, $total_pages, $log_entry !== null ? ['log_entry' => $log_entry] : []);
    ?>

    <hr style="margin:28px 0;">
    <h2>Reactivations</h2>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:90px;">Entry ID</th>
                <th>Old Status</th>
                <th>New Status</th>
                <th>Pasif Date</th>
                <th>Reactivated At</th>
                <th>Promo Code</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($reacts)): ?>
            <tr><td colspan="6" style="text-align:center;color:#646970;padding:20px;">No reactivations recorded.</td></tr>
        <?php else: ?>
            <?php foreach ($reacts as $r): ?>
            <tr>
                <td><code><?php echo (int) $r['entry_id']; ?></code></td>
                <td style="color:#dba617;"><?php echo esc_html($r['old_status'] ?? '—'); ?></td>
                <td style="color:#00a32a; font-weight:600;"><?php echo esc_html($r['new_status'] ?? '—'); ?></td>
                <td style="font-family:monospace; font-size:12px; color:#646970;"><?php echo esc_html($r['pasif_date'] ?? '—'); ?></td>
                <td style="font-family:monospace; font-size:12px; color:#646970;"><?php echo esc_html($r['reactivated_at']); ?></td>
                <td><code><?php echo esc_html($r['promo_code'] ?? '—'); ?></code></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php
}

// ---------------------------------------------------------------------------
// Tab: Debug
// ---------------------------------------------------------------------------
function hpm_render_debug_tab(): void
{
    hpm_admin_guard();
    $opts     = get_option('home_promo_manager_settings', []);
    $debug_on = !empty($opts['debug_mode']);
    ?>
    <h2>Debug</h2>

    <!-- Debug Mode Toggle -->
    <div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px;
                display:flex; align-items:center; justify-content:space-between;
                max-width:480px; margin-bottom:24px;">
        <div>
            <strong style="font-size:14px;">Debug Mode</strong>
            <p class="description" style="margin-top:4px;">Writes detailed HPM events to error_log. Disable in production.</p>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            <span id="hpm-debug-label" style="font-weight:600; color:<?php echo $debug_on ? '#00a32a' : '#646970'; ?>;">
                <?php echo $debug_on ? 'ON' : 'OFF'; ?>
            </span>
            <label style="position:relative; display:inline-block; width:44px; height:24px; cursor:pointer;">
                <input type="checkbox" id="hpm-debug-toggle" <?php checked($debug_on); ?>
                       style="opacity:0; width:0; height:0; position:absolute;">
                <span id="hpm-debug-track"
                      style="position:absolute; inset:0; background:<?php echo $debug_on ? '#00a32a' : '#c3c4c7'; ?>;
                             border-radius:12px; transition:background .2s;"></span>
                <span id="hpm-debug-thumb"
                      style="position:absolute; left:<?php echo $debug_on ? '22px' : '2px'; ?>; top:2px;
                             width:20px; height:20px; background:#fff; border-radius:50%;
                             box-shadow:0 1px 3px rgba(0,0,0,.2); transition:left .2s;"></span>
            </label>
        </div>
    </div>
    <p id="hpm-debug-status" style="display:none; font-style:italic; color:#646970; margin-bottom:16px;"></p>

    <hr style="margin-bottom:24px;">

    <!-- Eligibility Tester -->
    <div style="max-width:620px;">
        <h3 style="font-size:14px; font-weight:600; margin-bottom:12px; padding-bottom:8px;
                   border-bottom:1px solid #e8eaeb;">Eligibility Tester</h3>
        <p class="description" style="margin-bottom:14px;">
            Enter a Formidable entry ID to see what <code>$ctx</code> values would be built for that entry
            and which eligibility specs would fire.
            <strong>Note:</strong> <code>prev_daftar</code> is set to <code>''</code> (optimistic) since historical values aren't stored.
        </p>

        <div style="display:flex; gap:8px; align-items:flex-end; margin-bottom:20px;">
            <div>
                <label for="hpm-test-entry-id" style="display:block; font-weight:600; margin-bottom:5px;">Entry ID</label>
                <input type="number" id="hpm-test-entry-id" min="1" class="small-text" placeholder="e.g. 12345">
            </div>
            <button id="hpm-run-test" class="button button-primary">Run Test</button>
        </div>

        <div id="hpm-test-placeholder" style="padding:20px; text-align:center; color:#646970;
             background:#f9f9f9; border:1px solid #e8eaeb; border-radius:4px; font-size:13px;">
            Enter an entry ID above and click Run Test.
        </div>
        <div id="hpm-test-result" style="display:none;"></div>
    </div>

    <script>
    (function($) {

        // ── Debug toggle ────────────────────────────────────────────────────
        const debugNonce = <?php echo wp_json_encode(wp_create_nonce('hpm_debug_toggle')); ?>;

        $('#hpm-debug-toggle').on('change', function() {
            const on     = this.checked;
            const $lbl   = $('#hpm-debug-label');
            const $track = $('#hpm-debug-track');
            const $thumb = $('#hpm-debug-thumb');
            const $msg   = $('#hpm-debug-status');

            $lbl.text(on ? 'ON' : 'OFF').css('color', on ? '#00a32a' : '#646970');
            $track.css('background', on ? '#00a32a' : '#c3c4c7');
            $thumb.css('left', on ? '22px' : '2px');
            $msg.text('Saving…').css('color','#646970').show();

            $.post(ajaxurl, {
                action:  'hpm_save_debug_toggle',
                enabled: on ? '1' : '0',
                _nonce:  debugNonce,
            }, function(res) {
                if (res.success) {
                    $msg.text('Saved.').delay(1500).fadeOut(400);
                } else {
                    $msg.text('Error saving.').css('color','#d63638');
                }
            }).fail(function() {
                $msg.text('Request failed.').css('color','#d63638');
            });
        });

        // ── Eligibility tester ──────────────────────────────────────────────
        const testNonce = <?php echo wp_json_encode(wp_create_nonce('hpm_test_eligibility')); ?>;

        $('#hpm-run-test').on('click', function() {
            const entryId = $('#hpm-test-entry-id').val().trim();
            if (!entryId || parseInt(entryId) < 1) {
                alert('Please enter a valid entry ID.');
                return;
            }
            const $btn = $(this).prop('disabled', true).text('Running…');
            $('#hpm-test-placeholder').hide();
            $('#hpm-test-result').html('<p><em>Loading…</em></p>').show();

            $.post(ajaxurl, {
                action:   'hpm_test_eligibility',
                entry_id: entryId,
                _nonce:   testNonce,
            }, function(res) {
                $btn.prop('disabled', false).text('Run Test');
                if (!res.success) {
                    $('#hpm-test-result').html(
                        '<div class="notice notice-error"><p>'
                        + hpmEsc(res.data || 'Unknown error') + '</p></div>'
                    );
                    return;
                }
                const d = res.data;

                let html = '<h4 style="margin-bottom:8px;">Context (<code>$ctx</code>)</h4>';
                html += '<table class="widefat striped" style="max-width:500px; margin-bottom:20px;">';
                html += '<tbody>';
                Object.entries(d.ctx).forEach(([k, v]) => {
                    const display = v === null ? '<em style="color:#646970;">null</em>' : hpmEsc(String(v));
                    html += '<tr><th style="width:180px;"><code>' + hpmEsc(k) + '</code></th>'
                          + '<td>' + display + '</td></tr>';
                });
                html += '</tbody></table>';

                html += '<h4 style="margin-bottom:8px;">Spec Results</h4>';
                html += '<table class="widefat striped" style="max-width:500px;">';
                html += '<thead><tr><th>Spec</th><th>Passed</th><th>Category</th></tr></thead><tbody>';

                let anyPassed = false;
                d.specs.forEach(s => {
                    const color = s.passed ? '#00a32a' : '#d63638';
                    const label = s.passed ? '✓ YES' : '✗ NO';
                    if (s.passed) anyPassed = true;
                    html += '<tr>'
                          + '<td><code>' + hpmEsc(s.name) + '</code></td>'
                          + '<td style="color:' + color + '; font-weight:bold;">' + label + '</td>'
                          + '<td>' + (s.category ? '<strong>' + hpmEsc(s.category) + '</strong>' : '—') + '</td>'
                          + '</tr>';
                });
                html += '</tbody></table>';

                if (!anyPassed) {
                    html += '<div class="notice notice-warning inline" style="margin-top:12px;">'
                          + '<p><strong>Ineligible</strong> — no spec matched. Entry would be rejected.</p></div>';
                }

                $('#hpm-test-result').html(html);
            }).fail(function() {
                $btn.prop('disabled', false).text('Run Test');
                $('#hpm-test-result').html(
                    '<div class="notice notice-error"><p>AJAX request failed. Check browser console.</p></div>'
                );
            });
        });

        function hpmEsc(str) {
            return String(str)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

    }(jQuery));
    </script>
    <?php
}
