<?php

/**
 * Chunk uploader.
 *
 * REBUILT FOR SIZE. The previous version was a single curl attempt with a fixed 600 second timeout,
 * no retry and no resume, and it threw on any failure. On a 200MB site that is fine. On a 20GB site
 * it is about 400 chunks, and ONE network blip, one slow link that exceeds a fixed timeout, one
 * transient 502 from a deploy, throws the whole migration back to zero. The probability of at least
 * one failure across 400 chunks on a real customer link is not small, it is the expected case.
 *
 * That is the fixed-timeout, fixed-limit class: sized for the small site that was tested, starving
 * the large site that was not.
 *
 * WHAT CHANGED:
 *   - retries with exponential backoff and jitter, so a transient failure costs seconds not a restart
 *   - distinguishes RETRYABLE from PERMANENT: a 413 or 401 will never succeed on retry, so it fails
 *     fast with a message a human can act on, while a 502/504/timeout is retried
 *   - timeout scales with chunk size instead of a fixed 600s, with a floor for tiny chunks
 *   - records each completed chunk so a resumed run SKIPS it instead of re-uploading
 *   - reports the reason in customer language; a raw "HTTP 413" never reaches the UI
 */
/**
 * A transfer failure that is worth trying again: a dropped connection, a timeout, a 502 from a
 * server being reloaded. Distinct from a plain Exception, which here always means STOP.
 *
 * The distinction exists so the caller can tell the customer the truth. "The connection dropped,
 * retrying piece 57" and "your token is no longer valid" need opposite responses, and a single
 * exception type forces the UI to guess.
 */
class Kapsule_Retryable_Exception extends Exception {}

class Kapsule_Uploader {

    private string $token;
    private string $api_base;

    /** Chunk uploads that fail this way will never succeed by trying again. */
    private const PERMANENT_CODES = array( 400, 401, 403, 404, 413, 422 );

    /**
     * The filename the DATABASE dump must arrive under.
     *
     * The server's pre-flight looks for exactly this name and refuses the job without it. Two upload
     * paths existed and disagreed: the browser sent `db.sql.gz` and the WP-Cron path sent
     * `database.sql.gz`, so every cron-driven migration failed pre-flight with "db.sql.gz not found,
     * database upload did not complete". That message blames the customer's connection for a file
     * that transferred perfectly under a name nobody was reading. Named once here so the two paths
     * cannot drift apart again.
     */
    public const DB_REMOTE_NAME = 'db.sql.gz';

    public const MAX_ATTEMPTS    = 5;
    private const BASE_BACKOFF_S = 2;
    /** Assume a pessimistic 1 Mbps floor when sizing the timeout, plus headroom. */
    private const MIN_TIMEOUT_S  = 120;
    private const BYTES_PER_SEC  = 125000; // ~1 Mbps

    public function __construct( string $token ) {
        $this->token    = $token;
        $this->api_base = KAPSULE_MIGRATOR_API_BASE;
    }

    /** Chunks already accepted by the server, so a resumed run does not send them twice. */
    public static function completed_chunks(): array {
        $done = get_option( 'kapsule_migration_chunks_done', array() );
        return is_array( $done ) ? $done : array();
    }

    public static function mark_chunk_done( string $filename ): void {
        $done = self::completed_chunks();
        if ( ! in_array( $filename, $done, true ) ) {
            $done[] = $filename;
            update_option( 'kapsule_migration_chunks_done', $done, false );
        }
    }

    public static function reset_progress(): void {
        delete_option( 'kapsule_migration_chunks_done' );
    }

    /**
     * Upload one chunk.
     *
     * Returns true if uploaded, false if it was already done (resume). Throws `Kapsule_Retryable_Exception`
     * when the failure is worth another go, and a plain `Exception` when it never will be. Both messages
     * are written for a customer to read.
     *
     * WHY $max_attempts IS A PARAMETER, AND WHY THE BROWSER PASSES 1. Retrying in here means SLEEPING
     * in here, and every one of those seconds is spent inside a single HTTP request on the CUSTOMER'S
     * server. nginx's default `fastcgi_read_timeout` is 60 seconds. A 50MB piece on a modest business
     * uplink already flirts with that, and five attempts plus backoff guarantees a 504 that the browser
     * can only read as "the whole thing failed". So the browser passes 1, gets a fast honest answer,
     * and owns the waiting itself, where it can also SHOW the customer what is happening.
     *
     * WP-Cron has no browser to own the loop, so that path keeps the full budget.
     */
    public function upload_chunk( string $file_path, ?string $remote_name = null, int $max_attempts = 0 ): bool {
        $max_attempts = $max_attempts > 0 ? $max_attempts : self::MAX_ATTEMPTS;
        $filename = $remote_name ?? basename( $file_path );

        // RESUME FIRST, and the ORDER here is the point. A chunk the server has already accepted is
        // done, whether or not a copy of it still exists on this disk. Checking the file first meant a
        // resumed run threw "we could not read a prepared piece of your site" for a piece that had
        // arrived perfectly, simply because the packager no longer needed to rebuild it. Delivered
        // beats present.
        if ( in_array( $filename, self::completed_chunks(), true ) ) {
            return false;
        }

        if ( ! file_exists( $file_path ) ) {
            throw new Exception( __( 'We could not read a prepared piece of your site from disk. Free up space on the server and start the migration again.', 'kapsule-migrator' ) );
        }

        $size    = (int) filesize( $file_path );
        $timeout = max( self::MIN_TIMEOUT_S, (int) ceil( $size / self::BYTES_PER_SEC ) * 2 );

        $attempt  = 0;
        $last_msg = '';

        while ( $attempt < $max_attempts ) {
            $attempt++;
            list( $code, $body, $curl_error ) = $this->send( $file_path, $filename, $size, $timeout );

            if ( ! $curl_error && $code >= 200 && $code < 300 ) {
                self::mark_chunk_done( $filename );
                return true;
            }

            // Permanent: retrying cannot help, so say what happened in words a customer can act on.
            if ( ! $curl_error && in_array( $code, self::PERMANENT_CODES, true ) ) {
                throw new Exception( $this->explain( $code, $size ) );
            }

            $last_msg = $curl_error ? $curl_error : sprintf( /* translators: %s: an HTTP status code. */ __( 'the server replied %s', 'kapsule-migrator' ), $code );

            if ( $attempt < $max_attempts ) {
                // Exponential backoff with jitter, so a server under load is not hammered in lockstep
                // by every migration running at once. Only ever reached on the WP-Cron path: when the
                // browser drives, $max_attempts is 1 and it does the waiting itself.
                $delay = (int) ( self::BASE_BACKOFF_S * pow( 2, $attempt - 1 ) );
                $delay = min( $delay, 60 ) + wp_rand( 0, 3 );
                sleep( $delay );
            }
        }

        // Retryable, not fatal. The caller decides whether any budget is left; saying STOP here would
        // throw away a migration over one dropped packet.
        throw new Kapsule_Retryable_Exception( $last_msg );
    }

    /** Backoff for attempt N, in whole seconds. Shared so the browser waits the same way cron does. */
    public static function backoff_seconds( int $attempt ): int {
        $delay = (int) ( self::BASE_BACKOFF_S * pow( 2, max( 0, $attempt - 1 ) ) );
        return min( $delay, 60 );
    }

    /** One attempt. Returns [http code, body, curl error]. */
    private function send( string $file_path, string $filename, int $size, int $timeout ): array {
        $handle = fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return array( 0, '', __( 'we could not open the prepared file', 'kapsule-migrator' ) );
        }

        $ch = curl_init( $this->api_base . '/upload-chunk' );
        curl_setopt_array( $ch, array(
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 30,

            // A STALL IS NOT SLOWNESS, and the absolute timeout cannot tell them apart.
            //
            // CURLOPT_TIMEOUT has to stay generous because a real customer on a poor connection
            // genuinely needs minutes for a 50MB piece. But that same generosity means a connection
            // that has DIED is not noticed until the whole budget expires. Measured on 2026-08-21: an
            // nginx reload severed an in-flight upload and the transfer sat silent for 12.5 minutes
            // before the timeout fired and the retry succeeded in 5 seconds. The customer watching
            // that sees a frozen migration for a quarter of an hour.
            //
            // So abort on a transfer that has effectively stopped: under 1KB/s for 60 seconds running.
            // Anyone actually moving data, however slowly, stays well clear of that floor; a dead
            // socket hits it in a minute and the retry loop does its job immediately.
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME  => 60,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/octet-stream',
                'X-Migration-Token: ' . $this->token,
                'X-Chunk-Filename: ' . $filename,
                'Expect:',
            ),
            CURLOPT_SSL_VERIFYPEER => true,
        ) );

        $body  = curl_exec( $ch );
        $code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );
        fclose( $handle );

        return array( $code, is_string( $body ) ? $body : '', $error );
    }

    /**
     * Permanent failures, in the customer's language.
     *
     * A raw "HTTP 413" must never reach the person migrating their business. They cannot act on a
     * status code; they can act on "this piece was too large for the server".
     */
    private function explain( int $code, int $size ): string {
        $mb = round( $size / 1048576 );
        switch ( $code ) {
            case 413:
                return sprintf(
                    /* translators: %s: the size of the piece that was rejected, e.g. "50". */
                    __( 'One piece of your site (%s MB) was larger than the server would accept. This is a limit on our side, not a problem with your site. Contact support and we will raise it.', 'kapsule-migrator' ),
                    number_format_i18n( $mb )
                );
            case 401:
            case 403:
                return __( 'Your migration token is no longer valid. Generate a new one in your KapsuleHost panel and paste it in again.', 'kapsule-migrator' );
            case 404:
                return __( 'The migration this token belongs to no longer exists. Start a new migration from your KapsuleHost panel.', 'kapsule-migrator' );
            case 422:
                return __( 'The server could not read one of the prepared pieces of your site. Start the migration again to rebuild it.', 'kapsule-migrator' );
            default:
                return sprintf(
                    /* translators: %s: the HTTP status code the server returned. */
                    __( 'The server refused a piece of your site (code %s). Your site has not been changed. Contact support and we will look at it.', 'kapsule-migrator' ),
                    $code
                );
        }
    }
}
