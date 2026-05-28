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

    /** @var array<int,bool> reentrancy guard */
    private static array $writing_field = [];

    public static function init(): void
    {
        add_filter('frm_validate_entry',     [self::class, 'on_validate_entry'],     10, 2);
        add_action('frm_after_create_entry', [self::class, 'on_after_create_entry'], 10, 2);
        add_action('frm_pre_update_entry',   [self::class, 'on_pre_update_entry'],   10, 2);
        add_action('frm_after_update_entry', [self::class, 'on_after_update_entry'], 10, 2);
    }

    // -----------------------------------------------------------------
    // Pre-hook snapshot
    // -----------------------------------------------------------------

    public static function on_pre_update_entry(int $entry_id, array $values): void
    {
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

    // -----------------------------------------------------------------
    // Hook handlers (stubs — filled in Tasks 9 and 10)
    // -----------------------------------------------------------------

    public static function on_validate_entry(array $errors, array $values): array
    {
        return $errors; // Filled in Task 10
    }

    public static function on_after_create_entry(int $entry_id, int $form_id): void
    {
        // Filled in Task 9
    }

    public static function on_after_update_entry(int $entry_id, array $values): void
    {
        // Filled in Task 9
    }
}

// Wire up hooks on WordPress init (not in tests — ABSPATH guard above protects this)
if (defined('ABSPATH')) {
    add_action('init', ['HPM\\HookDispatcher', 'init']);
}
