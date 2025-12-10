<?php
/**
 * Plugin Name:       HOME Promo Manager
 * Plugin URI:        https://github.com/WanAqilDev/HOME-Promo-Manager
 * Description:       Promo manager for HOME with real-time counters (modular, split files).
 * Version:           0.1.3
 * Requires PHP:      7.4
 * Author:            Wan Aqil Hazim, QCXIS Sdn Bhd
 * Text Domain:       home-promo-manager
 * GitHub Plugin URI: WanAqilDev/HOME-Promo-Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent duplicate load
if (defined('HOME_PROMO_MANAGER_LOADED')) {
    return;
}
define('HOME_PROMO_MANAGER_LOADED', true);
define('HOME_PROMO_MANAGER_FILE', __FILE__);
define('HOME_PROMO_MANAGER_DIR', plugin_dir_path(__FILE__));
define('HOME_PROMO_MANAGER_VERSION', '0.1.3');

// Bootstrap
require_once __DIR__ . '/src/utils.php';
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/Manager.php';
require_once __DIR__ . '/src/admin.php';
require_once __DIR__ . '/src/rest.php';
require_once __DIR__ . '/src/shortcodes.php';
require_once __DIR__ . '/src/templates.php';
require_once __DIR__ . '/src/hooks.php';

// Activation / uninstall
register_activation_hook(__FILE__, ['\\HPM\\DB', 'install']);
register_uninstall_hook(__FILE__, ['\\HPM\\DB', 'uninstall']);

// Ensure tables exist on every init (auto-creates if missing)
add_action('init', ['\\HPM\\DB', 'maybe_create_tables']);

// GitHub Auto-Updater
require_once HOME_PROMO_MANAGER_DIR . 'src/updater.php';
if (is_admin()) {
    new HPM\Updater(__FILE__, 'WanAqilDev', 'HOME-Promo-Manager', HOME_PROMO_MANAGER_VERSION);
}