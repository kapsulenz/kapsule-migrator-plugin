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
     * Export the WordPress database to a gzip-compressed SQL file.
     */
    public function export_database(): string {
        global $wpdb;

        $db_file = $this->tmp_dir . 'database.sql';
        $gz_file = $db_file . '.gz';

        $tables = $wpdb->get_col( 'SHOW TABLES' );
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
