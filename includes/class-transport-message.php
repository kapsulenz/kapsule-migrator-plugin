<?php

/**
 * WHAT WENT WRONG WHEN THIS DID NOT EXIST, AND WHY IT IS A CLASS RATHER THAN A STRING.
 *
 * A real customer, mid-migration on 2026-08-31, read this on their own WordPress:
 *
 *     cURL error 28: Operation timed out after 15002 milliseconds with 0 bytes received
 *
 * That sentence is written by libcurl, about a socket, for whoever is holding the debugger. It names
 * a library the customer has never heard of, a number of milliseconds nobody can act on, and a byte
 * count that reads like data loss. The migration recovered by itself on the next poll and finished.
 * So the transport was fine and the SCREEN was the defect.
 *
 * THREE PLACES PRODUCED IT, which is why the fix is one shared mapper and not three better strings:
 *
 *   1. `Kapsule_Admin_Page::fetch_job_state()` stored `WP_Error::get_error_message()`, which WordPress
 *      fills with the curl transport's own text, into the option the status card renders.
 *   2. `Kapsule_Uploader::send()` returns `curl_error( $ch )` verbatim, which became the parenthesis in
 *      "We could not reach KapsuleHost after 5 tries (Operation timed out after 15002 milliseconds
 *      with 0 bytes received)."
 *   3. `Kapsule_Kapsule_Migrator::call_kapsule_api()` threw it as an Exception message, which lands in
 *      `kapsule_migration_error` and is printed on the stopped card.
 *
 * WHAT A CUSTOMER IS OWED INSTEAD: which party did not answer, what we were asking it, and whether
 * anything is required of them. A transient timeout during a step we retry is NOT an instruction to
 * act, and the copy says so without pretending the hiccup did not happen.
 *
 * WHAT WE KEEP. The raw text is genuinely useful to us and is not thrown away: every mapping call
 * writes the original to the PHP error log and, for the status read, to a developer-only option that
 * no template reads. Losing the diagnostic in order to protect the customer would be trading one
 * defect for another.
 *
 * MATCHING IS BEST EFFORT AND THE DEFAULT IS THE SAFE ONE. libcurl's wording varies by version and
 * WordPress may be running a non-curl transport altogether, so an unrecognised message falls to a
 * generic sentence that is true of every failure this function can be handed. Nothing reaches a
 * customer by falling through: the fallback is copy, not the input.
 */
class Kapsule_Transport_Message {

    /**
     * The customer's sentence for a failed request to KapsuleHost.
     *
     * `$raw` is the transport's own text and is used ONLY to choose between sentences. It is never
     * returned, never interpolated, and never concatenated into the result. That is deliberate: a
     * mapper that appends the original for context is the same defect with a nicer preamble.
     *
     * Every sentence ends by saying we are checking again, because every caller of this function is
     * on a path that retries: the browser retries a chunk on its own timer, and the status card polls
     * again on a fixed interval. If a caller is ever added that does NOT retry, it must use
     * `stopped()` below instead, which promises nothing.
     */
    public static function customer( string $raw ): string {
        $r = strtolower( $raw );

        if ( self::has( $r, array( 'timed out', 'timeout', 'operation too slow', 'curl error 28' ) ) ) {
            return __( 'KapsuleHost did not answer in time when we asked how your move is going. Nothing has gone wrong with your site and nothing is needed from you. We are checking again in a moment.', 'kapsule-migrator' );
        }
        // "Could not resolve host" and "Couldn t resolve host" differ only in the apostrophe across
        // libcurl versions, so the needle is the part they share.
        if ( self::has( $r, array( 'resolve host', 'resolve proxy', 'name or service not known', 'curl error 6' ) ) ) {
            return __( 'This server could not look up the address for KapsuleHost when we asked how your move is going. That is a name lookup on this server rather than a problem with your site. We are checking again in a moment.', 'kapsule-migrator' );
        }
        if ( self::has( $r, array( 'connection refused', 'failed to connect', 'connect to server', 'curl error 7' ) ) ) {
            return __( 'This server could not open a connection to KapsuleHost when we asked how your move is going. Nothing has gone wrong with your site and nothing is needed from you. We are checking again in a moment.', 'kapsule-migrator' );
        }
        if ( self::has( $r, array( 'ssl', 'tls', 'certificate' ) ) ) {
            return __( 'The secure connection to KapsuleHost could not be completed when we asked how your move is going. We are checking again in a moment. If this keeps happening, this server may need its list of trusted certificates updated.', 'kapsule-migrator' );
        }
        if ( self::has( $r, array( 'connection reset', 'recv failure', 'empty reply', 'transfer closed' ) ) ) {
            return __( 'The connection to KapsuleHost closed before we finished asking how your move is going. Nothing has gone wrong with your site and nothing is needed from you. We are checking again in a moment.', 'kapsule-migrator' );
        }

        return __( 'We could not read how your move is going just now. Your files are already with KapsuleHost and this site has not been changed. We are checking again in a moment.', 'kapsule-migrator' );
    }

    /**
     * The same mapping for a path that has RUN OUT of retries, so it must not promise another one.
     *
     * The distinction is the whole reason there are two functions. "We are checking again in a moment"
     * printed under a stopped migration is a promise nothing will keep, and a customer who believes it
     * waits instead of acting.
     */
    public static function stopped( string $raw ): string {
        $r = strtolower( $raw );

        if ( self::has( $r, array( 'timed out', 'timeout', 'operation too slow', 'curl error 28' ) ) ) {
            return __( 'KapsuleHost stopped answering this server in time', 'kapsule-migrator' );
        }
        if ( self::has( $r, array( 'resolve host', 'resolve proxy', 'name or service not known', 'curl error 6' ) ) ) {
            return __( 'this server could not look up the address for KapsuleHost', 'kapsule-migrator' );
        }
        if ( self::has( $r, array( 'connection refused', 'failed to connect', 'connect to server', 'curl error 7' ) ) ) {
            return __( 'this server could not open a connection to KapsuleHost', 'kapsule-migrator' );
        }
        if ( self::has( $r, array( 'ssl', 'tls', 'certificate' ) ) ) {
            return __( 'the secure connection to KapsuleHost could not be completed', 'kapsule-migrator' );
        }
        if ( self::has( $r, array( 'connection reset', 'recv failure', 'empty reply', 'transfer closed' ) ) ) {
            return __( 'the connection to KapsuleHost kept closing part way through', 'kapsule-migrator' );
        }

        return __( 'this server could not complete a request to KapsuleHost', 'kapsule-migrator' );
    }

    /**
     * KEEP THE DIAGNOSTIC. The customer does not read this and we do.
     *
     * `$context` names the call site so a support reader can tell a status poll from a chunk upload
     * without guessing, which was impossible while every failure produced the same bare curl string.
     */
    public static function log( string $context, string $raw ): void {
        if ( $raw === '' ) return;
        error_log( sprintf( '[kapsule-migrator] %s: %s', $context, $raw ) );
    }

    /** True when the haystack contains any needle. Lowercased by the caller, once. */
    private static function has( string $haystack, array $needles ): bool {
        foreach ( $needles as $needle ) {
            if ( strpos( $haystack, $needle ) !== false ) return true;
        }
        return false;
    }
}
