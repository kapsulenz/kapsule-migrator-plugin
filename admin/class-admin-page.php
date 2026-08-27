<?php

class Kapsule_Admin_Page {

    /**
     * THE PLUGIN HAS NO "COMPLETE" STATE OF ITS OWN, AND THAT IS THE WHOLE FIX.
     *
     * WHAT WENT WRONG, from Jesse's real migration of oaohost.com on 2026-08-24. This screen said:
     *
     *     Move complete. Your site is on KapsuleHost.
     *     21,129 files - 472.5 MB - Database: Copied - Took: 4 min
     *
     * The panel said "Scanning site files, 10%" at the same moment, and the job then FAILED on the
     * database import. The customer was told the exact thing that was about to fail had already
     * succeeded, and "Copied" was a hardcoded English word in the template rather than a fact about
     * anything.
     *
     * The plugin was not confused. It was reporting the only event it could observe: its own uploader
     * had finished sending. `ajax_upload_db_and_complete` then wrote
     * `kapsule_migration_status = 'complete'` and this template's `$status === 'complete'` branch drew
     * a completion screen. Every number on it (files, bytes, minutes) is a LOCAL measurement of what
     * this plugin sent, which is real, presented under a heading that claims something else entirely.
     *
     * "Files uploaded" and "site migrated" are different events separated by everything that can go
     * wrong: provisioning, unpacking, placing the files, importing the database, rewriting URLs,
     * proving the result serves. The `complete` POST is not the END of the migration, it is what
     * DISPATCHES the worker that does all of that.
     *
     * SO THIS IS NOT A REWORDING. The local status vocabulary no longer contains a value meaning
     * finished, `set_status()` refuses to write one, and the completion card is emitted by a method
     * whose first statement returns unless the PORTAL said `COMPLETED`. There is no local variable,
     * in any state, that can put "Your site is on KapsuleHost" on this screen.
     *
     * The local terminal state is `awaiting_import`: this plugin's work is genuinely finished and the
     * job's is not. From there every card is chosen by the job state fetched from
     * `/api/migration/plugin/job-status`, including the honest "we cannot reach KapsuleHost to check"
     * one, which is a state a customer must be shown rather than a reason to fall back to optimism.
     */
    const STATUSES = array(
        'idle',
        'preflight',
        'scanning',
        'uploading_files',
        'uploading_db',
        'awaiting_import',       // upload done, the JOB decides what happens next
        'standalone_packaging',
        'standalone_ready',
        'error',                 // a LOCAL failure: this plugin could not finish sending
    );

    /** Job statuses the portal can report. Only one of them may draw a completion screen. */
    const JOB_COMPLETE = 'COMPLETED';

    /**
     * The only writer of the local status, and it refuses anything not in the vocabulary above.
     *
     * A `die()` would be worse than the bug for a customer, so an unknown value is coerced to `error`
     * and recorded: a migration that stops and says so is recoverable, and a migration that claims to
     * have finished is not. The value is also the thing a future edit would reach for ('complete',
     * 'done', 'finished'), so it fails loudly in the log rather than silently rendering.
     */
    public static function set_status( string $status ): void {
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            error_log( sprintf( '[kapsule-migrator] refused to set unknown migration status "%s"', $status ) );
            update_option( 'kapsule_migration_status', 'error' );
            update_option( 'kapsule_migration_error', __( 'The migration ended in a state we do not recognise, so we stopped rather than guess. Your site here is untouched.', 'kapsule-migrator' ) );
            return;
        }
        update_option( 'kapsule_migration_status', $status );
    }

    public function register(): void {
        add_action( 'admin_menu',             array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_kapsule_get_status',              array( $this, 'ajax_get_status' ) );
        add_action( 'wp_ajax_kapsule_job_status',              array( $this, 'ajax_job_status' ) );
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
            // THE REAL KAPSULEHOST MARK, not a redraw of it. This file used to hold a hand-authored
            // approximation (flat cyan cloud, flat navy bar) which rendered fine and was still not our
            // logo; it now carries the artwork from brand/masters/kapsule-icon.png, the single source
            // the brand README names. The data-URI prefix is load-bearing and must stay exactly
            // "data:image/svg+xml;base64,": that literal is what makes WordPress paint the icon as a
            // BACKGROUND IMAGE instead of an <img>, and #adminmenu .wp-menu-image img sets opacity 0.6,
            // which washes a colour logo out. See the comment inside the SVG for the rest.
            'data:image/svg+xml;base64,' . base64_encode(
                // The file carries a long comment explaining where the artwork comes from and how to
                // regenerate it, worth keeping in source and not worth base64-encoding into every admin
                // page load.
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

    // ── The job's state, which is the only thing that decides what this screen says ──────────────
    //
    // The token is marked USED the moment `action=complete` lands, and the POST handler answers a USED
    // token with 409, so after the upload this plugin was STRUCTURALLY unable to ask what became of the
    // customer's site. That is why the fix is a door, not a better sentence: with nothing to read, no
    // wording could have been true. `GET /api/migration/plugin/job-status` accepts a used token for
    // exactly that reason.

    /**
     * Ask KapsuleHost what the JOB is doing, cache it, and return it.
     *
     * Returns null when we could not get an answer, and null is rendered as "we cannot reach
     * KapsuleHost" rather than falling back to the last good state. A stale cache shown as if it were
     * current is how a customer ends up reading a completion that was true ten minutes ago about a job
     * that has since failed, which is this whole defect wearing a slightly newer timestamp.
     */
    private function fetch_job_state( int $timeout = 15 ): ?array {
        $token = get_option( 'kapsule_migration_token', '' );
        if ( empty( $token ) ) return null;

        $response = wp_remote_get(
            KAPSULE_MIGRATOR_API_BASE . '/job-status?token=' . rawurlencode( $token ),
            array( 'timeout' => $timeout )
        );
        if ( is_wp_error( $response ) ) {
            update_option( 'kapsule_migration_job_state_error', $response->get_error_message() );
            return null;
        }
        if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            update_option( 'kapsule_migration_job_state_error', sprintf(
                /* translators: %s: an HTTP status code, e.g. "503". */
                __( 'KapsuleHost answered %s when we asked about your move.', 'kapsule-migrator' ),
                (string) wp_remote_retrieve_response_code( $response )
            ) );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        // A body without a `status` is not a job state. Treating it as one would let a proxy error page
        // or an empty response render as a job with no status, and "no status" is one `??` away from
        // being treated as fine.
        if ( ! is_array( $body ) || ! isset( $body['status'] ) || ! is_string( $body['status'] ) ) {
            update_option( 'kapsule_migration_job_state_error', __( 'KapsuleHost sent an answer we could not read.', 'kapsule-migrator' ) );
            return null;
        }

        $body['fetchedAt'] = time();
        update_option( 'kapsule_migration_job_state', $body );
        delete_option( 'kapsule_migration_job_state_error' );

        if ( ! empty( $body['jobId'] ) && is_string( $body['jobId'] ) ) {
            update_option( 'kapsule_migration_job_id', $body['jobId'] );
        }
        return $body;
    }

    /**
     * Read the job state for RENDERING, refreshing it first.
     *
     * Deliberately a live call on page load rather than a read of the cache. This screen is looked at
     * perhaps three times in a migration's life, so the cost is nothing, and a page that renders a
     * cached completion is exactly the failure being fixed.
     */
    private function job_state_for_render(): ?array {
        // EIGHT SECONDS, NOT FIFTEEN. This runs inside the page render, so the timeout IS how long a
        // customer stares at a blank admin screen when KapsuleHost is slow. Eight is long enough for a
        // healthy round trip from a shared host and short enough that the honest "we cannot reach
        // KapsuleHost" card arrives while they are still looking. The AJAX poll keeps the longer
        // budget, because nobody is blocked on it.
        $fresh = $this->fetch_job_state( 8 );
        if ( is_array( $fresh ) ) return $fresh;
        return null;
    }

    /**
     * THE ONE TEST FOR "IS IT DONE". Nothing else in this file may decide that question.
     *
     * Note what it does NOT accept: a null state (unreachable), a missing status, or any other job
     * status. RUNNING is not done. PENDING is not done. COMPLETED_WITH_ERRORS is not done, because a
     * partial migration told to a customer as a completion is the same lie in a smaller size.
     */
    private static function job_says_complete( ?array $job ): bool {
        return is_array( $job ) && isset( $job['status'] ) && $job['status'] === self::JOB_COMPLETE;
    }

    public function ajax_job_status(): void {
        check_ajax_referer( 'kapsule_migrator_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to do that. Ask an administrator of this site to run the migration.', 'kapsule-migrator' ) );
            return;
        }
        $job = $this->fetch_job_state();
        if ( $job === null ) {
            wp_send_json_error( array(
                'reachable' => false,
                'reason'    => get_option( 'kapsule_migration_job_state_error', '' ),
            ) );
            return;
        }
        wp_send_json_success( $job );
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
        /*
         * THE FILE MANIFEST GOES TO DISK, NOT INTO AN OPTION, AND THIS IS NOT TIDINESS.
         *
         * It used to be `update_option( 'kapsule_migration_chunks', $chunks )` with no autoload
         * argument, so WordPress stored it AUTOLOADED. Measured on a 127,977 file site: the manifest
         * serialises to 34.0 MB and costs 150.8 MB of PHP memory to unserialise. Autoloaded means
         * EVERY request on that site pays it, admin pages, AJAX and the front end alike, from the
         * moment the migration starts. On a shared host with a 128M limit that kills the site, and
         * what a customer sees is the migrator page loading and then dying, which is exactly the
         * report. It would take the whole site with it, not just this page.
         *
         * `autoload=false` alone would not have been enough: the chunk upload reads the manifest on
         * every piece, so that one request would still need 150 MB. Splitting it per chunk means a
         * piece loads only its own list, about 300 KB, and the peak stops scaling with site size.
         *
         * It lives beside the archive in the tmp dir, which is already where the chunk files go and
         * is already cleaned up by reset.
         */
        self::write_chunk_manifest( $packager->get_tmp_dir(), $chunks );
        update_option( 'kapsule_migration_chunk_count', count( $chunks ), false );
        update_option( 'kapsule_migration_file_count',  $packager->get_file_count() );
        update_option( 'kapsule_migration_file_bytes',  $packager->get_total_bytes() );
        update_option( 'kapsule_migration_next_chunk',  0 );
        self::set_status( 'uploading_files' );
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
            // Permanent for the same reason: no number of retries grants a capability.
            wp_send_json_error( array(
                'retryable' => false,
                'reason'    => __( 'You do not have permission to do that. Ask an administrator of this site to run the migration.', 'kapsule-migrator' ),
            ) );
            return;
        }

        $index  = absint( $_POST['index'] ?? 0 );
        $token  = get_option( 'kapsule_migration_token', '' );
        $tmp    = get_option( 'kapsule_migration_tmp_dir', '' );
        // Only this piece's file list, so memory does not scale with the size of the site.
        $chunk  = self::read_chunk_manifest( $tmp, $index );

        // `retryable => false` IS THE POINT, AND ITS ABSENCE PRODUCED A FALSE SENTENCE. Measured on a
        // real drive 2026-08-24: this branch answered with a bare STRING, so the browser's
        // `data.retryable === false` test saw `undefined`, treated a PERMANENT refusal as a dropped
        // packet, retried it five times, and then told the customer:
        //
        //   "We could not reach KapsuleHost after 5 tries (This migration is no longer in a state we
        //    can continue from. Stop and start over to begin a clean run.)"
        //
        // It had reached KapsuleHost perfectly. This site answered, clearly, five times. The sentence
        // names the wrong cause and then quotes the right one inside its own parenthesis, which is the
        // same class as the rest of this lane: a surface reporting something the system did not say.
        // A permanent refusal now reloads so the customer reads the real reason on the error card.
        if ( empty( $token ) || null === $chunk || ! $tmp ) {
            wp_send_json_error( array(
                'retryable' => false,
                'reason'    => __( 'This migration is no longer in a state we can continue from. Stop and start over to begin a clean run.', 'kapsule-migrator' ),
            ) );
            return;
        }

        try {
            $packager   = new Kapsule_Packager( $tmp );
            $chunk_path = $packager->package_chunk( $chunk, $index );

            $uploader = new Kapsule_Uploader( $token );
            // ONE attempt. The browser owns the retry loop so the customer can watch it happen and so
            // no single request sits long enough for the customer's own nginx to 504 it.
            $uploader->upload_chunk( $chunk_path, null, 1 );

            /*
             * Progress comes from a small table of per-chunk totals, not from re-reading manifests.
             * Summing "every chunk before this one" out of the manifests would put the whole file
             * list back into memory one piece at a time, which is the cost this change removes.
             * 118 integers instead of 128,000 paths.
             */
            $chunk_bytes_table = get_option( 'kapsule_migration_chunk_bytes', array() );
            $bytes_before = 0;
            for ( $i = 0; $i < $index; $i++ ) {
                $bytes_before += (int) ( $chunk_bytes_table[ $i ] ?? 0 );
            }
            $chunk_bytes = (int) ( $chunk_bytes_table[ $index ] ?? array_sum( array_column( $chunk, 'size' ) ) );
            $bytes_done  = $bytes_before + $chunk_bytes;
            $total_bytes = (int) get_option( 'kapsule_migration_file_bytes', 0 );

            update_option( 'kapsule_migration_next_chunk', $index + 1 );
            update_option( 'kapsule_migration_progress', array(
                'phase'            => 'files',
                'bytesTransferred' => $bytes_done,
                'totalBytes'       => $total_bytes,
            ) );

            /*
             * THE ANSWER TO THIS CALL WAS THROWN AWAY, AND IT IS THE ANSWER TO THREE OF THE REPORTS.
             *
             * The return value of `wp_remote_post` was discarded. KapsuleHost now replies to every
             * progress call with the job's real state, so capturing it costs nothing (the request
             * was already being made after every piece) and closes all three:
             *
             *   THE TWO SURFACES DISAGREED ON THE PERCENTAGE. This screen computed its own from
             *   bytes sent, and KapsuleHost computed a different one covering the whole job,
             *   including the work that happens after the upload. Both were honest and the customer
             *   had two numbers for one migration. There is now ONE number, theirs, rendered here.
             *
             *   THE PIECE COUNTS DISAGREED. This screen counted what it had SENT and the panel
             *   counted what had LANDED, which differ by whatever is in flight. `chunksReceived` is
             *   what they actually hold, so both screens show one count and it is the truthful one.
             *
             *   AND THIS PLUGIN NEVER LEARNED IT HAD BEEN STOPPED. A stop from the KapsuleHost
             *   panel wrote the stop on the job and nothing told this screen, so it carried on
             *   rendering a live transfer for a migration that had ended. `stopped` comes back on
             *   this same call, so the very next piece finds out.
             */
            $api = wp_remote_post( KAPSULE_MIGRATOR_API_BASE, array(
                'headers'     => array( 'Content-Type' => 'application/json' ),
                'body'        => wp_json_encode( array(
                    'token'            => $token,
                    'action'           => 'progress',
                    'phase'            => 'files',
                    'bytesTransferred' => $bytes_done,
                    'totalBytes'       => $total_bytes,
                    // SENT SO THE PANEL CAN SHOW WHAT THIS SCREEN SHOWS. The plugin has always known
                    // the piece count and the file census and never told KapsuleHost, so the panel
                    // could not render "piece 10 of 118" or a file count no matter how it was built.
                    'totalChunks'      => (int) get_option( 'kapsule_migration_chunk_count', 0 ),
                    'fileCount'        => (int) get_option( 'kapsule_migration_file_count', 0 ),
                ) ),
                'timeout'     => 10,
                'data_format' => 'body',
            ) );

            /*
             * EVERY FIELD DEFAULTS TO NULL, NEVER TO A NUMBER.
             *
             * An older server, a timeout, or a body we cannot parse must leave this screen showing
             * what it showed before rather than dropping to 0%. Null means "we were not told" and 0
             * is a measurement, and rendering the first as the second is how a screen claims nothing
             * has moved when it simply did not hear back. The JS treats null as "keep what you have".
             */
            $srv = array( 'progress' => null, 'chunksReceived' => null, 'chunkCount' => null, 'stopped' => null, 'stoppedBy' => null );
            if ( ! is_wp_error( $api ) ) {
                $decoded = json_decode( (string) wp_remote_retrieve_body( $api ), true );
                if ( is_array( $decoded ) ) {
                    foreach ( array_keys( $srv ) as $k ) {
                        if ( isset( $decoded[ $k ] ) ) {
                            $srv[ $k ] = $decoded[ $k ];
                        }
                    }
                }
            }

            /*
             * A STOP HANDS THE SCREEN OVER TO THE JOB, it does not invent a local state.
             *
             * `awaiting_import` is the status whose render calls `render_job_outcome()` with the job
             * state fetched from KapsuleHost, and that function's own comment is the rule being
             * followed here: "EVERY CARD FROM HERE IS CHOSEN BY THE JOB, NOT BY ANYTHING THIS PLUGIN
             * KNOWS." So the stop is announced by the job carrying `stopped`, and the card is chosen
             * from that.
             *
             * Two things deliberately NOT done. A new status word like 'stopped' would fall through
             * every `elseif ( $status === ... )` branch in the render and land on the idle screen,
             * silently losing the explanation. And `kapsule_migration_job_state` holds the whole job
             * BODY as an array, so writing a word into it would corrupt the cache that
             * `job_state_for_render()` reads: the option name reads like a status and is not one.
             */
            if ( ! empty( $srv['stopped'] ) ) {
                update_option( 'kapsule_migration_status', 'awaiting_import' );
                $this->fetch_job_state();
            }

            @unlink( $chunk_path );

            wp_send_json_success( array(
                'chunkIndex'     => $index,
                'bytesDone'      => $bytes_done,
                'totalBytes'     => $total_bytes,
                'progress'       => $srv['progress'],
                'chunksReceived' => $srv['chunksReceived'],
                'chunkCount'     => $srv['chunkCount'],
                'stopped'        => $srv['stopped'],
                'stoppedBy'      => $srv['stoppedBy'],
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
            self::set_status( 'error' );
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
            self::set_status( 'uploading_db' );

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

            // THE EVENT THAT JUST HAPPENED IS "THE UPLOAD FINISHED", AND THAT IS WHAT GETS RECORDED.
            //
            // The old line here was `update_option( ..., 'complete' )`, and this is the exact seam the
            // oaohost migration fell through: everything that can fail (provisioning, unpacking, the
            // file placement, the database import, the URL rewrite, proving the site serves) happens
            // AFTER this request returns, driven by the worker that the POST above has only just
            // dispatched. Reporting completion here is reporting the starting gun as the finish line.
            //
            // If the POST failed we have no job id, and that is a state a customer must be shown rather
            // than have smoothed over: the files are uploaded and nothing is processing them.
            if ( $job_id ) {
                update_option( 'kapsule_migration_job_id', $job_id );
            }
            self::set_status( 'awaiting_import' );

            // Read the job state once, straight away, so a customer whose page reloads immediately sees
            // a real state rather than an empty one. It is only a read; it cannot manufacture progress.
            $this->fetch_job_state();

            $packager->cleanup();

            wp_send_json_success( array(
                'status' => 'awaiting_import',
                'jobId'  => $job_id,
            ) );
        } catch ( Exception $e ) {
            self::set_status( 'error' );
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

        self::set_status( 'standalone_packaging' );
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

        /*
         * TELL KAPSULE FIRST. Stopping used to be entirely local: this handler deleted local options
         * and cleaned the temp directory, and made no request to KapsuleHost at all. So a customer
         * who stopped a migration on their own site left our panel still claiming to be working on
         * it, with no way for them to tell whether anything was still running against their server.
         *
         * BEFORE the token is deleted, because the token is how we authenticate the stop. Doing it
         * after would mean announcing the stop with a credential we had just thrown away.
         *
         * Short timeout and the result is ignored on purpose: the customer pressed stop, and their
         * site's cleanup must not hang or fail because our end was slow. If the call does not land,
         * the panel still notices the silence, because it knows when the last piece arrived.
         */
        $stop_token = get_option( 'kapsule_migration_token', '' );
        if ( ! empty( $stop_token ) ) {
            wp_remote_post( KAPSULE_MIGRATOR_API_BASE . '/stop', array(
                'timeout'  => 5,
                'blocking' => true,
                'headers'  => array( 'X-Migration-Token' => $stop_token ),
            ) );
        }

        wp_clear_scheduled_hook( 'kapsule_run_migration' );
        wp_clear_scheduled_hook( 'kapsule_run_standalone' );
        delete_option( 'kapsule_migration_token' );
        delete_option( 'kapsule_migration_status' );
        delete_option( 'kapsule_migration_progress' );
        delete_option( 'kapsule_migration_error' );
        delete_option( 'kapsule_migration_job_id' );
        // Still deleted by name: a site upgrading from a version that wrote the 34 MB autoloaded
        // option must have that row removed, or it keeps paying for it on every request forever.
        delete_option( 'kapsule_migration_chunks' );
        delete_option( 'kapsule_migration_chunk_bytes' );
        delete_option( 'kapsule_migration_chunk_count' );
        delete_option( 'kapsule_migration_file_count' );
        delete_option( 'kapsule_migration_file_bytes' );
        delete_option( 'kapsule_migration_next_chunk' );
        delete_option( 'kapsule_migration_started_at' );
        // The cached job state belongs to the job that is being abandoned. Leaving it behind would let
        // a NEW run's first paint render the PREVIOUS run's outcome, which is the same defect with a
        // fresher date on it.
        delete_option( 'kapsule_migration_job_state' );
        delete_option( 'kapsule_migration_job_state_error' );
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
                        <span class="km-chip" data-state="done"><?php echo esc_html__( 'Package ready', 'kapsule-migrator' ); ?></span>
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

            <?php elseif ( $status === 'awaiting_import' ) :
                // EVERY CARD FROM HERE IS CHOSEN BY THE JOB, NOT BY ANYTHING THIS PLUGIN KNOWS.
                //
                // `$job` is null when we could not reach KapsuleHost, and that renders as "we cannot
                // check" rather than as the last thing we saw. There is deliberately no `?:` here that
                // could resolve to a completion.
                $this->render_job_outcome( $this->job_state_for_render(), $tick, $bang, $info, $bold );
            ?>

            <?php elseif ( $status === 'error' ) : ?>

                <div class="km-card">
                    <div class="km-card-body">
                        <span class="km-chip" data-state="error"><?php echo esc_html__( 'Move stopped', 'kapsule-migrator' ); ?></span>
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
     * Draw whatever the JOB says is true. The upload is over; nothing local decides anything here.
     *
     * The dispatch is exhaustive on purpose and its DEFAULT is "still working", never "finished". A
     * status this build has not heard of (a new enum value shipped by the portal after this plugin was
     * installed, which will happen, because a customer's plugin is as old as the day they installed it)
     * lands on "we are still working on it" plus a link to the panel, which is true of every state that
     * is not a terminal one and is safe for the ones that are: the panel then tells them the rest.
     * The opposite default is the entire defect.
     */
    private function render_job_outcome( ?array $job, string $tick, string $bang, string $info, array $bold ): void {
        // ONE call, one place, and it returns false for every input except a job the portal has
        // reported COMPLETED. If it returns false we fall through to the honest states below.
        if ( $this->render_complete_card( $job, $tick, $bold ) ) {
            return;
        }

        $status  = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';
        $job_id  = is_array( $job ) ? (string) ( $job['jobId'] ?? '' ) : get_option( 'kapsule_migration_job_id', '' );
        $panel   = $job_id ? KAPSULE_MIGRATOR_HOST . '/migration/' . $job_id : KAPSULE_MIGRATOR_HOST;

        /*
         * STOPPED, AND IT OUTRANKS FAILED, because a stop is not a failure and must not be dressed
         * as one.
         *
         * KapsuleHost records a stop by marking the job FAILED, which is the only terminal status
         * that schema has, plus a `stopped` timestamp saying what really happened. Read `status`
         * alone and a customer who deliberately halted their own move is shown a red error card
         * about something breaking. They know why it stopped: they did it, or they did it from the
         * other screen. This branch is placed BEFORE any status test for that reason.
         *
         * `stoppedBy` decides the sentence, because a stop the customer did not perform HERE is the
         * one that actually needs explaining: 'customer_plugin' means this screen, anything else
         * means their KapsuleHost panel, and being told which is what stops it reading as the site
         * having done something on its own.
         */
        $stopped_at = is_array( $job ) ? (string) ( $job['stopped'] ?? '' ) : '';
        if ( $stopped_at !== '' ) {
            $by_plugin = is_array( $job ) && ( $job['stoppedBy'] ?? '' ) === 'customer_plugin';
            ?>
            <div class="km-card">
                <div class="km-card-body">
                    <span class="km-chip"><?php echo esc_html__( 'Move stopped', 'kapsule-migrator' ); ?></span>
                    <h1 class="km-title"><?php echo esc_html__( 'This move was stopped', 'kapsule-migrator' ); ?></h1>
                    <p class="km-lede"><?php
                        echo esc_html(
                            $by_plugin
                                ? __( 'You stopped this move from this screen. Nothing further is being sent and nothing on this site was changed.', 'kapsule-migrator' )
                                : __( 'This move was stopped from your KapsuleHost panel. Nothing further is being sent and nothing on this site was changed.', 'kapsule-migrator' )
                        );
                    ?></p>

                    <div class="km-note" data-tone="info">
                        <?php echo $info; ?>
                        <span><?php echo esc_html__( 'Your site here is untouched and still serving visitors exactly as it was. The pieces that had already reached KapsuleHost are discarded, so starting again starts from the beginning rather than from a half delivered copy.', 'kapsule-migrator' ); ?></span>
                    </div>

                    <div class="km-actions">
                        <button id="kapsule-reset-btn" class="km-btn km-btn--primary"><?php echo esc_html__( 'Start over', 'kapsule-migrator' ); ?></button>
                        <a href="<?php echo esc_url( $panel ); ?>" class="km-btn km-btn--ghost" target="_blank" rel="noopener"><?php echo esc_html__( 'Open my panel', 'kapsule-migrator' ); ?></a>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        if ( $job === null ) {
            // UNREACHABLE IS A STATE, NOT A REASON TO GUESS. A payment path that silently falls back to
            // a lesser method is the same shape as a status screen that falls back to optimism, and this
            // one has the higher stakes: the customer's next move is deciding whether to switch their
            // DNS across.
            $reason = (string) get_option( 'kapsule_migration_job_state_error', '' );
            ?>
            <div class="km-card">
                <div class="km-card-body">
                    <span class="km-chip" data-state="connecting"><?php echo esc_html__( 'Checking', 'kapsule-migrator' ); ?></span>
                    <h1 class="km-title"><?php echo esc_html__( 'Your files are with KapsuleHost', 'kapsule-migrator' ); ?></h1>
                    <p class="km-lede"><?php echo esc_html__( 'Everything uploaded from this site. We cannot reach KapsuleHost right now to tell you what has happened since, so we will not guess: open your panel to see where the move has got to.', 'kapsule-migrator' ); ?></p>
                    <?php if ( $reason ) : ?>
                        <div class="km-note" data-tone="warn">
                            <?php echo $bang; ?>
                            <span><?php echo esc_html( $reason ); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="km-note" data-tone="info">
                        <?php echo $info; ?>
                        <span><?php echo esc_html__( 'This site has not been changed and is still serving visitors, whatever the move is doing.', 'kapsule-migrator' ); ?></span>
                    </div>
                    <div class="km-actions">
                        <button id="kapsule-recheck-btn" class="km-btn km-btn--primary"><?php echo esc_html__( 'Check again', 'kapsule-migrator' ); ?></button>
                        <a href="<?php echo esc_url( $panel ); ?>" class="km-btn km-btn--ghost" target="_blank" rel="noopener"><?php echo esc_html__( 'Open my panel', 'kapsule-migrator' ); ?></a>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        if ( $status === 'FAILED' || $status === 'CANCELLED' ) {
            $why = (string) ( $job['errorMessage'] ?? '' );
            $at  = $this->phase_label( (string) ( $job['phase'] ?? '' ) );
            ?>
            <div class="km-card">
                <div class="km-card-body">
                    <span class="km-chip" data-state="error"><?php echo esc_html__( 'Move stopped', 'kapsule-migrator' ); ?></span>
                    <h1 class="km-title"><?php echo esc_html__( 'The move did not finish', 'kapsule-migrator' ); ?></h1>
                    <p class="km-lede"><?php echo esc_html__( 'Your files reached KapsuleHost, but putting the site together did not finish. Nothing here has changed: this site is untouched and still serving visitors, and nothing has moved for anyone visiting it.', 'kapsule-migrator' ); ?></p>

                    <?php if ( $at ) : ?>
                        <div class="km-facts">
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Stopped at', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v"><?php echo esc_html( $at ); ?></div>
                            </div>
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Files placed', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v"><?php echo esc_html( ! empty( $job['filesPlaced'] ) ? __( 'Yes', 'kapsule-migrator' ) : __( 'No', 'kapsule-migrator' ) ); ?></div>
                            </div>
                            <div class="km-fact">
                                <div class="km-fact-k"><?php echo esc_html__( 'Database', 'kapsule-migrator' ); ?></div>
                                <div class="km-fact-v"><?php echo esc_html( ! empty( $job['databaseImported'] ) ? __( 'Imported', 'kapsule-migrator' ) : __( 'Not imported', 'kapsule-migrator' ) ); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $why ) : ?>
                        <div class="km-note" data-tone="error">
                            <?php echo $bang; ?>
                            <span><?php echo esc_html( $why ); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="km-actions">
                        <a href="<?php echo esc_url( $panel ); ?>" class="km-btn km-btn--primary" target="_blank" rel="noopener"><?php echo esc_html__( 'See the details in my panel', 'kapsule-migrator' ); ?></a>
                        <a href="<?php echo esc_url( KAPSULE_MIGRATOR_HOST ); ?>/support" class="km-btn km-btn--ghost" target="_blank" rel="noopener"><?php echo esc_html__( 'Contact support', 'kapsule-migrator' ); ?></a>
                        <button id="kapsule-reset-btn" class="km-btn km-btn--ghost"><?php echo esc_html__( 'Start over from here', 'kapsule-migrator' ); ?></button>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        if ( $status === 'COMPLETED_WITH_ERRORS' || $status === 'OPS_ESCALATED' ) {
            ?>
            <div class="km-card">
                <div class="km-card-body">
                    <span class="km-chip" data-state="error"><?php echo esc_html__( 'Needs a look', 'kapsule-migrator' ); ?></span>
                    <h1 class="km-title"><?php echo esc_html__( 'Part of your site moved', 'kapsule-migrator' ); ?></h1>
                    <p class="km-lede"><?php echo esc_html__( 'Some of the move finished and some of it did not, so we are not calling it done. Your panel lists exactly what came across and what is still here. This site is untouched and still serving visitors.', 'kapsule-migrator' ); ?></p>
                    <div class="km-actions">
                        <a href="<?php echo esc_url( $panel ); ?>" class="km-btn km-btn--primary" target="_blank" rel="noopener"><?php echo esc_html__( 'See what moved', 'kapsule-migrator' ); ?></a>
                        <a href="<?php echo esc_url( KAPSULE_MIGRATOR_HOST ); ?>/support" class="km-btn km-btn--ghost" target="_blank" rel="noopener"><?php echo esc_html__( 'Contact support', 'kapsule-migrator' ); ?></a>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        // PENDING, RUNNING, PAUSED, NO_JOB and anything this build has never heard of.
        $pct   = max( 0, min( 99, (int) ( $job['progress'] ?? 0 ) ) );
        $label = $this->phase_label( (string) ( $job['phase'] ?? '' ) );
        $msg   = (string) ( $job['phaseMessage'] ?? '' );
        ?>
        <div class="km-card">
            <div class="km-card-body">
                <span class="km-chip" data-state="transferring"><?php echo esc_html( $label ? $label : __( 'Working', 'kapsule-migrator' ) ); ?></span>
                <h1 class="km-title"><?php echo esc_html__( 'KapsuleHost is putting your site together', 'kapsule-migrator' ); ?></h1>
                <p class="km-lede"><?php echo esc_html__( 'Everything uploaded from this site. KapsuleHost is unpacking it, importing your database and checking the result. We will show you the outcome here, and it is safe to close this tab.', 'kapsule-migrator' ); ?></p>

                <div class="km-meter">
                    <div class="km-meter-head">
                        <span class="km-meter-pct"><span id="km-job-pct"><?php echo (int) $pct; ?></span><sub>%</sub></span>
                        <span class="km-meter-note" id="km-job-note"><?php echo esc_html( $msg ); ?></span>
                    </div>
                    <?php // data-live="0" ON PURPOSE. The rail's sheen is this plugin's claim that bytes
                          // are leaving THIS server, and by now they have all left: the work is happening at
                          // KapsuleHost. The bar still moves, because the job's progress is real, but it does
                          // not animate a transfer that finished. ?>
                    <div class="km-rail" id="km-rail" data-live="0">
                        <div class="km-fill" id="km-job-fill" style="width:<?php echo (int) $pct; ?>%"></div>
                    </div>
                </div>

                <div class="km-note" data-tone="info">
                    <?php echo $info; ?>
                    <span><?php echo wp_kses( __( '<strong>This site has not been changed.</strong> It is still live and serving visitors, and nothing moves for them until you point your domain at the new copy.', 'kapsule-migrator' ), $bold ); ?></span>
                </div>

                <div class="km-actions">
                    <a href="<?php echo esc_url( $panel ); ?>" class="km-btn km-btn--primary" target="_blank" rel="noopener"><?php echo esc_html__( 'Follow it in my panel', 'kapsule-migrator' ); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * THE COMPLETION SCREEN, AND THE ONLY GATE IN FRONT OF IT.
     *
     * Returns false without emitting a byte unless the PORTAL reported COMPLETED. That early return is
     * the structural half of this fix: the words "Your site is on KapsuleHost" exist in exactly one
     * method, and reaching them requires a value this plugin cannot produce for itself. Deleting the
     * gate deletes the copy with it.
     *
     * NOTE WHAT THE FACTS ARE MADE OF NOW. "Database: Copied" used to be a hardcoded string printed
     * beside a heading, which is how a customer whose database import was about to fail read the word
     * "Copied". It is now `$job['databaseImported']`, which the portal derives from the worker's stage
     * journal, so the cell cannot say the import happened unless the import completed. Same for the
     * files. The file COUNT and the byte total stay local, because they are honest local measurements
     * of what this plugin sent, and they are now labelled as what they are.
     */
    private function render_complete_card( ?array $job, string $tick, array $bold ): bool {
        if ( ! self::job_says_complete( $job ) ) {
            return false;
        }

        $file_count  = (int) get_option( 'kapsule_migration_file_count', 0 );
        $total_bytes = (int) get_option( 'kapsule_migration_file_bytes', 0 );
        $job_id      = (string) ( $job['jobId'] ?? get_option( 'kapsule_migration_job_id', '' ) );
        $staging     = (string) ( $job['stagingDomain'] ?? '' );
        $db_ok       = ! empty( $job['databaseImported'] );
        ?>
        <div class="km-card">
            <div class="km-card-body">
                <div class="km-done">
                    <div class="km-done-ring">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <span class="km-chip" data-state="done"><?php echo esc_html__( 'Move complete', 'kapsule-migrator' ); ?></span>
                    <h1 class="km-title" style="margin-top:14px;"><?php echo esc_html__( 'Your site is on KapsuleHost', 'kapsule-migrator' ); ?></h1>
                    <p class="km-lede" style="margin:9px auto 0;"><?php
                        /* translators: %s: number of files this site sent, already formatted for the locale. */
                        printf( esc_html__( 'KapsuleHost has finished putting your site together from the %s files this site sent. Open the copy and look around before you point your domain at it.', 'kapsule-migrator' ),
                            esc_html( number_format_i18n( $file_count ) ) );
                    ?></p>
                </div>

                <div class="km-facts">
                    <div class="km-fact">
                        <div class="km-fact-k"><?php echo esc_html__( 'Files sent', 'kapsule-migrator' ); ?></div>
                        <div class="km-fact-v"><?php echo esc_html( number_format_i18n( $file_count ) ); ?></div>
                    </div>
                    <div class="km-fact">
                        <div class="km-fact-k"><?php echo esc_html__( 'Transferred', 'kapsule-migrator' ); ?></div>
                        <div class="km-fact-v"><?php echo esc_html( $this->format_bytes( $total_bytes ) ); ?></div>
                    </div>
                    <div class="km-fact">
                        <div class="km-fact-k"><?php echo esc_html__( 'Database', 'kapsule-migrator' ); ?></div>
                        <div class="km-fact-v"><?php echo esc_html( $db_ok ? __( 'Imported', 'kapsule-migrator' ) : __( 'Not imported', 'kapsule-migrator' ) ); ?></div>
                    </div>
                    <div class="km-fact">
                        <div class="km-fact-k"><?php echo esc_html__( 'Your copy', 'kapsule-migrator' ); ?></div>
                        <div class="km-fact-v"><?php echo esc_html( $staging ? $staging : __( 'in your panel', 'kapsule-migrator' ) ); ?></div>
                    </div>
                </div>

                <div class="km-note" data-tone="good">
                    <?php echo $tick; ?>
                    <span><?php echo wp_kses( __( '<strong>This site has not been changed.</strong> It is still live and serving visitors. Nothing moves for your visitors until you point your domain at the new copy.', 'kapsule-migrator' ), $bold ); ?></span>
                </div>

                <div class="km-actions">
                    <?php if ( $staging ) : ?>
                        <a href="https://<?php echo esc_attr( $staging ); ?>" class="km-btn km-btn--primary" target="_blank" rel="noopener"><?php echo esc_html__( 'Open the migrated site', 'kapsule-migrator' ); ?></a>
                    <?php endif; ?>
                    <?php if ( $job_id ) : ?>
                        <a href="<?php echo esc_url( KAPSULE_MIGRATOR_HOST ); ?>/migration/<?php echo esc_attr( $job_id ); ?>" class="km-btn km-btn--ghost" target="_blank" rel="noopener"><?php echo esc_html__( 'See the move in my panel', 'kapsule-migrator' ); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( KAPSULE_MIGRATOR_HOST ); ?>" class="km-btn km-btn--ghost" target="_blank" rel="noopener"><?php echo esc_html__( 'Back to my panel', 'kapsule-migrator' ); ?></a>
                </div>
            </div>
        </div>
        <?php
        return true;
    }

    /**
     * The customer's words for a worker phase.
     *
     * A phase this build does not recognise returns EMPTY, not the raw id. Printing `placing_files` at
     * a customer is worse than printing nothing, and the raw id would also read as translated copy in
     * every locale. Mirrors src/lib/migration/phases.ts in the portal; that file is the source of the
     * list and this is where those ids become sentences on the customer's own WordPress.
     */
    private function phase_label( string $phase ): string {
        switch ( $phase ) {
            case 'queued':         return __( 'Waiting to start', 'kapsule-migrator' );
            case 'preflight':      return __( 'Checking what arrived', 'kapsule-migrator' );
            case 'connecting':     return __( 'Connecting', 'kapsule-migrator' );
            case 'scanning':       return __( 'Looking at your site', 'kapsule-migrator' );
            case 'provisioning':   return __( 'Building your new environment', 'kapsule-migrator' );
            case 'receiving':      return __( 'Moving your files to the server', 'kapsule-migrator' );
            case 'unpacking':      return __( 'Unpacking your files', 'kapsule-migrator' );
            case 'placing_files':  return __( 'Putting the files in place', 'kapsule-migrator' );
            case 'pulling_files':  return __( 'Copying files', 'kapsule-migrator' );
            case 'importing_db':   return __( 'Importing your database', 'kapsule-migrator' );
            case 'search_replace': return __( 'Rewriting the addresses in your site', 'kapsule-migrator' );
            case 'verifying':      return __( 'Checking the result serves', 'kapsule-migrator' );
            case 'done':           return __( 'Finished', 'kapsule-migrator' );
            default:               return '';
        }
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
            case 'awaiting_import':      return __( 'KapsuleHost is working on it', 'kapsule-migrator' );
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

    /**
     * Write the file manifest to disk, one JSON file per chunk, plus a small table of byte totals.
     *
     * Beside the archive in the tmp dir, which reset already cleans up. Per chunk rather than one
     * file so a piece can be read without holding the whole site's file list in memory.
     */
    private static function write_chunk_manifest( string $tmp, array $chunks ): void {
        $totals = array();
        foreach ( $chunks as $i => $entries ) {
            $totals[ $i ] = array_sum( array_column( $entries, 'size' ) );
            $path = trailingslashit( $tmp ) . 'manifest-' . (int) $i . '.json';
            // JSON rather than serialize(): a truncated write fails to decode loudly instead of
            // unserialising into a half array that would silently skip files.
            file_put_contents( $path, wp_json_encode( $entries ), LOCK_EX );
        }
        update_option( 'kapsule_migration_chunk_bytes', $totals, false );
    }

    /** One chunk's file list, or null when it cannot be read. Never returns a partial list. */
    private static function read_chunk_manifest( string $tmp, int $index ) {
        if ( ! $tmp ) {
            return null;
        }
        $path = trailingslashit( $tmp ) . 'manifest-' . $index . '.json';
        if ( ! is_readable( $path ) ) {
            return null;
        }
        $raw = file_get_contents( $path );
        if ( false === $raw || '' === $raw ) {
            return null;
        }
        $entries = json_decode( $raw, true );
        // A decode failure is a TRUNCATED manifest, and continuing would package a chunk missing
        // files while reporting success. Refusing sends it down the retryable path instead.
        return is_array( $entries ) ? $entries : null;
    }

}
