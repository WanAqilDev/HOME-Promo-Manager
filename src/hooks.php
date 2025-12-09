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
// Priority 5 to run early before Formidable updates the meta
add_action('frm_pre_update_entry', function ($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    if ((int) $form_id !== (int) $mgr->s('form_id'))
        return;

    error_log('[HPM] frm_pre_update_entry - Capturing OLD values for entry ' . $entry_id);

    // Get current (old) values directly from database BEFORE update
    $status_field = (int) $mgr->s('status_field_id');
    $pasif_field = (int) $mgr->s('pasif_date_field_id');

    $old_status = ff_get_field_value_robust($entry_id, $status_field);
    $old_pasif = ff_get_field_value_robust($entry_id, $pasif_field);

    error_log('[HPM] Captured OLD status: ' . var_export($old_status, true) . ', OLD pasif: ' . var_export($old_pasif, true));

    $prev_data = [
        'status' => $old_status,
        'pasif_date' => $old_pasif,
    ];

    // Use 5-minute expiry instead of 60 seconds
    set_transient('hpm_prev_meta_' . $entry_id, $prev_data, 300);
}, 5, 2);

// After update: detect reactivation
add_action('frm_after_update_entry', function ($entry_id, $form_id) {
    $mgr = Manager::get_instance();

    error_log('[HPM] frm_after_update_entry triggered for entry: ' . $entry_id . ', form: ' . $form_id);

    // Form 13 is used for BOTH new registrations AND edits/reactivations
    if ((int) $form_id !== (int) $mgr->s('form_id')) {
        return;
    }

    if (!$mgr->is_active()) {
        error_log('[HPM] Promo not active. Skipping reactivation check.');
        return;
    }

    // Check if already reactivated (prevent duplicates)
    if (DB::has_reactivation($entry_id)) {
        error_log('[HPM] Entry ' . $entry_id . ' already has reactivation. Skipping.');
        delete_transient('hpm_prev_meta_' . $entry_id);
        return;
    }

    $prev = get_transient('hpm_prev_meta_' . $entry_id) ?: [];
    delete_transient('hpm_prev_meta_' . $entry_id);

    $old_status = $prev['status'] ?? null;
    $old_pasif = $prev['pasif_date'] ?? null;

    error_log('[HPM] Previous meta - Status: ' . var_export($old_status, true) . ', Pasif date: ' . var_export($old_pasif, true));

    // Get new status
    $status_field = (int) $mgr->s('status_field_id');

    error_log('[HPM] Checking entry ' . $entry_id . ' for status field ' . $status_field);

    // Debug: Check what fields exist for this entry
    global $wpdb;
    $all_metas = $wpdb->get_results($wpdb->prepare(
        "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d",
        $entry_id
    ), ARRAY_A);
    error_log('[HPM] All meta fields for entry ' . $entry_id . ': ' . print_r($all_metas, true));

    $new_status = ff_get_field_value_robust($entry_id, $status_field);

    error_log('[HPM] New status: ' . var_export($new_status, true));

    // Check reactivation conditions: status changed from 2 to 1, has pasif date, and > 90 days
    if ($old_status === '2' && $new_status === '1' && !empty($old_pasif)) {
        error_log('[HPM] Reactivation conditions met for entry ' . $entry_id);
        try {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $old_pasif, new \DateTimeZone('Asia/Kuala_Lumpur'));
            if (!$dt)
                $dt = new \DateTime($old_pasif, new \DateTimeZone('Asia/Kuala_Lumpur'));
            $pasif_ts = $dt->getTimestamp();
        } catch (\Exception $e) {
            $pasif_ts = 0;
        }

        $days_inactive = $pasif_ts ? ((time() - $pasif_ts) / 86400) : 0;

        error_log('[HPM] Days inactive: ' . $days_inactive);

        if ($days_inactive > 90) {
            error_log('[HPM] Triggering reactivation for entry ' . $entry_id);
            // Process reactivation
            $mgr->record_reactivation($entry_id, $old_status, $new_status, $old_pasif);
        } else {
            error_log('[HPM] Not enough days inactive (' . $days_inactive . ' <= 90). Skipping.');
        }
    } else {
        error_log('[HPM] Reactivation conditions NOT met. Old: ' . var_export($old_status, true) . ', New: ' . var_export($new_status, true) . ', Pasif: ' . var_export($old_pasif, true));
    }
}, 10, 2);