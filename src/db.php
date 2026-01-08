<?php
namespace HPM;

if (!defined('ABSPATH'))
    exit;

class DB
{
    const TABLE_BASE = 'home_promo_counted';
    const REACTIVATION_TABLE_BASE = 'home_promo_reactivations';

    /**
     * Get default SMART26 promo codes (DRY - single source of truth)
     * Starting with empty array - admins add codes via dashboard
     *
     * @return array Default promo codes configuration
     */
    public static function get_default_promo_codes()
    {
        return [];
    }

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

        // Main counted entries table (SMART26 schema)
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT(20) UNSIGNED NOT NULL,
            promo_code VARCHAR(50) DEFAULT '',
            branch VARCHAR(100) DEFAULT '',
            user_category VARCHAR(50) DEFAULT '',
            eligibility_verified TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_entry (entry_id),
            KEY idx_code (promo_code),
            KEY idx_category (user_category)
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
                'start' => '2026-01-12 12:00:00',
                'end' => '2026-01-14 11:59:00',
                'timezone' => 'Asia/Kuala_Lumpur',
                'form_id' => 13,
                'promo_field_id' => 3170,
                'daftar_field_id' => 196,
                'daftar_trigger_value' => 'Ya',
                'status_field_id' => 199,
                'pasif_date_field_id' => 1698,
                'diagnostic_date_field_id' => 0,
                'lead_status_field_id' => 0,
                'branch_field_id' => 0,
                'passive_threshold_days' => 90,
                'code_assignment_mode' => 'manual',  // 'auto' or 'manual'
                'promo_codes' => self::get_default_promo_codes(),  // Use shared defaults
                'total_max' => 200,
                'base_price' => 200.00,
                'discount_amount' => 52.00,
                'final_price' => 148.00,
                'admin_email' => get_option('admin_email'),
                'debug_mode' => false,
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

    /**
     * SMART26: Insert entry with code tracking
     * ATOMIC OPERATION - prevents race conditions and quota spillover
     * 
     * @param int $entry_id Formidable entry ID
     * @param string $code Promo code used
     * @param string $branch Branch selection
     * @param string $category User category (new/passive/diagnostic/lead)
     * @param int|null $limit Per-code quota limit
     * @return bool True if inserted successfully
     */
    public static function insert_entry_with_code($entry_id, $code, $branch = '', $category = '', $limit = null)
    {
        global $wpdb;
        $table = self::table_name();
        $entry_id = (int) $entry_id;
        $code_safe = sanitize_text_field($code);
        $branch_safe = sanitize_text_field($branch);
        $category_safe = sanitize_text_field($category);

        if ($limit !== null) {
            // ATOMIC: Check quota and insert in single query to prevent race conditions
            // This ensures two simultaneous requests can't both grab the "last slot"
            $limit = (int) $limit;
            
            $query = $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (entry_id, promo_code, branch, user_category, eligibility_verified)
                 SELECT %d, %s, %s, %s, 1 FROM DUAL
                 WHERE (SELECT COUNT(*) FROM {$table} WHERE promo_code = %s) < %d",
                $entry_id,
                $code_safe,
                $branch_safe,
                $category_safe,
                $code_safe, // For quota check
                $limit
            );
            
            $res = $wpdb->query($query);
            
            if ($res === false) {
                error_log('[HPM] Atomic insert failed: ' . $wpdb->last_error);
                return false;
            }
            
            // Check if row was actually inserted
            $inserted = ($wpdb->rows_affected > 0);
            
            if (!$inserted) {
                error_log("[HPM] Code quota reached atomically: {$code} (limit: {$limit})");
            }
            
            return $inserted;
            
        } else {
            // No limit check - direct insert
            $res = $wpdb->insert(
                $table,
                [
                    'entry_id' => $entry_id,
                    'promo_code' => $code_safe,
                    'branch' => $branch_safe,
                    'user_category' => $category_safe,
                    'eligibility_verified' => 1,
                ],
                ['%d', '%s', '%s', '%s', '%d']
            );

            if ($res === false) {
                error_log('[HPM] Failed to insert entry with code: ' . $wpdb->last_error);
                return false;
            }

            return ($wpdb->insert_id > 0);
        }
    }

    /**
     * SMART26: Get usage count for a specific promo code
     * 
     * @param string $code Promo code
     * @return int Usage count
     */
    public static function get_code_usage($code)
    {
        global $wpdb;
        $table = self::table_name();
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE promo_code = %s",
            $code
        ));

        return (int) $count;
    }

    /**
     * SMART26: Get usage breakdown by code
     * 
     * @return array Array of ['promo_code' => ..., 'count' => ...]
     */
    public static function get_code_stats()
    {
        global $wpdb;
        $table = self::table_name();
        
        $results = $wpdb->get_results(
            "SELECT promo_code, COUNT(*) as count 
             FROM {$table} 
             WHERE promo_code != ''
             GROUP BY promo_code
             ORDER BY count DESC",
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * SMART26: Get category breakdown
     * 
     * @return array Array of ['user_category' => ..., 'count' => ...]
     */
    public static function get_category_stats()
    {
        global $wpdb;
        $table = self::table_name();
        
        $results = $wpdb->get_results(
            "SELECT user_category, COUNT(*) as count 
             FROM {$table} 
             WHERE user_category != ''
             GROUP BY user_category
             ORDER BY count DESC",
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * SMART26: Get detailed entries for a specific code
     * 
     * @param string $code Promo code
     * @param int $limit Number of results
     * @return array Entry details
     */
    public static function get_code_entries($code, $limit = 100)
    {
        global $wpdb;
        $table = self::table_name();
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE promo_code = %s 
             ORDER BY created_at DESC 
             LIMIT %d",
            $code,
            $limit
        ), ARRAY_A);

        return $results ?: [];
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

    public static function count_reactivations()
    {
        global $wpdb;
        $table = self::reactivation_table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}