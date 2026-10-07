<?php
declare(strict_types=1);

// Ordinary failure adapter: no WordPress installation or database is loaded.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

final class ClearReviewReply extends RuntimeException {
    public function __construct( public array $reply, public int $status ) {
        parent::__construct( 'Captured JSON response' );
    }
}
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function maybe_serialize( mixed $value ): string { return is_array( $value ) ? serialize( $value ) : (string) $value; }
function wp_cache_delete( string $key, string $group ): bool {
    if ( 'cybermaps_debug_entries' === $key && 'after_verification_event' === $GLOBALS['review_case'] ) {
        $GLOBALS['review_options'][$key] = array( review_entry() );
    }
    return true;
}
function review_entry(): array { return array( 'time' => gmdate( 'c' ), 'level' => 'info', 'event' => 'retained.event', 'context' => array() ); }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['review_options'][$name] ?? $default; }
function update_option( string $name, mixed $value, mixed $autoload = null ): bool { $GLOBALS['review_options'][$name] = $value; return true; }
function delete_option( string $name ): bool {
    ++$GLOBALS['review_delete_calls'];
    if ( $GLOBALS['review_delete_fails'] ) { return false; }
    unset( $GLOBALS['review_options'][$name] );
    return true;
}
function check_ajax_referer( string $action, string $field ): void {
    if ( ! $GLOBALS['review_nonce_valid'] ) { throw new ClearReviewReply( array( 'success' => false ), 403 ); }
}
function current_user_can( string $capability ): bool { return $GLOBALS['review_capability']; }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( mixed $value ): string { return is_scalar( $value ) ? (string) $value : ''; }
function wp_send_json_success( array $data, int $status = 200 ): never { throw new ClearReviewReply( array( 'success' => true, 'data' => $data ), $status ); }
function wp_send_json_error( array $data, int $status = 400 ): never { throw new ClearReviewReply( array( 'success' => false, 'data' => $data ), $status ); }

final class ClearReviewDatabase {
    public string $options = 'wp_options';
    public string $last_error = '';
    public array $last_result = array();
    public function prepare( string $sql, ...$args ): string {
        if ( array( 'wp_options', 'cybermaps_debug_entries' ) !== $args ) { throw new LogicException( 'Unexpected option read' ); }
        return $sql;
    }
    public function query( string $sql ): never { throw new LogicException( 'Unexpected raw mutation' ); }
    public function get_var( string $sql ): ?string {
        if ( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1' !== $sql ) { throw new LogicException( 'Unexpected read SQL' ); }
        $this->last_error = '';
        $this->last_result = array();
        if ( 'read_failure' === $GLOBALS['review_case'] ) { $this->last_error = 'private SQL error'; return null; }
        if ( 'concurrent_event' === $GLOBALS['review_case'] ) { $GLOBALS['review_options']['cybermaps_debug_entries'] = array( review_entry() ); }
        if ( ! array_key_exists( 'cybermaps_debug_entries', $GLOBALS['review_options'] ) ) { return null; }
        $raw = maybe_serialize( $GLOBALS['review_options']['cybermaps_debug_entries'] );
        $this->last_result = array( (object) array( 'option_value' => $raw ) );
        return '' === $raw ? null : $raw;
    }
}
$cases = array(
    'failed_delete' => array( true, true, true, 'POST' ),
    'successful_delete' => array( false, true, true, 'POST' ),
    'already_empty' => array( true, true, true, 'POST' ),
    'already_absent' => array( true, true, true, 'POST' ),
    'read_failure' => array( false, true, true, 'POST' ),
    'unsupported_database' => array( false, true, true, 'POST' ),
    'malformed_retained' => array( true, true, true, 'POST' ),
    'concurrent_event' => array( false, true, true, 'POST' ),
    'after_verification_event' => array( false, true, true, 'POST' ),
    'forbidden' => array( false, false, true, 'POST' ),
    'invalid_nonce' => array( false, true, false, 'POST' ),
    'wrong_method' => array( false, true, true, 'GET' ),
);
$results = array();
foreach ( $cases as $name => [ $fails, $capability, $nonce, $method ] ) {
    $GLOBALS['review_options'] = array(
        'cybermaps_debug_entries' => 'already_empty' === $name ? array() : array(
            array( 'time' => gmdate( 'c' ), 'level' => 'info', 'event' => 'retained.event', 'context' => array() ),
        ),
    );
    if ( 'already_absent' === $name ) { unset( $GLOBALS['review_options']['cybermaps_debug_entries'] ); }
    if ( 'malformed_retained' === $name ) { $GLOBALS['review_options']['cybermaps_debug_entries'] = 'retained-malformed-data'; }
    $GLOBALS['review_case'] = $name;
    $GLOBALS['wpdb'] = 'unsupported_database' === $name ? null : new ClearReviewDatabase();
    $GLOBALS['review_delete_fails'] = $fails;
    $GLOBALS['review_capability'] = $capability;
    $GLOBALS['review_nonce_valid'] = $nonce;
    $GLOBALS['review_delete_calls'] = 0;
    $_SERVER['REQUEST_METHOD'] = $method;
    try {
        ( new Cybermaps\Admin\SystemStatus() )->ajax_clear();
    } catch ( ClearReviewReply $reply ) {
        $results[$name] = array(
            'status' => $reply->status,
            'reply' => $reply->reply,
            'retained_entries' => is_array( $GLOBALS['review_options']['cybermaps_debug_entries'] ?? null ) ? count( $GLOBALS['review_options']['cybermaps_debug_entries'] ) : 0,
            'retained_value' => $GLOBALS['review_options']['cybermaps_debug_entries'] ?? null,
            'delete_calls' => $GLOBALS['review_delete_calls'],
        );
    }
}
echo json_encode( array( 'adapter_only' => true, 'cases' => $results ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";
