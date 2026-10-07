<?php
declare(strict_types=1);

namespace Cybermaps\Admin {
	function wp_unslash( string $value ): string { return stripslashes( $value ); }
	function sanitize_text_field( mixed $value ): string { $GLOBALS['identity_input_events'][] = 'payload'; return \sanitize_text_field( $value ); }
	function sanitize_key( mixed $value ): string { $GLOBALS['identity_input_events'][] = 'payload'; return \sanitize_key( $value ); }
}
namespace {
	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
	function wp_send_json_error( array $data, int $status = 200 ): never { echo json_encode( array( 'status' => $status, 'events' => $GLOBALS['identity_input_events'], 'queries' => $GLOBALS['identity_input_queries'] ) ); exit; }
	function wp_send_json_success( array $data ): never { wp_send_json_error( $data, 200 ); }
	function check_ajax_referer( string $action, string $key ): void {
		$GLOBALS['identity_input_events'][] = 'nonce';
		if ( 'nonce' === $GLOBALS['identity_input_case'] ) { wp_send_json_error( array(), 403 ); }
	}
	require dirname( __DIR__, 2 ) . '/bootstrap.php';
	$GLOBALS['identity_input_events'] = array();
	$GLOBALS['identity_input_queries'] = 0;
	$GLOBALS['identity_input_case'] = $argv[1];
	$GLOBALS['cybermaps_mock_current_user_capabilities'] = 'capability' === $argv[1] ? array() : array( 'manage_options' );
	$GLOBALS['wpdb'] = new class {
		public string $posts = 'wp_posts'; public string $last_error = '';
		public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
		public function prepare( string $query, mixed ...$values ): string { return $query; }
		public function get_results( string $query, string $mode ): array { ++$GLOBALS['identity_input_queries']; return array( array( 'ID' => 7, 'post_title' => 'Page' ) ); }
	};
	$_SERVER['REQUEST_METHOD'] = match ( $argv[1] ) { 'method-space' => ' POST ', 'method-escape' => 'P\\OST', 'method-array' => array( 'POST' ), default => 'POST' };
	$_POST = array( 'query' => match ( $argv[1] ) { 'raw-query-limit' => 'P' . str_repeat( '\\', 200 ), 'query-max' => str_repeat( 'P', 200 ), 'query-array' => array( 'Page' ), default => 'Page' }, 'cursor' => match ( $argv[1] ) { 'cursor-max' => str_repeat( '9', 18 ), 'cursor-limit' => str_repeat( '9', 19 ), 'cursor-percent' => '%31', 'cursor-punctuation' => '<b>1</b>', 'cursor-escape' => '1\\', 'cursor-array' => array( '1' ), default => '0' } );
	( new \Cybermaps\Admin\IdentityPageSelector() )->ajax_search();
	throw new RuntimeException( 'AJAX handler must terminate.' );
}
