<?php

class Kapsule_Uploader {

    private string $token;
    private string $api_base;

    public function __construct( string $token ) {
        $this->token    = $token;
        $this->api_base = KAPSULE_MIGRATOR_API_BASE;
    }

    /**
     * Upload a single chunk file to Kapsule.
     * First fetches a signed upload URL, then PUTs the file.
     */
    public function upload_chunk( string $file_path, ?string $remote_name = null ): void {
        if ( ! file_exists( $file_path ) ) {
            throw new Exception( "Chunk file not found: {$file_path}" );
        }

        $filename    = $remote_name ?? basename( $file_path );
        $signed_data = $this->get_signed_url( $filename, filesize( $file_path ) );

        if ( empty( $signed_data['uploadUrl'] ) ) {
            throw new Exception( 'Failed to get signed upload URL from Kapsule' );
        }

        $this->put_file( $signed_data['uploadUrl'], $file_path );
    }

    private function get_signed_url( string $filename, int $bytes ): array {
        $response = wp_remote_post( $this->api_base . '/signed-url', array(
            'headers'     => array( 'Content-Type' => 'application/json' ),
            'body'        => wp_json_encode( array(
                'token'    => $this->token,
                'filename' => $filename,
                'bytes'    => $bytes,
            ) ),
            'timeout'     => 15,
            'data_format' => 'body',
        ) );

        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? $body : array();
    }

    private function put_file( string $url, string $file_path ): void {
        $file_size = filesize( $file_path );
        $handle    = fopen( $file_path, 'rb' );

        if ( ! $handle ) {
            throw new Exception( "Cannot open file for upload: {$file_path}" );
        }

        $ch = curl_init( $url );
        curl_setopt_array( $ch, array(
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $file_size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600, // 10 min max per chunk
            CURLOPT_HTTPHEADER     => array( 'Content-Type: application/octet-stream' ),
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
            throw new Exception( "Upload returned HTTP {$code}" );
        }
    }
}
