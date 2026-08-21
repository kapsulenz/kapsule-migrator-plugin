<?php
/**
 * Plugin Name: Kapsule Migrator
 * Plugin URI:  https://kapsulehost.com/migrate
 * Description: Migrate your WordPress site to KapsuleHost, or export your site for manual migration anywhere.
 * Version:     1.1.0
 * Author:      KapsuleHost
 * Author URI:  https://kapsulehost.com
 * License:     GPL-2.0-or-later
 * Text Domain: kapsule-migrator
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Update URI:  https://kpanel.kapsulehost.com/api/migration/plugin-version
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KAPSULE_MIGRATOR_VERSION',     '1.1.0' );
define( 'KAPSULE_MIGRATOR_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'KAPSULE_MIGRATOR_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
/**
 * ONE host constant. Everything else is derived from it.
 *
 * R-387: this plugin previously carried TWELVE hardcoded kapsulecloud.com URLs. That host still
 * answers (a POST-CUTOVER alias vhost 301s to kapsulehost.com), so the handshake succeeded and every
 * early signal was green, but the alias vhost has no `location = /api/migration/plugin/upload-chunk`
 * and therefore never received its 128M client_max_body_size. Bulk file uploads were rejected by
 * nginx with HTTP 413 before reaching the application, and every real migration died on the first
 * chunk. Measured: an identical 60MB POST returns 400 from kapsulehost (the app answering) and 413
 * from kapsulecloud (nginx rejecting).
 *
 * A single constant is the point. Twelve copies is what let the brand rot in place unnoticed, and it
 * is what would let the next rename do the same. Anything needing the host derives it from here, and
 * a filter lets a site override it without editing the plugin.
 */
define( 'KAPSULE_MIGRATOR_HOST', apply_filters( 'kapsule_migrator_host', 'https://kpanel.kapsulehost.com' ) );
define( 'KAPSULE_MIGRATOR_SITE', apply_filters( 'kapsule_migrator_site', 'https://kapsulehost.com' ) );

define( 'KAPSULE_MIGRATOR_API_BASE',    KAPSULE_MIGRATOR_HOST . '/api/migration/plugin' );
define( 'KAPSULE_MIGRATOR_VERSION_API', KAPSULE_MIGRATOR_HOST . '/api/migration/plugin-version' );

require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-kapsule-migrator.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-preflight.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-packager.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-uploader.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'includes/class-updater.php';
require_once KAPSULE_MIGRATOR_PLUGIN_DIR . 'admin/class-admin-page.php';

function kapsule_migrator_init() {
    load_plugin_textdomain( 'kapsule-migrator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    $updater = new Kapsule_Updater( __FILE__ );
    $updater->register();

    $plugin = new Kapsule_Migrator();
    $plugin->run();
}
add_action( 'plugins_loaded', 'kapsule_migrator_init' );

register_activation_hook( __FILE__, array( 'Kapsule_Migrator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Kapsule_Migrator', 'deactivate' ) );
