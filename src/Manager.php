<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

class Manager {
    private static $instance = null;
    private $settings = [];

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = isset($GLOBALS['get_option']) ? $GLOBALS['get_option']('home_promo_manager_settings', []) : [];
        // ensure sensible defaults
        $defaults = [
            'start' => '2025-12-01 12:00:00',
            'end' => '2025-12-24 23:59:00',
            'form_id' => 13,
            'promo_field_id' => 3170,
            'daftar_field_id' => 196,
            'max' => 480,
            'tier1_max' => 240,
            'code_tier1' => 'promo24',
            'code_tier2' => 'promo12',
            'admin_email' => isset($GLOBALS['get_option']) ? $GLOBALS['get_option']('admin_email') : '',
        ];
        $this->settings = isset($GLOBALS['wp_parse_args']) ? $GLOBALS['wp_parse_args']($this->settings, $defaults) : $defaults;
    }

    public function s($key) {
        return $this->settings[$key] ?? null;
    }

    public function is_active() {
        // interpret start/end as Asia/Kuala_Lumpur, compare in site tz
        $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        try {
            $start = new \DateTimeImmutable($this->s('start'), $tz);
            $end = new \DateTimeImmutable($this->s('end'), $tz);
        } catch (\Exception $e) {
            return false;
        }
        $now = new \DateTimeImmutable('now', $tz);
        // convert start/end to site tz for comparison
        $siteTz = $tz;
        $start = $start->setTimezone($siteTz);
        $end = $end->setTimezone($siteTz);
        return ($now >= $start && $now < $end);
    }

    public function get_count() {
        return DB::count_entries();
    }

    public function handle_new_registration($entry_id, $form_id) {
        if ((int)$form_id !== (int)$this->s('form_id')) return;
        if (!$this->is_active()) return;
        // Use helper to get entry meta instead of FrmEntryMeta
        $daftar = function_exists('ff_get_entry_meta') ? ff_get_entry_meta($entry_id, (int)$this->s('daftar_field_id')) : null;
        if ($daftar === 'Ya') {
            $this->record_activation($entry_id);
        }
    }

    public function record_activation($entry_id) {
        // return true if newly recorded
        $inserted = DB::insert_entry($entry_id);
        if (!$inserted) return false;
        // write promo code into entry meta if Formidable available (best-effort)
        $count = $this->get_count();
        $code = $this->get_current_code($count);
        $promo_field_id = (int)$this->s('promo_field_id');
        // Use direct DB update for promo code
        global $wpdb;
        if ($code) {
            $wpdb->replace(
                $wpdb->prefix . 'frm_item_metas',
                [
                    'item_id' => $entry_id,
                    'field_id' => $promo_field_id,
                    'meta_value' => $code
                ],
                ['%d', '%d', '%s']
            );
        }
        // if milestone, send basic email
        $tier1 = (int)$this->s('tier1_max');
        $max = (int)$this->s('max');
        if (in_array($count, [$tier1, ($tier1*2), $max], true)) {
            $subject = 'HOME Promo – milestone';
            $msg = "Entry: {$entry_id}\nCode: {$code}\nTotal: {$count}/{$max}";
            if (isset($GLOBALS['wp_mail'])) {
                $GLOBALS['wp_mail']($this->s('admin_email'), $subject, $msg);
            }
        }
        return true;
    }

    public function get_current_code($count = null) {
        if ($count === null) $count = $this->get_count();
        $max = (int)$this->s('max');
        $tier1 = (int)$this->s('tier1_max');
        if ($count >= $max) return '';
        return ($count < $tier1) ? $this->s('code_tier1') : $this->s('code_tier2');
    }
}

// Patch: use WordPress global functions directly, do not redeclare
// Patch: reference FrmEntryMeta as global class, do not alias