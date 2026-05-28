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
            is_legacy TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_entry (entry_id),
            KEY idx_code (promo_code),
            KEY idx_category (user_category),
            KEY idx_legacy (is_legacy)
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

        if (!function_exists('dbDelta') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
        dbDelta($sql2);

        // New tables: active pointer, campaigns, status log
        $charset_collate = $wpdb->get_charset_collate();
        foreach (self::get_new_table_sqls($charset_collate) as $new_sql) {
            dbDelta($new_sql);
        }
        // Seed the pointer row (idempotent)
        $wpdb->query(
            "INSERT IGNORE INTO {$wpdb->prefix}home_promo_active (singleton, campaign_id) VALUES (1, NULL)"
        );

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
            ], '', 'no');
        }

        self::run_column_migrations();

        self::run_pasif_backfill(
            (int) (get_option('home_promo_manager_settings')['pasif_date_field_id'] ?? 1698),
            (int) (get_option('home_promo_manager_settings')['form_id'] ?? 13)
        );
        self::ensure_autoload_no();
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

            if (!function_exists('dbDelta') && defined('ABSPATH')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
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

    /**
     * Check if entry exists in promo tracking table
     * 
     * @param int $entry_id Formidable entry ID
     * @return bool True if entry exists
     */
    public static function entry_exists($entry_id)
    {
        global $wpdb;
        $table = self::table_name();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE entry_id = %d",
            (int) $entry_id
        ));
        return (int) $count > 0;
    }

    /**
     * Get entry data from promo table
     * 
     * @param int $entry_id Formidable entry ID
     * @return array|null Entry data or null if not found
     */
    public static function get_entry_data($entry_id)
    {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE entry_id = %d",
            (int) $entry_id
        ), ARRAY_A);
    }

    /**
     * Check if entry is marked as legacy
     * 
     * @param int $entry_id Formidable entry ID
     * @return bool True if legacy entry
     */
    public static function is_legacy_entry($entry_id)
    {
        global $wpdb;
        $table = self::table_name();
        $is_legacy = $wpdb->get_var($wpdb->prepare(
            "SELECT is_legacy FROM {$table} WHERE entry_id = %d",
            (int) $entry_id
        ));
        return (int) $is_legacy === 1;
    }

    /**
     * Update entry's is_legacy flag
     * 
     * @param int $entry_id Formidable entry ID
     * @param bool $is_legacy Legacy flag value
     * @return bool Success
     */
    public static function set_legacy_flag($entry_id, $is_legacy = true)
    {
        global $wpdb;
        $table = self::table_name();
        $result = $wpdb->update(
            $table,
            ['is_legacy' => $is_legacy ? 1 : 0],
            ['entry_id' => (int) $entry_id],
            ['%d'],
            ['%d']
        );
        return $result !== false;
    }

    /**
     * Add is_legacy column if it doesn't exist (for migration)
     * 
     * @return bool Success
     */
    public static function add_legacy_column()
    {
        global $wpdb;
        $table = self::table_name();

        // Check if column exists
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'is_legacy'",
            DB_NAME,
            $table
        ));

        if ((int) $column_exists > 0) {
            return true; // Already exists
        }

        // Add column
        $sql = "ALTER TABLE {$table}
                ADD COLUMN is_legacy TINYINT(1) DEFAULT 0 AFTER eligibility_verified,
                ADD INDEX idx_legacy (is_legacy)";

        $result = $wpdb->query($sql);

        if ($result === false) {
            error_log('[HPM] Failed to add is_legacy column: ' . $wpdb->last_error);
            return false;
        }

        error_log('[HPM] Successfully added is_legacy column');
        return true;
    }

    /**
     * Return the base names (without prefix) of the three new tables added in this version.
     *
     * @return string[]
     */
    public static function get_new_table_sql_names(): array
    {
        return ['home_promo_active', 'home_promo_campaigns', 'home_promo_status_log'];
    }

    /**
     * Return dbDelta-safe CREATE TABLE SQL strings for the three new tables.
     *
     * @param string $charset Result of $wpdb->get_charset_collate()
     * @return string[]
     */
    private static function get_new_table_sqls(string $charset): array
    {
        global $wpdb;
        return [
            "CREATE TABLE {$wpdb->prefix}home_promo_active (
  singleton TINYINT(1) NOT NULL DEFAULT 1,
  campaign_id INT NULL,
  activated_at DATETIME NULL,
  activated_by BIGINT NULL,
  PRIMARY KEY  (singleton),
  UNIQUE KEY uq_active_campaign (campaign_id)
) {$charset};",

            "CREATE TABLE {$wpdb->prefix}home_promo_campaigns (
  id INT AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  mode VARCHAR(10) NOT NULL DEFAULT 'auto',
  start_date DATETIME NOT NULL,
  end_date DATETIME NOT NULL,
  quota INT NOT NULL,
  discount_amount DECIMAL(8,2) NOT NULL,
  campaign_code VARCHAR(40) NULL,
  codes_config LONGTEXT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_slug (slug)
) {$charset};",

            "CREATE TABLE {$wpdb->prefix}home_promo_status_log (
  id INT AUTO_INCREMENT,
  entry_id BIGINT NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  logged_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_entry_logged (entry_id, logged_at)
) {$charset};",
        ];
    }

    /**
     * Check if a column exists in a table
     *
     * @param string $table Table name
     * @param string $column Column name
     * @return bool True if column exists
     */
    public static function column_exists(string $table, string $column): bool {
        global $wpdb;
        return ! empty($wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s", $column
        )));
    }

    /**
     * Run guarded ALTER TABLE migrations for new columns.
     * Each ALTER is only issued if the target column does not already exist.
     */
    public static function run_column_migrations(): void {
        global $wpdb;

        // --- wp_home_promo_counted additions ---
        if (!self::column_exists("{$wpdb->prefix}home_promo_counted", 'campaign_id')) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}home_promo_counted
               ADD COLUMN campaign_id INT NULL DEFAULT NULL,
               ADD COLUMN source VARCHAR(20) NULL DEFAULT 'live',
               ADD UNIQUE KEY uq_entry_campaign (entry_id, campaign_id),
               ADD INDEX idx_campaign (campaign_id),
               ADD INDEX idx_campaign_code (campaign_id, promo_code)"
            );
        }

        // --- wp_home_promo_reactivations additions ---
        if (!self::column_exists("{$wpdb->prefix}home_promo_reactivations", 'went_pasif_at')) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}home_promo_reactivations
               ADD COLUMN campaign_id INT NULL DEFAULT NULL,
               ADD COLUMN went_pasif_at DATETIME NULL COMMENT 'UTC'"
            );
        }

        // --- InnoDB check for counted table ---
        $engine = $wpdb->get_var($wpdb->prepare(
            "SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = %s",
            "{$wpdb->prefix}home_promo_counted"
        ));
        if ($engine && strtolower($engine) !== 'innodb') {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}home_promo_counted ENGINE=InnoDB"
            );
        }
    }

    /**
     * Chunked backfill: seed status_log with 'Pasif' events for all form entries
     * that have no existing log entry. Runs once, guarded by an option flag.
     *
     * @param int $pasif_field_id Field ID holding the pasif date in frm_item_metas
     * @param int $form_id        Formidable form ID to scan
     */
    public static function run_pasif_backfill(int $pasif_field_id, int $form_id): void {
        global $wpdb;

        if (get_option('hpm_pasif_backfill_done')) {
            return;
        }

        $sentinel_date = '1970-01-01 00:00:00';
        $chunk = 1000;

        do {
            $entries = $wpdb->get_results($wpdb->prepare(
                "SELECT fi.item_id AS entry_id,
                    fm.meta_value AS pasif_date_value
               FROM {$wpdb->prefix}frm_items fi
          LEFT JOIN {$wpdb->prefix}frm_item_metas fm
                 ON fm.item_id = fi.item_id AND fm.field_id = %d
              WHERE fi.form_id = %d
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}home_promo_status_log sl
                     WHERE sl.entry_id = fi.item_id
                )
              LIMIT %d",
                $pasif_field_id, $form_id, $chunk
            ), ARRAY_A);

            if (empty($entries)) break;

            $wpdb->query('START TRANSACTION');
            foreach ($entries as $row) {
                $logged_at = (!empty($row['pasif_date_value']))
                    ? gmdate('Y-m-d H:i:s', strtotime($row['pasif_date_value']))
                    : $sentinel_date;
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}home_promo_status_log
                   (entry_id, from_status, to_status, logged_at)
                 VALUES (%d, %s, %s, %s)",
                    (int) $row['entry_id'], 'unknown', 'Pasif', $logged_at
                ));
            }
            $wpdb->query('COMMIT');

        } while (count($entries) === $chunk);

        update_option('hpm_pasif_backfill_done', '1');
    }

    /**
     * Ensure the home_promo_manager_settings option exists and has autoload=no.
     */
    public static function ensure_autoload_no(): void {
        global $wpdb;
        $defaults = [
            'form_id'               => 13,
            'daftar_field_id'       => 196,
            'status_field_id'       => 199,
            'status_label_field_id' => 1617,
            'pasif_date_field_id'   => 1698,
            'promo_field_id'        => 3170,
        ];

        if (get_option('home_promo_manager_settings') === false) {
            add_option('home_promo_manager_settings', $defaults, '', 'no');
        } else {
            $wpdb->update(
                $wpdb->options,
                ['autoload' => 'no'],
                ['option_name' => 'home_promo_manager_settings']
            );
            wp_cache_delete('home_promo_manager_settings', 'options');
            wp_cache_delete('alloptions', 'options');
        }
    }
}
