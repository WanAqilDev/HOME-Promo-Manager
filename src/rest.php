<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    // Public counter endpoint
    register_rest_route('promo/v1', '/counter', [
        'methods'             => 'GET',
        'callback'            => 'HPM\rest_counter',
        'permission_callback' => '__return_true',
    ]);

    // Public per-entry enrollment status (one-time just_enrolled flag)
    register_rest_route('promo/v1', '/status/(?P<entry_id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'HPM\rest_entry_status',
        'permission_callback' => '__return_true',
        'args'                => ['entry_id' => ['validate_callback' => 'is_numeric']],
    ]);

    // Admin-only campaign CRUD
    $admin_perm = fn() => current_user_can(CampaignEngine::CAP);

    register_rest_route('promo/v1', '/campaigns', [
        ['methods' => 'GET',  'callback' => 'HPM\rest_campaigns_list',   'permission_callback' => $admin_perm],
        ['methods' => 'POST', 'callback' => 'HPM\rest_campaigns_create', 'permission_callback' => $admin_perm],
    ]);

    register_rest_route('promo/v1', '/campaigns/(?P<id>\d+)', [
        ['methods' => 'PUT',    'callback' => 'HPM\rest_campaigns_update', 'permission_callback' => $admin_perm],
        ['methods' => 'DELETE', 'callback' => 'HPM\rest_campaigns_delete', 'permission_callback' => $admin_perm],
    ]);
});

function rest_counter(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $campaign = CampaignEngine::get_active();
    if (!$campaign) {
        return new \WP_REST_Response(['used' => 0, 'max' => 0, 'remaining' => 0, 'active' => false]);
    }
    $cache_key = 'hpm_counter_' . $campaign->id;
    $cached    = wp_cache_get($cache_key, 'hpm');
    if ($cached !== false) {
        return new \WP_REST_Response($cached);
    }
    $used = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted WHERE campaign_id = %d",
        $campaign->id
    ));
    $data = [
        'used'      => $used,
        'max'       => $campaign->quota,
        'remaining' => max(0, $campaign->quota - $used),
        'active'    => true,
    ];
    wp_cache_set($cache_key, $data, 'hpm', 60);
    return new \WP_REST_Response($data);
}

function rest_entry_status(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $entry_id = (int) $req['entry_id'];

    $row = DB::get_entry_promo_status($entry_id);
    if (!$row) {
        return new \WP_REST_Response(['enrolled' => false, 'just_enrolled' => false]);
    }

    $campaign_name = '';
    if (!empty($row['campaign_id'])) {
        $campaigns_table = $wpdb->prefix . 'home_promo_campaigns';
        $campaign_name   = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$campaigns_table} WHERE id = %d LIMIT 1",
            (int) $row['campaign_id']
        ));
    }

    $transient_key = 'hpm_just_enrolled_' . $entry_id;
    $just_enrolled = (bool) get_transient($transient_key);
    if ($just_enrolled) {
        delete_transient($transient_key);
    }

    $raw_date = $row['enrolled_at'] ?? '';
    $ts       = $raw_date ? strtotime($raw_date) : false;
    $enrolled_at_fmt = ($ts && $ts > 0) ? wp_date('j M Y, g:i a', $ts) : '';

    return new \WP_REST_Response([
        'enrolled'      => true,
        'just_enrolled' => $just_enrolled,
        'code'          => $row['promo_code'] ?? '',
        'category'      => $row['user_category'] ?? '',
        'enrolled_at'   => $enrolled_at_fmt,
        'campaign'      => $campaign_name,
    ]);
}

function rest_campaigns_list(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}home_promo_campaigns ORDER BY id DESC"
    );
    return new \WP_REST_Response($rows);
}

function rest_campaigns_create(\WP_REST_Request $req): \WP_REST_Response {
    $data  = hpm_sanitise_campaign_fields($req->get_params());
    $error = hpm_validate_campaign_fields($data, null);
    if ($error) return new \WP_REST_Response(['error' => $error], 400);

    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}home_promo_campaigns
           (name,slug,status,mode,start_date,end_date,quota,discount_amount,campaign_code,codes_config,created_at,updated_at)
         VALUES (%s,%s,'draft',%s,%s,%s,%d,%f,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
        $data['name'], $data['slug'], $data['mode'],
        $data['start_date'], $data['end_date'],
        $data['quota'], $data['discount_amount'],
        $data['campaign_code'], $data['codes_config']
    ));
    return new \WP_REST_Response(['id' => $wpdb->insert_id], 201);
}

function rest_campaigns_update(\WP_REST_Request $req): \WP_REST_Response {
    $id    = (int) $req['id'];
    $data  = hpm_sanitise_campaign_fields($req->get_params());
    $error = hpm_validate_campaign_fields($data, $id);
    if ($error) return new \WP_REST_Response(['error' => $error], 400);

    global $wpdb;
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
    return new \WP_REST_Response(['updated' => true]);
}

function rest_campaigns_delete(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $id      = (int) $req['id'];
    $pointed = (int) $wpdb->get_var(
        "SELECT campaign_id FROM {$wpdb->prefix}home_promo_active WHERE singleton=1"
    );
    if ($pointed === $id) {
        return new \WP_REST_Response(['error' => 'Cannot delete active campaign.'], 409);
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}home_promo_campaigns WHERE id=%d", $id
    ));
    CampaignEngine::flush();
    return new \WP_REST_Response(['deleted' => true]);
}
