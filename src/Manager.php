<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

class Manager
{
    private static $instance = null;
    private $settings = [];

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->settings = isset($GLOBALS['get_option']) ? $GLOBALS['get_option']('home_promo_manager_settings', []) : [];
        // ensure sensible defaults
        $defaults = [
            'start' => '2099-01-01 00:00:00', // Default to future to prevent accidental activation
            'end' => '2099-01-01 23:59:00',
            'timezone' => 'Asia/Kuala_Lumpur',
            'form_id' => 13,
            'promo_field_id' => 3170,
            'daftar_field_id' => 196,
            'daftar_trigger_value' => 'Ya',
            'status_field_id' => 199,
            'pasif_date_field_id' => 1698,
            'max' => 480,
            'tier1_max' => 240,
            'code_tier1' => 'promo24',
            'code_tier2' => 'promo12',
            'debug_mode' => false,
            'admin_email' => isset($GLOBALS['get_option']) ? $GLOBALS['get_option']('admin_email') : '',
        ];
        $this->settings = isset($GLOBALS['wp_parse_args']) ? $GLOBALS['wp_parse_args']($this->settings, $defaults) : $defaults;

        if ($this->s('debug_mode')) {
            error_log('[HPM-DEBUG] Manager initialized. Settings: ' . print_r($this->settings, true));
        }
    }

    public function s($key)
    {
        return $this->settings[$key] ?? null;
    }

    public function is_active()
    {
        // interpret start/end as configured timezone, compare in site tz
        $tz_string = $this->s('timezone') ?: 'Asia/Kuala_Lumpur';
        try {
            $tz = new \DateTimeZone($tz_string);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        }

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
        $end = $end->setTimezone($siteTz);

        $active = ($now >= $start && $now < $end);

        if ($this->s('debug_mode') || true) { // FORCE LOGGING TEMPORARILY
            error_log(sprintf(
                '[HPM-DEBUG-FORCE] is_active check: Now=%s, Start=%s, End=%s, Result=%s',
                $now->format('Y-m-d H:i:s'),
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $active ? 'TRUE' : 'FALSE'
            ));
        }

        return $active;
    }

    public function get_count()
    {
        return DB::count_entries();
    }

    public function handle_new_registration($entry_id, $form_id)
    {
        if ((int) $form_id !== (int) $this->s('form_id'))
            return;
        if (!$this->is_active())
            return;
        // Use helper to get entry meta instead of FrmEntryMeta
        $daftar = function_exists('ff_get_entry_meta') ? ff_get_entry_meta($entry_id, (int) $this->s('daftar_field_id')) : null;
        if ($daftar === 'Ya') {
            $this->record_activation($entry_id);
        }
    }

    public function record_activation($entry_id)
    {
        // return true if newly recorded
        $max = (int) $this->s('max');
        $inserted = DB::insert_entry($entry_id, $max);
        if (!$inserted)
            return false;
        // write promo code into entry meta if Formidable available (best-effort)
        $count = $this->get_count();
        $code = $this->get_current_code($count);
        $promo_field_id = (int) $this->s('promo_field_id');
        // Use helper function for promo code update
        if ($code) {
            ff_update_entry_meta($entry_id, $promo_field_id, $code);
        }
        // if milestone, send basic email
        $tier1 = (int) $this->s('tier1_max');
        $max = (int) $this->s('max');
        if (in_array($count, [$tier1, ($tier1 * 2), $max], true)) {
            $subject = 'HOME Promo – milestone';
            $msg = "Entry: {$entry_id}\nCode: {$code}\nTotal: {$count}/{$max}";
            if (isset($GLOBALS['wp_mail'])) {
                $GLOBALS['wp_mail']($this->s('admin_email'), $subject, $msg);
            }
        }
        return true;
    }

    /**
     * Record a reactivation for returning clients
     *
     * @param int $entry_id
     * @param string $old_status
     * @param string $new_status
     * @param string $pasif_date
     * @return bool
     */
    public function record_reactivation($entry_id, $old_status, $new_status, $pasif_date)
    {
        error_log('[HPM] Manager::record_reactivation called for entry ' . $entry_id);

        // Get promo code first
        $count = $this->get_count();
        $code = $this->get_current_code($count);

        error_log('[HPM] Count: ' . $count . ', Promo code: ' . $code);

        // Log to reactivation table
        $logged = DB::log_reactivation($entry_id, $old_status, $new_status, $pasif_date, $code);

        if (!$logged) {
            error_log('[HPM] Failed to log reactivation to table');
            return false;
        }

        error_log('[HPM] Reactivation logged successfully');

        // Update entry meta with promo code
        $promo_field_id = (int) $this->s('promo_field_id');
        if ($code) {
            error_log('[HPM] Updating promo field ' . $promo_field_id . ' with code: ' . $code);
            ff_update_entry_meta($entry_id, $promo_field_id, $code);
        }

        // Mark entry as reactivated with a flag
        error_log('[HPM] Setting reactivation flags');
        ff_update_entry_meta($entry_id, 9999, 'yes'); // Use a custom field ID for reactivation flag

        // Use configured timezone for the date
        $tz_string = $this->s('timezone') ?: 'Asia/Kuala_Lumpur';
        try {
            $tz = new \DateTimeZone($tz_string);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        }
        $now = new \DateTime('now', $tz);
        ff_update_entry_meta($entry_id, 9998, $now->format('Y-m-d H:i:s')); // Use a custom field ID for reactivation date

        // Count this as an activation
        error_log('[HPM] Counting reactivation as activation');
        $max = (int) $this->s('max');
        DB::insert_entry($entry_id, $max);

        error_log('[HPM] Reactivation complete for entry ' . $entry_id);
        return true;
    }

    public function get_current_code($count = null)
    {
        if ($count === null)
            $count = $this->get_count();
        $max = (int) $this->s('max');
        $tier1 = (int) $this->s('tier1_max');
        if ($count >= $max)
            return '';
        return ($count < $tier1) ? $this->s('code_tier1') : $this->s('code_tier2');
    }
}

// Patch: use WordPress global functions directly, do not redeclare
// Patch: reference FrmEntryMeta as global class, do not alias