<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

// Basic Formidable hooks wiring

require_once __DIR__ . '/utils.php';

// NEW REGISTRATION: Handle new entries when form is submitted
add_action('frm_after_create_entry', function($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    if ((int)$form_id !== (int)$mgr->s('form_id')) return;
    if (!$mgr->is_active()) return;
    $daftar_field = (int)$mgr->s('daftar_field_id');
    
    $daftar_val = ff_get_entry_meta($entry_id, $daftar_field);
    if ($daftar_val === 'Ya') {
        $mgr->record_activation($entry_id);
    }
}, 10, 2);

// NEW REGISTRATION: Set default promo value on new entry creation
add_filter('frm_pre_create_entry', function($values) {
    $mgr = Manager::get_instance();
    $form_id = !empty($values['form_id']) ? (int)$values['form_id'] : 0;
    if ($form_id !== (int)$mgr->s('form_id')) return $values;
    if (!isset($values['item_meta']) || !is_array($values['item_meta'])) $values['item_meta'] = [];
    $promo_key = (string) $mgr->s('promo_field_id');
    $values['item_meta'][$promo_key] = 'Tiada';
    return $values;
});

// REACTIVATION: Check eligibility when edit form (form 41) is loaded
add_filter('frm_setup_edit_fields_vars', function($values, $field, $entry_id) {
    $mgr = Manager::get_instance();
    
    // Only run for form 41 (edit form)
    if (!isset($field->form_id) || (int)$field->form_id !== 41) return $values;
    if (!$mgr->is_active()) return $values;
    
    error_log('[HPM] Form 41 loaded for entry ' . $entry_id);
    
    // Get current status and pasif date
    $status_field = (int)$mgr->s('status_field_id');
    $pasif_field = (int)$mgr->s('pasif_date_field_id');
    
    error_log('[HPM] Querying fields - Status field: ' . $status_field . ', Pasif field: ' . $pasif_field);
    
    $current_status = ff_get_entry_meta($entry_id, $status_field);
    $pasif_date = ff_get_entry_meta($entry_id, $pasif_field);
    
    error_log('[HPM] Retrieved values - Status: ' . var_export($current_status, true) . ' (type: ' . gettype($current_status) . '), Pasif date: ' . var_export($pasif_date, true));
    
    error_log('[HPM] Current status: ' . var_export($current_status, true) . ', Pasif date: ' . var_export($pasif_date, true));
    
    // Check if status is pasif (2) and has pasif date
    if ($current_status === '2' && !empty($pasif_date)) {
        try {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $pasif_date, new \DateTimeZone('Asia/Kuala_Lumpur'));
            if (!$dt) $dt = new \DateTime($pasif_date, new \DateTimeZone('Asia/Kuala_Lumpur'));
            $pasif_ts = $dt->getTimestamp();
        } catch (\Exception $e) {
            $pasif_ts = 0;
        }
        
        $days_inactive = $pasif_ts ? ((time() - $pasif_ts) / 86400) : 0;
        
        error_log('[HPM] Days inactive: ' . $days_inactive);
        
        // If > 90 days and not already reactivated, tag as qualified
        if ($days_inactive > 90 && !DB::has_reactivation($entry_id)) {
            error_log('[HPM] Entry ' . $entry_id . ' qualifies for reactivation promo');
            
            // Store qualification data in transient for submission processing
            set_transient('hpm_reactivation_qualified_' . $entry_id, [
                'old_status' => $current_status,
                'pasif_date' => $pasif_date,
                'qualified_at' => time(),
            ], 3600); // 1 hour expiry
        }
    }
    
    return $values;
}, 10, 3);

// REACTIVATION: Process qualified entries after form 41 submission
add_action('frm_after_update_entry', function($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    
    // Only run for form 41
    if ((int)$form_id !== 41) return;
    if (!$mgr->is_active()) return;
    
    error_log('[HPM] Form 41 submitted for entry ' . $entry_id);
    
    // Check if this entry was tagged as qualified during form load
    $qualification = get_transient('hpm_reactivation_qualified_' . $entry_id);
    
    if (!$qualification) {
        error_log('[HPM] No reactivation qualification found for entry ' . $entry_id);
        return;
    }
    
    error_log('[HPM] Found reactivation qualification: ' . print_r($qualification, true));
    
    // Get new status after submission
    $status_field = (int)$mgr->s('status_field_id');
    
    error_log('[HPM] Querying status field: ' . $status_field . ' for entry: ' . $entry_id);
    
    $new_status = ff_get_entry_meta($entry_id, $status_field);
    
    error_log('[HPM] New status after submission: ' . var_export($new_status, true));
    
    // Check if status changed from pasif (2) to aktif (1)
    if ($qualification['old_status'] === '2' && $new_status === '1') {
        error_log('[HPM] Status changed from pasif to aktif - processing reactivation');
        
        // Process the reactivation
        $mgr->record_reactivation(
            $entry_id,
            $qualification['old_status'],
            $new_status,
            $qualification['pasif_date']
        );
        
        // Clean up qualification transient
        delete_transient('hpm_reactivation_qualified_' . $entry_id);
    } else {
        error_log('[HPM] Status did not change to aktif - skipping reactivation');
    }
}, 99, 2);

// OLD APPROACH (DEPRECATED): Capture previous meta - keeping for now but form 41 approach above is better
// Capture previous meta BEFORE any database update (hook for reactivation flow)
// Priority 5 to run early before Formidable updates the meta
add_action('frm_pre_update_entry', function($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    if ((int)$form_id !== (int)$mgr->s('form_id')) return;
    
    error_log('[HPM] frm_pre_update_entry - Capturing OLD values for entry ' . $entry_id);
    
    // Get current (old) values directly from database BEFORE update
    $status_field = (int)$mgr->s('status_field_id');
    $pasif_field = (int)$mgr->s('pasif_date_field_id');
    
    $old_status = ff_get_entry_meta($entry_id, $status_field);
    $old_pasif = ff_get_entry_meta($entry_id, $pasif_field);
    
    error_log('[HPM] Captured OLD status: ' . var_export($old_status, true) . ', OLD pasif: ' . var_export($old_pasif, true));
    
    $prev_data = [
        'status' => $old_status,
        'pasif_date' => $old_pasif,
    ];
    
    // Use 5-minute expiry instead of 60 seconds
    set_transient('hpm_prev_meta_' . $entry_id, $prev_data, 300);
}, 5, 2);

// After update: detect reactivation
add_action('frm_after_update_entry', function($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    
    error_log('[HPM] frm_after_update_entry triggered for entry: ' . $entry_id . ', form: ' . $form_id);
    
    if ((int)$form_id !== (int)$mgr->s('form_id')) {
        error_log('[HPM] Form ID mismatch. Expected: ' . $mgr->s('form_id') . ', Got: ' . $form_id);
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
    $status_field = (int)$mgr->s('status_field_id');
    $new_status = ff_get_entry_meta($entry_id, $status_field);
    
    error_log('[HPM] New status: ' . var_export($new_status, true));
    
    // Check reactivation conditions: status changed from 2 to 1, has pasif date, and > 90 days
    if ($old_status === '2' && $new_status === '1' && !empty($old_pasif)) {
        error_log('[HPM] Reactivation conditions met for entry ' . $entry_id);
        try {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $old_pasif, new \DateTimeZone('Asia/Kuala_Lumpur'));
            if (!$dt) $dt = new \DateTime($old_pasif, new \DateTimeZone('Asia/Kuala_Lumpur'));
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