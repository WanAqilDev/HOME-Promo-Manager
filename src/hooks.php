<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

// Basic Formidable hooks wiring

require_once __DIR__ . '/utils.php';

add_action('frm_after_create_entry', function($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    if ((int)$form_id !== (int)$mgr->s('form_id')) return;
    if (!$mgr->is_active()) return;
    $daftar_field = (int)$mgr->s('daftar_field_id');
    
    // Use helper to get entry meta instead of non-existent method
    $daftar_val = ff_get_entry_meta($entry_id, $daftar_field);
    if ($daftar_val === 'Ya') {
        $mgr->record_activation($entry_id);
    }
}, 10, 2);

add_filter('frm_pre_create_entry', function($values) {
    $mgr = Manager::get_instance();
    $form_id = !empty($values['form_id']) ? (int)$values['form_id'] : 0;
    if ($form_id !== (int)$mgr->s('form_id')) return $values;
    if (!isset($values['item_meta']) || !is_array($values['item_meta'])) $values['item_meta'] = [];
    $promo_key = (string) $mgr->s('promo_field_id');
    $values['item_meta'][$promo_key] = 'Tiada';
    return $values;
});

// Capture previous meta (hook for reactivation flow)
add_action('frm_before_update_entry', function($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    if ((int)$form_id !== (int)$mgr->s('form_id')) return;
    
    // Store previous status and pasif date in longer-lived transient
    $status_field = (int)$mgr->s('status_field_id');
    $pasif_field = (int)$mgr->s('pasif_date_field_id');
    
    $prev_data = [
        'status' => ff_get_entry_meta($entry_id, $status_field),
        'pasif_date' => ff_get_entry_meta($entry_id, $pasif_field),
    ];
    
    // Use 5-minute expiry instead of 60 seconds
    set_transient('hpm_prev_meta_' . $entry_id, $prev_data, 300);
}, 10, 2);

// After update: detect reactivation
add_action('frm_after_update_entry', function($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    if ((int)$form_id !== (int)$mgr->s('form_id')) return;
    if (!$mgr->is_active()) return;
    
    // Check if already reactivated (prevent duplicates)
    if (DB::has_reactivation($entry_id)) {
        delete_transient('hpm_prev_meta_' . $entry_id);
        return;
    }
    
    $prev = get_transient('hpm_prev_meta_' . $entry_id) ?: [];
    delete_transient('hpm_prev_meta_' . $entry_id);
    
    $old_status = $prev['status'] ?? null;
    $old_pasif = $prev['pasif_date'] ?? null;
    
    // Get new status
    $new_status = ff_get_entry_meta($entry_id, (int)$mgr->s('status_field_id'));
    
    // Check reactivation conditions: status changed from 2 to 1, has pasif date, and > 90 days
    if ($old_status === '2' && $new_status === '1' && !empty($old_pasif)) {
        try {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $old_pasif, new \DateTimeZone('Asia/Kuala_Lumpur'));
            if (!$dt) $dt = new \DateTime($old_pasif, new \DateTimeZone('Asia/Kuala_Lumpur'));
            $pasif_ts = $dt->getTimestamp();
        } catch (\Exception $e) {
            $pasif_ts = 0;
        }
        
        $days_inactive = $pasif_ts ? ((time() - $pasif_ts) / 86400) : 0;
        
        if ($days_inactive > 90) {
            // Process reactivation
            $mgr->record_reactivation($entry_id, $old_status, $new_status, $old_pasif);
        }
    }
}, 10, 2);