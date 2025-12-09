<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

// Basic Formidable hooks wiring

require_once __DIR__ . '/utils.php';

// NEW REGISTRATION: Handle new entries when form is submitted
add_action('frm_after_create_entry', function ($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    if ((int) $form_id !== (int) $mgr->s('form_id'))
        return;
    if (!$mgr->is_active())
        return;
    $daftar_field = (int) $mgr->s('daftar_field_id');

    $daftar_val = ff_get_entry_meta($entry_id, $daftar_field);
    if ($daftar_val === 'Ya') {
        $mgr->record_activation($entry_id);
    }
}, 10, 2);

// NEW REGISTRATION: Set default promo value on new entry creation
add_filter('frm_pre_create_entry', function ($values) {
    $mgr = Manager::get_instance();
    $form_id = !empty($values['form_id']) ? (int) $values['form_id'] : 0;
    if ($form_id !== (int) $mgr->s('form_id'))
        return $values;
    if (!isset($values['item_meta']) || !is_array($values['item_meta']))
        $values['item_meta'] = [];
    $promo_key = (string) $mgr->s('promo_field_id');
    $values['item_meta'][$promo_key] = 'Tiada';
    return $values;
});

// AUTO-SET PASIF DATE: When status changes to pasif (2), automatically set the pasif date to today
add_action('frm_after_update_entry', function ($entry_id, $form_id) {
    $mgr = Manager::get_instance();

    // Only run for Form 13
    if ((int) $form_id !== (int) $mgr->s('form_id'))
        return;

    $status_field = (int) $mgr->s('status_field_id');
    $pasif_field = (int) $mgr->s('pasif_date_field_id');

    if (!$status_field || !$pasif_field)
        return;

    // Get current status
    $current_status = ff_get_field_value_robust($entry_id, $status_field);

    error_log('[HPM Auto-Date] Entry ' . $entry_id . ' status: ' . var_export($current_status, true));

    // If status is pasif (2), ensure pasif date is set to today
    if ($current_status === '2') {
        $existing_pasif_date = ff_get_field_value_robust($entry_id, $pasif_field);

        // Only update if empty or doesn't exist yet
        if (empty($existing_pasif_date)) {
            $today = date('Y-m-d');
            error_log('[HPM Auto-Date] Setting pasif date to ' . $today . ' for entry ' . $entry_id);
            ff_update_entry_meta($entry_id, $pasif_field, $today);
        } else {
            error_log('[HPM Auto-Date] Pasif date already set to ' . $existing_pasif_date . ' - keeping existing date');
        }
    }
}, 5, 2);



// REACTIVATION: Capture previous meta BEFORE any database update
// REACTIVATION: Capture previous meta BEFORE any database update
// Priority 5 to run early before Formidable updates the meta
add_action('frm_pre_update_entry', function ($arg1, $arg2 = null, $arg3 = null) {
    $mgr = Manager::get_instance();

    error_log('[HPM-DEBUG] frm_pre_update_entry FIRED.');
    error_log('[HPM-DEBUG] Arg1 type: ' . gettype($arg1) . ', value: ' . print_r($arg1, true));
    error_log('[HPM-DEBUG] Arg2 type: ' . gettype($arg2) . ', value: ' . print_r($arg2, true));
    error_log('[HPM-DEBUG] Arg3 type: ' . gettype($arg3) . ', value: ' . print_r($arg3, true));

    // Attempt to identify Entry ID and Form ID
    $entry_id = 0;
    $form_id = 0;

    // Hypothesis 1: ($entry_id, $form_id)
    if (is_numeric($arg1) && is_numeric($arg2)) {
        $entry_id = (int) $arg1;
        $form_id = (int) $arg2;
    }
    // Hypothesis 2: ($entry_id, $values)
    elseif (is_numeric($arg1) && is_array($arg2)) {
        $entry_id = (int) $arg1;
        $form_id = isset($arg2['form_id']) ? (int) $arg2['form_id'] : 0;
    }

    // Fallback: Look up Form ID if we have Entry ID
    if ($entry_id > 0 && $form_id === 0) {
        global $wpdb;
        $form_id = (int) $wpdb->get_var($wpdb->prepare("SELECT form_id FROM {$wpdb->prefix}frm_items WHERE id = %d", $entry_id));
        error_log('[HPM-DEBUG] Looked up Form ID from DB: ' . $form_id);
    }

    error_log(sprintf('[HPM-DEBUG] Resolved: Entry: %d, Form: %d', $entry_id, $form_id));

    if ($form_id !== (int) $mgr->s('form_id')) {
        error_log('[HPM-DEBUG] Form ID mismatch. Expected: ' . $mgr->s('form_id') . ', Got: ' . $form_id);
        return;
    }

    error_log('[HPM-DEBUG] Capturing OLD values for entry ' . $entry_id);

    // Get current (old) values directly from database BEFORE update
    $status_field = (int) $mgr->s('status_field_id');
    $pasif_field = (int) $mgr->s('pasif_date_field_id');

    $old_status = ff_get_field_value_robust($entry_id, $status_field);
    $old_pasif = ff_get_field_value_robust($entry_id, $pasif_field);

    error_log(sprintf('[HPM-DEBUG] Captured OLD: Status=%s, PasifDate=%s', var_export($old_status, true), var_export($old_pasif, true)));

    $prev_data = [
        'status' => $old_status,
        'pasif_date' => $old_pasif,
    ];

    // Use 5-minute expiry
    set_transient('hpm_prev_meta_' . $entry_id, $prev_data, 300);
}, 5, 3);

// After update: detect reactivation
add_action('frm_after_update_entry', function ($entry_id, $form_id) {
    $mgr = Manager::get_instance();

    error_log(sprintf('[HPM-DEBUG] Post-update triggered. Entry: %d, Form: %d', $entry_id, $form_id));

    // Form 13 is used for BOTH new registrations AND edits/reactivations
    if ((int) $form_id !== (int) $mgr->s('form_id')) {
        return;
    }

    if (!$mgr->is_active()) {
        error_log('[HPM-DEBUG] Promo not active. Skipping.');
        return;
    }

    // Check if already reactivated (prevent duplicates)
    if (DB::has_reactivation($entry_id)) {
        error_log('[HPM-DEBUG] Already reactivated. Skipping.');
        delete_transient('hpm_prev_meta_' . $entry_id);
        return;
    }

    $prev = get_transient('hpm_prev_meta_' . $entry_id) ?: [];
    delete_transient('hpm_prev_meta_' . $entry_id);

    if (empty($prev)) {
        error_log('[HPM-DEBUG] No previous meta found (transient missing/expired).');
        return;
    }

    $old_status = $prev['status'] ?? null;
    $old_pasif = $prev['pasif_date'] ?? null;

    error_log(sprintf('[HPM-DEBUG] Retrieved OLD from transient: Status=%s, PasifDate=%s', var_export($old_status, true), var_export($old_pasif, true)));

    // Get new status
    $status_field = (int) $mgr->s('status_field_id');
    $new_status = ff_get_field_value_robust($entry_id, $status_field);

    error_log(sprintf('[HPM-DEBUG] Retrieved NEW: Status=%s', var_export($new_status, true)));

    // Check reactivation conditions: status changed from 2 to 1, has pasif date, and > 90 days
    if ($old_status === '2' && $new_status === '1' && !empty($old_pasif)) {
        error_log('[HPM-DEBUG] Status change 2->1 detected. Checking date...');
        try {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $old_pasif, new \DateTimeZone('Asia/Kuala_Lumpur'));
            if (!$dt)
                $dt = new \DateTime($old_pasif, new \DateTimeZone('Asia/Kuala_Lumpur'));
            $pasif_ts = $dt->getTimestamp();
        } catch (\Exception $e) {
            $pasif_ts = 0;
        }

        $days_inactive = $pasif_ts ? ((time() - $pasif_ts) / 86400) : 0;

        error_log(sprintf('[HPM-DEBUG] Days inactive: %.2f', $days_inactive));

        if ($days_inactive > 90) {
            error_log('[HPM-DEBUG] QUALIFIED! Triggering reactivation.');
            // Process reactivation
            $mgr->record_reactivation($entry_id, $old_status, $new_status, $old_pasif);
        } else {
            error_log('[HPM-DEBUG] Not qualified (<= 90 days).');
        }
    } else {
        error_log('[HPM-DEBUG] Conditions not met.');
    }
}, 10, 2);