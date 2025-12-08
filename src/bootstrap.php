<?php
// Bootstrap for HOME Promo Manager

// load core pieces
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Manager.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/rest.php';
// load admin UI (settings page)
require_once __DIR__ . '/admin.php';

// Activation / uninstall
register_activation_hook(HOME_PROMO_MANAGER_FILE, ['\\HPM\\DB', 'install']);
register_uninstall_hook(HOME_PROMO_MANAGER_FILE, ['\\HPM\\DB', 'uninstall']);

// Ensure tables exist on every init (auto-creates if missing)
add_action('init', ['\\HPM\\DB', 'maybe_create_tables']);

// Instantiate manager (singleton)
$hpm_manager = \HPM\Manager::get_instance();
$GLOBALS['home_promo_manager'] = $hpm_manager;