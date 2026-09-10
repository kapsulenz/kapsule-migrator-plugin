<?php
/**
 * BOTH-ARMS PROOF FOR THE DATABASE EXPORTER, DRIVEN AGAINST A REAL MySQL/MariaDB SERVER.
 *
 * Run:  tests/run-export-database-test.sh          (provisions the throwaway user and databases)
 *       php tests/test-export-database.php         (if KM_DB_* is already in the environment)
 *
 * ── WHY TWO ARMS, AND WHY THE SECOND ONE IS NOT PADDING ───────────────────────────────────────
 *
 * ARM A is a database WITH views. It is the arm that goes red on the unfixed exporter.
 * ARM B is a database WITHOUT one, and it exists because "skip the object" is a fix shape that
 * passes arm A perfectly while destroying every customer who has no view at all. Arm B counts the
 * rows that arrived. A change that exported nothing would sail through arm A and fail here.
 *
 * ── WHAT THE POPULATION IS, AND WHERE IT COMES FROM ───────────────────────────────────────────
 *
 * Nothing in the assertions below names a table. Every object the assertions walk is read out of
 * `information_schema.TABLES` for the SOURCE database at assert time, so adding a table to the
 * fixture automatically adds it to every check, and a check cannot silently stop covering an object
 * that someone renamed. If that query comes back empty the run reports BLIND and fails: an empty
 * population is not a pass, it is a broken instrument.
 *
 * ── THE IMPORT IS THE WORKER'S IMPORT ─────────────────────────────────────────────────────────
 *
 * The dump is replayed with `gunzip -c ... | mysql ...` and BOTH pipeline exits are read, which is
 * the shape `src/lib/migration/pipe-status.ts` enforces on the portal side. Reading only the near
 * end of that pipe is how this defect stayed invisible for as long as it did.
 */

// ── Connection details ────────────────────────────────────────────────────────────────────────
$HOST   = getenv( 'KM_DB_HOST' ) ?: '127.0.0.1';
$PORT   = (int) ( getenv( 'KM_DB_PORT' ) ?: 3306 );
$USER   = getenv( 'KM_DB_USER' ) ?: '';
$PASS   = getenv( 'KM_DB_PASS' ) ?: '';
$PREFIX = getenv( 'KM_DB_PREFIX' ) ?: 'km_r505_';
$CNF    = getenv( 'KM_DB_CNF' ) ?: '';

if ( '' === $USER ) {
    fwrite( STDERR, "BLIND: no KM_DB_USER in the environment. Run tests/run-export-database-test.sh instead.\n" );
    exit( 2 );
}

define( 'KAPSULE_MIGRATOR_VERSION', '0.0.0-test' );

require_once __DIR__ . '/wpdb-stub.php';
require_once dirname( __DIR__ ) . '/includes/class-dump-preamble.php';
require_once dirname( __DIR__ ) . '/includes/class-packager.php';

// ── Tiny harness ──────────────────────────────────────────────────────────────────────────────
$PASSED = 0;
$FAILED = 0;
$BLIND  = 0;

function ok( string $label, bool $result, string $measured = '' ): void {
    global $PASSED, $FAILED;
    $tail = '' === $measured ? '' : "  ({$measured})";
    if ( $result ) { echo "\033[32m[PASS]\033[0m {$label}{$tail}\n"; $PASSED++; }
    else           { echo "\033[31m[FAIL]\033[0m {$label}{$tail}\n"; $FAILED++; }
}

function blind( string $label, string $why ): void {
    global $BLIND;
    echo "\033[33m[BLIND]\033[0m {$label}  ({$why})\n";
    $BLIND++;
}

function admin_conn( string $host, int $port, string $user, string $pass ): mysqli {
    $c = new mysqli( $host, $user, $pass, '', $port );
    if ( $c->connect_errno ) {
        fwrite( STDERR, "BLIND: cannot connect as {$user}: {$c->connect_error}\n" );
        exit( 2 );
    }
    $c->set_charset( 'utf8mb4' );
    return $c;
}

function must( mysqli $c, string $sql ): void {
    if ( ! $c->query( $sql ) ) {
        fwrite( STDERR, "SETUP FAILED: {$c->error}\n  while running: " . substr( $sql, 0, 160 ) . "\n" );
        exit( 2 );
    }
}

/** Replay a dump exactly the way the worker does, and report BOTH ends of the pipe. */
function import_dump( string $gz, string $cnf, string $db ): array {
    $cmd = 'set -o pipefail; gunzip -c ' . escapeshellarg( $gz )
         . ' | mysql --defaults-file=' . escapeshellarg( $cnf )
         . ' --default-character-set=utf8mb4 --max-allowed-packet=1G ' . escapeshellarg( $db )
         . ' 2>&1; echo "PIPES=${PIPESTATUS[*]}"';
    $out = shell_exec( '/bin/bash -c ' . escapeshellarg( $cmd ) . ' 2>&1' );
    $out = (string) $out;
    preg_match( '/PIPES=([0-9 ]+)/', $out, $m );
    $codes = isset( $m[1] ) ? array_map( 'intval', preg_split( '/\s+/', trim( $m[1] ) ) ) : array();
    $msg   = trim( preg_replace( '/PIPES=[0-9 ]+/', '', $out ) );
    // NAME WHAT MySQL SAID, not the first 200 characters of the echoed statement. mysql prints the
    // refused statement before its complaint, so a head-of-output excerpt reliably shows everything
    // except the error, which is the one thing worth reading.
    $err = '';
    if ( preg_match( '/^ERROR\s+\d+.*$/mi', $msg, $em ) ) $err = trim( $em[0] );
    return array( 'codes' => $codes, 'output' => $msg, 'error' => $err );
}

// ── The fixture. Messy the way a real WordPress site is messy. ────────────────────────────────
function build_fixture( mysqli $c, string $db, bool $with_views ): void {
    must( $c, "DROP DATABASE IF EXISTS `{$db}`" );
    must( $c, "CREATE DATABASE `{$db}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
    must( $c, "USE `{$db}`" );

    must( $c, "CREATE TABLE `wp_options` (
        `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `option_name` varchar(191) NOT NULL DEFAULT '',
        `option_value` longtext NOT NULL,
        PRIMARY KEY (`option_id`), UNIQUE KEY `option_name` (`option_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

    must( $c, "CREATE TABLE `wp_posts` (
        `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
        `post_title` text NOT NULL,
        `post_content` longtext NOT NULL,
        `post_status` varchar(20) NOT NULL DEFAULT 'publish',
        `post_date` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
        PRIMARY KEY (`ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

    must( $c, "CREATE TABLE `wp_users` (
        `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_login` varchar(60) NOT NULL DEFAULT '',
        `user_email` varchar(100) NOT NULL DEFAULT '',
        PRIMARY KEY (`ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

    // Sorts AFTER the view name below, so it is one of the tables the alphabetical walk never
    // reached on the failing run. That is not decoration: it is the half of the ledger row that
    // made a broken migration report itself COMPLETED.
    must( $c, "CREATE TABLE `wp_term_relationships` (
        `object_id` bigint unsigned NOT NULL DEFAULT 0,
        `term_taxonomy_id` bigint unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`object_id`,`term_taxonomy_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

    // A percent sign and a four-byte character, because the same writer handles both and a fixture
    // that drops them would stop covering the repairs already landed in this function.
    must( $c, "INSERT INTO `wp_options` (`option_name`,`option_value`) VALUES
        ('siteurl','https://example.test'),
        ('blogname','50% off, always \\U0001F680'),
        ('permalink_structure','/%year%/%monthnum%/%postname%/')" );
    must( $c, "INSERT INTO `wp_posts` (`post_title`,`post_content`,`post_status`,`post_date`) VALUES
        ('Hello world','Welcome to 100% WordPress','publish','2024-01-01 10:00:00'),
        ('Draft post','Not ready','draft','2024-02-01 10:00:00'),
        ('Second post','More content here','publish','2024-03-01 10:00:00')" );
    must( $c, "INSERT INTO `wp_users` (`user_login`,`user_email`) VALUES
        ('admin','admin@example.test'), ('editor','editor@example.test')" );
    must( $c, "INSERT INTO `wp_term_relationships` (`object_id`,`term_taxonomy_id`) VALUES
        (1,1),(1,2),(3,1)" );

    if ( ! $with_views ) return;

    // THE VIEW FROM THE LEDGER ROW, unchanged: an expression column makes it NOT insertable, which
    // is the ERROR 1471 the customer's migration died on. Its name sorts between `wp_posts` and
    // `wp_term_relationships`, so a run that stops here has already imported the options table.
    must( $c, "CREATE VIEW `wp_posts_report` AS
        SELECT `ID`, `post_title`, `post_date`, LENGTH(`post_content`) AS `content_length`
        FROM `wp_posts` WHERE `post_status` = 'publish'" );

    // A VIEW BUILT ON THE FIRST VIEW, deliberately named so it sorts FIRST. "Emit views after the
    // tables" is not sufficient on its own: this one also has to be created after the view it reads,
    // and alphabetical order puts it before. Real sites carry these; a reporting plugin that layers
    // a summary over a detail view produces exactly this shape.
    must( $c, "CREATE VIEW `wp_aaa_report_summary` AS
        SELECT COUNT(*) AS `published_posts`, MAX(`content_length`) AS `longest`
        FROM `wp_posts_report`" );
}

/** The population every assertion walks, read from the source at assert time. */
function source_objects( mysqli $c, string $db ): array {
    $out = array();
    $st  = $c->prepare( 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME' );
    $st->bind_param( 's', $db );
    $st->execute();
    $res = $st->get_result();
    while ( $row = $res->fetch_assoc() ) $out[ $row['TABLE_NAME'] ] = $row['TABLE_TYPE'];
    $st->close();
    return $out;
}

function scalar( mysqli $c, string $sql ) {
    $r = @$c->query( $sql );
    if ( ! $r ) return null;
    $row = $r->fetch_row();
    $r->free();
    return $row ? $row[0] : null;
}

// ══ ONE ARM ═══════════════════════════════════════════════════════════════════════════════════
function run_arm( string $label, bool $with_views ): void {
    global $HOST, $PORT, $USER, $PASS, $PREFIX, $CNF, $BLIND;

    $src = $PREFIX . ( $with_views ? 'src_view' : 'src_plain' );
    $dst = $PREFIX . ( $with_views ? 'dst_view' : 'dst_plain' );

    echo "\n══ {$label} ══════════════════════════════════════════════════════\n";

    $admin = admin_conn( $HOST, $PORT, $USER, $PASS );
    build_fixture( $admin, $src, $with_views );
    must( $admin, "DROP DATABASE IF EXISTS `{$dst}`" );
    must( $admin, "CREATE DATABASE `{$dst}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

    // ── Population, derived. An empty one is BLIND, never a pass. ─────────────────────────────
    $objects = source_objects( $admin, $src );
    $bases   = array_keys( array_filter( $objects, fn( $t ) => 'VIEW' !== $t ) );
    $views   = array_keys( array_filter( $objects, fn( $t ) => 'VIEW' === $t ) );

    if ( empty( $objects ) ) {
        blind( "{$label}: source population", "information_schema returned no objects for {$src}; assertions cannot see" );
        return;
    }
    echo "population: " . count( $objects ) . " objects in {$src} = "
       . count( $bases ) . " base table(s) [" . implode( ', ', $bases ) . "], "
       . count( $views ) . " view(s) [" . ( $views ? implode( ', ', $views ) : 'none' ) . "]\n";

    if ( $with_views && empty( $views ) ) {
        blind( "{$label}: view fixture", 'the arm that must contain a view contains none; the fixture did not build' );
        return;
    }

    // ── Export, through the real Kapsule_Packager ─────────────────────────────────────────────
    global $wpdb;
    $wpdb = new Kapsule_Test_WPDB( $HOST, $USER, $PASS, $src, $PORT );

    $tmp      = rtrim( sys_get_temp_dir(), '/' ) . '/km-r505-' . $label . '-' . getmypid() . '/';
    @mkdir( $tmp, 0755, true );
    $packager = new Kapsule_Packager( $tmp );

    $gz    = '';
    $error = '';
    try {
        $gz = $packager->export_database();
    } catch ( Throwable $e ) {
        $error = $e->getMessage();
    }

    ok( "{$label}: export_database() produced a dump",
        '' === $error && '' !== $gz && file_exists( $gz ) && filesize( $gz ) > 0,
        '' !== $error ? 'threw: ' . substr( $error, 0, 160 ) : 'gz=' . ( $gz ? filesize( $gz ) : 0 ) . ' bytes' );

    if ( '' !== $error || ! $gz || ! file_exists( $gz ) ) return;

    $sql = gzdecode( file_get_contents( $gz ) );
    if ( false === $sql ) {
        blind( "{$label}: dump text", 'the gz could not be decoded, so no text assertion can see anything' );
        return;
    }
    echo "dump: " . strlen( $sql ) . " bytes of SQL, " . substr_count( $sql, "\n" ) . " lines\n";

    // ── Text assertions, populations derived from $views / $bases ─────────────────────────────
    $definers = preg_match_all( '/DEFINER\s*=/i', $sql );
    ok( "{$label}: no DEFINER clause survives into the dump",
        0 === $definers, "DEFINER= occurrences: {$definers}" );

    $view_inserts = array();
    foreach ( $views as $v ) {
        $n = substr_count( $sql, "INSERT INTO `{$v}`" );
        if ( $n > 0 ) $view_inserts[ $v ] = $n;
    }
    ok( "{$label}: no INSERT is aimed at a view",
        empty( $view_inserts ),
        $views ? ( 'views checked: ' . count( $views ) . ', offending INSERTs: ' . ( $view_inserts ? json_encode( $view_inserts ) : '0' ) )
               : 'views checked: 0 (population empty in this arm)' );

    $last_table_create = -1;
    $missing_creates   = array();
    foreach ( $bases as $b ) {
        $pos = strpos( $sql, "CREATE TABLE `{$b}`" );
        if ( false === $pos ) { $missing_creates[] = $b; continue; }
        $last_table_create = max( $last_table_create, $pos );
    }
    ok( "{$label}: every base table has a CREATE TABLE in the dump",
        empty( $missing_creates ),
        'base tables: ' . count( $bases ) . ', missing: ' . ( $missing_creates ? implode( ',', $missing_creates ) : '0' ) );

    $out_of_order = array();
    $view_creates = array();
    foreach ( $views as $v ) {
        if ( ! preg_match( '/^CREATE[^\n]*\bVIEW\s+`' . preg_quote( $v, '/' ) . '`/m', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
            $view_creates[ $v ] = -1;
            $out_of_order[]     = "{$v}:absent";
            continue;
        }
        $view_creates[ $v ] = $m[0][1];
        if ( $m[0][1] < $last_table_create ) $out_of_order[] = "{$v}:before-tables";
    }
    ok( "{$label}: every view is created as a VIEW, after the last base table",
        empty( $out_of_order ),
        $views ? ( 'last CREATE TABLE at byte ' . $last_table_create . '; view CREATEs at ' . json_encode( $view_creates ) )
               : 'views checked: 0 (population empty in this arm)' );

    // ── Import, twice, reading BOTH ends of the pipe ──────────────────────────────────────────
    $r1 = import_dump( $gz, $CNF, $dst );
    ok( "{$label}: import #1 succeeded at every stage of the pipe",
        ! empty( $r1['codes'] ) && array_sum( $r1['codes'] ) === 0,
        'PIPESTATUS=[' . implode( ',', $r1['codes'] ) . ']'
        . ( '' !== $r1['error'] ? ' mysql said: ' . $r1['error'] : '' ) );

    // A second replay into the SAME destination. `DROP TABLE IF EXISTS` against a view does not
    // remove it, so the unfixed dump fails here with ERROR 1050 even where it survived the first.
    $r2 = import_dump( $gz, $CNF, $dst );
    ok( "{$label}: import #2 into the same database succeeded (the dump is replayable)",
        ! empty( $r2['codes'] ) && array_sum( $r2['codes'] ) === 0,
        'PIPESTATUS=[' . implode( ',', $r2['codes'] ) . ']'
        . ( '' !== $r2['error'] ? ' mysql said: ' . $r2['error'] : '' ) );

    // ── Destination assertions, same derived population ───────────────────────────────────────
    $dst_objects = source_objects( $admin, $dst );
    if ( empty( $dst_objects ) && ! empty( $objects ) ) {
        blind( "{$label}: destination population", "nothing at all arrived in {$dst}" );
        return;
    }

    $type_mismatch = array();
    foreach ( $objects as $name => $type ) {
        $got = $dst_objects[ $name ] ?? 'ABSENT';
        if ( $got !== $type ) $type_mismatch[ $name ] = "{$type}->{$got}";
    }
    ok( "{$label}: every source object exists at the destination as the SAME kind of object",
        empty( $type_mismatch ),
        'objects: ' . count( $objects ) . ', mismatched: ' . ( $type_mismatch ? json_encode( $type_mismatch ) : '0' ) );

    $row_mismatch = array();
    $rows_total   = 0;
    foreach ( $bases as $b ) {
        $a = scalar( $admin, "SELECT COUNT(*) FROM `{$src}`.`{$b}`" );
        $z = scalar( $admin, "SELECT COUNT(*) FROM `{$dst}`.`{$b}`" );
        if ( null === $a || null === $z ) { $row_mismatch[ $b ] = 'unreadable'; continue; }
        $rows_total += (int) $a;
        if ( (int) $a !== (int) $z ) $row_mismatch[ $b ] = "{$a}!={$z}";
    }
    ok( "{$label}: every base table arrived with every row",
        empty( $row_mismatch ),
        "rows in source: {$rows_total}, tables compared: " . count( $bases )
        . ', mismatched: ' . ( $row_mismatch ? json_encode( $row_mismatch ) : '0' ) );

    // THE ANTI-SKIP GUARD. A "fix" that exported nothing would satisfy every check above except
    // this one, and this one is the reason the second arm exists at all.
    ok( "{$label}: the migration actually carried data (not an empty dump passing on vacuous checks)",
        count( $bases ) > 0 && $rows_total > 0,
        "base tables: " . count( $bases ) . ", rows: {$rows_total}" );

    $view_broken = array();
    foreach ( $views as $v ) {
        $a = scalar( $admin, "SELECT COUNT(*) FROM `{$src}`.`{$v}`" );
        $z = scalar( $admin, "SELECT COUNT(*) FROM `{$dst}`.`{$v}`" );
        if ( null === $z )          { $view_broken[ $v ] = 'not queryable at destination'; continue; }
        if ( (int) $a !== (int) $z ) $view_broken[ $v ] = "{$a}!={$z}";
    }
    ok( "{$label}: every view is queryable at the destination and returns what it returned at source",
        empty( $view_broken ),
        $views ? ( 'views checked: ' . count( $views ) . ', broken: ' . ( $view_broken ? json_encode( $view_broken ) : '0' ) )
               : 'views checked: 0 (population empty in this arm)' );

    // No stand-in scaffolding may be left behind pretending to be a table.
    $leftover = array();
    foreach ( $views as $v ) {
        if ( ( $dst_objects[ $v ] ?? '' ) !== 'VIEW' ) $leftover[] = $v;
    }
    ok( "{$label}: no view was left at the destination as a table",
        empty( $leftover ),
        $views ? ( 'views: ' . count( $views ) . ', left as tables: ' . ( $leftover ? implode( ',', $leftover ) : '0' ) )
               : 'views checked: 0 (population empty in this arm)' );

    $packager->cleanup();
    @rmdir( $tmp );
    $admin->close();
}

// ══ THE GUARDS IN THE SHIPPED CODE, PROVEN ABLE TO GO RED ═════════════════════════════════════
//
// The exporter now refuses to hand over a dump in which a view was treated as a table. A refusal
// that cannot fire is decoration, so it is driven here against a PLANTED instance of exactly the
// shape the unfixed exporter produced, in the same form the real check reads (a file on disk), and
// separately against a clean dump so it is shown not to cry wolf.
function run_guard_controls(): void {
    $rc = new ReflectionClass( Kapsule_Packager::class );

    $find = $rc->getMethod( 'find_view_dumped_as_table' );
    $find->setAccessible( true );

    $tmp = rtrim( sys_get_temp_dir(), '/' ) . '/km-r505-guard-' . getmypid() . '/';
    @mkdir( $tmp, 0755, true );

    echo "\n══ GUARD CONTROLS ════════════════════════════════════════════════\n";

    // The planted defect is a verbatim transcript of what the old writer emitted for the ledger's
    // own fixture, measured on 2026-09-10 before the fix: DROP TABLE, the CREATE VIEW that
    // SHOW CREATE TABLE handed back, and one INSERT per row of the view.
    $bad = $tmp . 'planted-r505.sql';
    file_put_contents( $bad,
        "SET FOREIGN_KEY_CHECKS=0;\n\n"
        . "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL);\n\n"
        . "DROP TABLE IF EXISTS `wp_posts_report`;\n"
        . "CREATE ALGORITHM=UNDEFINED DEFINER=`olduser`@`oldhost` SQL SECURITY DEFINER VIEW `wp_posts_report` AS select 1 AS `x`;\n"
        . "INSERT INTO `wp_posts_report` (`ID`, `post_title`) VALUES ('1', 'Hello');\n"
    );
    $verdict = $find->invoke( null, $bad, array( 'wp_posts_report' ) );
    ok( 'GUARD: the refusal fires on a planted view-dumped-as-table',
        '' !== $verdict, '' !== $verdict ? 'said: ' . $verdict : 'said nothing, so it cannot protect anyone' );

    // Same file, same check, and the view omitted from the population: proof the guard is reading the
    // view list it is given rather than pattern-matching anything that looks suspicious.
    ok( 'GUARD: the refusal stays silent about an object that is not a view',
        '' === $find->invoke( null, $bad, array() ), 'empty view population -> no verdict' );

    $good = $tmp . 'clean.sql';
    file_put_contents( $good,
        "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL);\n"
        . "INSERT INTO `wp_posts` (`ID`) VALUES ('1');\n\n"
        . "DROP TABLE IF EXISTS `wp_posts_report`;\nDROP VIEW IF EXISTS `wp_posts_report`;\n"
        . "CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `wp_posts_report` AS select 1 AS `x`;\n"
    );
    $verdict = $find->invoke( null, $good, array( 'wp_posts_report' ) );
    ok( 'GUARD: the refusal does not fire on a correctly written dump',
        '' === $verdict, '' === $verdict ? 'clean' : 'false positive: ' . $verdict );

    // ── The DDL sanitiser ─────────────────────────────────────────────────────────────────────
    $san = $rc->getMethod( 'sanitise_view_ddl' );
    $san->setAccessible( true );

    // MySQL's shape rather than MariaDB's: MariaDB normalises the database qualifier away before we
    // see it, so the only way to prove the qualifier strip works at all is to hand it one.
    $mysql_shape = "CREATE ALGORITHM=UNDEFINED DEFINER=`oldcustomer`@`1.2.3.4` SQL SECURITY DEFINER VIEW `v` AS select `oldsite_wp`.`wp_posts`.`ID` AS `ID` from `oldsite_wp`.`wp_posts`";
    $out = $san->invoke( null, $mysql_shape, 'oldsite_wp' );
    ok( 'GUARD: DEFINER is removed from a view DDL',
        false === stripos( $out, 'DEFINER=' ), 'result: ' . $out );
    ok( 'GUARD: SQL SECURITY is forced to INVOKER',
        false !== stripos( $out, 'SQL SECURITY INVOKER' ) && false === stripos( $out, 'SQL SECURITY DEFINER' ),
        'result carries: ' . ( false !== stripos( $out, 'INVOKER' ) ? 'INVOKER' : 'DEFINER' ) );
    ok( 'GUARD: the source database qualifier is stripped from the view body',
        false === strpos( $out, '`oldsite_wp`.`' ) && false !== strpos( $out, 'from `wp_posts`' ),
        'body now reads: ' . substr( $out, (int) stripos( $out, ' AS select' ) ) );

    // A body containing the literal words the header regex hunts for. Over-broad rewriting of a
    // customer's own SELECT is the way a "safe" sanitiser corrupts a site silently.
    $tricky = "CREATE ALGORITHM=UNDEFINED DEFINER=`u`@`h` SQL SECURITY DEFINER VIEW `v` AS select 'DEFINER=`x`@`y` SQL SECURITY DEFINER' AS `note` from `wp_posts`";
    $out2   = $san->invoke( null, $tricky, '' );
    ok( 'GUARD: the sanitiser never rewrites the customer\'s own SELECT',
        false !== strpos( $out2, "'DEFINER=`x`@`y` SQL SECURITY DEFINER' AS `note`" )
        && 1 === preg_match_all( '/DEFINER=/i', $out2 ),
        'DEFINER= occurrences left (all inside the string literal): ' . preg_match_all( '/DEFINER=/i', $out2 ) );

    @unlink( $bad );
    @unlink( $good );
    @rmdir( $tmp );
}

// ══ Both arms ═════════════════════════════════════════════════════════════════════════════════
echo "Kapsule migrator: database exporter, both-arms proof (R-505)\n";
echo "server: " . $HOST . ':' . $PORT . " as " . $USER . "\n";

run_arm( 'ARM-A-with-view', true );
run_arm( 'ARM-B-no-view',   false );
run_guard_controls();

echo "\n";
echo "Results: {$PASSED} passed, {$FAILED} failed, {$BLIND} blind\n";
exit( ( $FAILED > 0 || $BLIND > 0 ) ? 1 : 0 );
