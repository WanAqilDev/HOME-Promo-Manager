<?php
// Define ABSPATH to satisfy plugin checks
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/mockery/mockery/library/Mockery.php';

// Mock basic WordPress functions
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
    }
}
if (!function_exists('register_setting')) {
    function register_setting($group, $name, $args = [])
    {
    }
}
if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null)
    {
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($option)
    {
        return true;
    }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        if (is_object($args)) {
            $r = get_object_vars($args);
        } elseif (is_array($args)) {
            $r = &$args;
        } else {
            wp_parse_str($args, $r);
        }
        if (is_array($defaults)) {
            return array_merge($defaults, $r);
        }
        return $r;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return trim($str);
    }
}
if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email)
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}
if (!function_exists('dbDelta')) {
    function dbDelta($sql)
    {
    }
}

// Mock $wpdb global
class MockWPDB
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $rows_affected = 0;

    public function get_charset_collate()
    {
        return 'utf8mb4_unicode_ci';
    }
    public function prepare($query, ...$args)
    {
        return vsprintf(str_replace('%d', '%d', str_replace('%s', '%s', $query)), $args);
    }
    public function query($query)
    {
        return true;
    }
    public function get_var($query)
    {
        return null;
    }
    public function insert($table, $data, $format = null)
    {
        return true;
    }
    public function get_row($query, $output = OBJECT, $y = 0)
    {
        return null;
    }
    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        return 1;
    }
    public function get_results($query, $output = OBJECT)
    {
        return [];
    }
    public function get_col($query)
    {
        return [];
    }
}

// WordPress constants
if (!defined('OBJECT')) define('OBJECT', 'OBJECT');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

// WordPress function stubs
if (!function_exists('sanitize_title')) {
    function sanitize_title($title, $fallback = '', $context = 'save') {
        return strtolower(preg_replace('/[^a-z0-9-]/', '-', strtolower($title)));
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data); }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return true; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 1; }
}
if (!function_exists('wp_die')) {
    function wp_die($msg = '', $title = '', $args = []) { throw new \RuntimeException("wp_die: $msg"); }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action, $name = '_wpnonce', $referer = true, $echo = true) {
        if ($echo) echo ''; return '';
    }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action = -1, $query_arg = '_wpnonce') { return 1; }
}
if (!function_exists('esc_html')) {
    function esc_html($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($url, $protocols = null, $context = 'display') { return $url; }
}
if (!function_exists('esc_js')) {
    function esc_js($text) { return addslashes($text); }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) { return true; }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) { return false; }
}
if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = []) { return true; }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '', $force = false) { return true; }
}
if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') { return true; }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('wp_timezone')) {
    function wp_timezone() { return new \DateTimeZone('UTC'); }
}
if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        return date($format, $timestamp ?? time());
    }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'http://localhost/wp-json/' . ltrim($path, '/'); }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = []) { return true; }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return dirname($file) . '/'; }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {}
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {}
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return 1; }
}

$GLOBALS['wpdb'] = new MockWPDB();

// Load plugin files
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Manager.php';
require_once __DIR__ . '/../src/CampaignEngine.php';
