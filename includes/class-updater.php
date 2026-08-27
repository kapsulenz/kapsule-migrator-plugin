<?php

class Kapsule_Updater {

    private string $plugin_file;
    private string $plugin_slug;

    public function __construct( string $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename( $plugin_file );
    }

    public function register(): void {
        // Fires when WP checks for updates for plugins with Update URI pointing to our host
        add_filter( 'update_plugins_kpanel.kapsulehost.com', array( $this, 'check_update' ), 10, 4 );

        // Provide plugin info for the details popup
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
    }

    public function check_update( $update, array $plugin_data, string $plugin_file, $locales ) {
        if ( $plugin_file !== $this->plugin_slug ) return $update;

        $remote = $this->fetch_version_info();
        if ( ! $remote || empty( $remote['version'] ) ) return $update;

        if ( ! version_compare( $remote['version'], KAPSULE_MIGRATOR_VERSION, '>' ) ) return $update;

        /*
         * `version` IS REQUIRED BY CORE AND WAS NEVER SENT, SO THIS PLUGIN HAS NEVER SELF-UPDATED.
         *
         * WordPress's own loop, wp-includes/update.php, immediately after it calls this filter:
         *
         *     // Is it valid? We require at least a version.
         *     if ( ! isset( $update->version ) ) {
         *         continue;
         *     }
         *     ...
         *     // WordPress needs the version field specified as 'new_version'.
         *     if ( ! isset( $update->new_version ) ) {
         *         $update->new_version = $update->version;
         *     }
         *
         * So `version` is the field core VALIDATES on and `new_version` is the one it DERIVES. This
         * returned only `new_version`, which reads exactly right and is the wrong way round: core
         * `continue`d, and the plugin landed in NEITHER `response` NOR `no_update`. No error, no
         * warning, no entry anywhere. From outside it is indistinguishable from "you are up to date".
         *
         * MEASURED 2026-08-27 on a real WordPress 7.1 install running 1.4.1 with the endpoint
         * announcing 1.5.0: this filter returned a correct object with new_version 1.5.0, the header
         * parsed, the hostname matched, the callback was attached, the cache held the right body, and
         * `wp plugin list` still said `update: none`. The other five plugins on that site were all
         * filed under no_update; ours was absent from both.
         *
         * WHAT IT MEANS, and it is bigger than one release: bumping the version does NOT deliver a
         * fix on its own. That was the conclusion drawn from 1.4.1 being published twice, and it was
         * only half the story. Every customer install has been frozen at whatever version it was
         * first installed with, for as long as this plugin has had an Update URI.
         *
         * Both fields are sent now. `new_version` stays because core only derives it when absent, and
         * being explicit costs nothing and survives the day core stops deriving it.
         */
        return (object) array(
            'id'           => 'kpanel.kapsulehost.com/' . $this->plugin_slug,
            'slug'         => 'kapsule-migrator',
            'plugin'       => $this->plugin_slug,
            'version'      => $remote['version'],
            'new_version'  => $remote['version'],
            'url'          => $remote['homepage'] ?? KAPSULE_MIGRATOR_SITE . '/migrate',
            'package'      => $remote['download_url'],
            'icons'        => array(),
            'banners'      => array(),
            'tested'       => $remote['tested_up_to'] ?? '6.7',
            'requires_php' => $remote['requires_php'] ?? '7.4',
        );
    }

    public function plugin_info( $result, string $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ( $args->slug ?? '' ) !== 'kapsule-migrator' ) return $result;

        $remote = $this->fetch_version_info();
        if ( ! $remote ) return $result;

        return (object) array(
            'name'          => 'Kapsule Migrator',
            'slug'          => 'kapsule-migrator',
            'version'       => $remote['version'] ?? KAPSULE_MIGRATOR_VERSION,
            'requires'      => '5.0',
            'requires_php'  => '7.4',
            'tested'        => $remote['tested_up_to'] ?? '6.7',
            'author'        => 'KapsuleHost',
            'homepage'      => KAPSULE_MIGRATOR_SITE . '/migrate',
            'download_link' => $remote['download_url'] ?? '',
            'sections'      => array(
                'description' => 'Migrate your WordPress site to KapsuleHost, or export your site for manual migration anywhere.',
                'changelog'   => $remote['changelog'] ?? '',
            ),
        );
    }

    private function fetch_version_info(): ?array {
        $transient_key = 'kapsule_updater_' . KAPSULE_MIGRATOR_VERSION;

        // "Check again" on the Updates screen sets force-check, and it has to mean it. Somebody who
        // has been told to update, and who clicks the one button in WordPress that says it will look
        // again, must not be handed a six hour old answer and conclude there is nothing to install.
        $force = ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $cached = $force ? false : get_transient( $transient_key );
        if ( $cached !== false ) return $cached;

        $response = wp_remote_get( KAPSULE_MIGRATOR_VERSION_API, array(
            'timeout' => 10,
            'headers' => array( 'Accept' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) return null;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['version'] ) ) return null;

        set_transient( $transient_key, $body, 6 * HOUR_IN_SECONDS );
        return $body;
    }
}
