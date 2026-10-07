<?php
/** Isolated process for real terminating publication paths with captured PHP headers. */
declare(strict_types=1);
namespace Cybermaps\Sitemap {
	function header( string $value, bool $replace = true ): void { \cybermaps_fixture_header( $value, $replace ); }
}
namespace Cybermaps\Discovery {
	function header( string $value, bool $replace = true ): void { \cybermaps_fixture_header( $value, $replace ); }
}
namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	require dirname( __DIR__ ) . '/bootstrap.php';
	function cybermaps_fixture_header( string $value, bool $replace ): void {
		[ $name, $value ] = explode( ':', $value, 2 );
		$GLOBALS['fixture_headers'][ strtolower( $name ) ] = trim( $value );
	}
	$_SERVER['REQUEST_METHOD'] = $argv[2];
	$_SERVER['REQUEST_URI'] = '/sitemap_index.xml';
	$GLOBALS['fixture_headers'] = array();
	$GLOBALS['cybermaps_mock_status_headers'] = array( 200 );
	update_option( 'cybermaps_settings', array( 'static_engine_mode' => 'off' ) );
	register_shutdown_function( static function (): void {
		fwrite( STDERR, "\nFIXTURE:" . json_encode( array( 'headers' => $GLOBALS['fixture_headers'], 'status' => end( $GLOBALS['cybermaps_mock_status_headers'] ) ?: 200 ) ) );
	} );
	if ( 'throttle' === $argv[1] ) {
		( new \ReflectionMethod( \Cybermaps\Discovery\Throttler::class, 'throttle_response' ) )->invoke( new \Cybermaps\Discovery\Throttler() );
	}
	if ( 'router' === $argv[1] ) {
		( new \ReflectionMethod( \Cybermaps\Discovery\PublicationRouter::class, 'respond_handler_failure' ) )->invoke( null, 'fixture' );
	}
	if ( 'router-size' === $argv[1] ) {
		$_SERVER['REQUEST_URI'] = '/ai.json';
		update_option( 'cybermaps_settings', array( 'static_engine_mode' => 'off', 'enable_discovery_hub' => '1' ) );
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_handler_instance'] = array( static fn(): object => new class {
			public function handle(): never { throw new \Cybermaps\Discovery\PublicationSizeLimitException( 'ai.json', 1 ); }
		} );
		( new \Cybermaps\Discovery\PublicationRouter( new \Cybermaps\Discovery\ADP() ) )->handle();
		throw new \RuntimeException( 'Size exception route was not handled.' );
	}
	$xml = '<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc></url></urlset>';
	if ( str_contains( $argv[1], 'conditional' ) ) { $_SERVER['HTTP_IF_NONE_MATCH'] = \Cybermaps\Discovery\PublicationCachePolicy::etag( $xml ); }
	if ( str_contains( $argv[1], 'diagnostic' ) ) {
		$_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC_CHALLENGE'] = str_repeat( 'a', 32 );
		$_GET['cybermaps_php_path_probe'] = str_contains( $argv[1], 'mismatch' ) ? str_repeat( 'b', 32 ) : str_repeat( 'a', 32 );
	}
	$orchestrator = new \Cybermaps\Sitemap\Orchestrator();
	( new \ReflectionMethod( $orchestrator, 'begin_publication' ) )->invoke( $orchestrator );
	( new \ReflectionMethod( $orchestrator, 'begin_sitemap_response' ) )->invoke( $orchestrator );
	if ( 'stale-generation' === $argv[1] ) { \Cybermaps\Core\CacheManager::clear_family( 'sitemap' ); }
	try {
		( new \ReflectionMethod( $orchestrator, 'serve_sitemap_xml' ) )->invoke( $orchestrator, $xml, 'index' );
	} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
		\Cybermaps\Discovery\PublicationRequestGuard::serve_unavailable( $error );
	}
}
