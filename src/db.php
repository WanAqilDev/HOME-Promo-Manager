<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

class DB
{
    const TABLE_BASE = 'home_promo_counted';
    const REACTIVATION_TABLE_BASE = 'home_promo_reactivations';

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_BASE;
    }

    public static function reactivation_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::REACTIVATION_TABLE_BASE;
    }

    public static function install()
    {
        global $wpdb;
        $table = self::table_name();
        $reactivation_table = self::reactivation_table_name();
        $charset = $wpdb->get_charset_collate();

        // Main counted entries table
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_entry (entry_id)
        ) $charset;";

        // Reactivation tracking table
        $sql2 = "CREATE TABLE IF NOT EXISTS {$reactivation_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT(20) UNSIGNED NOT NULL,
            old_status VARCHAR(50),
            new_status VARCHAR(50),
            pasif_date DATETIME,
            reactivated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            promo_code VARCHAR(50),
            PRIMARY KEY (id),
            KEY idx_entry (entry_id),
            KEY idx_reactivated (reactivated_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($sql2);

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

    /**
     * Ensure tables exist (run on init to auto-create if missing)
     */
    public static function maybe_create_tables()
    {
        global $wpdb;
        $reactivation_table = self::reactivation_table_name();

        // Check if reactivation table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$reactivation_table}'");

        if ($table_exists !== $reactivation_table) {
            error_log('[HPM] Reactivation table missing. Creating: ' . $reactivation_table);

            // Table missing, create it
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE IF NOT EXISTS {$reactivation_table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                entry_id BIGINT(20) UNSIGNED NOT NULL,
                old_status VARCHAR(50),
                new_status VARCHAR(50),
                pasif_date DATETIME,
                reactivated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                promo_code VARCHAR(50),
                PRIMARY KEY (id),
                KEY idx_entry (entry_id),
                KEY idx_reactivated (reactivated_at)
            ) $charset;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            error_log('[HPM] Table creation attempted. Checking result...');
            $verify = $wpdb->get_var("SHOW TABLES LIKE '{$reactivation_table}'");
            if ($verify === $reactivation_table) {
                error_log('[HPM] Table created successfully: ' . $reactivation_table);
            } else {
                error_log('[HPM] Table creation failed or pending');
            }
        }
    }

    public static function uninstall()
    {
        global $wpdb;
        $table = self::table_name();
        $reactivation_table = self::reactivation_table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        $wpdb->query("DROP TABLE IF EXISTS {$reactivation_table}");
        delete_option('home_promo_manager_settings');
        delete_option('home_promo_manager_version');
    }

    public static function insert_entry($entry_id, $limit = null)
    {
        global $wpdb;
        $table = self::table_name();
        $entry_id = (int) $entry_id;

        if ($limit !== null) {
            // Atomic check-and-insert
            $limit = (int) $limit;
            $query = $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (entry_id)
                 SELECT %d FROM DUAL
                 WHERE (SELECT COUNT(*) FROM {$table}) < %d",
                $entry_id,
                $limit
            );
            $res = $wpdb->query($query);
        } else {
            // Legacy behavior (no limit check) - discouraged but kept for compatibility
            $res = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (entry_id) VALUES (%d)",
                $entry_id
            ));
        }

        if ($res === false) {
            return false;
        }
        // return true if inserted (rows_affected > 0)
        return ($wpdb->rows_affected > 0);
    }

    public static function count_entries()
    {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function clear()
    {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    /**
     * Log a reactivation event to the tracking table
     *
     * @param int $entry_id
     * @param string $old_status
     * @param string $new_status
     * @param string $pasif_date
     * @param string $promo_code
     * @return bool
     */
    public static function log_reactivation($entry_id, $old_status, $new_status, $pasif_date, $promo_code)
    {
        global $wpdb;
        $table = self::reactivation_table_name();

        error_log('[HPM] Logging reactivation for entry_id: ' . $entry_id);
        error_log('[HPM] Old status: ' . $old_status . ', New status: ' . $new_status);
        error_log('[HPM] Pasif date: ' . $pasif_date . ', Promo code: ' . $promo_code);

        $res = $wpdb->insert(
            $table,
            [
                'entry_id' => (int) $entry_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'pasif_date' => $pasif_date,
                'promo_code' => $promo_code,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        if ($res === false) {
            error_log('[HPM] Failed to log reactivation. Error: ' . $wpdb->last_error);
        } else {
            error_log('[HPM] Successfully logged reactivation. Insert ID: ' . $wpdb->insert_id);
        }

        return $res !== false;
    }

    /**
     * Check if entry has already been reactivated (prevent duplicates)
     *
     * @param int $entry_id
     * @return bool
     */
    public static function has_reactivation($entry_id)
    {
        global $wpdb;
        $table = self::reactivation_table_name();

        error_log('[HPM] Checking if entry_id ' . $entry_id . ' has reactivation');
        error_log('[HPM] Query table: ' . $table);

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE entry_id = %d",
            (int) $entry_id
        ));

        if ($wpdb->last_error) {
            error_log('[HPM] Error checking reactivation: ' . $wpdb->last_error);
        }

        error_log('[HPM] Reactivation count for entry ' . $entry_id . ': ' . $count);

        return $count > 0;
    }
}