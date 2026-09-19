<?php
/**
 * A STATUS CODE IS NOT A SENTENCE, AND "GONE" IS NOT "UNREACHABLE".
 * PHP CLI test, no WordPress required. Run: php tests/test-job-state-classification.php
 *
 * ── FOUND BY JESSE, 2026-09-19 ─────────────────────────────────────────────────────────────────
 *
 * His migration screen read: "KapsuleHost answered 401 when we asked about your move." A bare HTTP
 * number shown to a customer, on the page whose whole job is to reassure them. The TRANSPORT branch
 * four lines above it already knew better: it sends the raw text to a developer-only option and gives
 * the customer a sentence. The status-code branch was the odd one out in the same function.
 *
 * The worse half is not wording. Every non-200 was treated identically, so the poll retried a DELETED
 * migration for NINE MINUTES: 31 requests, all 401, each rendered as "still trying", under a bar
 * frozen at 96%. No number of retries fixes a token that no longer exists.
 *
 * THIS FILE HAS A HISTORY OF EXACTLY THIS. `git log` carries "The plugin said 'we could not reach
 * KapsuleHost' about an answer KapsuleHost had just given it, five times", and 1.3.1 fixed the same
 * shape on a different path. The arms grade the CLASSIFICATION so the next one goes red here.
 */
define('KAPSULE_MIGRATOR_API_BASE', 'https://example.invalid/api');

$GLOBALS['opts'] = array();
$GLOBALS['next_response'] = null;

function get_option($k, $d = false)  { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k)           { unset($GLOBALS['opts'][$k]); return true; }
function __($s, $d = null)           { return $s; }
function esc_html($s)                { return $s; }
function add_action()                { }
function check_ajax_referer()        { return true; }
function current_user_can()          { return true; }
function determine_locale()          { return 'en_US'; }
function wp_send_json_error($d = null)   { $GLOBALS['json'] = array('success' => false, 'data' => $d); }
function wp_send_json_success($d = null) { $GLOBALS['json'] = array('success' => true,  'data' => $d); }
function wp_remote_get($url, $args = array()) { return $GLOBALS['next_response']; }
function is_wp_error($t)             { return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return is_array($r) && isset($r['code']) ? $r['code'] : 0; }
function wp_remote_retrieve_body($r)          { return is_array($r) && isset($r['body']) ? $r['body'] : ''; }
class WP_Error { public $m; function __construct($m = 'boom') { $this->m = $m; }
    function get_error_message() { return $this->m; } function get_error_code() { return 'http_request_failed'; } }

require_once dirname(__DIR__) . '/includes/class-transport-message.php';
require_once dirname(__DIR__) . '/admin/class-admin-page.php';

$fails = 0; $ran = 0;
function ok($cond, $what) { global $fails, $ran; $ran++;
    if ($cond) { echo "  PASS  $what\n"; } else { echo "  FAIL  $what\n"; $fails++; } }

$page = new Kapsule_Admin_Page();
$m = new ReflectionMethod('Kapsule_Admin_Page', 'fetch_job_state');
$m->setAccessible(true);

function drive($response) {
    global $page, $m;
    $GLOBALS['opts'] = array('kapsule_migration_token' => 'a-token-that-exists');
    $GLOBALS['next_response'] = $response;
    $out = $m->invoke($page);
    return array($out, $GLOBALS['opts']);
}

echo "TERMINAL: the migration is gone and retrying cannot help\n";
foreach (array(401, 403, 404, 410) as $code) {
    list($out, $o) = drive(array('code' => $code, 'body' => ''));
    ok($out === null, "$code returns null");
    ok(!empty($o['kapsule_migration_job_gone']), "$code sets gone");
    ok(strpos((string) $o['kapsule_migration_job_state_error'], (string) $code) === false,
       "$code keeps the number OUT of the customer's sentence");
    ok(isset($o['kapsule_migration_job_state_error_raw']), "$code still records the raw code for support");
}

echo "TRANSIENT: we could not reach them, waiting is correct\n";
foreach (array(429, 500, 502, 503, 504) as $code) {
    list($out, $o) = drive(array('code' => $code, 'body' => ''));
    ok(empty($o['kapsule_migration_job_gone']), "$code is NOT marked gone");
    ok(strpos((string) $o['kapsule_migration_job_state_error'], (string) $code) === false,
       "$code keeps the number OUT of the customer's sentence");
}

echo "TRANSPORT: a WP_Error is transient by definition\n";
list($out, $o) = drive(new WP_Error('cURL error 28: Operation timed out'));
ok($out === null, 'wp_error returns null');
ok(empty($o['kapsule_migration_job_gone']), 'wp_error is NOT marked gone');
ok(strpos((string) $o['kapsule_migration_job_state_error'], 'cURL') === false,
   'libcurl text stays out of the customer sentence (the 1.6.0 fix still holds)');

echo "RECOVERY: a good read clears the flag, or a recovered migration reads as gone for ever\n";
$GLOBALS['opts'] = array('kapsule_migration_token' => 't', 'kapsule_migration_job_gone' => 1,
                         'kapsule_migration_job_state_error' => 'old');
$GLOBALS['next_response'] = array('code' => 200, 'body' => json_encode(array('status' => 'RUNNING')));
$out = $m->invoke($page);
ok(is_array($out) && $out['status'] === 'RUNNING', 'a good read returns the job');
ok(!isset($GLOBALS['opts']['kapsule_migration_job_gone']), 'gone is cleared on recovery');

echo "THE BROWSER IS TOLD, or it retries for ever whatever PHP decided\n";
$GLOBALS['opts'] = array('kapsule_migration_token' => 't');
$GLOBALS['next_response'] = array('code' => 401, 'body' => '');
$page->ajax_job_status();
ok(!empty($GLOBALS['json']['data']['gone']), 'ajax carries gone=true so the poll can stop');
$GLOBALS['opts'] = array('kapsule_migration_token' => 't');
$GLOBALS['next_response'] = array('code' => 503, 'body' => '');
$page->ajax_job_status();
ok(empty($GLOBALS['json']['data']['gone']), 'CONTROL: a 503 does NOT stop the poll');

echo "\n$ran checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
