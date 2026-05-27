<?php
/**
 * Plugin Name: Kapsule Migrator
 * Plugin URI:  https://kapsulecloud.com/migrate
 * Description: Migrate your WordPress site to Kapsule Cloud — free, zero downtime.
 * Version:     1.0.0
 * Author:      Kapsule Cloud
 * Author URI:  https://kapsulecloud.com
 * License:     GPL-2.0-or-later
 * Text Domain: kapsule-migrator
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KAPSULE_MIGRATOR_VERSION', '1.0.0' );
define( 'KAPSULE_MIGRATOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KAPSULE_MIGRATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KAPSULE_MIGRATOR_API_BASE', 'https://kpanel.kapsulecloud.com/api/migration/plugin' );

require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-kapsule-migrator.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-preflight.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-packager.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-uploader.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'admin/class-admin-page.php';

function kapsule_migrator_init() {
    $plugin = new Kapsule_Migrator();
    $plugin->run();
}
add_action( 'plugins_loaded', 'kapsule_migrator_init' );

register_activation_hook( __FILE__, array( 'Kapsule_Migrator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kapsule_Migrator', 'deactivate' ) );
