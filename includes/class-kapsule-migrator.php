<?php

class Kapsule_Migrator {

    public function run() {
        $admin = new Kapsule_Admin_Page();
        $admin->register();

        // REST API endpoints — the plugin exposes these for AJAX actions
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Background migration hook
        add_action( 'kapsule_run_migration', array( $this, 'run_migration' ) );
    }

    public function register_rest_routes() {
        register_rest_route( 'kapsule-migrator/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_status' ),
            'permission_callback' => array( $this, 'check_admin' ),
        ) );
        register_rest_route( 'kapsule-migrator/v1', '/start', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'start_migration' ),
            'permission_callback' => array( $this, 'check_admin' ),
        ) );
    }

    public function check_admin() {
        return current_user_can( 'manage_options' );
    }

    public function get_status( $request ) {
        $token = get_option( 'kapsule_migration_token', '' );
        $status = get_option( 'kapsule_migration_status', 'idle' );
        $progress = get_option( 'kapsule_migration_progress', array() );

        return new WP_REST_Response( array(
            'token'    => $token ? substr( $token, 0, 8 ) . '...' : null,
            'status'   => $status,
            'progress' => $progress,
            'version'  => KAPSULE_MIGRATOR_VERSION,
        ) );
    }

    public function start_migration( $request ) {
        $params = $request->get_json_params();
        $token  = sanitize_text_field( $params['token'] ?? '' );

        if ( empty( $token ) ) {
            return new WP_Error( 'missing_token', 'Migration token required', array( 'status' => 400 ) );
        }

        // Verify token with Kapsule API
        $verify = $this->call_kapsule_api( array(
            'token'         => $token,
            'action'        => 'handshake',
            'pluginVersion' => KAPSULE_MIGRATOR_VERSION,
            'sourceDomain'  => parse_url( home_url(), PHP_URL_HOST ),
        ) );

        if ( is_wp_error( $verify ) ) {
            return $verify;
        }
        if ( empty( $verify['ok'] ) ) {
            return new WP_Error( 'handshake_failed', 'Could not connect to Kapsule', array( 'status' => 401 ) );
        }

        // Store token and queue preflight + migration
        update_option( 'kapsule_migration_token', $token );
        update_option( 'kapsule_migration_status', 'preflight' );
        update_option( 'kapsule_migration_progress', array() );

        // Schedule migration to run in background (WP cron)
        wp_schedule_single_event( time() + 5, 'kapsule_run_migration' );

        return new WP_REST_Response( array( 'ok' => true, 'status' => 'preflight' ) );
    }

    public function run_migration() {
        $token = get_option( 'kapsule_migration_token', '' );
        if ( empty( $token ) ) return;

        try {
            // Phase 1: Preflight scan
            update_option( 'kapsule_migration_status', 'scanning' );
            $preflight = new Kapsule_Preflight();
            $scan = $preflight->scan();

            $this->call_kapsule_api( array(
                'token'     => $token,
                'action'    => 'preflight',
                'preflight' => $scan,
            ) );

            // Phase 2: Package + upload files in chunks
            update_option( 'kapsule_migration_status', 'uploading_files' );
            $packager = new Kapsule_Packager();
            $uploader = new Kapsule_Uploader( $token );

            $packager->package_files( function( $chunk_path, $bytes_done, $bytes_total ) use ( $uploader, $token ) {
                $uploader->upload_chunk( $chunk_path );
                update_option( 'kapsule_migration_progress', array(
                    'phase'            => 'files',
                    'bytesTransferred' => $bytes_done,
                    'totalBytes'       => $bytes_total,
                ) );
                $this->call_kapsule_api( array(
                    'token'            => $token,
                    'action'           => 'progress',
                    'phase'            => 'files',
                    'bytesTransferred' => $bytes_done,
                    'totalBytes'       => $bytes_total,
                ) );
            } );

            // Phase 3: Export + upload database
            update_option( 'kapsule_migration_status', 'uploading_db' );
            $db_path = $packager->export_database();
            $uploader->upload_chunk( $db_path, 'database.sql.gz' );
            $this->call_kapsule_api( array(
                'token'  => $token,
                'action' => 'progress',
                'phase'  => 'database',
            ) );

            // Phase 4: Signal complete
            update_option( 'kapsule_migration_status', 'complete' );
            $manifest = array(
                'files_count'   => $packager->get_file_count(),
                'files_bytes'   => $packager->get_total_bytes(),
                'db_bytes'      => filesize( $db_path ),
                'wp_version'    => get_bloginfo( 'version' ),
                'plugins'       => $this->get_active_plugins(),
                'theme'         => wp_get_theme()->get( 'Name' ),
                'is_multisite'  => is_multisite(),
            );
            $result = $this->call_kapsule_api( array(
                'token'          => $token,
                'action'         => 'complete',
                'uploadManifest' => $manifest,
            ) );
            if ( ! empty( $result['jobId'] ) ) {
                update_option( 'kapsule_migration_job_id', $result['jobId'] );
            }

            // Cleanup temp files
            $packager->cleanup();

        } catch ( Exception $e ) {
            update_option( 'kapsule_migration_status', 'error' );
            update_option( 'kapsule_migration_error', $e->getMessage() );
        }
    }

    private function call_kapsule_api( array $payload ): array {
        $response = wp_remote_post( KAPSULE_MIGRATOR_API_BASE, array(
            'headers'     => array( 'Content-Type' => 'application/json' ),
            'body'        => wp_json_encode( $payload ),
            'timeout'     => 30,
            'data_format' => 'body',
        ) );

        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? $body : array();
    }

    private function get_active_plugins(): array {
        $plugins = get_option( 'active_plugins', array() );
        return array_map( function( $p ) {
            $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $p, false, false );
            return array( 'slug' => $p, 'name' => $data['Name'] ?? $p, 'version' => $data['Version'] ?? '' );
        }, $plugins );
    }

    public static function activate() {
        // Nothing on activate — no DB tables needed
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'kapsule_run_migration' );
        delete_option( 'kapsule_migration_token' );
        delete_option( 'kapsule_migration_status' );
        delete_option( 'kapsule_migration_progress' );
        delete_option( 'kapsule_migration_error' );
        delete_option( 'kapsule_migration_job_id' );
    }
}
