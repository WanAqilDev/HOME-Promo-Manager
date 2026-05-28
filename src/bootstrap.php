<?php
namespace HPM;

if (!defined('ABSPATH')) exit;

$plugin_dir = plugin_dir_path(__FILE__ . '/../');

require_once $plugin_dir . 'src/db.php';
require_once $plugin_dir . 'src/Manager.php';
require_once $plugin_dir . 'src/CampaignEngine.php';
require_once $plugin_dir . 'src/Eligibility.php';
require_once $plugin_dir . 'src/hooks.php';
require_once $plugin_dir . 'src/admin.php';
require_once $plugin_dir . 'src/rest.php';
require_once $plugin_dir . 'src/shortcodes.php';
require_once $plugin_dir . 'src/templates.php';
require_once $plugin_dir . 'src/updater.php';

// Version bump detection → run install migrations
$stored_version = get_option('home_promo_manager_version', '0.0.0');
if (version_compare($stored_version, HOME_PROMO_MANAGER_VERSION, '<')) {
    DB::install($stored_version, HOME_PROMO_MANAGER_VERSION);
    update_option('home_promo_manager_version', HOME_PROMO_MANAGER_VERSION);
}

DB::schedule_cleanup();
