<?php

class Kapsule_Packager {

    private string $tmp_dir;
    private int    $file_count  = 0;
    private int    $total_bytes = 0;

    public function __construct() {
        $this->tmp_dir = get_temp_dir() . 'kapsule-migrator-' . uniqid() . '/';
        wp_mkdir_p( $this->tmp_dir );
    }

    /**
     * Detect which archive backend is available.
     * Returns 'zip' (ZipArchive) or 'tar' (PharData), or throws.
     */
    private static function archive_backend(): string {
        if ( class_exists( 'ZipArchive' ) ) return 'zip';
        if ( class_exists( 'PharData' ) )   return 'tar';
        throw new Exception( 'No archive extension available (php-zip or phar required). Please contact Kapsule support.' );
    }

    /**
     * Package the WP files into ≤50 MB chunks and call $callback for each.
     * Callback signature: callable( string $chunk_path, int $bytes_done, int $bytes_total )
     */
    public function package_files( callable $callback ): void {
        $backend = self::archive_backend();

        @set_time_limit( 0 ); // Remove PHP execution limit — hosting may ignore this, but worth trying

        $root       = ABSPATH;
        $chunk_size = 50 * 1024 * 1024; // 50 MB per chunk
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

        $open_chunk = function () use ( &$archive, &$chunk_path, &$chunk_bytes, &$chunk_index, $backend, $ext ) {
            $chunk_path  = $this->tmp_dir . "files-chunk-{$chunk_index}.{$ext}";
            $chunk_bytes = 0;
            if ( $backend === 'zip' ) {
                $archive = new ZipArchive();
                $archive->open( $chunk_path, ZipArchive::CREATE );
            } else {
                $archive = new PharData( $chunk_path );
            }
        };

        $close_chunk = function () use ( &$archive, $backend ) {
            if ( $archive === null ) return;
            if ( $backend === 'zip' ) {
                $archive->close();
            } else {
                // PharData flushes on unset
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
