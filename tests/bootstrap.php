<?php
// Define ABSPATH to satisfy plugin checks
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

require_once __DIR__ . '/../vendor/autoload.php';

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
}

$GLOBALS['wpdb'] = new MockWPDB();

// Load plugin files
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Manager.php';
