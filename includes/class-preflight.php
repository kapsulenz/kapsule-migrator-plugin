<?php

class Kapsule_Preflight {

    public function scan(): array {
        return array(
            'wpVersion'      => get_bloginfo( 'version' ),
            'phpVersion'     => PHP_VERSION,
            'isMultisite'    => is_multisite(),
            'isWooCommerce'  => class_exists( 'WooCommerce' ),
            'theme'          => wp_get_theme()->get( 'Name' ),
            'activePlugins'  => count( get_option( 'active_plugins', array() ) ),
            'uploadsBytes'   => $this->dir_size( wp_upload_dir()['basedir'] ),
            'uploadsFiles'   => $this->count_files( wp_upload_dir()['basedir'] ),
            'wpRootBytes'    => $this->dir_size( ABSPATH ),
            'wpRootFiles'    => $this->count_files( ABSPATH ),
            'dbSizeBytes'    => $this->db_size(),
            'dbTables'       => $this->db_table_count(),
            'homeUrl'        => home_url(),
            'siteUrl'        => site_url(),
            'maxUploadBytes' => wp_max_upload_size(),
            'maxExecSeconds' => (int) ini_get( 'max_execution_time' ),
            'memoryLimitMb'  => $this->memory_limit_mb(),
            'phpExtensions'  => $this->required_extensions(),
            'scannedAt'      => gmdate( 'c' ),
        );
    }

    private function dir_size( string $dir ): int {
        if ( ! is_dir( $dir ) ) return 0;
        $size = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $iter as $file ) {
            if ( $file->isFile() ) $size += $file->getSize();
        }
        return $size;
    }

    private function count_files( string $dir ): int {
        if ( ! is_dir( $dir ) ) return 0;
        $count = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $iter as $file ) {
            if ( $file->isFile() ) $count++;
        }
        return $count;
    }

    private function db_size(): int {
        global $wpdb;
        $db_name = DB_NAME;
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s",
            $db_name
        ) );
        return (int) $result;
    }

    private function db_table_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s", DB_NAME )
        );
    }

    private function memory_limit_mb(): int {
        $limit = ini_get( 'memory_limit' );
        if ( '-1' === $limit ) return -1;
        $unit = strtoupper( substr( $limit, -1 ) );
        $val  = (int) $limit;
        if ( 'G' === $unit ) return $val * 1024;
        if ( 'M' === $unit ) return $val;
        if ( 'K' === $unit ) return (int) ( $val / 1024 );
        return $val;
    }

    private function required_extensions(): array {
        $needed = array( 'curl', 'zip', 'pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl' );
        $result = array();
        foreach ( $needed as $ext ) {
            $result[ $ext ] = extension_loaded( $ext );
        }
        return $result;
    }
}
