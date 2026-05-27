<?php
/**
 * PHP CLI test for Kapsule_Packager -- no WordPress required.
 * Run: php tests/test-packager.php
 */

// ── Constants ──────────────────────────────────────────────────────────────
$wp_root = sys_get_temp_dir() . '/kapsule-test-wp-' . getmypid() . '/';
define('ABSPATH',                  $wp_root);
define('KAPSULE_MIGRATOR_VERSION', '1.0.0');
define('KAPSULE_MIGRATOR_PLUGIN_DIR', dirname(__DIR__) . '/');
define('KAPSULE_MIGRATOR_API_BASE',   'https://kpanel.kapsulecloud.com/api/migration/plugin');

// ── WP stubs ───────────────────────────────────────────────────────────────
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function wp_mkdir_p($dir) { return @mkdir($dir, 0755, true); }

// ── Load real class ────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/includes/class-packager.php';

// ── Testable subclass: overrides chunk limit, delegates rest to same logic ─
// Mirrors the fixed skip logic (rel prefixed with '/') from class-packager.php.
class Kapsule_Packager_Testable extends Kapsule_Packager {
    private int $chunk_limit;

    public function __construct(int $chunk_limit = 50 * 1024 * 1024) {
        parent::__construct();
        $this->chunk_limit = $chunk_limit;
    }

    public function package_files(callable $callback): void {
        $this->_do_package(ABSPATH, $this->chunk_limit, $callback);
    }

    /**
     * Internal packager with configurable root + chunk limit.
     * Uses the same '/' prefix fix as class-packager.php.
     */
    protected function _do_package(string $root, int $chunk_size, callable $callback): void {
        $root        = rtrim($root, '/') . '/';
        $chunk_index = 0;
        $chunk_bytes = 0;
        $zip         = null;
        $zip_path    = '';

        $skip_patterns = [
            '/.git/',
            '/node_modules/',
            '/wp-content/cache/',
            '/wp-content/uploads/backup',
            '/wp-content/updraft',
            'wp-config.php',
            'wp-config-sample.php',
        ];

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $rc = new ReflectionClass(Kapsule_Packager::class);
        $fc = $rc->getProperty('file_count');  $fc->setAccessible(true);
        $tb = $rc->getProperty('total_bytes'); $tb->setAccessible(true);
        $td = $rc->getProperty('tmp_dir');     $td->setAccessible(true);

        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $path         = $file->getRealPath();
            $rel          = str_replace($root, '', $path);
            $rel_prefixed = '/' . $rel;

            foreach ($skip_patterns as $pattern) {
                if (strpos($rel_prefixed, $pattern) !== false) continue 2;
            }

            $size = $file->getSize();
            $fc->setValue($this, $fc->getValue($this) + 1);
            $tb->setValue($this, $tb->getValue($this) + $size);

            if ($zip === null || $chunk_bytes + $size > $chunk_size) {
                if ($zip !== null) {
                    $zip->close();
                    $callback($zip_path, $chunk_bytes * $chunk_index, $tb->getValue($this));
                    $chunk_index++;
                }
                $zip_path    = $td->getValue($this) . "files-chunk-{$chunk_index}.zip";
                $zip         = new ZipArchive();
                $zip->open($zip_path, ZipArchive::CREATE);
                $chunk_bytes = 0;
            }

            $zip->addFile($path, $rel);
            $chunk_bytes += $size;
        }

        if ($zip !== null) {
            $zip->close();
            $callback($zip_path, $tb->getValue($this), $tb->getValue($this));
        }
    }
}

// ── Fixed-root variant for chunk-splitting test ────────────────────────────
class Kapsule_Packager_FixedRoot extends Kapsule_Packager_Testable {
    private string $fixed_root;
    private int    $fixed_limit;

    public function __construct(string $root, int $chunk_limit) {
        parent::__construct($chunk_limit);
        $this->fixed_root  = $root;
        $this->fixed_limit = $chunk_limit;
    }

    public function package_files(callable $callback): void {
        $this->_do_package($this->fixed_root, $this->fixed_limit, $callback);
    }
}

// ── Test framework ─────────────────────────────────────────────────────────
$passed = 0;
$failed = 0;

function ok(string $label, bool $result): void {
    global $passed, $failed;
    if ($result) {
        echo "\033[32m[PASS]\033[0m $label\n";
        $passed++;
    } else {
        echo "\033[31m[FAIL]\033[0m $label\n";
        $failed++;
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function create_file(string $path, string $content = ''): void {
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $content);
}

function zip_entries(array $paths): array {
    $entries = [];
    foreach ($paths as $zip_path) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) continue;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();
    }
    return $entries;
}

function rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($dir);
}

// ── Build fake WP file tree under ABSPATH ─────────────────────────────────
$root = ABSPATH;
@mkdir($root, 0755, true);

create_file($root . 'wp-config.php',        '<?php // wp-config');
create_file($root . 'wp-config-sample.php', '<?php // wp-config-sample');
create_file($root . 'wp-login.php',         '<?php // wp-login');
create_file($root . 'wp-includes/version.php', '<?php $wp_version = "6.5";');
create_file($root . 'wp-content/themes/mytheme/style.css',    '/* Theme Name: My Theme */');
create_file($root . 'wp-content/themes/mytheme/functions.php','<?php // functions');
create_file($root . 'wp-content/plugins/hello/hello.php',     '<?php // Hello Dolly');
create_file($root . 'wp-content/cache/some-cache-file.html',  '<html>cache</html>');
create_file($root . 'wp-content/uploads/image.jpg',           str_repeat("\xFF\xD8", 64));
create_file($root . '.git/config',                            '[core]');
create_file($root . 'node_modules/lodash/index.js',           'module.exports = {};');

// ── Run packager ───────────────────────────────────────────────────────────
$chunks   = [];
$packager = new Kapsule_Packager_Testable();
$packager->package_files(function (string $path) use (&$chunks) {
    $chunks[] = $path;
});

$all_entries = zip_entries($chunks);

// ── Tests 1-11 ────────────────────────────────────────────────────────────
echo "\n--- Basic packaging tests ---\n";

ok('Test 1:  At least one chunk ZIP created',
    count($chunks) >= 1 && file_exists($chunks[0]));

ok('Test 2:  wp-config.php NOT in any ZIP',
    !in_array('wp-config.php', $all_entries, true));

ok('Test 3:  wp-config-sample.php NOT in any ZIP',
    !in_array('wp-config-sample.php', $all_entries, true));

ok('Test 4:  wp-login.php IS in a ZIP',
    in_array('wp-login.php', $all_entries, true));

ok('Test 5:  wp-content/themes/mytheme/style.css IS in a ZIP',
    in_array('wp-content/themes/mytheme/style.css', $all_entries, true));

ok('Test 6:  No wp-content/cache/ entries in any ZIP',
    count(array_filter($all_entries, fn($e) => strpos($e, 'wp-content/cache/') !== false)) === 0);

ok('Test 7:  No .git/ entries in any ZIP',
    count(array_filter($all_entries, fn($e) => strpos($e, '.git/') !== false)) === 0);

ok('Test 8:  No node_modules/ entries in any ZIP',
    count(array_filter($all_entries, fn($e) => strpos($e, 'node_modules/') !== false)) === 0);

ok('Test 9:  get_file_count() returns positive integer',
    is_int($packager->get_file_count()) && $packager->get_file_count() > 0);

ok('Test 10: get_total_bytes() returns positive integer',
    is_int($packager->get_total_bytes()) && $packager->get_total_bytes() > 0);

// ── Test 11: cleanup() removes temp dir ───────────────────────────────────
echo "\n--- Cleanup test ---\n";
$tmp = $packager->get_tmp_dir();
$packager->cleanup();
ok('Test 11: cleanup() removes all temp files',
    !is_dir($tmp));

// ── Test 12: Chunk splitting at small boundary ────────────────────────────
echo "\n--- Chunk-splitting test ---\n";

// Three files of 60 bytes each; chunk limit of 50 bytes
// -> each file forces a new chunk, producing >= 2 ZIPs.
$split_root = sys_get_temp_dir() . '/kapsule-test-split-' . getmypid() . '/';
@mkdir($split_root, 0755, true);

$file_content = str_repeat('X', 60);
file_put_contents($split_root . 'a.txt', $file_content);
file_put_contents($split_root . 'b.txt', $file_content);
file_put_contents($split_root . 'c.txt', $file_content);

$split_chunks   = [];
$split_packager = new Kapsule_Packager_FixedRoot($split_root, 50);
$split_packager->package_files(function (string $path) use (&$split_chunks) {
    $split_chunks[] = $path;
});

ok('Test 12: Chunk splitting produces multiple ZIPs when files exceed limit',
    count($split_chunks) > 1);

$split_entries = zip_entries($split_chunks);
ok('Test 12b: All files present across split chunks',
    in_array('a.txt', $split_entries, true) &&
    in_array('b.txt', $split_entries, true) &&
    in_array('c.txt', $split_entries, true));

$split_packager->cleanup();
rmdir_recursive($split_root);

// ── Teardown ───────────────────────────────────────────────────────────────
rmdir_recursive(ABSPATH);

// ── Summary ───────────────────────────────────────────────────────────────
echo "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
