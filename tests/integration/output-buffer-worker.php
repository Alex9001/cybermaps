<?php
declare(strict_types=1);

// Disposable subprocess only: bootstrap is supplied by the unit/native parent.
$bootstrap = $argv[1];
$scenario = $argv[2];
$buffer_mode = $argv[3];
$request_method = $argv[4];
$origin = $argv[5] ?? 'https://example.com';
$_SERVER['HTTP_HOST'] = (string) parse_url( $origin, PHP_URL_HOST );
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
$_SERVER['REQUEST_URI'] = '/sitemap-posts-post-1.xml';
$_SERVER['REQUEST_METHOD'] = $request_method;
require $bootstrap;

// Core normally flushes buffers on shutdown. Observe the handler's ownership
// before PHP's final flush instead, without changing production shutdown hooks.
if ( ! defined( 'CYBERMAPS_PHPUNIT' ) ) { remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 ); }
add_filter( 'pre_http_request', static fn() => new WP_Error( 'fixture_http_blocked', 'Disposable buffer fixture.' ) );
if ( defined( 'CYBERMAPS_PHPUNIT' ) ) {
	$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_caching' => '1', 'static_engine_mode' => 'off' ) );
	$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
	$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'public' => true ) );
	$GLOBALS['cybermaps_mock_status_headers'] = array();
}
\Cybermaps\Core\ConfigurationStore::reset_memo();
\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
\Cybermaps\Core\CacheManager::reset_runtime();

$orchestrator = new class( $scenario ) extends \Cybermaps\Sitemap\Orchestrator {
	public function __construct( private string $scenario ) { parent::__construct(); }
	public function generate_xml( string $type, int $page, ?array $preloaded_urls = null ) {
		if ( 'unavailable_render' === $this->scenario ) { \Cybermaps\Core\CacheManager::clear_family( 'sitemap' ); }
		return parent::generate_xml( $type, $page, $preloaded_urls );
	}
};
$generation = ( new ReflectionMethod( \Cybermaps\Sitemap\Orchestrator::class, 'begin_publication' ) )->invoke( $orchestrator );
$provider_id = \Cybermaps\Sitemap\ProviderIdentity::post_type( 'post' );
$provider = new class( $scenario, $origin ) implements \Cybermaps\Sitemap\ProviderInterface {
	public function __construct( private string $scenario, private string $origin ) {}
	public function get_count(): int { return 1; }
	public function get_lastmod(): string { return ''; }
	public function get_urls( int $page ): array { return array( array( 'loc' => 'late_404' === $this->scenario ? 'javascript:bad' : $this->origin . '/buffer-fixture/' ) ); }
};
( new ReflectionProperty( \Cybermaps\Sitemap\Orchestrator::class, 'providers' ) )->setValue( $orchestrator, array( $provider_id => $provider ) );
$cache_key = ( new ReflectionMethod( \Cybermaps\Sitemap\Orchestrator::class, 'get_response_cache_key' ) )->invoke( $orchestrator, $provider_id, 1 );
// Isolate this worker from another worker's derived response/occupancy.
delete_option( \Cybermaps\Sitemap\PageOccupancyManifest::MANIFEST_OPTION );
if ( in_array( $scenario, array( 'cached', 'not_modified' ), true ) ) {
	\Cybermaps\Core\CacheManager::set_if_current( $cache_key, '<urlset><url><loc>' . $origin . '/cached-fixture/</loc></url></urlset>', 60, 'sitemap', $generation );
}
if ( 'not_modified' === $scenario ) { $_SERVER['HTTP_IF_NONE_MATCH'] = '*'; }
if ( 'unavailable_headers' === $scenario ) {
	$invalidate = static function ( $value ) {
		\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		return $value;
	};
	if ( defined( 'CYBERMAPS_PHPUNIT' ) ) { $GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_final_content_encoding'][] = $invalidate; }
	else { add_filter( 'cybermaps_publication_final_content_encoding', $invalidate ); }
}
$phases = array();
if ( 'nested' === $buffer_mode ) {
	ob_start( static function ( string $body, int $phase ) use ( &$phases ): string { $phases[] = $phase; return ''; } );
	echo 'outer-prefix|';
}
ob_start( static function ( string $body, int $phase ) use ( &$phases ): string { $phases[] = $phase; return ''; }, 0, 'fixed' === $buffer_mode ? 0 : PHP_OUTPUT_HANDLER_STDFLAGS );
echo 'caller-prefix|';
$before = array( 'level' => ob_get_level(), 'handlers' => ob_list_handlers() );
register_shutdown_function( static function () use ( $before, &$phases, $scenario, $buffer_mode, $request_method ): void {
	$body = (string) ob_get_contents();
	$statuses = $GLOBALS['cybermaps_mock_status_headers'] ?? array();
	$status = $statuses ? end( $statuses ) : ( http_response_code() ?: 200 );
	$result = array( 'scenario' => $scenario, 'buffer_mode' => $buffer_mode, 'method' => $request_method, 'status' => $status,
		'before' => $before, 'after' => array( 'level' => ob_get_level(), 'handlers' => ob_list_handlers() ),
		'callback_phases_before_shutdown' => $phases, 'prefix_preserved' => str_starts_with( $body, 'caller-prefix|' ),
		'body' => substr( $body, strlen( 'caller-prefix|' ) ) );
	fwrite( STDERR, json_encode( $result, JSON_THROW_ON_ERROR ) );
} );
$type = 'early_404' === $scenario ? 'missing-provider' : $provider_id;
$query_vars = 'unrelated' === $scenario ? array( 'cybermaps_sitemap' => '' ) : array( 'cybermaps_sitemap' => $type, 'cybermaps_page' => 1 );
$orchestrator->handle_sitemap_request( (object) array( 'query_vars' => $query_vars ) );
exit;
