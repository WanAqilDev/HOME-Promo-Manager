<?php
/**
 * Plugin Name:       HOME Promo Manager
 * Plugin URI:        https://qc.com.my/
 * Description:       Promo manager for HOME with real-time counters (modular, split files).
 * Version:           1.4.1
 * Requires PHP:      7.4
 * Author:            Wan Aqil Hazim, QCXIS Sdn Bhd
 * Text Domain:       home-promo-manager
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
define('HOME_PROMO_MANAGER_VERSION', '1.4.1');

// Bootstrap
require_once HOME_PROMO_MANAGER_DIR . 'src/bootstrap.php';