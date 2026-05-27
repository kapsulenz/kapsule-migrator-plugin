<?php

class Kapsule_Admin_Page {

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_kapsule_get_status', array( $this, 'ajax_get_status' ) );
        add_action( 'wp_ajax_kapsule_start_migration', array( $this, 'ajax_start_migration' ) );
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
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'kapsule_migrator_nonce' ),
            'status'  => get_option( 'kapsule_migration_status', 'idle' ),
            'jobId'   => get_option( 'kapsule_migration_job_id', '' ),
        ) );
    }

    public function ajax_get_status(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        wp_send_json_success( array(
            'status'   => get_option( 'kapsule_migration_status', 'idle' ),
            'progress' => get_option( 'kapsule_migration_progress', array() ),
            'error'    => get_option( 'kapsule_migration_error', '' ),
            'jobId'    => get_option( 'kapsule_migration_job_id', '' ),
        ) );
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

        // Handshake with Kapsule
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
            $msg = $body['error'] ?? 'Could not connect to Kapsule';
            wp_send_json_error( $msg );
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

    public function render_page(): void {
        $status = get_option( 'kapsule_migration_status', 'idle' );
        $job_id = get_option( 'kapsule_migration_job_id', '' );
        $error  = get_option( 'kapsule_migration_error', '' );
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
                    <h2>Migrate this site to Kapsule Cloud</h2>
                    <p class="kapsule-subtext">
                        Paste your migration token from <a href="https://kpanel.kapsulecloud.com/sites/migrate" target="_blank">kpanel.kapsulecloud.com/sites/migrate</a>.
                        We'll copy your files and database directly to Kapsule — securely, without any downtime on your current site.
                    </p>

                    <div class="kapsule-trust-strip">
                        <span class="kapsule-trust-item">🔒 Encrypted transfer</span>
                        <span class="kapsule-trust-item">✓ Read-only — your site is never modified</span>
                        <span class="kapsule-trust-item">✓ Token deleted after use</span>
                    </div>

                    <div class="kapsule-form-row">
                        <input type="text" id="kapsule-token-input" class="regular-text" placeholder="Paste your migration token here" />
                        <button id="kapsule-start-btn" class="button button-primary button-large">
                            Start Migration
                        </button>
                    </div>
                    <p id="kapsule-error-msg" class="kapsule-error" style="display:none;"></p>
                </div>

            <?php elseif ( in_array( $status, array( 'preflight', 'scanning', 'uploading_files', 'uploading_db' ), true ) ) : ?>
                <div class="kapsule-card kapsule-status-card">
                    <div class="kapsule-spinner"></div>
                    <h2>Migration in progress</h2>
                    <p id="kapsule-status-text" class="kapsule-subtext"><?php echo esc_html( $this->status_label( $status ) ); ?></p>
                    <div class="kapsule-progress-bar"><div id="kapsule-progress-fill" class="kapsule-progress-fill" style="width:0%"></div></div>
                    <p class="kapsule-close-note">You can close this tab — migration continues in the background. You'll be notified in Kapsule when it's done.</p>
                </div>

            <?php elseif ( $status === 'complete' ) : ?>
                <div class="kapsule-card kapsule-success-card">
                    <div class="kapsule-check">✓</div>
                    <h2>Migration complete</h2>
                    <p class="kapsule-subtext">Your site has been transferred to Kapsule. Head back to your Kapsule dashboard to review it and go live.</p>
                    <?php if ( $job_id ) : ?>
                        <a href="https://kpanel.kapsulecloud.com/migration/<?php echo esc_attr( $job_id ); ?>" class="button button-primary button-large" target="_blank">
                            View your Kapsule site →
                        </a>
                    <?php endif; ?>
                    <p style="margin-top:16px;font-size:13px;color:#666;">You can now deactivate and delete this plugin from your site.</p>
                </div>

            <?php elseif ( $status === 'error' ) : ?>
                <div class="kapsule-card kapsule-error-card">
                    <h2>Migration encountered an issue</h2>
                    <?php if ( $error ) : ?>
                        <p class="kapsule-error-detail"><?php echo esc_html( $error ); ?></p>
                    <?php endif; ?>
                    <p class="kapsule-subtext">Contact <a href="https://kpanel.kapsulecloud.com/support" target="_blank">Kapsule support</a> with the above error and we'll sort it out.</p>
                    <button id="kapsule-retry-btn" class="button button-secondary">Reset and try again</button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function status_label( string $status ): string {
        $labels = array(
            'preflight'      => 'Scanning your site...',
            'scanning'       => 'Scanning files and database...',
            'uploading_files' => 'Uploading files to Kapsule...',
            'uploading_db'   => 'Uploading database...',
        );
        return $labels[ $status ] ?? 'Working...';
    }
}
