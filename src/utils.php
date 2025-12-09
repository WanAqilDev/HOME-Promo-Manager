<?php
/**
 * Get field value with multiple fallback methods for maximum reliability
 */
if (!function_exists('ff_get_field_value_robust')) {
    function ff_get_field_value_robust($entry_id, $field_id) {
        global $wpdb;
        
        error_log('[HPM Utils] Getting field ' . $field_id . ' for entry ' . $entry_id);
        
        // Method 1: Try frm_item_metas table
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d AND field_id = %d LIMIT 1",
                $entry_id, $field_id
            )
        );
        
        error_log('[HPM Utils] Method 1 (frm_item_metas) returned: ' . var_export($value, true));
        
        // If value is serialized, unserialize it
        if (is_string($value) && (substr($value, 0, 2) === 'a:' || substr($value, 0, 2) === 'O:')) {
            $unserialized = @unserialize($value);
            if (is_array($unserialized)) {
                $value = !empty($unserialized) ? reset($unserialized) : '';
            } elseif ($unserialized !== false) {
                $value = $unserialized;
            }
        }
        
        // Method 2: Try using Formidable's API if available and value is empty
        if (empty($value) && function_exists('FrmEntry::getOne')) {
            $entry = FrmEntry::getOne($entry_id, true);
            if ($entry && isset($entry->metas[$field_id])) {
                $value = $entry->metas[$field_id];
                error_log('[HPM Utils] Method 2 (FrmEntry::getOne) returned: ' . var_export($value, true));
            }
        }
        
        // Method 3: Check if it's stored in the main entry data
        if (empty($value)) {
            $entry_data = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}frm_items WHERE id = %d LIMIT 1",
                    $entry_id
                ),
                ARRAY_A
            );
            
            if ($entry_data) {
                error_log('[HPM Utils] Entry data: ' . print_r($entry_data, true));
            }
        }
        
        error_log('[HPM Utils] Final value for field ' . $field_id . ': ' . var_export($value, true));
        
        return $value;
    }
}

if (!function_exists('ff_get_entry_meta')) {
    /**
     * Get entry meta value for a given entry and field in Formidable Forms.
     * Handles serialized data and returns the actual value.
     *
     * @param int $entry_id
     * @param int $field_id
     * @return mixed|null
     */
    function ff_get_entry_meta($entry_id, $field_id) {
        global $wpdb;
        $raw_value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value 
                FROM {$wpdb->prefix}frm_item_metas 
                WHERE item_id = %d AND field_id = %d 
                LIMIT 1",
                $entry_id, $field_id
            )
        );
        
        // If value is serialized, unserialize it
        if (is_string($raw_value) && (substr($raw_value, 0, 2) === 'a:' || substr($raw_value, 0, 2) === 'O:')) {
            $unserialized = @unserialize($raw_value);
            // If it's an array, return first value or empty string
            if (is_array($unserialized)) {
                return !empty($unserialized) ? reset($unserialized) : '';
            }
            return $unserialized !== false ? $unserialized : $raw_value;
        }
        
        return $raw_value;
    }
}

if (!function_exists('ff_update_entry_meta')) {
    /**
     * Update or insert entry meta value for a given entry and field in Formidable Forms.
     *
     * @param int $entry_id
     * @param int $field_id
     * @param mixed $value
     * @return bool
     */
    function ff_update_entry_meta($entry_id, $field_id, $value) {
        global $wpdb;
        $res = $wpdb->replace(
            $wpdb->prefix . 'frm_item_metas',
            [
                'item_id' => (int)$entry_id,
                'field_id' => (int)$field_id,
                'meta_value' => $value
            ],
            ['%d', '%d', '%s']
        );
        return $res !== false;
    }
}