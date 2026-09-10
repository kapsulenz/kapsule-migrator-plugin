<?php
/**
 * A MINIMAL, FAITHFUL wpdb FOR TESTING THE DUMP WRITER OUTSIDE WORDPRESS.
 *
 * The exporter is the only part of the plugin that talks to a database, and until now it had no test
 * at all, because testing it looked like it needed a WordPress install. It does not: it needs the six
 * wpdb methods it actually calls, and a real MySQL server behind them.
 *
 * The placeholder-escape behaviour is reproduced EXACTLY as WordPress implements it (a `%` becomes
 * `{` + 64 hex + `}` inside `_real_escape`, and `remove_placeholder_escape` puts it back). A stub that
 * simplified that away would quietly stop exercising the percent-sign repair that lives in the same
 * function, and a test that stops testing something is worse than no test.
 */

if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );
if ( ! defined( 'ARRAY_N' ) ) define( 'ARRAY_N', 'ARRAY_N' );
if ( ! defined( 'OBJECT' ) )  define( 'OBJECT',  'OBJECT'  );

class Kapsule_Test_WPDB {

    /** @var mysqli */
    public $dbh;
    private array  $col_info    = array();
    private string $placeholder = '';
    public  array  $queries     = array();
    public  string $last_error  = '';

    public function __construct( string $host, string $user, string $pass, string $db, int $port = 3306 ) {
        $this->dbh = new mysqli( $host, $user, $pass, $db, $port );
        if ( $this->dbh->connect_errno ) {
            throw new RuntimeException( 'wpdb stub could not connect: ' . $this->dbh->connect_error );
        }
        $this->dbh->set_charset( 'utf8mb4' );
    }

    private function run( string $query ) {
        $this->queries[] = $query;
        $res = @$this->dbh->query( $query );
        if ( false === $res ) {
            $this->last_error = $this->dbh->error;
            return false;
        }
        $this->last_error = '';
        if ( $res instanceof mysqli_result ) {
            $this->col_info = array();
            foreach ( $res->fetch_fields() as $f ) {
                $this->col_info[] = $f->name;
            }
        }
        return $res;
    }

    public function get_col( string $query ): array {
        $res = $this->run( $query );
        if ( ! $res instanceof mysqli_result ) return array();
        $out = array();
        while ( $row = $res->fetch_row() ) $out[] = $row[0];
        $res->free();
        return $out;
    }

    public function get_row( string $query, string $output = OBJECT ) {
        $res = $this->run( $query );
        if ( ! $res instanceof mysqli_result ) return null;
        $row = $res->fetch_row();
        $res->free();
        return $row ?: null;
    }

    public function get_results( string $query, string $output = OBJECT ): array {
        $res = $this->run( $query );
        if ( ! $res instanceof mysqli_result ) return array();
        $out = array();
        while ( $row = $res->fetch_row() ) $out[] = $row;
        $res->free();
        return $out;
    }

    public function get_var( string $query ) {
        $res = $this->run( $query );
        if ( ! $res instanceof mysqli_result ) return null;
        $row = $res->fetch_row();
        $res->free();
        return $row ? $row[0] : null;
    }

    public function get_col_info( string $info_type = 'name' ): array {
        return $this->col_info;
    }

    // ── WordPress placeholder escaping, reproduced ─────────────────────────────────────────────
    public function placeholder_escape(): string {
        if ( '' === $this->placeholder ) {
            $this->placeholder = '{' . hash_hmac( 'sha256', uniqid( '', true ), 'kapsule-test-salt' ) . '}';
        }
        return $this->placeholder;
    }

    public function add_placeholder_escape( string $query ): string {
        return str_replace( '%', $this->placeholder_escape(), $query );
    }

    public function remove_placeholder_escape( string $query ): string {
        return str_replace( $this->placeholder_escape(), '%', $query );
    }

    public function _real_escape( string $string ): string {
        return $this->add_placeholder_escape( $this->dbh->real_escape_string( $string ) );
    }

    public function _escape( $data ) {
        if ( is_array( $data ) ) return array_map( array( $this, '_escape' ), $data );
        return $this->_real_escape( (string) $data );
    }
}

// ── The WordPress globals the packager reaches for ────────────────────────────────────────────
if ( ! function_exists( 'esc_sql' ) ) {
    function esc_sql( $data ) {
        global $wpdb;
        return $wpdb->_escape( $data );
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'get_temp_dir' ) ) {
    function get_temp_dir() { return rtrim( sys_get_temp_dir(), '/' ) . '/'; }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $dir ) { return is_dir( $dir ) || @mkdir( $dir, 0755, true ); }
}
