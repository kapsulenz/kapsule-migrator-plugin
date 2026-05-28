<?php

class Kapsule_Admin_Page {

    public function register(): void {
        add_action( 'admin_menu',             array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_kapsule_get_status',      array( $this, 'ajax_get_status' ) );
        add_action( 'wp_ajax_kapsule_start_migration', array( $this, 'ajax_start_migration' ) );
        add_action( 'wp_ajax_kapsule_start_standalone', array( $this, 'ajax_start_standalone' ) );
        add_action( 'wp_ajax_kapsule_reset',           array( $this, 'ajax_reset' ) );
        add_action( 'admin_post_kapsule_download_file', array( $this, 'handle_download' ) );
    }

    public function add_menu(): void {
        add_menu_page(
            'Kapsule Migrator',
            'Kapsule Migrate',
            'manage_options',
            'kapsule-migrator',
            array( $this, 'render_page' ),
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>' ),
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
        wp_enqueue_script(
            'kapsule-migrator-admin',
            KAPSULE_MIGRATOR_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            KAPSULE_MIGRATOR_VERSION,
            true
        );
        wp_localize_script( 'kapsule-migrator-admin', 'kapsuleMigrator', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'downloadUrl' => admin_url( 'admin-post.php' ),
            'nonce'       => wp_create_nonce( 'kapsule_migrator_nonce' ),
            'status'      => get_option( 'kapsule_migration_status', 'idle' ),
            'jobId'       => get_option( 'kapsule_migration_job_id', '' ),
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
            wp_send_json_error( 'Permission denied' );
            return;
        }

        $token = sanitize_text_field( $_POST['token'] ?? '' );
        if ( empty( $token ) ) {
            wp_send_json_error( 'Token required' );
            return;
        }

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
            wp_send_json_error( $body['error'] ?? 'Could not connect to Kapsule' );
            return;
        }

        update_option( 'kapsule_migration_token', $token );
        update_option( 'kapsule_migration_status', 'preflight' );
        update_option( 'kapsule_migration_progress', array() );
        delete_option( 'kapsule_migration_error' );
        wp_clear_scheduled_hook( 'kapsule_run_migration' );
        wp_schedule_single_event( time() + 3, 'kapsule_run_migration' );

        wp_send_json_success( array( 'status' => 'preflight' ) );
    }

    public function ajax_start_standalone(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
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
            wp_send_json_error( 'Permission denied' );
            return;
        }

        wp_clear_scheduled_hook( 'kapsule_run_migration' );
        wp_clear_scheduled_hook( 'kapsule_run_standalone' );
        delete_option( 'kapsule_migration_token' );
        delete_option( 'kapsule_migration_status' );
        delete_option( 'kapsule_migration_progress' );
        delete_option( 'kapsule_migration_error' );
        delete_option( 'kapsule_migration_job_id' );

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
            wp_die( 'Forbidden', 403 );
        }

        $file_index = absint( $_GET['file'] ?? -1 );
        $nonce      = sanitize_text_field( $_GET['nonce'] ?? '' );

        if ( ! wp_verify_nonce( $nonce, 'kapsule_download_' . $file_index ) ) {
            wp_die( 'Invalid or expired download link. Please reload the page and try again.' );
        }

        $files = get_option( 'kapsule_standalone_files', array() );
        if ( ! isset( $files[ $file_index ] ) ) {
            wp_die( 'File not found. The package may have been cleaned up — run Export again.' );
        }

        $file_info = $files[ $file_index ];
        $path      = $file_info['path'];

        if ( ! file_exists( $path ) ) {
            wp_die( 'File no longer on disk. Run Export again to regenerate.' );
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
        ?>
        <div class="wrap kapsule-migrator-wrap">
            <div class="kapsule-header">
                <div class="kapsule-logo">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    <span>Kapsule Migrator</span>
                </div>
            </div>

            <?php if ( $status === 'idle' ) : ?>
                <div class="kapsule-card">
                    <div class="kapsule-tabs">
                        <button class="kapsule-tab kapsule-tab--active" data-tab="connected">Migrate to Kapsule</button>
                        <button class="kapsule-tab" data-tab="standalone">Export Site</button>
                    </div>

                    <div class="kapsule-tab-panel" id="kapsule-panel-connected">
                        <h2>Migrate this site to Kapsule Cloud</h2>
                        <p class="kapsule-subtext">
                            Paste your migration token from <a href="https://kpanel.kapsulecloud.com/sites/migrate" target="_blank">kpanel.kapsulecloud.com/sites/migrate</a>.
                            We'll copy your files and database directly to Kapsule — securely, without any downtime on your current site.
                        </p>

                        <div class="kapsule-trust-strip">
                            <span class="kapsule-trust-item">&#x1F512; Encrypted transfer</span>
                            <span class="kapsule-trust-item">&#x2713; Read-only &mdash; your site is never modified</span>
                            <span class="kapsule-trust-item">&#x2713; Token deleted after use</span>
                        </div>

                        <div class="kapsule-form-row">
                            <input type="text" id="kapsule-token-input" class="regular-text" placeholder="Paste your migration token here" />
                            <button id="kapsule-start-btn" class="button button-primary button-large">
                                Start Migration
                            </button>
                        </div>
                        <p id="kapsule-error-msg" class="kapsule-error" style="display:none;"></p>
                    </div>

                    <div class="kapsule-tab-panel" id="kapsule-panel-standalone" style="display:none;">
                        <h2>Export your site for manual migration</h2>
                        <p class="kapsule-subtext">
                            Package your WordPress files and database into downloadable archives. No Kapsule account needed &mdash;
                            use these files to migrate to any host manually.
                        </p>

                        <div class="kapsule-trust-strip">
                            <span class="kapsule-trust-item">&#x2713; No account required</span>
                            <span class="kapsule-trust-item">&#x2713; Files stay on your server until you download them</span>
                            <span class="kapsule-trust-item">&#x2713; wp-config.php excluded for security</span>
                        </div>

                        <button id="kapsule-standalone-btn" class="button button-primary button-large">
                            Export Site
                        </button>
                        <p id="kapsule-standalone-error-msg" class="kapsule-error" style="display:none;"></p>
                    </div>
                </div>

            <?php elseif ( in_array( $status, array( 'preflight', 'scanning', 'uploading_files', 'uploading_db', 'standalone_packaging' ), true ) ) : ?>
                <div class="kapsule-card kapsule-status-card">
                    <div class="kapsule-spinner"></div>
                    <h2><?php echo $status === 'standalone_packaging' ? 'Exporting site' : 'Migration in progress'; ?></h2>
                    <p id="kapsule-status-text" class="kapsule-subtext"><?php echo esc_html( $this->status_label( $status ) ); ?></p>
                    <div class="kapsule-progress-bar"><div id="kapsule-progress-fill" class="kapsule-progress-fill" style="width:0%"></div></div>
                    <p class="kapsule-close-note">
                        <?php if ( $status === 'standalone_packaging' ) : ?>
                            Packaging runs in the background. Keep this tab open to see when your files are ready.
                        <?php else : ?>
                            You can close this tab &mdash; migration continues in the background. You'll be notified in Kapsule when it's done.
                        <?php endif; ?>
                    </p>
                    <p style="margin-top:16px;">
                        <button id="kapsule-reset-btn" class="button button-secondary" style="color:#dc2626;border-color:#dc2626;">
                            Cancel and start over
                        </button>
                    </p>
                </div>

            <?php elseif ( $status === 'standalone_ready' ) : ?>
                <div class="kapsule-card">
                    <div class="kapsule-check">&#x2713;</div>
                    <h2>Your site is packaged</h2>
                    <p class="kapsule-subtext">
                        Download your files below. Import them on your new host using your host's import tool or WP-CLI.
                    </p>

                    <div class="kapsule-download-list">
                        <?php foreach ( $files as $file ) : ?>
                            <div class="kapsule-download-row">
                                <div class="kapsule-download-info">
                                    <span class="kapsule-download-name"><?php echo esc_html( $file['name'] ); ?></span>
                                    <span class="kapsule-download-size"><?php echo esc_html( $this->format_bytes( $file['size'] ) ); ?></span>
                                </div>
                                <a href="<?php echo esc_url( $file['url'] ); ?>" class="button button-secondary">
                                    Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                        <p class="kapsule-subtext" style="margin-bottom:12px;">
                            Want a faster path? Migrate directly to <a href="https://kpanel.kapsulecloud.com/sites/migrate" target="_blank">Kapsule Cloud</a>
                            &mdash; no manual file handling required.
                        </p>
                        <button id="kapsule-reset-btn" class="button button-secondary">Remove package &amp; start over</button>
                    </div>
                </div>

            <?php elseif ( $status === 'complete' ) : ?>
                <div class="kapsule-card kapsule-success-card">
                    <div class="kapsule-check">&#x2713;</div>
                    <h2>Migration complete</h2>
                    <p class="kapsule-subtext">Your site has been transferred to Kapsule. Head back to your Kapsule dashboard to review it and go live.</p>
                    <?php if ( $job_id ) : ?>
                        <a href="https://kpanel.kapsulecloud.com/migration/<?php echo esc_attr( $job_id ); ?>" class="button button-primary button-large" target="_blank">
                            View your Kapsule site &rarr;
                        </a>
                    <?php endif; ?>
                    <p style="margin-top:16px;font-size:13px;color:#666;">You can now deactivate and delete this plugin from your site.</p>
                </div>

            <?php elseif ( $status === 'error' ) : ?>
                <div class="kapsule-card kapsule-error-card">
                    <h2>Something went wrong</h2>
                    <?php if ( $error ) : ?>
                        <p class="kapsule-error-detail"><?php echo esc_html( $error ); ?></p>
                    <?php endif; ?>
                    <p class="kapsule-subtext">
                        Contact <a href="https://kpanel.kapsulecloud.com/support" target="_blank">Kapsule support</a> with the above error and we'll sort it out.
                    </p>
                    <button id="kapsule-reset-btn" class="button button-secondary">Reset and try again</button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function status_label( string $status ): string {
        $labels = array(
            'preflight'           => 'Scanning your site...',
            'scanning'            => 'Scanning files and database...',
            'uploading_files'     => 'Uploading files to Kapsule...',
            'uploading_db'        => 'Uploading database to Kapsule...',
            'standalone_packaging' => 'Scanning and packaging your site...',
        );
        return $labels[ $status ] ?? 'Working...';
    }

    private function format_bytes( int $bytes ): string {
        if ( $bytes >= 1073741824 ) return round( $bytes / 1073741824, 1 ) . ' GB';
        if ( $bytes >= 1048576 )    return round( $bytes / 1048576, 1 )    . ' MB';
        if ( $bytes >= 1024 )       return round( $bytes / 1024, 1 )       . ' KB';
        return $bytes . ' B';
    }
}
