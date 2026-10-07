<?php
/** Actual terminating router/Updates fixture with native PHP response headers. */
declare(strict_types=1);

if ( 'cli-server' !== PHP_SAPI ) {
	exit;
}
require dirname( __DIR__ ) . '/bootstrap.php';
ob_start();
register_shutdown_function( static function(): void {
	// The WP mock records status_header; bridge it to the actual HTTP status.
	http_response_code( end( $GLOBALS['cybermaps_mock_status_headers'] ) ?: 200 );
	ob_end_flush();
} );
$GLOBALS['cybermaps_mock_status_headers'] = array( 200 );
$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'static_engine_mode' => 'off', 'enable_discovery_hub' => '1' ) );
$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
if ( '/updates.json' === $_SERVER['REQUEST_URI'] ) {
	$GLOBALS['cybermaps_mock_wp_query_callback'] = static function(): array {
		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		return array();
	};
	( new \Cybermaps\Discovery\Updates() )->handle();
} else {
	$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_handler_instance'] = array( static fn(): object => new class {
		public function handle(): never {
			throw new \Cybermaps\Core\BuildUnavailableException( 'Publication changed during selection.' );
		}
	} );
	( new \Cybermaps\Discovery\PublicationRouter( new \Cybermaps\Discovery\ADP() ) )->handle();
}
throw new \RuntimeException( 'The fixture route did not terminate.' );
