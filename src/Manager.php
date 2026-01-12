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
        if (function_exists('get_option')) {
            $this->settings = get_option('home_promo_manager_settings', []);
        } elseif (isset($GLOBALS['get_option'])) {
            $this->settings = $GLOBALS['get_option']('home_promo_manager_settings', []);
        } else {
            $this->settings = [];
        }
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
            'admin_email' => function_exists('get_option') ? get_option('admin_email') : (isset($GLOBALS['get_option']) ? $GLOBALS['get_option']('admin_email') : ''),
        ];
        if (function_exists('wp_parse_args')) {
            $this->settings = wp_parse_args($this->settings, $defaults);
        } elseif (isset($GLOBALS['wp_parse_args'])) {
            $this->settings = $GLOBALS['wp_parse_args']($this->settings, $defaults);
        } else {
            $this->settings = array_merge($defaults, $this->settings);
        }

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

        if ($this->s('debug_mode')) {
            error_log(sprintf(
                '[HPM-DEBUG] is_active check: Now=%s, Start=%s, End=%s, Result=%s',
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

    /**
     * Validate and record a promo code registration (SMART26)
     * Respects code_assignment_mode setting
     *
     * @param string $code User-entered promo code
     * @param int $entry_id Formidable entry ID
     * @param string $branch Branch selection
     * @param string $category User category (new/passive/diagnostic/lead)
     * @return array ['success' => bool, 'message' => string, 'code' => string]
     */
    public function validate_and_record($code, $entry_id, $branch = '', $category = 'new')
    {
        if ($this->s('debug_mode')) {
            error_log(sprintf(
                '[HPM-DEBUG] validate_and_record called: code=%s, entry=%d, branch=%s, category=%s',
                $code, $entry_id, $branch, $category
            ));
        }

        // Check if promo is active
        if (!$this->is_active()) {
            return [
                'success' => false,
                'message' => 'Promo period has ended.',
                'code' => ''
            ];
        }

        // Get code assignment mode
        $mode = $this->s('code_assignment_mode') ?: 'manual';

        if ($mode === 'auto') {
            // Legacy mode: auto-assign tier-based code
            if ($this->s('debug_mode')) {
                error_log('[HPM-DEBUG] Using auto-assign mode (legacy)');
            }
            $count = $this->get_count();
            $assigned_code = $this->get_current_code($count);
            
            if (!$assigned_code) {
                return [
                    'success' => false,
                    'message' => 'All promo slots are full.',
                    'code' => ''
                ];
            }

            // Record with auto-assigned code
            $max = (int) $this->s('max');
            $inserted = DB::insert_entry($entry_id, $max);
            
            if ($inserted) {
                // Update entry with assigned code
                $promo_field_id = (int) $this->s('promo_field_id');
                ff_update_entry_meta($entry_id, $promo_field_id, $assigned_code);
                
                return [
                    'success' => true,
                    'message' => 'Registration successful.',
                    'code' => $assigned_code
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to record registration.',
                'code' => ''
            ];
        }

        // Manual mode: SMART26 code validation
        if ($this->s('debug_mode')) {
            error_log('[HPM-DEBUG] Using manual mode (SMART26)');
        }

        // Validate code format
        if (empty($code)) {
            return [
                'success' => false,
                'message' => 'Please enter a promo code.',
                'code' => ''
            ];
        }

        // Validate code exists and is active
        $code_config = $this->get_code_from_settings($code);
        if (!$code_config) {
            return [
                'success' => false,
                'message' => 'Invalid promo code.',
                'code' => ''
            ];
        }

        if (!($code_config['active'] ?? true)) {
            return [
                'success' => false,
                'message' => 'This promo code is no longer active.',
                'code' => ''
            ];
        }

        // Check code quota
        $max_for_code = (int) ($code_config['max'] ?? 0);
        $used = DB::get_code_usage($code);
        
        if ($used >= $max_for_code) {
            return [
                'success' => false,
                'message' => 'This promo code has reached its maximum limit.',
                'code' => ''
            ];
        }

        // Record with code-specific tracking
        $inserted = DB::insert_entry_with_code($entry_id, $code, $branch, $category, $max_for_code);
        
        if ($inserted) {
            // Update entry with validated code
            $promo_field_id = (int) $this->s('promo_field_id');
            ff_update_entry_meta($entry_id, $promo_field_id, $code);
            
            // Update branch field if provided
            if (!empty($branch)) {
                $branch_field_id = (int) $this->s('branch_field_id');
                if ($branch_field_id) {
                    ff_update_entry_meta($entry_id, $branch_field_id, $branch);
                }
            }
            
            if ($this->s('debug_mode')) {
                error_log(sprintf(
                    '[HPM-DEBUG] Code %s registered successfully for entry %d (used: %d/%d)',
                    $code, $entry_id, $used + 1, $max_for_code
                ));
            }

            return [
                'success' => true,
                'message' => 'Registration successful! Your promo code has been applied.',
                'code' => $code
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to record registration. Please try again.',
            'code' => ''
        ];
    }

    /**
     * Validate a promo code without recording
     *
     * @param string $code Promo code to validate
     * @return array ['valid' => bool, 'message' => string, 'remaining' => int]
     */
    public function validate_code($code)
    {
        if (empty($code)) {
            return [
                'valid' => false,
                'message' => 'Please enter a promo code.',
                'remaining' => 0
            ];
        }

        // Get code configuration
        $code_config = $this->get_code_from_settings($code);
        if (!$code_config) {
            return [
                'valid' => false,
                'message' => 'Invalid promo code.',
                'remaining' => 0
            ];
        }

        if (!($code_config['active'] ?? true)) {
            return [
                'valid' => false,
                'message' => 'This promo code is no longer active.',
                'remaining' => 0
            ];
        }

        // Check quota
        $max = (int) ($code_config['max'] ?? 0);
        $used = DB::get_code_usage($code);
        $remaining = max(0, $max - $used);

        if ($remaining <= 0) {
            return [
                'valid' => false,
                'message' => 'This promo code has reached its maximum limit.',
                'remaining' => 0
            ];
        }

        // Check if promo is active
        if (!$this->is_active()) {
            return [
                'valid' => false,
                'message' => 'Promo period has ended.',
                'remaining' => 0
            ];
        }

        return [
            'valid' => true,
            'message' => sprintf('Valid! %d slot%s remaining.', $remaining, $remaining === 1 ? '' : 's'),
            'remaining' => $remaining
        ];
    }

    /**
     * Get code configuration from settings
     *
     * @param string $code Promo code
     * @return array|null Code configuration or null if not found
     */
    private function get_code_from_settings($code)
    {
        $promo_codes = $this->s('promo_codes') ?: [];
        
        if ($this->s('debug_mode')) {
            error_log(sprintf(
                '[HPM-DEBUG] get_code_from_settings(%s) - Available codes: %s',
                $code,
                implode(', ', array_keys($promo_codes))
            ));
        }
        
        return $promo_codes[$code] ?? null;
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
            if (function_exists('wp_mail')) {
                wp_mail($this->s('admin_email'), $subject, $msg);
            } elseif (isset($GLOBALS['wp_mail'])) {
                $GLOBALS['wp_mail']($this->s('admin_email'), $subject, $msg);
            }
        }

        // Set cookie for frontend popup
        if (!headers_sent()) {
            setcookie('hpm_promo_eligible', $code, time() + 3600, '/'); // Expires in 1 hour
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
    public function record_reactivation($entry_id, $old_status, $new_status, $pasif_date, $user_code = '')
    {
        error_log('[HPM] Manager::record_reactivation called for entry ' . $entry_id);

        // Get code assignment mode
        $mode = $this->s('code_assignment_mode') ?: 'manual';
        $code = '';
        $branch = '';
        $category = 'passive'; // Reactivations are passive category

        if ($mode === 'auto') {
            // Auto mode: use tier-based code
            $count = $this->get_count();
            $code = $this->get_current_code($count);
            error_log('[HPM] Auto mode - Count: ' . $count . ', Promo code: ' . $code);
        } else {
            // Manual mode: validate user-entered code
            if (empty($user_code)) {
                // Try to get code from entry meta (may have been set during validation)
                $promo_field_id = (int) $this->s('promo_field_id');
                $user_code = ff_get_field_value_robust($entry_id, $promo_field_id);
            }

            if (empty($user_code)) {
                error_log('[HPM] SMART26 mode - No promo code provided for reactivation');
                return false;
            }

            // Validate the code
            $validation = $this->validate_code($user_code);
            if (!$validation['valid']) {
                error_log('[HPM] SMART26 mode - Invalid code: ' . $validation['message']);
                return false;
            }

            $code = $user_code;
            
            // Get branch if available
            $branch_field_id = (int) $this->s('branch_field_id');
            if ($branch_field_id) {
                $branch = ff_get_field_value_robust($entry_id, $branch_field_id) ?: '';
            }

            error_log('[HPM] SMART26 mode - Code validated: ' . $code);
        }

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

        // Count this as an activation (only if not already counted)
        error_log('[HPM] Counting reactivation as activation');
        if (!DB::entry_exists($entry_id)) {
            if ($mode === 'auto') {
                $max = (int) $this->s('max');
                DB::insert_entry($entry_id, $max);
            } else {
                // SMART26 mode: use code-specific insertion
                $code_config = $this->get_code_from_settings($code);
                $max_for_code = (int) ($code_config['max'] ?? 0);
                DB::insert_entry_with_code($entry_id, $code, $branch, $category, $max_for_code);
            }
            error_log('[HPM] Entry inserted into promo table');
        } else {
            error_log('[HPM] Entry already exists in promo table - skipping insertion');
        }

        error_log('[HPM] Reactivation complete for entry ' . $entry_id);

        // Set cookie for frontend popup
        if (!headers_sent()) {
            setcookie('hpm_promo_eligible', $code, time() + 3600, '/'); // Expires in 1 hour
        }

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