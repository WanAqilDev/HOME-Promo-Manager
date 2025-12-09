<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    register_rest_route('promo/v1', '/counter', [
        'methods' => 'GET',
        'callback' => function() {
            // Ensure tables exist before querying
            DB::maybe_create_tables();
            
            $mgr = Manager::get_instance();
            if (!$mgr->is_active()) {
                return rest_ensure_response(['active' => false]);
            }
            $count = $mgr->get_count();
            $max = (int)$mgr->s('max');
            $tier1 = (int)$mgr->s('tier1_max');
            $remaining_total = max(0, $max - $count);
            $remaining_tier = ($count < $tier1) ? max(0, $tier1 - $count) : $remaining_total;
            try {
                $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
                $end_utc = (new \DateTimeImmutable($mgr->s('end'), $tz))
                    ->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
            } catch (\Exception $e) {
                $end_utc = 0;
            }
            return rest_ensure_response([
                'active' => true,
                'current_code' => $mgr->get_current_code($count),
                'remaining_total' => intval($remaining_total),
                'remaining_tier' => intval($remaining_tier),
                'end_time' => intval($end_utc),
            ]);
        },
        'permission_callback' => '__return_true',
    ]);
});