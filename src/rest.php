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
    $used = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted WHERE campaign_id = %d",
        $campaign->id
    ));
    return new \WP_REST_Response([
        'used'      => $used,
        'max'       => $campaign->quota,
        'remaining' => max(0, $campaign->quota - $used),
        'active'    => true,
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
