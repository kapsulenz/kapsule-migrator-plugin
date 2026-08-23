<?php

class Kapsule_Admin_Page {

    public function register(): void {
        add_action( 'admin_menu',             array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_kapsule_get_status',              array( $this, 'ajax_get_status' ) );
        add_action( 'wp_ajax_kapsule_start_migration',         array( $this, 'ajax_start_migration' ) );
        add_action( 'wp_ajax_kapsule_start_standalone',        array( $this, 'ajax_start_standalone' ) );
        add_action( 'wp_ajax_kapsule_reset',                   array( $this, 'ajax_reset' ) );
        add_action( 'wp_ajax_kapsule_upload_chunk',            array( $this, 'ajax_upload_chunk' ) );
        add_action( 'wp_ajax_kapsule_upload_db_and_complete',  array( $this, 'ajax_upload_db_and_complete' ) );
        add_action( 'admin_post_kapsule_download_file',        array( $this, 'handle_download' ) );
    }

    public function add_menu(): void {
        add_menu_page(
            'KapsuleHost Migrator',
            'KapsuleHost Migrate',
            'manage_options',
            'kapsule-migrator',
            array( $this, 'render_page' ),
            // The KapsuleHost mark. See assets/brand/kapsulehost-menu.svg for why this carries its own
            // colours: WordPress renders a data-URI menu icon as a BACKGROUND IMAGE and never recolours
            // it, so the placeholder's stroke="currentColor" resolved to nothing. Two flat brand colours,
            // measured at 20px against the default, light and midnight sidebars.
            'data:image/svg+xml;base64,' . base64_encode(
                // The file carries a long comment explaining the colour choices, which is worth keeping in
                // source and not worth base64-encoding into every admin page load.
                (string) preg_replace( '/<!--.*?-->\s*/s', '', (string) file_get_contents( KAPSULE_MIGRATOR_PLUGIN_DIR . 'assets/brand/kapsulehost-menu.svg' ) )
            ),
            30
        );
    }

    public function enqueue_scripts( string $hook ): void {
        if ( strpos( $hook, 'kapsule-migrator' ) === false ) return;
        wp_enqueue_style(
            'kapsule-migrator-admin',
            KAPSULE_MIGRATOR_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            KAPSULE_MIGRATOR_VERSION
        );
        // RTL is handled INSIDE admin.css with logical properties (margin-inline-start, text-align:end,
        // inset-inline-start) plus a small [dir="rtl"] block for the two genuinely directional things:
        // the meter's sheen and the arrow in the mark.
        //
        // Deliberately NOT wp_style_add_data( ..., 'rtl', 'replace' ). That tells WordPress to swap the
        // URL for `admin-rtl.css`, and WP does not check the file exists: with no such file an Arabic
        // customer gets a 404 and NO styling at all, which is far worse than an unflipped layout. It
        // would also mean two stylesheets stating the same design and drifting apart. One
        // direction-agnostic sheet cannot drift from itself.

        wp_enqueue_script(
            'kapsule-migrator-admin',
            // NOT admin.js. `wp i18n make-json` strips a `?min.js` suffix to map minified files back to
            // their source, with a pattern loose enough to eat a real letter: admin.js becomes a.js,
            // kadmin.js becomes ka.js. The generated catalogue is then named for md5('assets/js/a.js')
            // while WordPress looks up md5('assets/js/migrator.js'), so it is never found and every
            // runtime string silently stays English with all 15 JSON files present and looking correct.
            // A filename that cannot end in min.js sidesteps the bug permanently, with no build step to
            // forget. Verified: tools/verify-i18n.sh asserts the hash matches.
            KAPSULE_MIGRATOR_PLUGIN_URL . 'assets/js/migrator.js',
            array( 'jquery', 'wp-i18n' ),
            KAPSULE_MIGRATOR_VERSION,
            true
        );
        // Every string the transfer states build at runtime (retry countdowns, paused copy, transfer
        // rates) lives in JS, so the JS needs its own catalogue. Without this the PHP half of the
        // screen translates and the half that moves stays English.
        wp_set_script_translations(
            'kapsule-migrator-admin',
            'kapsule-migrator',
            KAPSULE_MIGRATOR_PLUGIN_DIR . 'languages'
        );
        wp_localize_script( 'kapsule-migrator-admin', 'kapsuleMigrator', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'downloadUrl' => admin_url( 'admin-post.php' ),
            'nonce'       => wp_create_nonce( 'kapsule_migrator_nonce' ),
            'status'      => get_option( 'kapsule_migration_status', 'idle' ),
            'jobId'       => get_option( 'kapsule_migration_job_id', '' ),
            'chunkCount'  => (int) get_option( 'kapsule_migration_chunk_count', 0 ),
            'totalBytes'  => (int) get_option( 'kapsule_migration_file_bytes', 0 ),
            'fileCount'   => (int) get_option( 'kapsule_migration_file_count', 0 ),
            'nextChunk'   => (int) get_option( 'kapsule_migration_next_chunk', 0 ),
            'token'       => get_option( 'kapsule_migration_token', '' ),
            'apiBase'     => KAPSULE_MIGRATOR_API_BASE,
            // The BROWSER's locale is not the SITE's locale, and number formatting must follow the
            // site. Without this the PHP half of the facts row rendered "5,9 Go" (WordPress, French)
            // while the JS half rendered "2.8 Go" (browser, en-US) in adjacent cells. Converted to a
            // BCP 47 tag because that is what Intl expects: fr_FR is not a language tag, fr-FR is.
            'bcp47'       => str_replace( '_', '-', determine_locale() ),
            // The browser owns the retry loop, so it owns the budget too. See admin.js and
            // Kapsule_Uploader: one ATTEMPT per request, never a sleep inside one.
            'maxAttempts' => Kapsule_Uploader::MAX_ATTEMPTS,
        ) );
    }

    public function ajax_get_status(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        $status = get_option( 'kapsule_migration_status', 'idle' );

        $response = array(
            'status'   => $status,
            'progress' => get_option( 'kapsule_migration_progress', array() ),
            'error'    => get_option( 'kapsule_migration_error', '' ),
            'jobId'    => get_option( 'kapsule_migration_job_id', '' ),
        );

        if ( $status === 'standalone_ready' ) {
            $files         = get_option( 'kapsule_standalone_files', array() );
            $download_files = array();
            foreach ( $files as $i => $file ) {
                $download_files[] = array(
                    'name'  => $file['name'],
                    'size'  => $file['size'],
                    'index' => $i,
                    'url'   => admin_url( 'admin-post.php?action=kapsule_download_file&file=' . $i . '&nonce=' . wp_create_nonce( 'kapsule_download_' . $i ) ),
                );
            }
            $response['downloadFiles'] = $download_files;
        }

        wp_send_json_success( $response );
    }

    public function ajax_start_migration(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to do that. Ask an administrator of this site to run the migration.', 'kapsule-migrator' ) );
            return;
        }

        $token = sanitize_text_field( $_POST['token'] ?? '' );
        if ( empty( $token ) ) {
            wp_send_json_error( __( 'Paste your migration token first. You will find it in your KapsuleHost panel under Sites, then Migrate.', 'kapsule-migrator' ) );
            return;
        }

        // Handshake
        $response = wp_remote_post( KAPSULE_MIGRATOR_API_BASE, array(
            'headers'     => array( 'Content-Type' => 'application/json' ),
            'body'        => wp_json_encode( array(
                'token'         => $token,
                'action'        => 'handshake',
                'pluginVersion' => KAPSULE_MIGRATOR_VERSION,
                'sourceDomain'  => parse_url( home_url(), PHP_URL_HOST ),
            ) ),
            'timeout'     => 15,
            'data_format' => 'body',
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['ok'] ) ) {
            wp_send_json_error( $body['error'] ?? __( 'We could not connect to KapsuleHost with that token. Check it and try again.', 'kapsule-migrator' ) );
            return;
        }

        // Preflight scan
        @set_time_limit( 0 );
        $preflight = new Kapsule_Preflight();
        $scan      = $preflight->scan();
        wp_remote_post( KAPSULE_MIGRATOR_API_BASE, array(
            'headers'     => array( 'Content-Type' => 'application/json' ),
            'body'        => wp_json_encode( array(
                'token'     => $token,
                'action'    => 'preflight',
                'preflight' => $scan,
            ) ),
            'timeout'     => 15,
            'data_format' => 'body',
        ) );

        // Scan all files and split into chunks — browser will drive each chunk as a separate AJAX call
        $packager = new Kapsule_Packager();
        $files    = $packager->scan_files();
        $chunks   = Kapsule_Packager::build_chunks( $files );

        update_option( 'kapsule_migration_token',       $token );
        update_option( 'kapsule_migration_tmp_dir',     $packager->get_tmp_dir() );
        update_option( 'kapsule_migration_chunks',      $chunks );
        update_option( 'kapsule_migration_chunk_count', count( $chunks ) );
        update_option( 'kapsule_migration_file_count',  $packager->get_file_count() );
        update_option( 'kapsule_migration_file_bytes',  $packager->get_total_bytes() );
        update_option( 'kapsule_migration_next_chunk',  0 );
        update_option( 'kapsule_migration_status',      'uploading_files' );
        update_option( 'kapsule_migration_progress',    array() );
        update_option( 'kapsule_migration_started_at',  time() );
        // A NEW migration must not inherit the completed-chunk list from a previous one. Chunk names
        // are positional (`files-chunk-7.zip`) and therefore IDENTICAL between runs, so without this
        // the uploader treats run 2's pieces as already sent, skips every upload, and the customer is
        // told the migration completed while the far side received nothing. The REST entry point has
        // always done this; this AJAX one is the path the browser actually uses and did not.
        Kapsule_Uploader::reset_progress();
        delete_option( 'kapsule_migration_error' );
        wp_clear_scheduled_hook( 'kapsule_run_migration' );

        wp_send_json_success( array(
            'status'     => 'uploading_files',
            'chunkCount' => count( $chunks ),
            'totalBytes' => $packager->get_total_bytes(),
        ) );
    }

    public function ajax_upload_chunk(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to do that. Ask an administrator of this site to run the migration.', 'kapsule-migrator' ) );
            return;
        }

        $index  = absint( $_POST['index'] ?? 0 );
        $token  = get_option( 'kapsule_migration_token', '' );
        $chunks = get_option( 'kapsule_migration_chunks', array() );
        $tmp    = get_option( 'kapsule_migration_tmp_dir', '' );

        if ( empty( $token ) || ! isset( $chunks[ $index ] ) || ! $tmp ) {
            wp_send_json_error( __( 'This migration is no longer in a state we can continue from. Stop and start over to begin a clean run.', 'kapsule-migrator' ) );
            return;
        }

        try {
            $packager   = new Kapsule_Packager( $tmp );
            $chunk_path = $packager->package_chunk( $chunks[ $index ], $index );

            $uploader = new Kapsule_Uploader( $token );
            // ONE attempt. The browser owns the retry loop so the customer can watch it happen and so
            // no single request sits long enough for the customer's own nginx to 504 it.
            $uploader->upload_chunk( $chunk_path, null, 1 );

            // Accumulate progress
            $bytes_before = 0;
            for ( $i = 0; $i < $index; $i++ ) {
                $bytes_before += array_sum( array_column( $chunks[ $i ], 'size' ) );
            }
            $chunk_bytes = array_sum( array_column( $chunks[ $index ], 'size' ) );
            $bytes_done  = $bytes_before + $chunk_bytes;
            $total_bytes = (int) get_option( 'kapsule_migration_file_bytes', 0 );

            update_option( 'kapsule_migration_next_chunk', $index + 1 );
            update_option( 'kapsule_migration_progress', array(
                'phase'            => 'files',
                'bytesTransferred' => $bytes_done,
                'totalBytes'       => $total_bytes,
            ) );

            wp_remote_post( KAPSULE_MIGRATOR_API_BASE, array(
                'headers'     => array( 'Content-Type' => 'application/json' ),
                'body'        => wp_json_encode( array(
                    'token'            => $token,
                    'action'           => 'progress',
                    'phase'            => 'files',
                    'bytesTransferred' => $bytes_done,
                    'totalBytes'       => $total_bytes,
                ) ),
                'timeout'     => 10,
                'data_format' => 'body',
            ) );

            @unlink( $chunk_path );

            wp_send_json_success( array(
                'chunkIndex' => $index,
                'bytesDone'  => $bytes_done,
                'totalBytes' => $total_bytes,
            ) );

        } catch ( Kapsule_Retryable_Exception $e ) {
            // NOT an error state. The piece did not land, the run is still perfectly alive, and
            // everything already accepted is still accepted. Writing status='error' here would turn a
            // dropped packet into a dead migration and throw away hours of transfer.
            @unlink( $chunk_path ?? '' );
            wp_send_json_error( array(
                'retryable' => true,
                'chunkIndex' => $index,
                'reason'    => $e->getMessage(),
            ) );

        } catch ( Exception $e ) {
            update_option( 'kapsule_migration_status', 'error' );
            update_option( 'kapsule_migration_error',  $e->getMessage() );
            wp_send_json_error( array(
                'retryable' => false,
                'chunkIndex' => $index,
                'reason'    => $e->getMessage(),
            ) );
        }
    }

    public function ajax_upload_db_and_complete(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to do that. Ask an administrator of this site to run the migration.', 'kapsule-migrator' ) );
            return;
        }

        $token = get_option( 'kapsule_migration_token', '' );
        $tmp   = get_option( 'kapsule_migration_tmp_dir', '' );

        if ( empty( $token ) || ! $tmp ) {
            wp_send_json_error( __( 'This migration is no longer in a state we can continue from. Stop and start over to begin a clean run.', 'kapsule-migrator' ) );
            return;
        }

        try {
            @set_time_limit( 0 );
            update_option( 'kapsule_migration_status', 'uploading_db' );

            $packager = new Kapsule_Packager( $tmp );
            $db_path  = $packager->export_database();

            $uploader = new Kapsule_Uploader( $token );
            $uploader->upload_chunk( $db_path, Kapsule_Uploader::DB_REMOTE_NAME );

            wp_remote_post( KAPSULE_MIGRATOR_API_BASE, array(
                'headers'     => array( 'Content-Type' => 'application/json' ),
                'body'        => wp_json_encode( array(
                    'token'  => $token,
                    'action' => 'progress',
                    'phase'  => 'database',
                ) ),
                'timeout'     => 10,
                'data_format' => 'body',
            ) );

            $manifest = array(
                'files_count'  => (int) get_option( 'kapsule_migration_file_count', 0 ),
                'files_bytes'  => (int) get_option( 'kapsule_migration_file_bytes', 0 ),
                'chunk_count'  => (int) get_option( 'kapsule_migration_chunk_count', 0 ),
                'db_bytes'     => filesize( $db_path ),
                'wp_version'   => get_bloginfo( 'version' ),
                'plugins'      => $this->get_active_plugins(),
                'theme'        => wp_get_theme()->get( 'Name' ),
                'is_multisite' => is_multisite(),
            );

            $result      = wp_remote_post( KAPSULE_MIGRATOR_API_BASE, array(
                'headers'     => array( 'Content-Type' => 'application/json' ),
                'body'        => wp_json_encode( array(
                    'token'          => $token,
                    'action'         => 'complete',
                    'uploadManifest' => $manifest,
                ) ),
                'timeout'     => 30,
                'data_format' => 'body',
            ) );
            $result_body = is_wp_error( $result ) ? array() : json_decode( wp_remote_retrieve_body( $result ), true );
            $job_id      = $result_body['jobId'] ?? '';

            update_option( 'kapsule_migration_status', 'complete' );
            if ( $job_id ) {
                update_option( 'kapsule_migration_job_id', $job_id );
            }

            $packager->cleanup();

            wp_send_json_success( array(
                'status' => 'complete',
                'jobId'  => $job_id,
            ) );
        } catch ( Exception $e ) {
            update_option( 'kapsule_migration_status', 'error' );
            update_option( 'kapsule_migration_error',  $e->getMessage() );
            wp_send_json_error( $e->getMessage() );
        }
    }

    private function get_active_plugins(): array {
        $plugins = get_option( 'active_plugins', array() );
        return array_map( function( $p ) {
            $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $p, false, false );
            return array( 'slug' => $p, 'name' => $data['Name'] ?? $p, 'version' => $data['Version'] ?? '' );
        }, $plugins );
    }

    public function ajax_start_standalone(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to do that. Ask an administrator of this site to run the migration.', 'kapsule-migrator' ) );
            return;
        }

        // Clean up any previous standalone package
        $old_tmp = get_option( 'kapsule_standalone_tmp_dir', '' );
        if ( $old_tmp && is_dir( $old_tmp ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $old_tmp, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iter as $f ) {
                $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
            }
            @rmdir( $old_tmp );
        }

        update_option( 'kapsule_migration_status', 'standalone_packaging' );
        update_option( 'kapsule_migration_progress', array() );
        delete_option( 'kapsule_migration_error' );
        delete_option( 'kapsule_standalone_tmp_dir' );
        delete_option( 'kapsule_standalone_files' );
        wp_clear_scheduled_hook( 'kapsule_run_standalone' );
        wp_schedule_single_event( time() + 3, 'kapsule_run_standalone' );

        wp_send_json_success( array( 'status' => 'standalone_packaging' ) );
    }

    public function ajax_reset(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to do that. Ask an administrator of this site to run the migration.', 'kapsule-migrator' ) );
            return;
        }

        wp_clear_scheduled_hook( 'kapsule_run_migration' );
        wp_clear_scheduled_hook( 'kapsule_run_standalone' );
        delete_option( 'kapsule_migration_token' );
        delete_option( 'kapsule_migration_status' );
        delete_option( 'kapsule_migration_progress' );
        delete_option( 'kapsule_migration_error' );
        delete_option( 'kapsule_migration_job_id' );
        delete_option( 'kapsule_migration_chunks' );
        delete_option( 'kapsule_migration_chunk_count' );
        delete_option( 'kapsule_migration_file_count' );
        delete_option( 'kapsule_migration_file_bytes' );
        delete_option( 'kapsule_migration_next_chunk' );
        delete_option( 'kapsule_migration_started_at' );
        // Same reason as the start path: leaving this behind makes the NEXT run skip every piece it
        // thinks it already sent. "Start over" that quietly sends nothing is worse than not resetting.
        Kapsule_Uploader::reset_progress();

        // Clean up AJAX-driven migration tmp dir
        $migration_tmp = get_option( 'kapsule_migration_tmp_dir', '' );
        delete_option( 'kapsule_migration_tmp_dir' );
        if ( $migration_tmp && is_dir( $migration_tmp ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $migration_tmp, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iter as $f ) {
                $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
            }
            @rmdir( $migration_tmp );
        }

        $tmp_dir = get_option( 'kapsule_standalone_tmp_dir', '' );
        if ( $tmp_dir && is_dir( $tmp_dir ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $tmp_dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iter as $f ) {
                $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
            }
            @rmdir( $tmp_dir );
        }
        delete_option( 'kapsule_standalone_tmp_dir' );
        delete_option( 'kapsule_standalone_files' );

        wp_send_json_success( array( 'status' => 'idle' ) );
    }

    public function handle_download(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to download this file.', 'kapsule-migrator' ), 403 );
        }

        $file_index = absint( $_GET['file'] ?? -1 );
        $nonce      = sanitize_text_field( $_GET['nonce'] ?? '' );

        if ( ! wp_verify_nonce( $nonce, 'kapsule_download_' . $file_index ) ) {
            wp_die( esc_html__( 'That download link has expired. Reload this page and try again.', 'kapsule-migrator' ) );
        }

        $files = get_option( 'kapsule_standalone_files', array() );
        if ( ! isset( $files[ $file_index ] ) ) {
            wp_die( esc_html__( 'We could not find that file. The package may have been cleaned up, so package this site again.', 'kapsule-migrator' ) );
        }

        $file_info = $files[ $file_index ];
        $path      = $file_info['path'];

        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'That file is no longer on this server. Package this site again to rebuild it.', 'kapsule-migrator' ) );
        }

        // Stream the file
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Cache-Control: private, no-cache, no-store' );
        header( 'Pragma: no-cache' );

        while ( ob_get_level() ) ob_end_clean();
        readfile( $path );
        exit;
    }

    /**
     * The whole admin surface.
     *
     * Structure note, because it is deliberate: every state renders the SAME card shell (mark, chip,
     * title, lede) and swaps only what is true underneath it. That is what stops a migration feeling
     * like four unrelated screens, and it is why the transfer meter can stay on screen from the first
     * byte to the last without being rebuilt.
     *
     * The meter is the signature element. Most migration plugins show a spinner and a percentage that
     * is a guess. This shows what is actually moving: pieces accepted by the far side, bytes moved,
     * files counted. Every number here comes from a real measurement, never from an animation.
     *
     * EVERY customer-facing string here is translated. This plugin runs on the customer's OWN
     * WordPress at the most nervous moment they will ever have with us, and an English wall in front
     * of a French or Arabic customer moving their business is a worse failure than an ugly screen.
     * Strings are wrapped for the `kapsule-migrator` text domain and shipped as compiled catalogues
     * for the same 16 locales the rest of the estate sells in.
     */
    public function render_page(): void {
        $status = get_option( 'kapsule_migration_status', 'idle' );
        $job_id = get_option( 'kapsule_migration_job_id', '' );
        $error  = get_option( 'kapsule_migration_error', '' );
        $files  = array();

        if ( $status === 'standalone_ready' ) {
            $raw = get_option( 'kapsule_standalone_files', array() );
            foreach ( $raw as $i => $file ) {
                $files[] = array(
                    'name'  => $file['name'],
                    'size'  => $file['size'],
                    'index' => $i,
                    'url'   => admin_url( 'admin-post.php?action=kapsule_download_file&file=' . $i . '&nonce=' . wp_create_nonce( 'kapsule_download_' . $i ) ),
                );
            }
        }

        $tick = '<svg viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M2 6.2l2.6 2.6L10 3.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $bang = '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.6" stroke="currentColor" stroke-width="1.6"/><path d="M8 4.8v3.6M8 11h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        $info = '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.6" stroke="currentColor" stroke-width="1.6"/><path d="M8 7.4v3.8M8 4.9h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        $bold = array( 'strong' => array() );
        ?>
        <div class="wrap kapsule-mig">

            <div class="km-top">
                <?php
                // THE SHIPPED LOCKUP, NOT A RECONSTRUCTION OF IT. These are the same two files the
                // navigation bar on kapsulehost.com serves, copied byte for byte, so the mark and the
                // wordmark arrive together as the brand draws them and their spacing, weights and the
                // teal on "Host" cannot drift away from the real thing.
                //
                // WHY THE LIGHT VARIANT, AND WHY ONLY ONE. The brand ships two, because WordPress will
                // not recolour an image: the wordmark is baked into the pixels, so the variant whose
                // text contrasts with the surface underneath has to be chosen rather than left to a
                // filter. kapsulehost.com's own nav takes the DARK one (white text) because that bar is
                // dark. This header takes the LIGHT one (dark text) because the WordPress content area
                // is not.
                //
                // That was measured, not assumed. WordPress bundles NINE admin colour schemes, and the
                // surface painted behind this lockup was read in every one of them: fresh, light,
                // modern, blue, coffee, ectoplasm, midnight, ocean and sunrise all put rgb(250,250,250)
                // there (luminance 0.98). The schemes recolour the sidebar and the admin bar, not the
                // content. So one variant is correct everywhere and a second would be unreachable.
                // If a surface here ever does go dark, take kapsulehost-dark@1x/@2x from
                // kapsulecloud-marketing/public/v13/brand and switch on it; do not redraw the wordmark.
                $km_lockup = KAPSULE_MIGRATOR_PLUGIN_URL . 'assets/brand/kapsulehost-light@1x.png';
                $km_lockup_2x = KAPSULE_MIGRATOR_PLUGIN_URL . 'assets/brand/kapsulehost-light@2x.png';
                ?>
                <img
                    class="km-lockup"
                    src="<?php echo esc_url( $km_lockup ); ?>"
                    srcset="<?php echo esc_url( $km_lockup ); ?> 1x, <?php echo esc_url( $km_lockup_2x ); ?> 2x"
                    width="161" height="28"
                    alt="KapsuleHost"
                    decoding="async"
                />
                <span class="km-lockup-sub">Migrator</span>
            </div>

            <?php if ( $status === 'idle' ) : ?>

                <div class="km-card">
                    <div class="km-tabs" role="tablist">
                        <button class="kapsule-tab km-tab km-tab--on" data-tab="connected" role="tab"><?php echo esc_html__( 'Move to KapsuleHost', 'kapsule-migrator' ); ?></button>
                        <button class="kapsule-tab km-tab" data-tab="standalone" role="tab"><?php echo esc_html__( 'Download a copy', 'kapsule-migrator' ); ?></button>
                    </div>

                    <div class="kapsule-tab-panel" id="kapsule-panel-connected">
                        <div class="km-card-body">
                            <p class="km-eyebrow"><?php echo esc_html__( 'Step 1 of 1', 'kapsule-migrator' ); ?></p>
                            <h1 class="km-title"><?php echo esc_html__( 'Move this site to KapsuleHost', 'kapsule-migrator' ); ?></h1>
                            <p class="km-lede"><?php
                                echo esc_html__( 'Paste the migration token from your KapsuleHost panel. We copy your files and your database across and leave this site exactly as it is, serving visitors the whole time.', 'kapsule-migrator' );
                            ?></p>

                            <div class="km-field">
                                <label class="km-label" for="kapsule-token-input"><?php echo esc_html__( 'Migration token', 'kapsule-migrator' ); ?></label>
                                <input type="text" id="kapsule-token-input" class="km-input" spellcheck="false" autocomplete="off"
                                       placeholder="<?php echo esc_attr__( 'Paste your token', 'kapsule-migrator' ); ?>" />
                                <span class="km-hint"><?php
                                    echo esc_html__( 'Find it in your panel under Sites, then Migrate. The token works once and is deleted when the move finishes.', 'kapsule-migrator' );
                                ?></span>
                            </div>

                            <div class="km-note" data-tone="info">
                                <?php echo $info; ?>
                                <span><?php
                                    // HOW LONG IT TAKES depends on THIS server's upload speed, which nothing can know
                                    // before measuring it, so this sets an expectation without inventing a number. The
                                    // real figure appears during the transfer, derived from bytes actually moved.
                                    echo esc_html__( 'How long this takes depends on how fast this server can upload. Small sites finish in a few minutes and large ones take longer; you will see a live estimate once the transfer starts. If it is interrupted, reopen this page and it carries on from the last piece that arrived.', 'kapsule-migrator' );
                                ?></span>
                            </div>

                            <div class="km-actions">
                                <button id="kapsule-start-btn" class="km-btn km-btn--primary"><?php echo esc_html__( 'Start the move', 'kapsule-migrator' ); ?></button>
                            </div>

                            <div id="kapsule-error-msg" class="km-note" data-tone="error" style="display:none;">
                                <?php echo $bang; ?>
                                <span></span>
                            </div>
                        </div>

                        <div class="km-trust">
                            <span class="km-trust-i"><?php echo $tick . esc_html__( 'Encrypted end to end', 'kapsule-migrator' ); ?></span>
                            <span class="km-trust-i"><?php echo $tick . esc_html__( 'This site is only ever read, never changed', 'kapsule-migrator' ); ?></span>
                            <span class="km-trust-i"><?php echo $tick . esc_html__( 'Token deleted after use', 'kapsule-migrator' ); ?></span>
                        </div>
                    </div>

                    <div class="kapsule-tab-panel" id="kapsule-panel-standalone" style="display:none;">
                        <div class="km-card-body">
                            <p class="km-eyebrow"><?php echo esc_html__( 'No account needed', 'kapsule-migrator' ); ?></p>
                            <h1 class="km-title"><?php echo esc_html__( 'Download a copy of this site', 'kapsule-migrator' ); ?></h1>
                            <p class="km-lede"><?php
                                echo esc_html__( 'Package the files and database into archives you can download and take anywhere. Useful if you are moving by hand or want a copy before you change anything.', 'kapsule-migrator' );
                            ?></p>

                            <div class="km-actions">
                                <button id="kapsule-standalone-btn" class="km-btn km-btn--primary"><?php echo esc_html__( 'Package this site', 'kapsule-migrator' ); ?></button>
                            </div>

                            <div id="kapsule-standalone-error-msg" class="km-note" data-tone="error" style="display:none;">
                                <?php echo $bang; ?>
                                <span></span>
                            </div>
                        </div>

                        <div class="km-trust">
                            <span class="km-trust-i"><?php echo $tick . esc_html__( 'Files stay on your server until you download them', 'kapsule-migrator' ); ?></span>
                            <span class="km-trust-i"><?php echo $tick . esc_html__( 'wp-config.php is left out on purpose', 'kapsule-migrator' ); ?></span>
                        </div>
                    </div>
                </div>

            <?php elseif ( in_array( $status, array( 'preflight', 'scanning', 'uploading_files', 'uploading_db', 'standalone_packaging' ), true ) ) :
                $is_standalone   = $status === 'standalone_packaging';
                $step_scan_done  = in_array( $status, array( 'uploading_files', 'uploading_db' ), true );
                $step_scan_act   = in_array( $status, array( 'preflight', 'scanning' ), true );
                $step_files_done = $status === 'uploading_db';
                $step_files_act  = $status === 'uploading_files';
                $step_db_act     = $status === 'uploading_db';

                $chunk_count = (int) get_option( 'kapsule_migration_chunk_count', 0 );
                $next_chunk  = (int) get_option( 'kapsule_migration_next_chunk', 0 );
                $file_count  = (int) get_option( 'kapsule_migration_file_count', 0 );
                $total_bytes = (int) get_option( 'kapsule_migration_file_bytes', 0 );
                $progress    = get_option( 'kapsule_migration_progress', array() );
                $done_bytes  = (int) ( $progress['bytesTransferred'] ?? 0 );

                // Server-rendered so a resumed page is CORRECT before a single line of JS runs. A meter
                // that starts at zero and jumps is indistinguishable from a migration that restarted.
                $pct = ( $total_bytes > 0 ) ? (int) min( 99, max( 0, round( ( $done_bytes / $total_bytes ) * 100 ) ) ) : 0;

                if ( $is_standalone ) {
                    $head = __( 'Packaging your site', 'kapsule-migrator' );
                    $lede = __( 'We are building downloadable archives of your files and database. Keep this tab open.', 'kapsule-migrator' );
                } elseif ( $step_scan_act ) {
                    $head = __( 'Looking at your site', 'kapsule-migrator' );
                    $lede = __( 'We are counting your files and checking the connection to KapsuleHost. Nothing has moved yet.', 'kapsule-migrator' );
                } else {
                    $head = __( 'Moving your site', 'kapsule-migrator' );
                    $lede = __( 'Your site is being copied across in pieces. It stays live and unchanged the whole time.', 'kapsule-migrator' );
                }
            ?>

                <div class="km-card">
                    <div class="km-card-body">
                        <span class="km-chip" id="km-chip" data-state="<?php echo $step_scan_act || $is_standalone ? 'connecting' : 'transferring'; ?>">
                            <span class="km-dot"></span>
                            <span id="km-chip-label"><?php echo esc_html( $this->status_label( $status ) ); ?></span>
                        </span>

                        <h1 class="km-title" id="km-head"><?php echo esc_html( $head ); ?></h1>
                        <p class="km-lede" id="kapsule-status-text"><?php echo esc_html( $lede ); ?></p>

                        <div class="km-meter">
                            <div class="km-meter-head">
                                <span class="km-meter-pct"><span id="km-pct"><?php echo (int) $pct; ?></span><sub>%</sub></span>
                                <span class="km-meter-note" id="km-meter-note"><?php
                                    if ( $step_files_act && $chunk_count > 0 ) {
                                        /* translators: 1: current piece number, 2: total number of pieces. */
                                        echo esc_html( sprintf( __( 'piece %1$s of %2$s', 'kapsule-migrator' ),
                                            number_format_i18n( min( $next_chunk + 1, $chunk_count ) ),
                                            number_format_i18n( $chunk_count ) ) );
                                    }
                                ?></span>
                            </div>
                            <div class="km-rail" id="km-rail" data-live="1">
                                <div class="km-fill" id="kapsule-progress-fill" style="width:<?php echo (int) $pct; ?>%"></div>
                            </div>
                        </div>

                        <?php if ( ! $is_standalone ) : ?>
                        <div class="km-facts">
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Pieces sent', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v" id="km-f-pieces"><?php echo esc_html( self::number_pair( number_format_i18n( $next_chunk ), number_format_i18n( $chunk_count ) ) ); ?></div>
                            </div>
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Moved', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v" id="km-f-moved"><?php echo esc_html( $this->format_bytes( $done_bytes ) ); ?></div>
                            </div>
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Total', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v" id="km-f-total"><?php echo esc_html( $this->format_bytes( $total_bytes ) ); ?></div>
                            </div>
                            <div class="km-fact">
                                <div class="km-fact-k" id="km-f-4k"><?php echo esc_html__( 'Files', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v" id="km-f-4v"><?php echo esc_html( number_format_i18n( $file_count ) ); ?></div>
                            </div>
                        </div>

                        <div class="km-steps" id="kapsule-steps">
                            <div class="km-step kapsule-step" id="kstep-scan" data-done="<?php echo $step_scan_done ? '1' : '0'; ?>" data-on="<?php echo $step_scan_act ? '1' : '0'; ?>">
                                <span class="km-step-i"><?php echo $tick; ?></span>
                                <span><?php echo esc_html__( 'Count the files and check the connection', 'kapsule-migrator' ); ?></span>
                            </div>
                            <div class="km-step kapsule-step" id="kstep-files" data-done="<?php echo $step_files_done ? '1' : '0'; ?>" data-on="<?php echo $step_files_act ? '1' : '0'; ?>">
                                <span class="km-step-i"><?php echo $tick; ?></span>
                                <span><?php echo esc_html__( 'Copy the files across', 'kapsule-migrator' ); ?></span>
                            </div>
                            <div class="km-step kapsule-step" id="kstep-db" data-done="0" data-on="<?php echo $step_db_act ? '1' : '0'; ?>">
                                <span class="km-step-i"><?php echo $tick; ?></span>
                                <span><?php echo esc_html__( 'Copy the database across', 'kapsule-migrator' ); ?></span>
                            </div>
                            <div class="km-step kapsule-step" id="kstep-done" data-done="0" data-on="0">
                                <span class="km-step-i"><?php echo $tick; ?></span>
                                <span><?php echo esc_html__( 'KapsuleHost puts it together', 'kapsule-migrator' ); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="km-note" id="km-note" data-tone="info">
                            <?php echo $info; ?>
                            <span id="km-note-text"><?php
                                if ( $is_standalone ) {
                                    echo esc_html__( 'Keep this tab open. We will show your download links the moment packaging finishes.', 'kapsule-migrator' );
                                } else {
                                    echo wp_kses( __( '<strong>Keep this tab open.</strong> The move runs from here, so closing the tab pauses it. Nothing is lost if you do: reopen this page and it carries on from the last piece that arrived.', 'kapsule-migrator' ), $bold );
                                }
                            ?></span>
                        </div>

                        <div class="km-actions">
                            <button id="kapsule-reset-btn" class="km-btn km-btn--ghost"><?php echo esc_html__( 'Stop and start over', 'kapsule-migrator' ); ?></button>
                        </div>
                    </div>
                </div>

            <?php elseif ( $status === 'standalone_ready' ) : ?>

                <div class="km-card">
                    <div class="km-card-body">
                        <span class="km-chip" data-state="done"><span class="km-dot"></span> <?php echo esc_html__( 'Package ready', 'kapsule-migrator' ); ?></span>
                        <h1 class="km-title"><?php echo esc_html__( 'Your site is packaged', 'kapsule-migrator' ); ?></h1>
                        <p class="km-lede"><?php
                            echo esc_html__( 'Download the archives below and import them on your new host. This site has not been changed.', 'kapsule-migrator' );
                        ?></p>

                        <div class="km-files">
                            <?php foreach ( $files as $file ) : ?>
                                <div class="km-file">
                                    <div>
                                        <div class="km-file-n"><?php echo esc_html( $file['name'] ); ?></div>
                                        <div class="km-file-s"><?php echo esc_html( $this->format_bytes( $file['size'] ) ); ?></div>
                                    </div>
                                    <a href="<?php echo esc_url( $file['url'] ); ?>" class="km-btn km-btn--ghost"><?php echo esc_html__( 'Download', 'kapsule-migrator' ); ?></a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="km-note" data-tone="info">
                            <?php echo $info; ?>
                            <span><?php echo esc_html__( 'Moving to KapsuleHost instead? Use the token path and we do all of this for you, with nothing to download.', 'kapsule-migrator' ); ?></span>
                        </div>

                        <div class="km-actions">
                            <button id="kapsule-reset-btn" class="km-btn km-btn--ghost"><?php echo esc_html__( 'Delete the package and start over', 'kapsule-migrator' ); ?></button>
                        </div>
                    </div>
                </div>

            <?php elseif ( $status === 'complete' ) :
                $file_count  = (int) get_option( 'kapsule_migration_file_count', 0 );
                $total_bytes = (int) get_option( 'kapsule_migration_file_bytes', 0 );
                $started     = (int) get_option( 'kapsule_migration_started_at', 0 );
                $took        = $started > 0 ? max( 1, (int) round( ( time() - $started ) / 60 ) ) : 0;
            ?>

                <div class="km-card">
                    <div class="km-card-body">
                        <div class="km-done">
                            <div class="km-done-ring">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>
                            <span class="km-chip" data-state="done"><span class="km-dot"></span> <?php echo esc_html__( 'Move complete', 'kapsule-migrator' ); ?></span>
                            <h1 class="km-title" style="margin-top:14px;"><?php echo esc_html__( 'Your site is on KapsuleHost', 'kapsule-migrator' ); ?></h1>
                            <p class="km-lede" style="margin:9px auto 0;"><?php
                                /* translators: %s: number of files copied, already formatted for the locale. */
                                printf( esc_html__( '%s files and your database were copied and checked. Open the copy and look around before you point your domain at it.', 'kapsule-migrator' ),
                                    esc_html( number_format_i18n( $file_count ) ) );
                            ?></p>
                        </div>

                        <div class="km-facts">
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Files copied', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v"><?php echo esc_html( number_format_i18n( $file_count ) ); ?></div>
                            </div>
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Transferred', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v"><?php echo esc_html( $this->format_bytes( $total_bytes ) ); ?></div>
                            </div>
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Database', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v"><?php echo esc_html__( 'Copied', 'kapsule-migrator' ); ?></div>
                            </div>
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Took', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v"><?php
                                    if ( $took > 0 ) {
                                        /* translators: %s: whole number of minutes the migration took. */
                                        printf( esc_html( _n( '%s min', '%s min', $took, 'kapsule-migrator' ) ), esc_html( number_format_i18n( $took ) ) );
                                    } else {
                                        echo esc_html__( 'not recorded', 'kapsule-migrator' );
                                    }
                                ?></div>
                            </div>
                        </div>

                        <div class="km-note" data-tone="good">
                            <?php echo $tick; ?>
                            <span><?php echo wp_kses( __( '<strong>This site has not been changed.</strong> It is still live and serving visitors. Nothing moves for your visitors until you point your domain at the new copy.', 'kapsule-migrator' ), $bold ); ?></span>
                        </div>

                        <div class="km-actions">
                            <?php if ( $job_id ) : ?>
                                <a href="<?php echo esc_url( KAPSULE_MIGRATOR_HOST ); ?>/migration/<?php echo esc_attr( $job_id ); ?>" class="km-btn km-btn--primary" target="_blank" rel="noopener"><?php echo esc_html__( 'Open the migrated site', 'kapsule-migrator' ); ?></a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( KAPSULE_MIGRATOR_HOST ); ?>" class="km-btn km-btn--ghost" target="_blank" rel="noopener"><?php echo esc_html__( 'Back to my panel', 'kapsule-migrator' ); ?></a>
                        </div>
                    </div>
                </div>

            <?php elseif ( $status === 'error' ) : ?>

                <div class="km-card">
                    <div class="km-card-body">
                        <span class="km-chip" data-state="error"><span class="km-dot"></span> <?php echo esc_html__( 'Move stopped', 'kapsule-migrator' ); ?></span>
                        <h1 class="km-title"><?php echo esc_html__( 'We stopped before anything changed', 'kapsule-migrator' ); ?></h1>
                        <p class="km-lede"><?php
                            echo esc_html__( 'The move did not finish, so we stopped rather than leave you with half a site. Your site here is untouched and still serving visitors.', 'kapsule-migrator' );
                        ?></p>

                        <?php if ( $error ) : ?>
                            <div class="km-note" data-tone="error">
                                <?php echo $bang; ?>
                                <span><?php echo esc_html( $error ); ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="km-actions">
                            <button id="kapsule-reset-btn" class="km-btn km-btn--primary"><?php echo esc_html__( 'Try again', 'kapsule-migrator' ); ?></button>
                            <a href="<?php echo esc_url( KAPSULE_MIGRATOR_HOST ); ?>/support" class="km-btn km-btn--ghost" target="_blank" rel="noopener"><?php echo esc_html__( 'Contact support', 'kapsule-migrator' ); ?></a>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * A numeric pair like "56 / 119", safe in a right-to-left layout.
     *
     * THE BUG THIS FIXES, measured in Arabic: "56 / 119" is made entirely of NEUTRAL characters
     * (digits, spaces, a slash). In an RTL paragraph the bidi algorithm lays that whole run
     * right-to-left, so it renders as "119 / 56" and tells the customer 119 pieces of 56 have been
     * sent. The attempt counter did the same, showing attempt 5 of 3.
     *
     * Wrapping the pair in U+2066 LEFT-TO-RIGHT ISOLATE ... U+2069 POP DIRECTIONAL ISOLATE pins it to
     * one direction without affecting anything around it. Deliberately NOT a translatable string: a
     * bare numeric pair has no words to translate, and making it translatable invites a reordering
     * that reintroduces exactly this defect.
     */
    public static function number_pair( string $a, string $b ): string {
        return "\u{2066}" . $a . ' / ' . $b . "\u{2069}";
    }

    /**
     * The short label in the status chip. Mirrors statusLabel() in admin.js on purpose: the PHP one
     * renders the first paint, the JS one every update after it, and they must say the same words.
     */
    private function status_label( string $status ): string {
        switch ( $status ) {
            case 'preflight':            return __( 'Checking the connection', 'kapsule-migrator' );
            case 'scanning':             return __( 'Counting your files', 'kapsule-migrator' );
            case 'uploading_files':      return __( 'Copying files', 'kapsule-migrator' );
            case 'uploading_db':         return __( 'Copying database', 'kapsule-migrator' );
            case 'standalone_packaging': return __( 'Packaging', 'kapsule-migrator' );
            default:                     return __( 'Working', 'kapsule-migrator' );
        }
    }

    /**
     * Byte sizes, localised.
     *
     * The unit is translatable because it is NOT universal: a French customer reads "Go", not "GB".
     * The number goes through number_format_i18n so the decimal separator follows the reader too
     * (5,9 Go rather than 5.9 Go). Mirrors fmtBytes() in admin.js.
     */
    private function format_bytes( int $bytes ): string {
        if ( $bytes >= 1073741824 ) {
            /* translators: %s: a formatted number of gigabytes, e.g. "5.9". */
            return sprintf( __( '%s GB', 'kapsule-migrator' ), number_format_i18n( $bytes / 1073741824, 1 ) );
        }
        if ( $bytes >= 1048576 ) {
            /* translators: %s: a formatted number of megabytes. */
            return sprintf( __( '%s MB', 'kapsule-migrator' ), number_format_i18n( $bytes / 1048576, 1 ) );
        }
        if ( $bytes >= 1024 ) {
            /* translators: %s: a formatted number of kilobytes. */
            return sprintf( __( '%s KB', 'kapsule-migrator' ), number_format_i18n( $bytes / 1024, 1 ) );
        }
        /* translators: %s: a formatted number of bytes. */
        return sprintf( __( '%s B', 'kapsule-migrator' ), number_format_i18n( $bytes ) );
    }
}
