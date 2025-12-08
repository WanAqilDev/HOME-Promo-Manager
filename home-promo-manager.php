<?php
/**
 * Plugin Name:       HOME Promo Manager
 * Plugin URI:        https://github.com/WanAqilDev/HOME-Promo-Manager
 * Description:       Promo manager for HOME with real-time counters (modular, split files).
 * Version:           1.4.2
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
define('HOME_PROMO_MANAGER_VERSION', '1.4.2');

// Bootstrap
require_once HOME_PROMO_MANAGER_DIR . 'src/bootstrap.php';

// GitHub Auto-Updater
require_once HOME_PROMO_MANAGER_DIR . 'src/updater.php';
if (is_admin()) {
    new HPM\Updater(__FILE__, 'WanAqilDev', 'HOME-Promo-Manager', HOME_PROMO_MANAGER_VERSION);
}