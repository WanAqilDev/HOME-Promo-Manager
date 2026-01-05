<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

// Basic Formidable hooks wiring

require_once __DIR__ . '/utils.php';

// VALIDATION: Pre-submission validation for SMART26 promo codes
add_filter('frm_validate_entry', function ($errors, $values) {
    $mgr = Manager::get_instance();
    $form_id = !empty($values['form_id']) ? (int) $values['form_id'] : 0;
    
    if ($form_id !== (int) $mgr->s('form_id')) {
        return $errors;
    }

    // Only validate if promo is active
    if (!$mgr->is_active()) {
        return $errors;
    }

    // Only validate in manual (SMART26) mode
    $mode = $mgr->s('code_assignment_mode') ?: 'manual';
    if ($mode !== 'manual') {
        return $errors; // Skip validation in auto mode
    }

    // Check if user wants to register (Daftar = Ya)
    $daftar_field = (string) $mgr->s('daftar_field_id');
    $trigger_val = $mgr->s('daftar_trigger_value') ?: 'Ya';
    $daftar_val = !empty($values['item_meta'][$daftar_field]) ? $values['item_meta'][$daftar_field] : '';

    if ($daftar_val !== $trigger_val) {
        return $errors; // Not registering, skip validation
    }

    // Get promo code from submission
    $promo_field = (string) $mgr->s('promo_field_id');
    $code = !empty($values['item_meta'][$promo_field]) ? trim($values['item_meta'][$promo_field]) : '';

    if ($mgr->s('debug_mode')) {
        error_log(sprintf(
            '[HPM-VALIDATE] Form submission - Code: %s, Daftar: %s',
            $code, $daftar_val
        ));
    }

    // Validate the code
    $validation = $mgr->validate_code($code);
    
    if (!$validation['valid']) {
        // Add error to the promo field
        if (!isset($errors['field' . $promo_field])) {
            $errors['field' . $promo_field] = '';
        }
        $errors['field' . $promo_field] = $validation['message'];
        
        if ($mgr->s('debug_mode')) {
            error_log('[HPM-VALIDATE] Validation failed: ' . $validation['message']);
        }
    } else {
        if ($mgr->s('debug_mode')) {
            error_log('[HPM-VALIDATE] Code validated: ' . $validation['message']);
        }
    }

    return $errors;
}, 10, 2);

// NEW REGISTRATION: Handle new entries when form is submitted
add_action('frm_after_create_entry', function ($entry_id, $form_id) {
    $mgr = Manager::get_instance();
    if ((int) $form_id !== (int) $mgr->s('form_id'))
        return;
    if (!$mgr->is_active())
        return;
    
    $daftar_field = (int) $mgr->s('daftar_field_id');
    $trigger_val = $mgr->s('daftar_trigger_value') ?: 'Ya';
    $daftar_val = ff_get_entry_meta($entry_id, $daftar_field);
    
    if ($daftar_val !== $trigger_val) {
        return; // Not registering for promo
    }

    // Get code assignment mode
    $mode = $mgr->s('code_assignment_mode') ?: 'manual';
    
    if ($mode === 'auto') {
        // Legacy auto-assign mode
        $mgr->record_activation($entry_id);
        if ($mgr->s('debug_mode')) {
            error_log('[HPM-CREATE] Auto mode - activation recorded for entry ' . $entry_id);
        }
    } else {
        // SMART26 manual mode with code validation
        $promo_field = (int) $mgr->s('promo_field_id');
        $code = ff_get_field_value_robust($entry_id, $promo_field);
        
        // Get branch selection
        $branch_field = (int) $mgr->s('branch_field_id');
        $branch = $branch_field ? ff_get_field_value_robust($entry_id, $branch_field) : '';
        
        // Determine category based on entry data
        $category = 'new'; // Default to new registration
        
        // Check if diagnostic (has recent diagnostic date)
        $diagnostic_field = (int) $mgr->s('diagnostic_date_field_id');
        if ($diagnostic_field) {
            $diagnostic_date = ff_get_field_value_robust($entry_id, $diagnostic_field);
            if (!empty($diagnostic_date)) {
                // Check if within 90 days
                try {
                    $tz = new \DateTimeZone($mgr->s('timezone') ?: 'Asia/Kuala_Lumpur');
                    $diag_dt = new \DateTime($diagnostic_date, $tz);
                    $now = new \DateTime('now', $tz);
                    $days_since = ($now->getTimestamp() - $diag_dt->getTimestamp()) / 86400;
                    if ($days_since <= 90) {
                        $category = 'diagnostic';
                    }
                } catch (\Exception $e) {
                    // Ignore date parsing errors
                }
            }
        }
        
        // Check if lead
        $lead_field = (int) $mgr->s('lead_status_field_id');
        if ($lead_field && $category === 'new') { // Only check if not already categorized
            $lead_status = ff_get_field_value_robust($entry_id, $lead_field);
            if ($lead_status === 'Lead' || $lead_status === 'lead') {
                $category = 'lead';
            }
        }
        
        if ($mgr->s('debug_mode')) {
            error_log(sprintf(
                '[HPM-CREATE] SMART26 mode - Entry: %d, Code: %s, Branch: %s, Category: %s',
                $entry_id, $code, $branch, $category
            ));
        }
        
        // Validate and record with SMART26 system
        $result = $mgr->validate_and_record($code, $entry_id, $branch, $category);
        
        if ($result['success']) {
            if ($mgr->s('debug_mode')) {
                error_log('[HPM-CREATE] SMART26 registration successful: ' . $result['message']);
            }
        } else {
            if ($mgr->s('debug_mode')) {
                error_log('[HPM-CREATE] SMART26 registration failed: ' . $result['message']);
            }
            // Error already logged, validation should have caught this
        }
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
add_action('frm_pre_update_entry', function ($values, $entry_id) {
    $mgr = Manager::get_instance();

    // Formidable passes ($values, $id)
    // $values is the array of submitted data
    // $entry_id is the ID of the entry being updated

    $form_id = isset($values['form_id']) ? (int) $values['form_id'] : 0;
    $entry_id = (int) $entry_id;

    if ($mgr->s('debug_mode')) {
        error_log(sprintf('[HPM-DEBUG] Pre-update triggered. Entry: %d, Form: %d', $entry_id, $form_id));
    }

    if ($form_id !== (int) $mgr->s('form_id')) {
        return $values;
    }

    if ($mgr->s('debug_mode')) {
        error_log('[HPM-DEBUG] Capturing OLD values for entry ' . $entry_id);
    }

    // Get current (old) values directly from database BEFORE update
    $status_field = (int) $mgr->s('status_field_id');
    $pasif_field = (int) $mgr->s('pasif_date_field_id');

    $old_status = ff_get_field_value_robust($entry_id, $status_field);
    $old_pasif = ff_get_field_value_robust($entry_id, $pasif_field);

    $daftar_field = (int) $mgr->s('daftar_field_id');
    $old_daftar = ff_get_field_value_robust($entry_id, $daftar_field);

    if ($mgr->s('debug_mode')) {
        error_log(sprintf('[HPM-DEBUG] Captured OLD: Status=%s, PasifDate=%s', var_export($old_status, true), var_export($old_pasif, true)));
    }

    $prev_data = [
        'status' => $old_status,
        'pasif_date' => $old_pasif,
        'daftar' => $old_daftar,
    ];

    // Use 5-minute expiry
    set_transient('hpm_prev_meta_' . $entry_id, $prev_data, 300);

    return $values;
}, 5, 2);

// After update: detect reactivation
add_action('frm_after_update_entry', function ($entry_id, $form_id) {
    $mgr = Manager::get_instance();

    if ($mgr->s('debug_mode')) {
        error_log(sprintf('[HPM-DEBUG] Post-update triggered. Entry: %d, Form: %d', $entry_id, $form_id));
    }

    // Form 13 is used for BOTH new registrations AND edits/reactivations
    if ((int) $form_id !== (int) $mgr->s('form_id')) {
        return;
    }

    if (!$mgr->is_active()) {
        if ($mgr->s('debug_mode'))
            error_log('[HPM-DEBUG] Promo not active. Skipping.');
        return;
    }

    // Check if already reactivated (prevent duplicates)
    if (DB::has_reactivation($entry_id)) {
        if ($mgr->s('debug_mode'))
            error_log('[HPM-DEBUG] Already reactivated. Skipping.');
        delete_transient('hpm_prev_meta_' . $entry_id);
        return;
    }

    $prev = get_transient('hpm_prev_meta_' . $entry_id) ?: [];
    delete_transient('hpm_prev_meta_' . $entry_id);

    if (empty($prev)) {
        if ($mgr->s('debug_mode'))
            error_log('[HPM-DEBUG] No previous meta found (transient missing/expired).');
        return;
    }

    $old_status = $prev['status'] ?? null;
    $old_status = $prev['status'] ?? null;
    $old_pasif = $prev['pasif_date'] ?? null;
    $old_daftar = $prev['daftar'] ?? null;

    if ($mgr->s('debug_mode')) {
        error_log(sprintf('[HPM-DEBUG] Retrieved OLD from transient: Status=%s, PasifDate=%s', var_export($old_status, true), var_export($old_pasif, true)));
    }

    // Get new status
    $status_field = (int) $mgr->s('status_field_id');
    $new_status = ff_get_field_value_robust($entry_id, $status_field);

    if ($mgr->s('debug_mode')) {
        error_log(sprintf('[HPM-DEBUG] Retrieved NEW: Status=%s', var_export($new_status, true)));
    }

    // Check reactivation conditions: status changed from 2 to 1, has pasif date, and > 90 days
    if ($old_status === '2' && $new_status === '1' && !empty($old_pasif)) {
        if ($mgr->s('debug_mode'))
            error_log('[HPM-DEBUG] Status change 2->1 detected. Checking date...');

        $tz_string = $mgr->s('timezone') ?: 'Asia/Kuala_Lumpur';
        try {
            $tz = new \DateTimeZone($tz_string);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
        }

        try {
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $old_pasif, $tz);
            if (!$dt)
                $dt = new \DateTime($old_pasif, $tz);
            $pasif_ts = $dt->getTimestamp();
        } catch (\Exception $e) {
            $pasif_ts = 0;
        }

        $days_inactive = $pasif_ts ? ((time() - $pasif_ts) / 86400) : 0;

        if ($mgr->s('debug_mode'))
            error_log(sprintf('[HPM-DEBUG] Days inactive: %.2f', $days_inactive));

        // Edge Case: Partial Registration
        // If pasif_date is the same day as entry creation date, it means they partially registered.
        // We should allow them to activate even if < 90 days.
        $is_partial = false;
        $entry = \FrmEntry::getOne($entry_id);
        if ($entry && !empty($entry->created_at)) {
            try {
                $created_dt = new \DateTime($entry->created_at, new \DateTimeZone('UTC')); // DB is usually UTC
                $created_dt->setTimezone($tz); // Convert to configured TZ

                // $dt is the pasif_date object created earlier
                if ($dt && $created_dt->format('Y-m-d') === $dt->format('Y-m-d')) {
                    $is_partial = true;
                    if ($mgr->s('debug_mode'))
                        error_log('[HPM-DEBUG] Partial registration detected (Created == PasifDate). Bypassing 90-day check.');
                }
            } catch (\Exception $e) {
                error_log('[HPM-DEBUG] Error checking partial registration dates: ' . $e->getMessage());
            }
        }

        if ($days_inactive > 90 || $is_partial) {
            if ($mgr->s('debug_mode'))
                error_log('[HPM-DEBUG] QUALIFIED! Triggering reactivation.');
            
            // Get promo code for SMART26 mode
            $user_code = '';
            $mode = $mgr->s('code_assignment_mode') ?: 'manual';
            
            if ($mode === 'manual') {
                // In SMART26 mode, get the code from the entry
                $promo_field = (int) $mgr->s('promo_field_id');
                $user_code = ff_get_field_value_robust($entry_id, $promo_field);
                
                if ($mgr->s('debug_mode')) {
                    error_log(sprintf('[HPM-DEBUG] SMART26 reactivation with code: %s', $user_code));
                }
            }
            
            // Process reactivation (pass user code for SMART26 validation)
            $mgr->record_reactivation($entry_id, $old_status, $new_status, $old_pasif, $user_code);
        } else {
            if ($mgr->s('debug_mode'))
                error_log('[HPM-DEBUG] Not qualified (<= 90 days and not partial).');
        }
    } else {
        if ($mgr->s('debug_mode'))
            error_log('[HPM-DEBUG] Conditions not met.');
    }

    // Check for Daftar status change (Tidak -> Ya)
    // This handles users who initially said "Tidak" but changed to "Ya" later
    $daftar_field = (int) $mgr->s('daftar_field_id');
    $new_daftar = ff_get_field_value_robust($entry_id, $daftar_field);
    $trigger_val = $mgr->s('daftar_trigger_value') ?: 'Ya';

    if ($new_daftar === $trigger_val && $old_daftar !== $trigger_val) {
        if ($mgr->s('debug_mode')) {
            error_log(sprintf('[HPM-DEBUG] Daftar status changed from %s to %s. Triggering activation.', var_export($old_daftar, true), var_export($new_daftar, true)));
        }
        $mgr->record_activation($entry_id);
    }
}, 10, 2);