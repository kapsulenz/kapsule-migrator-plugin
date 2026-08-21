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
class Kapsule_Uploader {

    private string $token;
    private string $api_base;

    /** Chunk uploads that fail this way will never succeed by trying again. */
    private const PERMANENT_CODES = array( 400, 401, 403, 404, 413, 422 );

    private const MAX_ATTEMPTS   = 5;
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
     * Upload one chunk, retrying transient failures.
     *
     * Returns true if uploaded, false if it was already done (resume). Throws only when the failure
     * is permanent or the retries are exhausted, and the message is written for a customer.
     */
    public function upload_chunk( string $file_path, ?string $remote_name = null ): bool {
        if ( ! file_exists( $file_path ) ) {
            throw new Exception( "We could not read a prepared piece of your site from disk. Free space on the server and start the migration again." );
        }

        $filename = $remote_name ?? basename( $file_path );

        // RESUME: a chunk the server already accepted is not sent again. On a large site this is the
        // difference between a resumed migration finishing in minutes and starting from nothing.
        if ( in_array( $filename, self::completed_chunks(), true ) ) {
            return false;
        }

        $size    = (int) filesize( $file_path );
        $timeout = max( self::MIN_TIMEOUT_S, (int) ceil( $size / self::BYTES_PER_SEC ) * 2 );

        $attempt  = 0;
        $last_msg = '';

        while ( $attempt < self::MAX_ATTEMPTS ) {
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

            $last_msg = $curl_error ? $curl_error : "the server replied {$code}";

            if ( $attempt < self::MAX_ATTEMPTS ) {
                // Exponential backoff with jitter, so a server under load is not hammered in lockstep
                // by every migration running at once.
                $delay = (int) ( self::BASE_BACKOFF_S * pow( 2, $attempt - 1 ) );
                $delay = min( $delay, 60 ) + wp_rand( 0, 3 );
                sleep( $delay );
            }
        }

        throw new Exception(
            "A piece of your site could not be transferred after " . self::MAX_ATTEMPTS . " attempts ({$last_msg}). "
            . "Your site has not been changed. Start the migration again and it will continue from where it stopped."
        );
    }

    /** One attempt. Returns [http code, body, curl error]. */
    private function send( string $file_path, string $filename, int $size, int $timeout ): array {
        $handle = fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return array( 0, '', 'could not open the prepared file' );
        }

        $ch = curl_init( $this->api_base . '/upload-chunk' );
        curl_setopt_array( $ch, array(
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
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
                return "One piece of your site ({$mb} MB) was larger than the server would accept. "
                     . "This is a limit on our side, not a problem with your site. Contact support and we will raise it.";
            case 401:
            case 403:
                return "Your migration token is no longer valid. Generate a new one in your KapsuleHost panel and paste it in again.";
            case 404:
                return "The migration this token belongs to no longer exists. Start a new migration from your KapsuleHost panel.";
            case 422:
                return "The server could not read one of the prepared pieces of your site. Start the migration again to rebuild it.";
            default:
                return "The server refused a piece of your site (code {$code}). Your site has not been changed. Contact support and we will look at it.";
        }
    }
}
