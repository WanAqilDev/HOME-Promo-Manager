<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

class Campaign
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $slug,
        public readonly string  $status,
        public readonly string  $mode,
        public readonly string  $start_date,
        public readonly string  $end_date,
        public readonly int     $quota,
        public readonly float   $discount_amount,
        public readonly ?string $campaign_code,
        public readonly ?string $codes_config,
    ) {}

    public function get_codes_config(): array
    {
        if ($this->codes_config === null) return [];
        return (array) json_decode($this->codes_config, true);
    }
}

class CampaignEngine
{
    const CAP = 'manage_options';

    private static ?Campaign $active_campaign = null;
    private static bool      $loaded          = false;

    /** @var array<int,bool> reentrancy guard keyed by entry_id */
    private static array $writing_field = [];

    public static function get_active(): ?Campaign
    {
        if (!self::$loaded) {
            self::$active_campaign = self::query_active_campaign();
            self::$loaded          = true;
        }
        return self::$active_campaign;
    }

    public static function flush(): void
    {
        self::$active_campaign = null;
        self::$loaded          = false;
    }

    public static function is_active(): bool
    {
        return self::get_active() !== null;
    }

    private static function query_active_campaign(): ?Campaign
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.* FROM {$wpdb->prefix}home_promo_active a
              JOIN {$wpdb->prefix}home_promo_campaigns c ON c.id = a.campaign_id
             WHERE a.singleton = 1
               AND UTC_TIMESTAMP() BETWEEN c.start_date AND c.end_date"
        ));
        if (!$row) return null;

        $campaign = new Campaign(
            id:              (int)   $row->id,
            name:                    $row->name,
            slug:                    $row->slug,
            status:                  $row->status,
            mode:                    $row->mode,
            start_date:              $row->start_date,
            end_date:                $row->end_date,
            quota:           (int)   $row->quota,
            discount_amount: (float) $row->discount_amount,
            campaign_code:           $row->campaign_code ?? null,
            codes_config:            $row->codes_config ?? null,
        );

        // Mode-exclusivity assertion
        if ($campaign->mode === 'auto' && $campaign->codes_config !== null) {
            throw new \RuntimeException(
                "HPM: Campaign #{$campaign->id} mode=auto but codes_config is not null"
            );
        }
        if ($campaign->mode === 'manual' && $campaign->campaign_code !== null) {
            throw new \RuntimeException(
                "HPM: Campaign #{$campaign->id} mode=manual but campaign_code is not null"
            );
        }

        return $campaign;
    }

    public static function activate(int $campaign_id, int $user_id): array
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}home_promo_active
                    SET campaign_id = %d, activated_at = UTC_TIMESTAMP(), activated_by = %d
                  WHERE singleton = 1 AND campaign_id IS NULL",
                $campaign_id, $user_id
            ));

            if ($affected === 0) {
                $current_id = (int) $wpdb->get_var(
                    "SELECT campaign_id FROM {$wpdb->prefix}home_promo_active WHERE singleton = 1"
                );
                if ($current_id === $campaign_id) {
                    // Idempotent — already pointing at our campaign
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->prefix}home_promo_campaigns SET status = 'active' WHERE id = %d",
                        $campaign_id
                    ));
                    $wpdb->query('COMMIT');
                    self::flush();
                    return ['status' => 'ok'];
                }
                $wpdb->query('ROLLBACK');
                return ['status' => 'conflict', 'conflict_id' => $current_id];
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}home_promo_campaigns SET status = 'active' WHERE id = %d",
                $campaign_id
            ));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}home_promo_campaigns
                    SET status = 'paused' WHERE id <> %d AND status = 'active'",
                $campaign_id
            ));
            $wpdb->query('COMMIT');
            self::flush();
            return ['status' => 'ok'];

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log("HPM: activate() exception for campaign {$campaign_id}: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public static function deactivate(int $campaign_id, int $user_id): array
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}home_promo_active
                    SET campaign_id = NULL, activated_at = UTC_TIMESTAMP(), activated_by = %d
                  WHERE singleton = 1 AND campaign_id = %d",
                $user_id, $campaign_id
            ));

            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}home_promo_campaigns
                    SET status = 'paused' WHERE id = %d AND status = 'active'",
                $campaign_id
            ));

            $wpdb->query('COMMIT');
            self::flush();
            return ['status' => 'ok'];

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log("HPM: deactivate() exception for campaign {$campaign_id}: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // claim_slot() implemented in Task 8
}
