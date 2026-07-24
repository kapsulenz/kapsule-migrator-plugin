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
        throw new Exception( 'No archive backend available (php-zip, system tar, or phar required). Please contact Kapsule support.' );
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
        $chunk_size = 50 * 1024 * 1024; // 50 MB per chunk — well within our 100 MB server limit
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

        $open_chunk = function () use ( &$archive, &$chunk_path, &$chunk_bytes, &$chunk_index, &$shell_tar_files, $backend, $ext ) {
            $chunk_path      = $this->tmp_dir . "files-chunk-{$chunk_index}.{$ext}";
            $chunk_bytes     = 0;
            $shell_tar_files = array();
            if ( $backend === 'zip' ) {
                $archive = new ZipArchive();
                $archive->open( $chunk_path, ZipArchive::CREATE );
            } elseif ( $backend === 'shell-tar' ) {
                $archive = null; // not used; files collected in $shell_tar_files
            } else {
                $archive = new PharData( $chunk_path );
            }
        };

        $close_chunk = function () use ( &$archive, &$chunk_path, &$chunk_index, &$shell_tar_files, $backend ) {
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
                $bytes_done += $chunk_bytes;
                $chunk_index++;
                $open_chunk();
            }

            if ( $backend === 'zip' ) {
                $archive->addFile( $path, $rel );
            } elseif ( $backend === 'shell-tar' ) {
                $shell_tar_files[] = array( 'path' => $path, 'rel' => $rel );
            } else {
                $archive->addFile( $path, $rel );
            }
            $chunk_bytes += $size;
        }

        // Close and deliver the final (possibly only) chunk
        if ( $archive !== null && $chunk_bytes > 0 ) {
            $close_chunk();
            $callback( $chunk_path, $this->total_bytes, $this->total_bytes );
        }
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

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

        foreach ( $tables as $table ) {
            $table_escaped = esc_sql( $table );

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
                        return $v === null ? 'NULL' : "'" . esc_sql( $v ) . "'";
                    }, $row );
                    fwrite( $handle, "INSERT INTO `{$table_escaped}` ({$col_list}) VALUES (" . implode( ', ', $vals ) . ");\n" );
                }
                $offset += $batch;
            } while ( count( $rows ) === $batch );

            fwrite( $handle, "\n" );
        }

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
        fclose( $handle );

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
