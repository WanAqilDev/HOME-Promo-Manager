<?php
if (!function_exists('ff_get_entry_meta')) {
    /**
     * Get entry meta value for a given entry and field in Formidable Forms.
     *
     * @param int $entry_id
     * @param int $field_id
     * @return mixed|null
     */
    function ff_get_entry_meta($entry_id, $field_id) {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value 
                FROM {$wpdb->prefix}frm_item_metas 
                WHERE item_id = %d AND field_id = %d 
                LIMIT 1",
                $entry_id, $field_id
            )
        );
    }
}