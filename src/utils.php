<?php
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