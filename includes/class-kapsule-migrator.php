<?php

class Kapsule_Migrator {

    public function run() {
        $admin = new Kapsule_Admin_Page();
        $admin->register();

        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'kapsule_run_migration', array( $this, 'run_migration' ) );
        add_action( 'kapsule_run_standalone', array( $this, 'run_standalone' ) );
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
        return new WP_REST_Response( array(
            'token'    => get_option( 'kapsule_migration_token', '' ) ? '***' : null,
            'status'   => get_option( 'kapsule_migration_status', 'idle' ),
            'progress' => get_option( 'kapsule_migration_progress', array() ),
            'version'  => KAPSULE_MIGRATOR_VERSION,
        ) );
    }

    public function start_migration( $request ) {
        $params = $request->get_json_params();
        $token  = sanitize_text_field( $params['token'] ?? '' );

        if ( empty( $token ) ) {
            return new WP_Error( 'missing_token', 'Migration token required', array( 'status' => 400 ) );
        }

        $verify = $this->call_kapsule_api( array(
            'token'         => $token,
            'action'        => 'handshake',
            'pluginVersion' => KAPSULE_MIGRATOR_VERSION,
            'sourceDomain'  => parse_url( home_url(), PHP_URL_HOST ),
        ) );

        if ( is_wp_error( $verify ) ) return $verify;
        if ( empty( $verify['ok'] ) ) {
            return new WP_Error( 'handshake_failed', 'Could not connect to Kapsule', array( 'status' => 401 ) );
        }

        update_option( 'kapsule_migration_token', $token );
        Kapsule_Admin_Page::set_status( 'preflight' );
        update_option( 'kapsule_migration_progress', array() );
        // A NEW migration must not inherit the completed-chunk list from a previous one. The uploader
        // skips any chunk whose name it has already sent, and chunk names repeat between runs, so
        // without this a second migration would skip real work and report success having sent nothing.
        Kapsule_Uploader::reset_progress();
        wp_schedule_single_event( time() + 5, 'kapsule_run_migration' );

        return new WP_REST_Response( array( 'ok' => true, 'status' => 'preflight' ) );
    }

    public function run_migration() {
        $token = get_option( 'kapsule_migration_token', '' );
        if ( empty( $token ) ) return;

        try {
            Kapsule_Admin_Page::set_status( 'scanning' );
            $preflight = new Kapsule_Preflight();
            $scan = $preflight->scan();

            $this->call_kapsule_api( array(
                'token'     => $token,
                'action'    => 'preflight',
                'preflight' => $scan,
            ) );

            Kapsule_Admin_Page::set_status( 'uploading_files' );
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

            Kapsule_Admin_Page::set_status( 'uploading_db' );
            $db_path = $packager->export_database();
            $uploader->upload_chunk( $db_path, Kapsule_Uploader::DB_REMOTE_NAME );
            $this->call_kapsule_api( array(
                'token'  => $token,
                'action' => 'progress',
                'phase'  => 'database',
            ) );

            // THE SECOND WRITER OF THE SAME LIE, and it was worse than the one on the admin screen: it
            // declared the migration complete BEFORE it had even POSTed `action=complete`, so a
            // customer on the WP-Cron path was told their site had moved while the request that starts
            // the worker had not been sent. The upload finishing is `awaiting_import`; whether the
            // migration finished is a question only the job can answer. See Kapsule_Admin_Page::STATUSES.
            $manifest = array(
                'files_count'  => $packager->get_file_count(),
                'files_bytes'  => $packager->get_total_bytes(),
                'db_bytes'     => filesize( $db_path ),
                'wp_version'   => get_bloginfo( 'version' ),
                'plugins'      => $this->get_active_plugins(),
                'theme'        => wp_get_theme()->get( 'Name' ),
                'is_multisite' => is_multisite(),
            );
            $result = $this->call_kapsule_api( array(
                'token'          => $token,
                'action'         => 'complete',
                'uploadManifest' => $manifest,
            ) );
            if ( ! empty( $result['jobId'] ) ) {
                update_option( 'kapsule_migration_job_id', $result['jobId'] );
            }
            Kapsule_Admin_Page::set_status( 'awaiting_import' );

            $packager->cleanup();

        } catch ( Exception $e ) {
            Kapsule_Admin_Page::set_status( 'error' );
            update_option( 'kapsule_migration_error', $e->getMessage() );
        }
    }

    public function run_standalone() {
        try {
            Kapsule_Admin_Page::set_status( 'standalone_packaging' );
            update_option( 'kapsule_migration_progress', array(
                'phase'            => 'scanning',
                'bytesTransferred' => 0,
                'totalBytes'       => 0,
            ) );

            $packager = new Kapsule_Packager();

            $packager->package_files( function( $chunk_path, $bytes_done, $bytes_total ) {
                update_option( 'kapsule_migration_progress', array(
                    'phase'            => 'packaging',
                    'bytesTransferred' => $bytes_done,
                    'totalBytes'       => $bytes_total,
                ) );
            } );

            update_option( 'kapsule_migration_progress', array(
                'phase'            => 'exporting_db',
                'bytesTransferred' => 0,
                'totalBytes'       => 0,
            ) );

            $db_path = $packager->export_database();

            // Collect all output files
            $tmp_dir = $packager->get_tmp_dir();
            $files   = array();

            $db_gz = $tmp_dir . 'database.sql.gz';
            if ( file_exists( $db_gz ) ) {
                $files[] = array( 'name' => 'database.sql.gz', 'path' => $db_gz, 'size' => filesize( $db_gz ) );
            }

            $i = 0;
            foreach ( array( 'zip', 'tar' ) as $ext ) {
                $i = 0;
                while ( file_exists( $tmp_dir . "files-chunk-{$i}.{$ext}" ) ) {
                    $chunk   = $tmp_dir . "files-chunk-{$i}.{$ext}";
                    $files[] = array( 'name' => "files-chunk-{$i}.{$ext}", 'path' => $chunk, 'size' => filesize( $chunk ) );
                    $i++;
                }
                if ( $i > 0 ) break; // found files with this extension, stop looking
            }

            update_option( 'kapsule_standalone_tmp_dir', $tmp_dir );
            /*
             * autoload=false for the same reason the chunk manifest moved to disk: this is a file
             * list whose size scales with the customer's site, and autoloading it makes every
             * request on that site pay for it. Measured on the migration manifest: 34 MB serialised,
             * 150 MB to unserialise, on every request. This list is smaller but the same shape.
             */
            update_option( 'kapsule_standalone_files', $files, false );
            Kapsule_Admin_Page::set_status( 'standalone_ready' );
            update_option( 'kapsule_migration_progress', array() );

        } catch ( Exception $e ) {
            Kapsule_Admin_Page::set_status( 'error' );
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
            // THE THIRD ROUTE FOR A TRANSPORT LIBRARY'S OWN TEXT. This message becomes
            // `kapsule_migration_error`, which the stopped card prints verbatim. The raw text goes to
            // the log; the customer gets the sentence. `stopped()` because nothing retries after this.
            $raw = $response->get_error_message();
            Kapsule_Transport_Message::log( 'cron api call failed', $raw );
            throw new Exception( sprintf(
                /* translators: %s: a short plain description of what went wrong, e.g. "this server could not open a connection to KapsuleHost". */
                __( 'The move could not continue because %s. Nothing on this site has been changed.', 'kapsule-migrator' ),
                Kapsule_Transport_Message::stopped( $raw )
            ) );
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
        // Nothing on activate
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'kapsule_run_migration' );
        wp_clear_scheduled_hook( 'kapsule_run_standalone' );
        delete_option( 'kapsule_migration_token' );
        delete_option( 'kapsule_migration_status' );
        delete_option( 'kapsule_migration_progress' );
        delete_option( 'kapsule_migration_error' );
        delete_option( 'kapsule_migration_job_id' );
        // Same reason as ajax_reset: a cached job state outliving its job would render a previous
        // run's outcome on a fresh install's first paint.
        delete_option( 'kapsule_migration_job_state' );
        delete_option( 'kapsule_migration_job_state_error' );

        // Clean up any standalone temp files
        $tmp_dir = get_option( 'kapsule_standalone_tmp_dir', '' );
        if ( $tmp_dir && is_dir( $tmp_dir ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $tmp_dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iter as $f ) {
                $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
            }
            rmdir( $tmp_dir );
        }
        delete_option( 'kapsule_standalone_tmp_dir' );
        delete_option( 'kapsule_standalone_files' );
    }
}
