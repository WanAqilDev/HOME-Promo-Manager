<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

class DB {
    const TABLE_BASE = 'home_promo_counted';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_BASE;
    }

    public static function install() {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_entry (entry_id)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // ensure default settings option exists
        if (get_option('home_promo_manager_settings') === false) {
            add_option('home_promo_manager_settings', [
                'start' => '2025-12-01 12:00:00',
                'end' => '2025-12-24 23:59:00',
                'form_id' => 13,
                'promo_field_id' => 3170,
                'daftar_field_id' => 196,
                'max' => 480,
                'tier1_max' => 240,
                'code_tier1' => 'promo24',
                'code_tier2' => 'promo12',
                'admin_email' => get_option('admin_email'),
            ]);
        }
    }

    public static function uninstall() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option('home_promo_manager_settings');
        delete_option('home_promo_manager_version');
    }

    public static function insert_entry($entry_id) {
        global $wpdb;
        $table = self::table_name();
        $entry_id = (int)$entry_id;
        $res = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (entry_id) VALUES (%d)",
            $entry_id
        ));
        if ($res === false) {
            return false;
        }
        // return true if inserted (rows_affected > 0)
        return ($wpdb->rows_affected > 0);
    }

    public static function count_entries() {
        global $wpdb;
        $table = self::table_name();
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function clear() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}