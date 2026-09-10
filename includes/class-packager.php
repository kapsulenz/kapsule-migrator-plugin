<?php

class Kapsule_Packager {

    private string $tmp_dir;
    private int    $file_count  = 0;
    private int    $total_bytes = 0;

    public function __construct( string $existing_tmp_dir = '' ) {
        if ( $existing_tmp_dir && is_dir( $existing_tmp_dir ) ) {
            $this->tmp_dir = $existing_tmp_dir;
        } else {
            $this->tmp_dir = get_temp_dir() . 'kapsule-migrator-' . uniqid() . '/';
            wp_mkdir_p( $this->tmp_dir );
        }
    }

    /**
     * Detect which archive backend is available.
     * Priority: zip (ZipArchive) → shell-tar (system tar) → phar (PharData).
     */
    private static function archive_backend(): string {
        if ( class_exists( 'ZipArchive' ) )   return 'zip';
        if ( self::shell_tar_available() )     return 'shell-tar';
        if ( class_exists( 'PharData' ) )      return 'phar';
        throw new Exception( __( 'This server cannot create archives, so we cannot package your site. Ask your host to enable the PHP zip extension, then contact KapsuleHost support if it still fails.', 'kapsule-migrator' ) );
    }

    private static function shell_tar_available(): bool {
        if ( ! function_exists( 'exec' ) ) return false;
        $ret = -1;
        @exec( 'which tar 2>/dev/null', $out, $ret );
        return $ret === 0 && ! empty( $out[0] );
    }

    /**
     * Scan all WP files (honoring skip patterns) and return a flat array of
     * {path, rel, size} entries. Also updates $this->file_count / total_bytes.
     */
    public function scan_files(): array {
        @set_time_limit( 0 );
        $root          = ABSPATH;
        $skip_patterns = array(
            '/.git/',
            '/node_modules/',
            '/wp-content/cache/',
            '/wp-content/uploads/backup',
            '/wp-content/updraft',
            'wp-config.php',
            'wp-config-sample.php',
        );

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = array();
        foreach ( $iter as $file ) {
            if ( ! $file->isFile() ) continue;
            $path         = $file->getRealPath();
            $rel          = str_replace( $root, '', $path );
            $rel_prefixed = '/' . $rel;

            foreach ( $skip_patterns as $pattern ) {
                if ( strpos( $rel_prefixed, $pattern ) !== false ) continue 2;
            }

            $size            = $file->getSize();
            $files[]         = array( 'path' => $path, 'rel' => $rel, 'size' => $size );
            $this->file_count++;
            $this->total_bytes += $size;
        }

        return $files;
    }

    /**
     * Split a flat file list into groups of at most $chunk_size bytes.
     * Returns array of arrays (each inner array is one chunk's file entries).
     */
    public static function build_chunks( array $files, int $chunk_size = 50 * 1024 * 1024 ): array {
        $chunks        = array();
        $current       = array();
        $current_bytes = 0;

        foreach ( $files as $file ) {
            if ( ! empty( $current ) && $current_bytes + $file['size'] > $chunk_size ) {
                $chunks[]      = $current;
                $current       = array();
                $current_bytes = 0;
            }
            $current[]      = $file;
            $current_bytes += $file['size'];
        }

        if ( ! empty( $current ) ) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Package a specific set of file entries into a single archive chunk.
     * Returns the absolute path to the created archive.
     */
    public function package_chunk( array $file_entries, int $chunk_index ): string {
        @set_time_limit( 0 );
        $backend    = self::archive_backend();
        $ext        = $backend === 'zip' ? 'zip' : 'tar';
        $chunk_path = $this->tmp_dir . "files-chunk-{$chunk_index}.{$ext}";

        if ( $backend === 'zip' ) {
            $archive = new ZipArchive();
            $archive->open( $chunk_path, ZipArchive::CREATE );
            foreach ( $file_entries as $entry ) {
                $archive->addFile( $entry['path'], $entry['rel'] );
            }
            $archive->close();

        } elseif ( $backend === 'shell-tar' ) {
            // Write relative paths to a temp manifest, then invoke system tar.
            // This avoids all PharData limitations with certain file types/paths.
            $root      = rtrim( ABSPATH, DIRECTORY_SEPARATOR );
            $list_file = $this->tmp_dir . "chunk-{$chunk_index}-files.txt";
            $rels      = array_map( function( $e ) { return $e['rel']; }, $file_entries );
            file_put_contents( $list_file, implode( "\n", $rels ) );

            $cmd = 'tar -cf ' . escapeshellarg( $chunk_path )
                 . ' -C '    . escapeshellarg( $root )
                 . ' --files-from=' . escapeshellarg( $list_file )
                 . ' 2>&1';
            exec( $cmd, $out, $ret );
            @unlink( $list_file );

            if ( $ret !== 0 || ! file_exists( $chunk_path ) ) {
                throw new Exception( 'tar command failed: ' . implode( ' ', array_slice( $out, -3 ) ) );
            }

        } else {
            // PharData fallback
            $archive = new PharData( $chunk_path );
            foreach ( $file_entries as $entry ) {
                $archive->addFile( $entry['path'], $entry['rel'] );
            }
            unset( $archive );
        }

        return $chunk_path;
    }

    /**
     * Package the WP files into ≤50 MB chunks and call $callback for each.
     * Callback signature: callable( string $chunk_path, int $bytes_done, int $bytes_total )
     */
    public function package_files( callable $callback ): void {
        $backend = self::archive_backend();

        @set_time_limit( 0 ); // Remove PHP execution limit — hosting may ignore this, but worth trying

        $root       = ABSPATH;
        $chunk_size = 50 * 1024 * 1024; // 50 MB per chunk (well within our 100 MB server limit)
        $ext        = $backend === 'zip' ? 'zip' : 'tar';

        $skip_patterns = array(
            '/.git/',
            '/node_modules/',
            '/wp-content/cache/',
            '/wp-content/uploads/backup',
            '/wp-content/updraft',
            'wp-config.php',
            'wp-config-sample.php',
        );

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $chunk_index = 0;
        $chunk_bytes = 0;  // bytes in the current open chunk
        $bytes_done  = 0;  // cumulative bytes in completed chunks

        /** @var ZipArchive|PharData|null */
        $archive    = null;
        $chunk_path = '';

        // shell-tar backend collects files for the current chunk then tars them all at once
        $shell_tar_files = array();

        // RESUME: a chunk the server has already accepted is not rebuilt. Compression is the expensive
        // half of packaging, and on a resumed 6GB migration re-zipping fifty pieces that already
        // arrived costs many minutes of the customer's time to produce archives that are immediately
        // discarded. The file walk still runs, because the byte accounting and the chunk boundaries
        // have to come out identical or the numbering would drift and later pieces would be misnamed.
        $skip_chunk = false;

        $open_chunk = function () use ( &$archive, &$chunk_path, &$chunk_bytes, &$chunk_index, &$shell_tar_files, &$skip_chunk, $backend, $ext ) {
            $chunk_path      = $this->tmp_dir . "files-chunk-{$chunk_index}.{$ext}";
            $chunk_bytes     = 0;
            $shell_tar_files = array();
            $skip_chunk      = in_array( basename( $chunk_path ), Kapsule_Uploader::completed_chunks(), true );
            if ( $skip_chunk ) {
                $archive = null;
                return;
            }
            if ( $backend === 'zip' ) {
                $archive = new ZipArchive();
                $archive->open( $chunk_path, ZipArchive::CREATE );
            } elseif ( $backend === 'shell-tar' ) {
                $archive = null; // not used; files collected in $shell_tar_files
            } else {
                $archive = new PharData( $chunk_path );
            }
        };

        $close_chunk = function () use ( &$archive, &$chunk_path, &$chunk_index, &$shell_tar_files, &$skip_chunk, $backend ) {
            if ( $skip_chunk ) return;
            if ( $backend === 'shell-tar' ) {
                $root      = rtrim( ABSPATH, DIRECTORY_SEPARATOR );
                $list_file = $this->tmp_dir . "chunk-{$chunk_index}-files.txt";
                file_put_contents( $list_file, implode( "\n", array_column( $shell_tar_files, 'rel' ) ) );
                $cmd = 'tar -cf ' . escapeshellarg( $chunk_path )
                     . ' -C '    . escapeshellarg( $root )
                     . ' --files-from=' . escapeshellarg( $list_file )
                     . ' 2>&1';
                exec( $cmd, $out, $ret );
                @unlink( $list_file );
                if ( $ret !== 0 ) {
                    throw new Exception( 'tar command failed: ' . implode( ' ', array_slice( $out, -3 ) ) );
                }
                return;
            }
            if ( $archive === null ) return;
            if ( $backend === 'zip' ) {
                $archive->close();
            } else {
                unset( $archive );
            }
            $archive = null;
        };

        $open_chunk();

        foreach ( $iter as $file ) {
            if ( ! $file->isFile() ) continue;
            $path         = $file->getRealPath();
            $rel          = str_replace( $root, '', $path );
            $rel_prefixed = '/' . $rel;

            foreach ( $skip_patterns as $pattern ) {
                if ( strpos( $rel_prefixed, $pattern ) !== false ) continue 2;
            }

            $size = $file->getSize();
            $this->file_count++;
            $this->total_bytes += $size;

            // Roll over to a new chunk if adding this file would exceed the limit
            if ( $chunk_bytes > 0 && $chunk_bytes + $size > $chunk_size ) {
                $close_chunk();
                $callback( $chunk_path, $bytes_done + $chunk_bytes, $this->total_bytes );
                // DELETE THE PIECE ONCE IT IS DELIVERED. Without this, packaging a 6GB site leaves a
                // second 6GB of archives sitting in /tmp on the CUSTOMER'S server for the whole run,
                // so migrating requires double the site's size in free space and a customer who is
                // merely low on disk gets a failure that reads as our fault. Resume does not need the
                // file: it is tracked in the completed-chunk list, and upload_chunk checks that list
                // before it looks at the disk.
                @unlink( $chunk_path );
                $bytes_done += $chunk_bytes;
                $chunk_index++;
                $open_chunk();
            }

            if ( ! $skip_chunk ) {
                if ( $backend === 'zip' ) {
                    $archive->addFile( $path, $rel );
                } elseif ( $backend === 'shell-tar' ) {
                    $shell_tar_files[] = array( 'path' => $path, 'rel' => $rel );
                } else {
                    $archive->addFile( $path, $rel );
                }
            }
            $chunk_bytes += $size;
        }

        // Close and deliver the final (possibly only) chunk
        if ( $chunk_bytes > 0 && ( $archive !== null || $skip_chunk ) ) {
            $close_chunk();
            $callback( $chunk_path, $this->total_bytes, $this->total_bytes );
            @unlink( $chunk_path );
        }
    }

    /**
     * ESCAPE A VALUE FOR A FILE, WHICH IS NOT THE SAME JOB AS ESCAPING IT FOR A QUERY.
     *
     * THE DEFECT THIS FIXES DESTROYED EVERY PERCENT SIGN IN A CUSTOMER'S SITE, and it is the real
     * reason oaohost.com could not be migrated on 2026-08-24. Measured on that customer's own export:
     * 120,410 occurrences of ONE token across nine tables.
     *
     * `esc_sql()` is `wpdb::_real_escape()`, and the last thing that function does is
     * `add_placeholder_escape()`, which replaces every `%` with a 66-character token, `{` plus a
     * 64-character per-request HMAC plus `}`. That is deliberate and correct INSIDE WordPress: the
     * escaped value is expected to be spliced into a query that then goes through `wpdb::prepare()`,
     * whose final act is `remove_placeholder_escape()`. The `%` is hidden so `prepare()` does not read
     * it as one of its own printf placeholders, and it is put back a moment later.
     *
     * A DUMP NEVER GOES THROUGH `prepare()`. This writer took the escaped string and wrote it straight
     * to a file, so the `%` was hidden and never put back. Proven directly, not reasoned:
     *
     *     raw      50% off, dall%c2%a0slug                      (23 characters)
     *     esc_sql  50{9d43023b...218b0b} off, dall{9d43...}c2{9d43...}a0slug   (218 characters)
     *     repaired 50% off, dall%c2%a0slug                      identical to the raw value
     *
     * WHAT IT COST, and it is much worse than it looks. A percent sign is not rare in a WordPress
     * database: every percent-encoded character in a slug or a URL, every `%` in CSS or in a price, and
     * every serialised option that contains one. Each became 66 characters, which:
     *
     *   * broke PHP-serialised options, because `s:23:"..."` no longer matches a 218-character string
     *     and WordPress discards the whole option;
     *   * pushed values past the size of the column they live in, which is what produced the
     *     `ERROR 1406 Data too long for column 'post_name'` this customer actually saw, and the
     *     silent truncation to 200 characters on the run that "succeeded";
     *   * would have rendered the token as visible text anywhere the `%` had been.
     *
     * THE SCHEMA WAS NEVER THE PROBLEM. It looked as though the customer's database held values too
     * long for their own columns, which MySQL will not in fact allow: `varchar(200)` truncates at 200
     * whatever the SQL mode. The over-long values were manufactured HERE, on the way out.
     *
     * `remove_placeholder_escape()` has been public since WordPress 4.8.3; the fallback keeps this
     * working on anything older, where `placeholder_escape()` is still callable.
     */
    private static function escape_for_dump( $wpdb, string $value ): string {
        $escaped = esc_sql( $value );
        if ( method_exists( $wpdb, 'remove_placeholder_escape' ) ) {
            return $wpdb->remove_placeholder_escape( $escaped );
        }
        if ( method_exists( $wpdb, 'placeholder_escape' ) ) {
            return str_replace( $wpdb->placeholder_escape(), '%', $escaped );
        }
        return $escaped;
    }

    /**
     * Look for a WordPress placeholder token in a finished dump, and return the first one found.
     *
     * The token is `{` plus 64 hex characters plus `}`, produced by `wpdb::placeholder_escape()`. It
     * has no business in a SQL file: it only ever appears where a `%` was hidden and not restored.
     *
     * READ IN OVERLAPPING CHUNKS, because a fixed-size read will eventually split a 66 character token
     * across two buffers and the pattern would then match neither half. The overlap is longer than the
     * token, so no token can hide in a seam. A scanner that misses the thing it exists to find is the
     * failure mode this whole incident is made of.
     */
    private static function find_placeholder_leak( string $file ): string {
        $fh = fopen( $file, 'rb' );
        if ( ! $fh ) return '';
        $chunk   = 1024 * 1024;
        $overlap = 128;
        $tail    = '';
        while ( ! feof( $fh ) ) {
            $buf = fread( $fh, $chunk );
            if ( false === $buf || '' === $buf ) break;
            $window = $tail . $buf;
            if ( preg_match( '/\{[0-9a-f]{64}\}/', $window, $m ) ) {
                fclose( $fh );
                return $m[0];
            }
            $tail = substr( $window, -$overlap );
        }
        fclose( $fh );
        return '';
    }

    /**
     * WHICH OF THE THINGS `SHOW TABLES` RETURNS ARE ACTUALLY TABLES.
     *
     * `SHOW TABLES` lists VIEWS alongside base tables and says nothing about which is which, and for
     * the whole life of this plugin the exporter believed all of them were tables. That is R-505: a
     * view got `DROP TABLE IF EXISTS`, then `SHOW CREATE TABLE` (which for a view returns a CREATE
     * VIEW), and then one INSERT per row into an object that has no rows of its own and usually
     * cannot accept any. Measured on the ledger's own fixture:
     *
     *     ERROR 1471 (HY000) at line 40: The target table wp_posts_report of the INSERT is not
     *     insertable-into
     *
     * and because mysql stops at the first statement it refuses, the two tables that sorted after the
     * view (`wp_term_relationships`, `wp_users`) were never imported at all.
     *
     * ONE INSTRUMENT DECIDES BOTH THE POPULATION AND THE KIND. `SHOW FULL TABLES` returns the same
     * rows as `SHOW TABLES` with the type attached, so there is no chance of walking a list from one
     * query and classifying it from another that cannot see the same objects. `information_schema` is
     * the fallback for the rare host that refuses `SHOW FULL TABLES`.
     *
     * AND IF NEITHER CAN SEE, WE REFUSE. If both classifiers come back empty while `SHOW TABLES` can
     * still name objects, that is a blind instrument, not an empty database, and the difference
     * matters: treating blind as empty is how you ship a dump with a view in it again.
     *
     * @return array<int, array{name: string, is_view: bool}>
     */
    private static function classify_objects( $wpdb ): array {
        $objects = array();

        foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
            if ( ! isset( $row[0] ) || '' === (string) $row[0] ) continue;
            $type      = isset( $row[1] ) ? strtoupper( trim( (string) $row[1] ) ) : '';
            $objects[] = array( 'name' => (string) $row[0], 'is_view' => ( 'VIEW' === $type ) );
        }
        if ( ! empty( $objects ) ) return $objects;

        foreach ( (array) $wpdb->get_results(
            'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
            ARRAY_N
        ) as $row ) {
            if ( ! isset( $row[0] ) || '' === (string) $row[0] ) continue;
            $type      = isset( $row[1] ) ? strtoupper( trim( (string) $row[1] ) ) : '';
            $objects[] = array( 'name' => (string) $row[0], 'is_view' => ( 'VIEW' === $type ) );
        }
        if ( ! empty( $objects ) ) return $objects;

        $names = (array) $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $names ) ) return array(); // A genuinely empty database. Zero, not blind.

        throw new Exception( sprintf(
            /* translators: %d: the number of database objects that could not be classified. */
            __( 'We could not tell which of the %d parts of your database are tables and which are views, and copying them without knowing would produce a database copy that cannot be restored. Nothing has been uploaded and your site is untouched. Please contact KapsuleHost support and quote this message.', 'kapsule-migrator' ),
            count( $names )
        ) );
    }

    /**
     * Make a view's CREATE statement safe to replay on a machine that is not the customer's.
     *
     * TWO EDITS, BOTH OF THEM LOAD-BEARING, and both confined to the header of the statement (every
     * byte before ` VIEW \``), so nothing inside the customer's own SELECT is ever rewritten.
     *
     *  1. DROP THE DEFINER. `SHOW CREATE VIEW` hands back `DEFINER=\`someuser\`@\`somehost\``, naming
     *     an account on the server we are moving the site AWAY from. That account does not exist on
     *     ours, so the CREATE fails outright for anyone but a superuser. mysqldump has the same
     *     problem and hosts strip it for the same reason. With no DEFINER clause MySQL uses whoever
     *     runs the import, which exists by definition.
     *
     *  2. FORCE `SQL SECURITY INVOKER`. This one is a security control, not tidiness. Our import runs
     *     as root. A view carried in with `SQL SECURITY DEFINER` and its definer defaulted to root
     *     would execute its SELECT WITH ROOT PRIVILEGES every time the site queried it, on a box that
     *     hosts other customers. A migrated site that shipped `CREATE VIEW x AS SELECT * FROM
     *     mysql.user` would then be able to read the server's account table through its own database
     *     user. INVOKER makes the view run as whoever queries it, which for a WordPress site is its
     *     own database user reading its own tables: the intended behaviour, and nothing more.
     *
     * The database-name strip is a third, smaller thing. MariaDB normalises a same-database qualifier
     * away before we ever see it (measured: `FROM km_r505_probe.t` comes back as ``FROM `t``), but
     * MySQL does not always, and a migration changes the database name by definition, so a view whose
     * body still names the customer's OLD database would resolve to nothing here. Stripping only the
     * source database's own qualifier cannot change meaning: inside that database the two forms are
     * the same reference.
     */
    private static function sanitise_view_ddl( string $ddl, string $source_db = '' ): string {
        $split = stripos( $ddl, ' VIEW `' );
        if ( false === $split ) {
            // Not a shape we recognise. Leave the customer's DDL alone rather than mangle it.
            return $ddl;
        }
        $head = substr( $ddl, 0, $split );
        $body = substr( $ddl, $split );

        $head = preg_replace(
            '/\s*DEFINER\s*=\s*(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|CURRENT_USER(?:\s*\(\s*\))?)'
            . '(?:@(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|[^\s`\'"]+))?/i',
            '',
            $head
        );

        if ( preg_match( '/SQL\s+SECURITY\s+(?:DEFINER|INVOKER)/i', $head ) ) {
            $head = preg_replace( '/SQL\s+SECURITY\s+DEFINER/i', 'SQL SECURITY INVOKER', $head );
        } else {
            $head = rtrim( $head ) . ' SQL SECURITY INVOKER';
        }

        $out = rtrim( $head ) . $body;

        if ( '' !== $source_db ) {
            $out = str_replace( '`' . $source_db . '`.`', '`', $out );
        }

        return $out;
    }

    /**
     * Look for the R-505 shape in a finished dump: a statement aimed at a view that only a table can
     * accept. Returns the first offender found, or '' when the dump is clean.
     *
     * WHY THIS IS HERE AND NOT ONLY IN THE TEST. The test proves today's writer is right. This proves
     * TOMORROW'S is, on the customer's own database, before anything is uploaded. The writer above is
     * two passes over two lists and it would take one careless edit to put a view back in the wrong
     * one; a customer would then discover it the way the first one did, as a half-imported site. The
     * population is the view list this very export derived, so it cannot drift out of step with what
     * was written.
     */
    private static function find_view_dumped_as_table( string $file, array $view_names ): string {
        if ( empty( $view_names ) ) return '';
        $sql = @file_get_contents( $file );
        if ( false === $sql || '' === $sql ) return '';
        foreach ( $view_names as $view ) {
            if ( false !== strpos( $sql, 'INSERT INTO `' . $view . '`' ) ) {
                return sprintf( 'INSERT INTO `%s` (that object is a view, and a view holds no rows of its own)', $view );
            }
            if ( ! preg_match( '/^CREATE[^\n]*\bVIEW\s+`' . preg_quote( $view, '/' ) . '`/mi', $sql ) ) {
                return sprintf( '`%s` is a view and no CREATE VIEW for it was written', $view );
            }
        }
        return '';
    }

    /**
     * Export the WordPress database to a gzip-compressed SQL file.
     */
    public function export_database(): string {
        global $wpdb;

        $db_file = $this->tmp_dir . 'database.sql';
        $gz_file = $db_file . '.gz';

        $objects   = self::classify_objects( $wpdb );
        $source_db = (string) $wpdb->get_var( 'SELECT DATABASE()' );

        $tables = array();
        $views  = array();
        foreach ( $objects as $object ) {
            if ( $object['is_view'] ) $views[]  = $object['name'];
            else                      $tables[] = $object['name'];
        }

        $handle = fopen( $db_file, 'w' );

        // THE SESSION PREAMBLE A REAL mysqldump WRITES, AND THIS WRITER DID NOT.
        //
        // Line 1 of every dump this plugin has ever produced was `SET FOREIGN_KEY_CHECKS=0;` followed
        // straight by a CREATE TABLE. A real mysqldump establishes ten session settings first, and
        // three of them decide whether the customer's data survives the trip:
        //
        //   SET NAMES utf8mb4   without it the import runs at whatever the destination's client
        //                       default happens to be (measured on our own box: utf8mb3), and a
        //                       four-byte emoji in a post is rejected with ERROR 1366. That is
        //                       literally how oaohost.com's first migration failed.
        //   SET TIME_ZONE       without it every TIMESTAMP is re-interpreted in the destination's
        //                       offset, so the migrated site's posts change date. Nothing fails, no
        //                       error appears, and the customer finds out weeks later, if ever.
        //   SQL_MODE            'NO_AUTO_VALUE_ON_ZERO' is what lets a row with an explicit id of 0
        //                       keep it instead of being handed a new auto-increment value.
        //
        // The list is NOT typed here. It is captured from a real mysqldump by
        // tools/derive-dump-preamble.sh into includes/class-dump-preamble.php and checked by
        // tools/verify-dump-preamble.sh, because a hand-written copy drifts silently: the same
        // mysqldump emits nine of these lines for one set of flags and ten for another, and a list
        // written from memory would be confidently wrong about which.
        fwrite( $handle, Kapsule_Dump_Preamble::preamble() );
        fwrite( $handle, "\n" );

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

        foreach ( $tables as $table ) {
            // Routed through the same helper as the values. A table name containing a percent sign is
            // exotic and not impossible, and it would be corrupted by exactly the same mechanism.
            $table_escaped = self::escape_for_dump( $wpdb, $table );

            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table_escaped}`", ARRAY_N );
            fwrite( $handle, "DROP TABLE IF EXISTS `{$table_escaped}`;\n" );
            fwrite( $handle, $create[1] . ";\n\n" );

            $offset = 0;
            $batch  = 500;
            do {
                $rows = $wpdb->get_results( "SELECT * FROM `{$table_escaped}` LIMIT {$batch} OFFSET {$offset}", ARRAY_N );
                if ( empty( $rows ) ) break;
                $cols     = $wpdb->get_col_info( 'name' );
                $col_list = '`' . implode( '`, `', $cols ) . '`';
                foreach ( $rows as $row ) {
                    $vals = array_map( function ( $v ) use ( $wpdb ) {
                        return $v === null ? 'NULL' : "'" . self::escape_for_dump( $wpdb, $v ) . "'";
                    }, $row );
                    fwrite( $handle, "INSERT INTO `{$table_escaped}` ({$col_list}) VALUES (" . implode( ', ', $vals ) . ");\n" );
                }
                $offset += $batch;
            } while ( count( $rows ) === $batch );

            fwrite( $handle, "\n" );
        }

        // ── VIEWS, AFTER EVERY TABLE, AND NEVER WITH A ROW IN THEM ────────────────────────────────
        //
        // A view is a stored SELECT. It owns no rows, so there is nothing to INSERT, and it cannot be
        // created before the things it selects from exist. Hence: after the tables, always.
        //
        // THE STAND-IN PASS BELOW IS NOT DEFENSIVE PADDING, and it is the part that is easy to leave
        // out. "After the tables" is not sufficient, because a view is very often built on ANOTHER
        // view (a reporting plugin layering a summary over a detail view produces exactly that), and
        // the order this walk sees is the server's, which is alphabetical. `wp_aaa_report_summary`
        // reads `wp_posts_report` and sorts three thousand names ahead of it. Emitting the real
        // CREATE VIEW in that order fails with ERROR 1146: the object it selects from does not exist
        // yet, and the import stops there exactly as before.
        //
        // So every view name is first created as an empty stand-in TABLE with the right column names.
        // Any view created afterwards resolves its references against those stand-ins, whatever the
        // order. Each view's own section then drops its stand-in and creates the real view. Views
        // resolve their sources by name when queried, not when created, so a view built against a
        // stand-in is correct the moment the stand-in is replaced by the real thing.
        //
        // This is precisely what mysqldump does, for precisely this reason, and it is worth copying
        // rather than inventing a dependency sort: MariaDB has no VIEW_TABLE_USAGE table to sort
        // from, so a sort would have to guess dependencies out of the SELECT text, and a guess that
        // is wrong produces the same failed migration with more code in front of it.
        if ( ! empty( $views ) ) {
            fwrite( $handle, "-- Stand-in tables for views. Each is replaced by the real view below.\n" );
            foreach ( $views as $view ) {
                $view_escaped = self::escape_for_dump( $wpdb, $view );
                $columns      = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$view_escaped}`" );
                if ( empty( $columns ) ) {
                    // A view whose columns cannot be read is broken on the SOURCE (it usually selects
                    // from something that has since been dropped). Its CREATE still travels, so the
                    // customer keeps the object and its definition; there is simply no stand-in to
                    // make, because there are no column names to make it out of.
                    continue;
                }
                $defs = array();
                foreach ( $columns as $column ) {
                    $defs[] = '`' . str_replace( '`', '``', self::escape_for_dump( $wpdb, (string) $column ) ) . '` tinyint NOT NULL';
                }
                fwrite( $handle, "DROP TABLE IF EXISTS `{$view_escaped}`;\n" );
                fwrite( $handle, "DROP VIEW IF EXISTS `{$view_escaped}`;\n" );
                fwrite( $handle, "CREATE TABLE `{$view_escaped}` (\n  " . implode( ",\n  ", $defs ) . "\n);\n\n" );
            }

            foreach ( $views as $view ) {
                $view_escaped = self::escape_for_dump( $wpdb, $view );

                // SHOW CREATE VIEW, not SHOW CREATE TABLE. The old code used the table form, which
                // happens to return the view's DDL, and that accident is what made the bug look like
                // it was only about the INSERTs.
                $create = $wpdb->get_row( "SHOW CREATE VIEW `{$view_escaped}`", ARRAY_N );
                if ( ! is_array( $create ) || ! isset( $create[1] ) || '' === $create[1] ) {
                    continue;
                }

                // BOTH drops. `DROP TABLE IF EXISTS` removes the stand-in this dump just made; it does
                // NOT remove a view, which is the whole of ledger point 1 (measured on MariaDB 10.11:
                // `DROP TABLE IF EXISTS <view>` returns Note 1965 "is a view", exit 0, and the view is
                // still standing, so the CREATE behind it failed with ERROR 1050 on any second run).
                // `DROP VIEW IF EXISTS` is the one that clears a real view. Together they make the
                // dump replayable into a database that already holds either shape.
                fwrite( $handle, "DROP TABLE IF EXISTS `{$view_escaped}`;\n" );
                fwrite( $handle, "DROP VIEW IF EXISTS `{$view_escaped}`;\n" );
                fwrite( $handle, self::sanitise_view_ddl( $create[1], $source_db ) . ";\n\n" );
            }
        }

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
        // The matching epilogue, so the import leaves the session exactly as it found it rather than
        // leaving TIME_ZONE and SQL_MODE altered for whatever runs next on that connection.
        fwrite( $handle, Kapsule_Dump_Preamble::epilogue() );
        fclose( $handle );

        // ── REFUSE TO HAND OVER A DUMP THAT CARRIES THE CORRUPTION ────────────────────────────────
        //
        // THE CHECK THAT WOULD HAVE CAUGHT THIS, and it is here rather than in pre-flight for a reason
        // worth stating. The obvious place to look for trouble is the customer's DATABASE, and there
        // was nothing wrong with it: oaohost.com's schema and data agreed perfectly. The damage was
        // done by THIS FUNCTION, on the way out, so the only place it is visible is the file this
        // function just wrote. A check pointed at the source could not have gone red no matter how
        // carefully it was written.
        //
        // (A pre-flight check comparing every column's declared size against its longest value WAS
        // written first, and then deleted: MySQL will not store more characters than a varchar
        // declares, in any SQL mode, so it could never fire. A check that cannot go red reads as
        // protection and provides none, and it cost a full scan of every table on every page load.)
        $leak = self::find_placeholder_leak( $db_file );
        if ( '' !== $leak ) {
            @unlink( $db_file );
            throw new Exception( sprintf(
                /* translators: %s: the placeholder token found in the export. */
                __( 'We built a copy of your database and then found it was not safe to send: it still contains an internal placeholder (%s) where your content has a percent sign. Sending it would have changed your links, styling and settings. Nothing has been uploaded and your site is untouched. Please contact KapsuleHost support and quote this message.', 'kapsule-migrator' ),
                substr( $leak, 0, 12 ) . '...'
            ) );
        }

        // ── AND THE SAME TREATMENT FOR R-505 ──────────────────────────────────────────────────────
        //
        // Read the file that was just written and confirm no view in it was treated as a table. This
        // is the check that would have caught the original defect on the customer's own machine,
        // before a byte was uploaded, instead of three quarters of the way through an import on ours.
        $view_defect = self::find_view_dumped_as_table( $db_file, $views );
        if ( '' !== $view_defect ) {
            @unlink( $db_file );
            throw new Exception( sprintf(
                /* translators: %s: a description of the offending statement found in the export. */
                __( 'We built a copy of your database and then found it was not safe to send: it handles one of your database views as if it were a table (%s). Restoring it would stop partway through and leave some of your tables missing. Nothing has been uploaded and your site is untouched. Please contact KapsuleHost support and quote this message.', 'kapsule-migrator' ),
                $view_defect
            ) );
        }

        $gz = gzopen( $gz_file, 'wb9' );
        $in = fopen( $db_file, 'rb' );
        while ( ! feof( $in ) ) {
            gzwrite( $gz, fread( $in, 65536 ) );
        }
        fclose( $in );
        gzclose( $gz );
        unlink( $db_file );

        return $gz_file;
    }

    public function get_file_count(): int  { return $this->file_count; }
    public function get_total_bytes(): int { return $this->total_bytes; }
    public function get_tmp_dir(): string  { return $this->tmp_dir; }

    public function cleanup(): void {
        if ( is_dir( $this->tmp_dir ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $this->tmp_dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iter as $f ) {
                $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
            }
            rmdir( $this->tmp_dir );
        }
    }
}
