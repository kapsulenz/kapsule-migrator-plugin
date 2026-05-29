<?php

class Kapsule_Uploader {

    private string $token;
    private string $api_base;

    public function __construct( string $token ) {
        $this->token    = $token;
        $this->api_base = KAPSULE_MIGRATOR_API_BASE;
    }

    /**
     * Upload a single chunk file directly to the Kapsule portal server.
     */
    public function upload_chunk( string $file_path, ?string $remote_name = null ): void {
        if ( ! file_exists( $file_path ) ) {
            throw new Exception( "Chunk file not found: {$file_path}" );
        }

        $filename = $remote_name ?? basename( $file_path );
        $handle   = fopen( $file_path, 'rb' );

        if ( ! $handle ) {
            throw new Exception( "Cannot open file for upload: {$file_path}" );
        }

        // Use fstat() on the open handle — avoids PHP stat cache returning stale size
        $stat      = fstat( $handle );
        $file_size = $stat['size'];

        $url = $this->api_base . '/upload-chunk';

        $ch = curl_init( $url );
        curl_setopt_array( $ch, array(
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $file_size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/octet-stream',
                'X-Migration-Token: ' . $this->token,
                'X-Chunk-Filename: ' . $filename,
                'Expect:',
            ),
            CURLOPT_SSL_VERIFYPEER => true,
        ) );

        $result = curl_exec( $ch );
        $code   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error  = curl_error( $ch );
        curl_close( $ch );
        fclose( $handle );

        if ( $error ) {
            throw new Exception( "Upload failed: {$error}" );
        }
        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( "Upload returned HTTP {$code}: {$result}" );
        }
    }
}
