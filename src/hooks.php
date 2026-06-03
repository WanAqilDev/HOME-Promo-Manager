<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

class HookDispatcher
{
    const SENTINEL_UNSET = "\0HPM_UNSET";

    /**
     * @var array<int, array<int, string|null>> [$entry_id][$field_id] => value
     * A missing key means "pre-hook never ran" (use fallback SELECT).
     * An explicit null means "pre-hook ran, DB value was null".
     */
    private static array $snapshot = [];

    /**
     * @var array<int,bool> reentrancy guard for field 3170 writes
     * Populated in on_after_update_entry (Task 9) to prevent recursive hook
     * re-entry when FrmEntryMeta::update_entry_meta() fires frm_after_update_entry again.
     */
    private static array $writing_field = [];

    public static function init(): void
    {
        add_filter('frm_validate_entry',     [self::class, 'on_validate_entry'],     10, 2);
        add_action('frm_after_create_entry', [self::class, 'on_after_create_entry'], 10, 2);
        add_filter('frm_pre_update_entry',   [self::class, 'on_pre_update_entry'],   10, 2);
        add_action('frm_after_update_entry', [self::class, 'on_after_update_entry'], 10, 2);
    }

    // -----------------------------------------------------------------
    // Pre-hook snapshot
    // -----------------------------------------------------------------

    public static function on_pre_update_entry(array $values, $entry_id): array
    {
        $entry_id = (int) $entry_id;
        global $wpdb;
        $mgr = Manager::get_instance();

        $field_ids = [
            (int) $mgr->s('daftar_field_id'),
            (int) $mgr->s('status_field_id'),
            (int) $mgr->s('status_label_field_id'),
            (int) $mgr->s('pasif_date_field_id'),
        ];

        foreach ($field_ids as $field_id) {
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
                  WHERE item_id = %d AND field_id = %d LIMIT 1",
                $entry_id, $field_id
            ));
            self::$snapshot[$entry_id][$field_id] = $value; // null is legitimate
        }

        return $values; // frm_pre_update_entry is a filter — pass through unchanged
    }

    public static function get_field_snapshot_or_fallback(int $entry_id, int $field_id): ?string
    {
        global $wpdb;

        if (array_key_exists($field_id, self::$snapshot[$entry_id] ?? [])) {
            return self::$snapshot[$entry_id][$field_id];
        }

        // Pre-hook missed — do fallback SELECT
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
              WHERE item_id = %d AND field_id = %d LIMIT 1",
            $entry_id, $field_id
        ));
        self::$snapshot[$entry_id][$field_id] = $value;
        return $value;
    }

    public static function stash_snapshot(int $entry_id, int $field_id, ?string $value): void
    {
        self::$snapshot[$entry_id][$field_id] = $value;
    }

    // -----------------------------------------------------------------
    // $ctx builder
    // -----------------------------------------------------------------

    private static function build_ctx(string $event, int $entry_id, array $post_values): object
    {
        global $wpdb;
        $mgr = Manager::get_instance();

        $daftar_fid       = (int) $mgr->s('daftar_field_id');
        $status_fid       = (int) $mgr->s('status_field_id');
        $status_label_fid = (int) $mgr->s('status_label_field_id');
        $pasif_fid        = (int) $mgr->s('pasif_date_field_id');
        $promo_fid        = (int) $mgr->s('promo_field_id');

        // New values come from $post_values['item_meta']
        $daftar       = $post_values['item_meta'][$daftar_fid]       ?? null;
        $status       = $post_values['item_meta'][$status_fid]       ?? null;
        $status_label = $post_values['item_meta'][$status_label_fid] ?? null;

        // Previous values from snapshot (null for 'created' events)
        $prev_daftar       = ($event === 'created') ? null : self::get_field_snapshot_or_fallback($entry_id, $daftar_fid);
        $prev_status       = ($event === 'created') ? null : self::get_field_snapshot_or_fallback($entry_id, $status_fid);
        $prev_status_label = ($event === 'created') ? null : self::get_field_snapshot_or_fallback($entry_id, $status_label_fid);

        // Pasif history: log → snapshot fallback → null
        $went_pasif_at = $wpdb->get_var($wpdb->prepare(
            "SELECT logged_at FROM {$wpdb->prefix}home_promo_status_log
              WHERE entry_id = %d ORDER BY logged_at DESC LIMIT 1",
            $entry_id
        ));
        if ($went_pasif_at === null) {
            $went_pasif_at = self::get_field_snapshot_or_fallback($entry_id, $pasif_fid);
        }

        $pasif_days = null;
        if ($went_pasif_at !== null) {
            $pasif_days = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT TIMESTAMPDIFF(DAY, %s, UTC_TIMESTAMP())",
                $went_pasif_at
            ));
        }

        return (object) [
            'event'             => $event,
            'entry_id'          => $entry_id,
            'daftar'            => $daftar,
            'prev_daftar'       => $prev_daftar,
            'daftar_trigger'    => $mgr->s('daftar_trigger_value') ?? 'Ya',
            'pasif_threshold'   => (int) ($mgr->s('passive_threshold_days') ?? 90),
            'status'            => ($status !== null) ? (int) $status : null,
            'prev_status'       => ($prev_status !== null) ? (int) $prev_status : null,
            'status_label'      => $status_label,
            'prev_status_label' => $prev_status_label,
            'went_pasif_at'     => $went_pasif_at,
            'pasif_days'        => $pasif_days,
            'submitted_code'    => $post_values['item_meta'][$promo_fid] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    // Test helpers — used by tests to inspect/reset state
    // -----------------------------------------------------------------

    public static function reset_snapshot(): void { self::$snapshot = []; }

    public static function get_snapshot_for_test(int $entry_id): array
    {
        return self::$snapshot[$entry_id] ?? [];
    }

    public static function build_ctx_for_test(string $event, int $entry_id, array $post_values): object
    {
        return self::build_ctx($event, $entry_id, $post_values);
    }

    // -----------------------------------------------------------------
    // Hook handlers
    // -----------------------------------------------------------------

    public static function on_validate_entry(array $errors, array $values): array
    {
        global $wpdb;
        $mgr     = Manager::get_instance();
        $form_id = (int) ($values['form_id'] ?? 0);
        if ($form_id !== (int) $mgr->s('form_id')) return $errors;

        $campaign = CampaignEngine::get_active();
        if (!$campaign || $campaign->mode !== 'manual') return $errors;

        $entry_id  = (int) ($values['id'] ?? 0);
        $promo_fid = (int) $mgr->s('promo_field_id');
        $code      = trim($values['item_meta'][$promo_fid] ?? '');

        // Skip validation if already counted — unrelated field edits pass through freely
        if ($entry_id > 0) {
            $counted = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
                  WHERE entry_id = %d AND campaign_id = %d",
                $entry_id, $campaign->id
            ));
            if ($counted > 0) return $errors;
        }

        $codes_config = $campaign->get_codes_config();
        if (!isset($codes_config[$code])) {
            $errors['field_' . $promo_fid] = 'Kod promosi tidak sah.';
            return $errors;
        }

        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}home_promo_counted
              WHERE campaign_id = %d AND promo_code = %s",
            $campaign->id, $code
        ));
        if ($used >= $codes_config[$code]) {
            $errors['field_' . $promo_fid] = 'Kuota kod promosi ini telah habis.';
        }

        return $errors;
    }

    public static function on_after_create_entry(int $entry_id, int $form_id): void
    {
        $mgr = Manager::get_instance();
        if ($form_id !== (int) $mgr->s('form_id')) return;
        if (!CampaignEngine::is_active()) return;

        $post_values = [
            'form_id'   => $form_id,
            'item_meta' => self::read_current_meta($entry_id),
        ];
        $ctx = self::build_ctx('created', $entry_id, $post_values);
        CampaignEngine::claim_slot($ctx);
    }

    public static function on_after_update_entry(int $entry_id, int $form_id): void
    {
        $mgr = Manager::get_instance();
        if ($form_id !== (int) $mgr->s('form_id')) return;

        $values = [
            'form_id'   => $form_id,
            'item_meta' => self::read_current_meta($entry_id),
        ];

        // Log Pasif transition before building $ctx so the log row is visible
        // to build_ctx's status_log query within the same request.
        $status_label_fid = (int) $mgr->s('status_label_field_id');
        $new_label        = $values['item_meta'][$status_label_fid] ?? null;
        $prev_label       = self::get_field_snapshot_or_fallback($entry_id, $status_label_fid);
        self::write_pasif_log_if_needed($entry_id, $prev_label, $new_label);

        if (!CampaignEngine::is_active()) return;

        // Field 3170 integrity re-write: restore code if already counted and code is blank
        global $wpdb;
        $campaign = CampaignEngine::get_active();
        if ($campaign) {
            $counted_row = $wpdb->get_row($wpdb->prepare(
                "SELECT promo_code FROM {$wpdb->prefix}home_promo_counted
                  WHERE entry_id = %d AND campaign_id = %d LIMIT 1",
                $entry_id, $campaign->id
            ));
            if ($counted_row) {
                $promo_fid      = (int) $mgr->s('promo_field_id');
                $submitted_code = $values['item_meta'][$promo_fid] ?? '';
                if ((string) $submitted_code !== (string) $counted_row->promo_code) {
                    \FrmEntryMeta::update_entry_meta(
                        $entry_id, $promo_fid, null, $counted_row->promo_code
                    );
                }
                return; // already counted — no new slot
            }
        }

        $ctx = self::build_ctx('updated', $entry_id, $values);
        CampaignEngine::claim_slot($ctx);
    }

    public static function write_pasif_log_if_needed(int $entry_id, ?string $prev_label, ?string $new_label): void
    {
        if ($new_label !== 'Pasif') return;
        if ($prev_label === 'Pasif') return; // no transition

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}home_promo_status_log
               (entry_id, from_status, to_status, logged_at)
             VALUES (%d, %s, 'Pasif', UTC_TIMESTAMP())",
            $entry_id, $prev_label ?? 'unknown'
        ));
    }

    private static function read_current_meta(int $entry_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d",
            $entry_id
        ), ARRAY_A);
        $meta = [];
        foreach ((array) $rows as $row) {
            $meta[(int) $row['field_id']] = $row['meta_value'];
        }
        return $meta;
    }
}

// Wire up hooks on WordPress init (not in tests — ABSPATH guard above protects this)
if (defined('ABSPATH')) {
    add_action('init', ['HPM\\HookDispatcher', 'init']);
}
