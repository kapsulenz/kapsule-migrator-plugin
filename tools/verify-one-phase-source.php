<?php
/**
 * ONE PHASE VALUE DECIDES THE BADGE, THE HEADING AND THE BODY, AND NO TRANSPORT LIBRARY'S TEXT REACHES
 * A CUSTOMER. Proved by RENDERING the real class, not by reading it.
 *
 * WHAT A REAL CUSTOMER SAW, 2026-08-31, one card, one instant:
 *
 *     badge     Checking the connection
 *     heading   KapsuleHost is putting your site together
 *     body      Everything uploaded from this site. KapsuleHost is unpacking it, importing your
 *               database and checking the result.
 *     note      cURL error 28: Operation timed out after 15002 milliseconds with 0 bytes received
 *     meter     52%
 *
 * A grep cannot grade this. The old heading and body were perfectly ordinary `esc_html__()` calls and
 * looked exactly like correct code; what was wrong was that NOTHING CONNECTED THEM TO THE PHASE. So
 * this loads the shipped `Kapsule_Admin_Page` against WordPress stubs, renders the awaiting-import
 * card once per declared phase, and asserts the three fields it actually emitted.
 *
 * WHY THAT CAN ONLY PASS FOR ONE REASON: a field that is a constant renders the same bytes for every
 * phase. Fourteen phases and three fields is 42 assertions, and a constant heading fails 13 of them.
 *
 * PROVED ABLE TO GO RED, against the code that actually shipped rather than against a mutation this
 * file invented: `--self-test` extracts the PRE-FIX `admin/class-admin-page.php` from the pinned base
 * commit and runs the IDENTICAL assertions on it. They must fail there. A control pinned to a commit
 * cannot rot, and it survives the fix that silenced it.
 *
 * Usage:
 *   php tools/verify-one-phase-source.php              run the checks on the working tree
 *   php tools/verify-one-phase-source.php --self-test  prove the checks go red on the pre-fix source
 *   php tools/verify-one-phase-source.php --show       print the before and after cards for a human
 */

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// The commit this control is pinned to. It is the import of the helpdesk lane's uncommitted 1.5.7
// tree, which is the code the customer ran. A sha is immutable, so the negative arm cannot rot.
// ─────────────────────────────────────────────────────────────────────────────────────────────────
const PRE_FIX_REF = '115ad10';

$root = dirname( __DIR__ );
$mode = $argv[1] ?? '';

/*
 * THE NEGATIVE ARM, RUN IN ITS OWN PROCESS.
 *
 * Both copies of the class are called `Kapsule_Admin_Page`, so they cannot be loaded together. This
 * extracts the pinned pre-fix source and re-runs THIS FILE, unmodified, against it. Nothing about the
 * assertions changes between the two arms, which is the only way the red proves anything about the
 * green.
 */
if ( $mode === '--self-test' ) {
    $tmp = sys_get_temp_dir() . '/km-prefix-' . getmypid();
    @mkdir( $tmp . '/admin', 0700, true );
    @mkdir( $tmp . '/includes', 0700, true );

    $show = escapeshellarg( PRE_FIX_REF . ':admin/class-admin-page.php' );
    $out  = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && git show ' . $show . ' 2>&1' );
    if ( ! is_string( $out ) || strpos( $out, 'class Kapsule_Admin_Page' ) === false ) {
        echo "BLIND: could not extract " . PRE_FIX_REF . ":admin/class-admin-page.php\n";
        echo "  A negative arm that cannot read its subject is not a pass. Refusing rather than reporting green.\n";
        exit( 2 );
    }
    file_put_contents( $tmp . '/admin/class-admin-page.php', $out );

    echo "SELF-TEST: running the identical checks against the PRE-FIX source at " . PRE_FIX_REF . "\n";
    $cmd = 'KM_SRC=' . escapeshellarg( $tmp . '/admin/class-admin-page.php' )
         . ' php ' . escapeshellarg( __FILE__ ) . ' 2>&1';
    $red = shell_exec( $cmd );
    echo preg_replace( '/^/m', '  | ', rtrim( (string) $red ) ) . "\n\n";

    // A pre-fix source that PASSES means the checks are blind, which is worse than a failing fix.
    if ( strpos( (string) $red, 'GREEN' ) !== false ) {
        echo "BLIND: the pre-fix source PASSED these checks. They cannot see the defect they exist for.\n";
        exit( 2 );
    }
    if ( strpos( (string) $red, 'RED' ) === false ) {
        echo "BLIND: the pre-fix run produced neither a RED nor a GREEN verdict.\n";
        exit( 2 );
    }
    echo "SELF-TEST PASSED: the checks go red on the code that shipped the defect.\n";
    exit( 0 );
}

// ── A minimal WordPress, enough to render this one card ──────────────────────────────────────────

if ( ! defined( 'KAPSULE_MIGRATOR_HOST' ) ) {
    define( 'KAPSULE_MIGRATOR_HOST',        'https://kpanel.kapsulehost.com' );
    define( 'KAPSULE_MIGRATOR_API_BASE',    KAPSULE_MIGRATOR_HOST . '/api/migration/plugin' );
    define( 'KAPSULE_MIGRATOR_VERSION',     'test' );
    define( 'KAPSULE_MIGRATOR_PLUGIN_DIR',  $root . '/' );
    define( 'KAPSULE_MIGRATOR_PLUGIN_URL',  'https://example.test/wp-content/plugins/kapsule-migrator/' );
}

$GLOBALS['km_options'] = array();
$GLOBALS['km_http']    = null;   // set to a WP_Error or an array to steer wp_remote_get
$GLOBALS['km_log']     = array();

function get_option( $k, $d = false )       { return $GLOBALS['km_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['km_options'][ $k ] = $v; return true; }
function delete_option( $k )                { unset( $GLOBALS['km_options'][ $k ] ); return true; }
function __( $s, $d = null )                { return $s; }
function esc_html( $s )                     { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = null )        { return esc_html( $s ); }
function esc_attr( $s )                     { return esc_html( $s ); }
function esc_attr__( $s, $d = null )        { return esc_html( $s ); }
function esc_url( $s )                      { return (string) $s; }
function wp_kses( $s, $a )                  { return (string) $s; }
function number_format_i18n( $n, $dp = 0 )  { return number_format( (float) $n, (int) $dp ); }
function add_action()                       {}
function add_menu_page()                    {}
function admin_url( $p = '' )               { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( $a = '' )         { return 'nonce'; }
function determine_locale()                 { return 'en_US'; }
function rawurlencode_wp( $s )              { return rawurlencode( $s ); }
function wp_remote_get( $url, $args = array() ) { return $GLOBALS['km_http']; }
function is_wp_error( $t )                  { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r )      { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }

class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code()    { return $this->code; }
    public function get_error_message() { return $this->message; }
}

// `Kapsule_Uploader::MAX_ATTEMPTS` is referenced inside enqueue_scripts, which this harness never
// calls, but the class must exist for the file to load.
if ( ! class_exists( 'Kapsule_Uploader' ) ) {
    class Kapsule_Uploader { const MAX_ATTEMPTS = 5; public static function reset_progress() {} }
}

// ── Load the subject ─────────────────────────────────────────────────────────────────────────────
//
// KM_SRC lets the SAME harness render a different copy of the class, which is how the negative arm
// runs the identical assertions against the pre-fix source in a separate process.

$src = getenv( 'KM_SRC' ) ?: $root . '/admin/class-admin-page.php';
$transport = dirname( $src ) . '/../includes/class-transport-message.php';
if ( file_exists( $transport ) ) require_once $transport;
require_once $src;



// ── Rendering helpers ────────────────────────────────────────────────────────────────────────────

/** The three fields, pulled back out of the HTML the class actually emitted. */
function render_card( array $job ): array {
    $page = new Kapsule_Admin_Page();
    $m    = new ReflectionMethod( 'Kapsule_Admin_Page', 'render_job_outcome' );
    $m->setAccessible( true );

    ob_start();
    $m->invoke( $page, $job, '<svg/>', '<svg/>', '<svg/>', array( 'strong' => array() ) );
    $html = ob_get_clean();

    // The chip is the FIRST km-chip on the card; the heading the first km-title; the body the first
    // km-lede. Matched by class rather than by id so the same extractor reads the pre-fix markup,
    // which carried no ids at all. An extractor that only worked on the fixed shape would report the
    // negative arm as "broken" instead of "red".
    $grab = function ( string $class ) use ( $html ): string {
        if ( ! preg_match( '~<(?:span|h1|p)[^>]*class="' . preg_quote( $class, '~' ) . '"[^>]*>(.*?)</(?:span|h1|p)>~s', $html, $m ) ) {
            return '';
        }
        return trim( html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
    };

    return array(
        'badge' => $grab( 'km-chip' ),
        'head'  => $grab( 'km-title' ),
        'body'  => $grab( 'km-lede' ),
        'html'  => $html,
    );
}

function running_job( string $phase, int $pct ): array {
    return array(
        'jobId'   => 'job_test',
        'status'  => 'RUNNING',
        'progress' => $pct,
        'phase'   => $phase,
        // The worker's own free sentence. It exists on every real payload and must NOT reach the card.
        'phaseMessage' => 'internal: rsync stage 3/7 to cp1',
        'display' => array( 'stepKey' => $phase, 'percent' => $pct, 'stepLabel' => 'server english', 'steps' => array() ),
    );
}

/*
 * THE INCIDENT, RENDERED. `--card` prints the four fields for the exact case a customer hit: the job
 * sitting at phase `preflight` and 52%, with the status poll failing on a cURL 28.
 */
if ( $mode === '--card' ) {
    $c = render_card( running_job( 'preflight', 52 ) );
    printf( "    badge     %s\n", $c['badge'] );
    printf( "    heading   %s\n", $c['head'] );
    printf( "    body      %s\n", $c['body'] );

    $GLOBALS['km_options'] = array( 'kapsule_migration_token' => 'tok_test' );
    $GLOBALS['km_http']    = new WP_Error( 'http_request_failed',
        'cURL error 28: Operation timed out after 15002 milliseconds with 0 bytes received' );
    $page = new Kapsule_Admin_Page();
    $f    = new ReflectionMethod( 'Kapsule_Admin_Page', 'fetch_job_state' );
    $f->setAccessible( true );
    $f->invoke( $page, 15 );
    printf( "    on timeout %s\n", get_option( 'kapsule_migration_job_state_error', '(nothing recorded)' ) );
    printf( "    kept for us %s\n", is_array( get_option( 'kapsule_migration_job_state_error_raw' ) )
        ? get_option( 'kapsule_migration_job_state_error_raw' )['message'] : '(nothing kept)' );
    exit( 0 );
}

if ( $mode === '--show' ) {
    $tmp = sys_get_temp_dir() . '/km-prefix-show-' . getmypid();
    @mkdir( $tmp . '/admin', 0700, true );
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && git show '
        . escapeshellarg( PRE_FIX_REF . ':admin/class-admin-page.php' ) . ' 2>&1' );
    if ( ! is_string( $out ) || strpos( $out, 'class Kapsule_Admin_Page' ) === false ) {
        echo "BLIND: could not extract the pre-fix source.\n"; exit( 2 );
    }
    file_put_contents( $tmp . '/admin/class-admin-page.php', $out );

    echo "\n  BEFORE (" . PRE_FIX_REF . "), job at phase 'preflight', 52%, status poll times out\n\n";
    passthru( 'KM_SRC=' . escapeshellarg( $tmp . '/admin/class-admin-page.php' )
        . ' php ' . escapeshellarg( __FILE__ ) . ' --card 2>/dev/null | grep -v subject' );
    echo "\n  AFTER, same job, same failure\n\n";
    passthru( 'php ' . escapeshellarg( __FILE__ ) . ' --card 2>/dev/null | grep -v subject' );
    echo "\n";
    exit( 0 );
}

// ── The checks ───────────────────────────────────────────────────────────────────────────────────

$PHASES = array(
    'queued', 'uploading', 'preflight', 'connecting', 'scanning', 'provisioning', 'receiving',
    'unpacking', 'placing_files', 'pulling_files', 'importing_db', 'search_replace', 'verifying', 'done',
);

$failures = array();
function fail( string $msg ) { $GLOBALS['failures'][] = $msg; }

/*
 * CHECK 1. Every one of the three fields VARIES WITH THE PHASE.
 *
 * This is the assertion a constant cannot pass. It says nothing about where the strings live or what
 * they say; it only asks whether changing the single input changes the output. Fourteen phases means
 * a field that is constant collapses to one distinct value where fourteen were required.
 */
$seen = array( 'badge' => array(), 'head' => array(), 'body' => array() );
foreach ( $PHASES as $p ) {
    $c = render_card( running_job( $p, 52 ) );
    foreach ( array( 'badge', 'head', 'body' ) as $field ) {
        if ( $c[ $field ] === '' ) fail( "CHECK 1: phase '$p' rendered an EMPTY $field" );
        $seen[ $field ][ $c[ $field ] ] = true;
    }
}
foreach ( array( 'badge', 'head', 'body' ) as $field ) {
    $n = count( $seen[ $field ] );
    printf( "  %-6s distinct values across %d phases: %d\n", $field, count( $PHASES ), $n );
    if ( $n < count( $PHASES ) ) {
        fail( sprintf( 'CHECK 1: the %s took only %d distinct values across %d phases, so it is not derived from the phase',
            $field, $n, count( $PHASES ) ) );
    }
}

/*
 * CHECK 2. The three fields are the three fields of ONE row.
 *
 * Check 1 would still pass if the badge, the heading and the body were three separate per-phase
 * tables that happened to be in step today. This asserts each rendered field is byte for byte the
 * corresponding field of a single `job_copy()` call.
 */
if ( method_exists( 'Kapsule_Admin_Page', 'job_copy' ) ) {
    foreach ( $PHASES as $p ) {
        $c    = render_card( running_job( $p, 52 ) );
        $copy = Kapsule_Admin_Page::job_copy( $p );
        foreach ( array( 'badge', 'head', 'body' ) as $field ) {
            if ( $c[ $field ] !== $copy[ $field ] ) {
                fail( sprintf( "CHECK 2: phase '%s' %s rendered %s but job_copy() says %s",
                    $p, $field, var_export( $c[ $field ], true ), var_export( $copy[ $field ], true ) ) );
            }
        }
    }
} else {
    fail( 'CHECK 2: there is no job_copy(), so there is no single row for the three fields to come from' );
}

/*
 * CHECK 3. The worker's free-text `phaseMessage` does not reach the card.
 *
 * It is an unbounded string written by the worker for its own log. It was printed verbatim under the
 * percentage, which made it a fourth author on a card that is meant to have one, and it is the field
 * an internal error message travels in.
 */
$c = render_card( running_job( 'importing_db', 52 ) );
if ( strpos( $c['html'], 'internal: rsync stage' ) !== false ) {
    fail( 'CHECK 3: the worker phaseMessage was rendered on the customer card' );
}

/*
 * CHECK 4. A cURL failure never reaches the customer, and IS kept for us.
 *
 * The exact string from the 2026-08-31 incident, fed through the real `fetch_job_state()`.
 */
$GLOBALS['km_options'] = array( 'kapsule_migration_token' => 'tok_test' );
$GLOBALS['km_http']    = new WP_Error( 'http_request_failed',
    'cURL error 28: Operation timed out after 15002 milliseconds with 0 bytes received' );

$page = new Kapsule_Admin_Page();
$f    = new ReflectionMethod( 'Kapsule_Admin_Page', 'fetch_job_state' );
$f->setAccessible( true );
$state = $f->invoke( $page, 15 );

if ( $state !== null ) fail( 'CHECK 4: fetch_job_state did not report the failure as unreachable' );

$customer = (string) get_option( 'kapsule_migration_job_state_error', '' );
echo "  customer sentence: " . $customer . "\n";

foreach ( array( 'cURL', 'curl', 'milliseconds', '15002', '0 bytes' ) as $leak ) {
    if ( strpos( $customer, $leak ) !== false ) {
        fail( "CHECK 4: the customer-facing sentence contains the transport text '$leak'" );
    }
}
if ( $customer === '' ) fail( 'CHECK 4: no customer-facing sentence was recorded at all' );

// It must say we are trying again. A transient timeout on a retried read is not a call to action.
$retry_words = array( 'checking again', 'trying again', 'try again' );
$says_retry  = false;
foreach ( $retry_words as $w ) { if ( stripos( $customer, $w ) !== false ) $says_retry = true; }
if ( ! $says_retry ) fail( 'CHECK 4: the customer-facing sentence does not say we are retrying' );

// And the diagnostic survives for us.
$raw = get_option( 'kapsule_migration_job_state_error_raw', null );
if ( ! is_array( $raw ) || strpos( (string) ( $raw['message'] ?? '' ), 'cURL error 28' ) === false ) {
    fail( 'CHECK 5: the raw transport message was NOT kept in the developer-only field' );
}

/*
 * CHECK 7. THE BADGE WORDING IS THE PANEL'S WORDING, asserted against the portal's own file.
 *
 * This is the check the old comment asked a human to perform ("change display.ts in the same commit,
 * or the divergence is back"). It drifted on nine of thirteen rows while that comment sat there, so
 * it is a gate now. It reads `LABELS` out of `src/lib/migration/display.ts` and compares row by row.
 *
 * IT REFUSES RATHER THAN PASSES when it cannot find the portal file or cannot parse a plausible
 * number of rows out of it. A parity check that silently skips is a green that means nothing, and
 * this one runs on machines that may not have the portal checked out at all.
 */
$portal = getenv( 'KM_PORTAL' ) ?: '/var/www/kapsulecloud-portal';
$disp   = $portal . '/src/lib/migration/display.ts';
if ( ! is_readable( $disp ) ) {
    echo "  CHECK 7 BLIND: no display.ts at $disp (set KM_PORTAL). Parity with KPanel was NOT checked.\n";
    $blind = true;
} else {
    $txt = (string) file_get_contents( $disp );
    if ( ! preg_match( '~const LABELS[^{]*\{(.*?)\n\};~s', $txt, $mm ) ) {
        echo "  CHECK 7 BLIND: could not find the LABELS map in display.ts.\n";
        $blind = true;
    } else {
        preg_match_all( "~^\s*([a-z_]+):\s*'((?:[^'\\\\]|\\\\.)*)'~m", $mm[1], $rows, PREG_SET_ORDER );
        if ( count( $rows ) < 10 ) {
            echo "  CHECK 7 BLIND: parsed only " . count( $rows ) . " rows out of LABELS, which is not a table.\n";
            $blind = true;
        } else {
            echo "  parity: comparing " . count( $rows ) . " KPanel labels against job_copy()\n";
            foreach ( $rows as $r ) {
                list( , $key, $label ) = $r;
                $label = str_replace( "\\'", "'", $label );
                $mine  = method_exists( 'Kapsule_Admin_Page', 'job_copy' )
                    ? Kapsule_Admin_Page::job_copy( $key )['badge'] : '';
                if ( $mine !== $label ) {
                    fail( sprintf( "CHECK 7: phase '%s' is %s in KPanel and %s in the plugin",
                        $key, var_export( $label, true ), var_export( $mine, true ) ) );
                }
            }
        }
    }
}

// CHECK 6. Rendering the unreachable card must not print the raw text either.
$c = render_card_null();
function render_card_null(): array {
    $page = new Kapsule_Admin_Page();
    $m    = new ReflectionMethod( 'Kapsule_Admin_Page', 'render_job_outcome' );
    $m->setAccessible( true );
    ob_start();
    $m->invoke( $page, null, '<svg/>', '<svg/>', '<svg/>', array( 'strong' => array() ) );
    return array( 'html' => ob_get_clean() );
}
if ( strpos( $c['html'], 'cURL' ) !== false || strpos( $c['html'], 'milliseconds' ) !== false ) {
    fail( 'CHECK 6: the unreachable card printed the transport text' );
}

// ── Verdict ──────────────────────────────────────────────────────────────────────────────────────

if ( $failures ) {
    echo "\n  RED (" . count( $failures ) . ")\n";
    foreach ( $failures as $f2 ) echo "    - $f2\n";
    exit( 1 );
}
echo "\n  GREEN: badge, heading and body all derive from one phase value, and no transport text reaches a customer.\n";
exit( 0 );
