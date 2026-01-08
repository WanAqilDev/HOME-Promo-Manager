<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

add_action('rest_api_init', function () {
    // Counter endpoint - Returns promo statistics
    register_rest_route('promo/v1', '/counter', [
        'methods' => 'GET',
        'callback' => function () {
            // Ensure tables exist before querying
            DB::maybe_create_tables();

            $mgr = Manager::get_instance();
            if (!$mgr->is_active()) {
                return rest_ensure_response(['active' => false]);
            }

            // Get code assignment mode
            $mode = $mgr->s('code_assignment_mode') ?: 'manual';

            // Calculate end time
            try {
                $tz_string = $mgr->s('timezone') ?: 'Asia/Kuala_Lumpur';
                try {
                    $tz = new \DateTimeZone($tz_string);
                } catch (\Exception $e) {
                    $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
                }
                $end_utc = (new \DateTimeImmutable($mgr->s('end'), $tz))
                    ->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
            } catch (\Exception $e) {
                $end_utc = 0;
            }

            if ($mode === 'auto') {
                // Legacy mode: tier-based response
                $count = $mgr->get_count();
                $max = (int) $mgr->s('max');
                $tier1 = (int) $mgr->s('tier1_max');
                $remaining_total = max(0, $max - $count);
                $remaining_tier = ($count < $tier1) ? max(0, $tier1 - $count) : $remaining_total;

                return rest_ensure_response([
                    'active' => true,
                    'mode' => 'auto',
                    'current_code' => $mgr->get_current_code($count),
                    'remaining_total' => intval($remaining_total),
                    'remaining_tier' => intval($remaining_tier),
                    'end_time' => intval($end_utc),
                ]);
            } else {
                // SMART26 mode: per-code stats
                $code_stats_array = DB::get_code_stats();
                $category_stats = DB::get_category_stats();
                $promo_codes = $mgr->s('promo_codes') ?: [];

                // Convert to associative map for easier lookup
                $code_usage_map = [];
                foreach ($code_stats_array as $stat) {
                    $code_usage_map[$stat['promo_code']] = (int) $stat['count'];
                }

                // Build per-code breakdown
                $codes_data = [];
                $total_used = 0;
                $total_max = 0;
                $current_code = null;
                $current_remaining = 0;

                foreach ($promo_codes as $code => $config) {
                    if (!($config['active'] ?? true)) {
                        continue; // Skip inactive codes
                    }

                    $used = $code_usage_map[$code] ?? 0;
                    $max = (int) ($config['max'] ?? 0);
                    $remaining = max(0, $max - $used);
                    $total_used += $used;
                    $total_max += $max;

                    // Find first available code (for current_code backward compatibility)
                    if ($remaining > 0 && $current_code === null) {
                        $current_code = $code;
                        $current_remaining = $remaining;
                    }

                    $codes_data[] = [
                        'code' => $code,
                        'description' => $config['description'] ?? '',
                        'used' => $used,
                        'max' => $max,
                        'remaining' => $remaining,
                        'percentage' => $max > 0 ? round(($used / $max) * 100, 1) : 0,
                    ];
                }

                // Build category breakdown
                $categories_data = [];
                foreach ($category_stats as $cat => $count) {
                    $categories_data[$cat] = (int) $count;
                }

                // Backward compatible response for promo page
                return rest_ensure_response([
                    'active' => true,
                    'mode' => 'smart26',
                    'current_code' => $current_code ?: '-', // First available code
                    'remaining_tier' => $current_remaining, // Remaining for current code
                    'remaining_total' => max(0, $total_max - $total_used), // Total remaining
                    'total_used' => $total_used,
                    'total_max' => $total_max,
                    'codes' => $codes_data,
                    'categories' => $categories_data,
                    'end_time' => intval($end_utc),
                ]);
            }
        },
        'permission_callback' => '__return_true',
    ]);

    // Validation endpoint - Validates promo code in real-time
    register_rest_route('promo/v1', '/validate', [
        'methods' => 'POST',
        'callback' => function (\WP_REST_Request $request) {
            $mgr = Manager::get_instance();

            // Get code from request
            $code = $request->get_param('code');
            
            if (empty($code)) {
                return rest_ensure_response([
                    'valid' => false,
                    'message' => 'Please enter a promo code.',
                    'remaining' => 0,
                ]);
            }

            // Validate the code
            $validation = $mgr->validate_code($code);

            return rest_ensure_response($validation);
        },
        'permission_callback' => '__return_true',
        'args' => [
            'code' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Promo code to validate',
                'sanitize_callback' => function($value) {
                    return sanitize_text_field(trim($value));
                },
            ],
        ],
    ]);
});