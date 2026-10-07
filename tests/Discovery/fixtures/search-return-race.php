<?php
declare(strict_types=1);

// Load real value classes when a local WordPress tree is supplied, without bootstrap.
$native_root = rtrim( (string) getenv( 'CYBERMAPS_NATIVE_WP_ROOT' ), '/' );
if ( is_file( $native_root . '/wp-includes/class-wp-error.php' ) ) {
	require $native_root . '/wp-includes/class-wp-error.php';
	require $native_root . '/wp-includes/rest-api/class-wp-rest-request.php';
} else {
	class WP_Error {
		public function __construct( private string $code, private string $message, private array $data ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
	class WP_REST_Request {
		private array $params = array();
		public function __construct( string $method, string $route ) {}
		public function set_param( string $name, mixed $value ): void { $this->params[ $name ] = $value; }
		public function get_param( string $name ): mixed { return $this->params[ $name ] ?? null; }
	}
}
require dirname( __DIR__, 2 ) . '/bootstrap.php';

cybermaps_mock_reset_cache_runtime();
$GLOBALS['wpdb'] = (object) array( 'last_error' => '' );
$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_content_hints' => '0', 'llms_included_types' => array( 'post' ) ) );
$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
$GLOBALS['cybermaps_mock_posts'] = array();
$GLOBALS['cybermaps_mock_post_meta'] = array();
foreach ( range( 1, 250 ) as $id ) {
	$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Public title', 'post_content' => 'Revoked literal secret', 'post_excerpt' => '', 'post_date_gmt' => '2026-08-01 00:00:00', 'post_modified_gmt' => '2026-08-01 00:00:00' );
	if ( $id > 1 ) { $GLOBALS['cybermaps_mock_post_meta'][ $id ]['_cybermaps_exclude_ai'] = '1'; }
}
$fired = false;
$query_failure = 'sql' === ( $argv[2] ?? '' );
$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ) use ( &$fired, $query_failure ): array {
	if ( $query_failure ) {
		$fired = true;
		$GLOBALS['wpdb']->last_error = 'Injected native SELECT failure';
		return array();
	}
	if ( 2 === ( $args['paged'] ?? 1 ) ) {
		$fired = true;
		$GLOBALS['cybermaps_mock_posts'][1]->post_status = 'private';
		\Cybermaps\Core\AtomicOptionSequence::increment( 'cybermaps_cache_generation_discovery' );
		return array();
	}
	return array_values( $GLOBALS['cybermaps_mock_posts'] );
};
if ( 'ability' === ( $argv[1] ?? '' ) ) {
	$result = ( new ReflectionMethod( \Cybermaps\Core\AbilityKernel::class, 'search' ) )->invoke( \Cybermaps\Core\AbilityKernel::get_instance(), array( 'q' => 'ordinary', 'limit' => 2 ) );
} else {
	$request = new WP_REST_Request( 'GET', '/cybermaps/v1/search' );
	$request->set_param( 'q', 'ordinary' );
	$request->set_param( 'limit', 2 );
	$result = ( new \Cybermaps\Discovery\Search() )->handle_search( $request );
}
echo json_encode( array( 'fired' => $fired, 'code' => $result->get_error_code(), 'data' => $result->get_error_data(), 'message' => $result->get_error_message() ) );
