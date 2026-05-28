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

    // activate() and deactivate() implemented in Task 5
    // claim_slot() implemented in Task 8
}
