<?php

class Kapsule_Packager {

    private string $tmp_dir;
    private int $file_count  = 0;
    private int $total_bytes = 0;

    public function __construct() {
        $this->tmp_dir = get_temp_dir() . 'kapsule-migrator-' . uniqid() . '/';
        wp_mkdir_p( $this->tmp_dir );
    }

    /**
     * Package the WP files into 50 MB chunks and call $callback for each.
     * $callback( string $chunk_path, int $bytes_done, int $bytes_total )
     */
    public function package_files( callable $callback ): void {
        $root        = ABSPATH;
        $chunk_size  = 50 * 1024 * 1024; // 50 MB per chunk
        $chunk_index = 0;
        $chunk_bytes = 0;
        $zip         = null;
        $zip_path    = '';

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

        foreach ( $iter as $file ) {
            if ( ! $file->isFile() ) continue;
            $path = $file->getRealPath();

            // Skip common large/irrelevant dirs
            $rel = str_replace( $root, '', $path );
            foreach ( $skip_patterns as $pattern ) {
                if ( strpos( $rel, $pattern ) !== false ) continue 2;
            }

            $size = $file->getSize();
            $this->file_count++;
            $this->total_bytes += $size;

            if ( $zip === null || $chunk_bytes + $size > $chunk_size ) {
                if ( $zip !== null ) {
                    $zip->close();
                    $callback( $zip_path, $chunk_bytes * $chunk_index, $this->total_bytes );
                    $chunk_index++;
                }
                $zip_path    = $this->tmp_dir . "files-chunk-{$chunk_index}.zip";
                $zip         = new ZipArchive();
                $zip->open( $zip_path, ZipArchive::CREATE );
                $chunk_bytes = 0;
            }

            $zip->addFile( $path, $rel );
            $chunk_bytes += $size;
        }

        if ( $zip !== null ) {
            $zip->close();
            $callback( $zip_path, $this->total_bytes, $this->total_bytes );
        }
    }

    public function export_database(): string {
        global $wpdb;

        $db_file = $this->tmp_dir . 'database.sql';
        $gz_file = $db_file . '.gz';

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        $handle = fopen( $db_file, 'w' );

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

        foreach ( $tables as $table ) {
            // Drop + create
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
            fwrite( $handle, $create[1] . ";\n\n" );

            // Data in batches of 500 rows
            $offset = 0;
            $batch  = 500;
            do {
                $rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$batch} OFFSET {$offset}", ARRAY_N );
                if ( empty( $rows ) ) break;
                $cols  = $wpdb->get_col_info( 'name' );
                $col_list = '`' . implode( '`, `', $cols ) . '`';
                foreach ( $rows as $row ) {
                    $vals = array_map( function( $v ) use ( $wpdb ) {
                        return $v === null ? 'NULL' : "'" . esc_sql( $v ) . "'";
                    }, $row );
                    fwrite( $handle, "INSERT INTO `{$table}` ({$col_list}) VALUES (" . implode( ', ', $vals ) . ");\n" );
                }
                $offset += $batch;
            } while ( count( $rows ) === $batch );

            fwrite( $handle, "\n" );
        }

        fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
        fclose( $handle );

        // gzip
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
    public function get_tmp_dir(): string   { return $this->tmp_dir; }

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
