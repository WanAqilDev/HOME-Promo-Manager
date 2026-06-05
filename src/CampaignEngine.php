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

    public static function claim_slot(object $ctx): array
    {
        global $wpdb;

        $campaign = self::get_active();
        if (!$campaign) return ['status' => 'no_active_campaign'];

        // Early bail — already counted
        $already = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
              WHERE entry_id = %d AND campaign_id = %d",
            $ctx->entry_id, $campaign->id
        ));
        if ($already > 0) return ['status' => 'already_counted'];

        // Debug mode: only the configured outlet's clients may enroll
        $mgr = Manager::get_instance();
        if ($mgr->s('debug_mode') && ($debug_uid = (int) $mgr->s('debug_outlet_user_id')) > 0) {
            $outlet_uid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
                  WHERE item_id = %d AND field_id = 209 LIMIT 1",
                $ctx->entry_id
            ));
            if ($outlet_uid !== $debug_uid) {
                return ['status' => 'debug_blocked'];
            }
        }

        // Status check — fail-closed
        $status_ok_label = ((string) $ctx->status_label === 'Aktif');
        $status_ok_199   = ($ctx->status === 1);
        if ($status_ok_label !== $status_ok_199) {
            error_log(sprintf(
                '[HPM] Status divergence entry_id=%d field_1617="%s" field_199=%d — slot denied',
                $ctx->entry_id, $ctx->status_label, $ctx->status
            ));
            return ['status' => 'status_divergence'];
        }

        // Eligibility
        $spec   = new OrSpecification(new NewSpec(), new DiagnosedSpec(), new ReactivationSpec());
        $result = $spec->isSatisfied($ctx);
        if ($result === false) return ['status' => 'ineligible'];

        $category = $result;
        $source   = ($ctx->went_pasif_at === null) ? 'legacy_default' : 'live';

        // Code resolution
        if ($campaign->mode === 'auto') {
            $code_to_write = $campaign->campaign_code;
        } else {
            $code_to_write = $ctx->submitted_code ?? '';
            if (empty($code_to_write)) return ['status' => 'no_code'];
        }

        // Reentrancy guard
        if (!empty(self::$writing_field[$ctx->entry_id])) {
            return ['status' => 'reentrant'];
        }

        $wpdb->query('START TRANSACTION');
        try {
            // Manual mode Layer 2 — serialise quota check with FOR UPDATE
            if ($campaign->mode === 'manual') {
                $codes_config = $campaign->get_codes_config();
                $quota_code   = $codes_config[$code_to_write] ?? 0;
                $used = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
                      WHERE campaign_id = %d AND promo_code = %s FOR UPDATE",
                    $campaign->id, $code_to_write
                ));
                if ($used >= $quota_code) {
                    $wpdb->query('ROLLBACK');
                    return ['status' => 'code_quota_exhausted', 'code' => $code_to_write];
                }
            }

            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}home_promo_counted
                   (entry_id, campaign_id, promo_code, user_category, source)
                 VALUES (%d, %d, %s, %s, %s)",
                $ctx->entry_id, $campaign->id, $code_to_write, $category, $source
            ));

            if ($inserted === false) {
                $wpdb->query('ROLLBACK');
                return ['status' => 'error'];
            }
            if ((int) $inserted !== 1) {
                $wpdb->query('ROLLBACK');
                return ['status' => 'duplicate'];
            }

            self::$writing_field[$ctx->entry_id] = true;
            $field_ok = false;
            try {
                $field_ok = \FrmEntryMeta::update_entry_meta(
                    $ctx->entry_id,
                    Manager::get_instance()->s('promo_field_id'),
                    null,
                    $code_to_write
                );
            } finally {
                unset(self::$writing_field[$ctx->entry_id]);
            }

            if (!$field_ok) {
                $wpdb->query('ROLLBACK');
                error_log("HPM: field 3170 write failed for entry {$ctx->entry_id}, rolled back slot");
                return ['status' => 'field_write_failed'];
            }

            $wpdb->query('COMMIT');
            return ['status' => 'claimed', 'category' => $category, 'source' => $source];

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            unset(self::$writing_field[$ctx->entry_id]);
            error_log("HPM: exception during claim_slot, rolled back: " . $e->getMessage());
            return ['status' => 'error'];
        }
    }
}
