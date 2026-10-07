<?php
/**
 * Exercise native WordPress REST serialization without mutating site content/options.
 * Usage: php tests/integration/rest-representation.php DISPOSABLE_WP/wp-load.php [PLUGIN_SOURCE]
 * Isolated native classes: CYBERMAPS_NATIVE_WP_ROOT=/local/wordpress php ... --isolated
 */
declare(strict_types=1);
$isolated = '--isolated' === ( $argv[1] ?? '' );
if ( 'cli' !== PHP_SAPI ) { exit; }
$bootstrapped = defined( 'CYBERMAPS_DISPOSABLE_REST_FIXTURE' ) && true === CYBERMAPS_DISPOSABLE_REST_FIXTURE;
if ( $bootstrapped ) {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'CYBERMAPS_VERSION' ) || ! class_exists( 'WP_REST_Server' ) ) {
		throw new RuntimeException( 'The disposable fixture requires WordPress and the candidate plugin loaded by WP-CLI.' );
	}
} elseif ( $isolated ) {
	require dirname( __DIR__ ) . '/fixtures/rest-native-isolated-bootstrap.php';
} else {
	$bootstrap = realpath( $argv[1] ?? '' );
	if ( false === $bootstrap || ! str_starts_with( $bootstrap, sys_get_temp_dir() . '/' ) ) { throw new RuntimeException( 'Use a disposable WordPress installation under the configured temporary directory.' ); }
	require $bootstrap;
	require_once ( $argv[2] ?? dirname( __DIR__, 2 ) ) . '/cybermaps.php';
}

/** Only dispatch data and header transport are controlled; serving/encoding is native. */
class CybermapsRepresentationServer extends WP_REST_Server {
	public array $captured_headers = array();
	public int $captured_status = 200;
	public function check_authentication() { return null; }
	public function dispatch( $request ) {
		if ( '/cybermaps/v1/embedded-fixture' === $request->get_route() ) { return new WP_REST_Response( array( 'embedded' => true ) ); }
		$response = new WP_REST_Response( array( 'label' => 'café / public', 'nested' => array( 'answer' => 42 ) ) );
		$response->add_link( 'fixture', rest_url( 'cybermaps/v1/embedded-fixture' ), array( 'embeddable' => true ) );
		return $response;
	}
	public function send_header( $key, $value ) { $this->captured_headers[ strtolower( $key ) ] = $value; }
	protected function set_status( $code ) { $this->captured_status = $code; }
}
function cybermaps_representation_assert( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$api = new Cybermaps\Core\RestAPI();
add_filter( 'rest_post_dispatch', array( $api, 'add_public_cache_headers' ), 10, 3 );
add_filter( 'rest_post_dispatch', 'rest_filter_response_fields', 10, 3 );
add_filter( 'rest_json_encode_options', static fn( int $options, WP_REST_Request $request ): int => $request->has_param( 'fixture_encoding' ) ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : $options, 99, 2 );
add_filter( 'rest_pre_echo_response', static function ( $data, $server, $request ) {
	if ( $request->has_param( 'fixture_echo' ) ) { $data['late_filter'] = true; }
	return $data;
}, 99, 3 );
$cases = array(
	'compact' => array(), 'pretty' => array( '_pretty' => '1' ), 'fields' => array( '_fields' => 'nested.answer' ),
	'embed' => array( '_embed' => 'fixture' ), 'envelope' => array( '_envelope' => '1' ),
	'jsonp' => array( '_jsonp' => 'cybermapsFixture' ), 'encoding-filter' => array( 'fixture_encoding' => '1' ),
	'echo-filter' => array( 'fixture_echo' => '1' ), 'conditional' => array(), 'head' => array(),
);
$results = array();
foreach ( $cases as $case => $query ) {
	$server = new CybermapsRepresentationServer();
	$GLOBALS['wp_rest_server'] = $server;
	$_GET = $query; $_POST = array(); $_FILES = array();
	$_SERVER['REQUEST_METHOD'] = 'head' === $case ? 'HEAD' : 'GET';
	unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
	if ( 'conditional' === $case ) { $_SERVER['HTTP_IF_NONE_MATCH'] = '*'; }
	ob_start(); $server->serve_request( '/cybermaps/v1/discovery' ); $body = ob_get_clean();
	cybermaps_representation_assert( 200 === $server->captured_status, $case . ': native status changed.' );
	foreach ( array( 'etag', 'content-digest', 'repr-digest' ) as $name ) { cybermaps_representation_assert( ! isset( $server->captured_headers[ $name ] ), $case . ': premature ' . $name ); }
	$json = $body;
	if ( 'jsonp' === $case ) {
		cybermaps_representation_assert( str_starts_with( $body, '/**/cybermapsFixture(' ) && str_ends_with( $body, ')' ), 'JSONP wrapping lost.' );
		$json = substr( $body, strlen( '/**/cybermapsFixture(' ), -1 );
	}
	$data = 'head' === $case ? null : json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	if ( 'head' === $case ) { cybermaps_representation_assert( '' === $body, 'HEAD emitted a body.' ); }
	if ( 'pretty' === $case ) { cybermaps_representation_assert( str_contains( $body, "\n" ), 'Native pretty encoding lost.' ); }
	if ( 'fields' === $case ) { cybermaps_representation_assert( ! isset( $data['label'] ) && 42 === $data['nested']['answer'], 'Native field filtering lost.' ); }
	if ( 'embed' === $case ) { cybermaps_representation_assert( true === ( $data['_embedded']['fixture'][0]['embedded'] ?? null ), 'Native embedding lost.' ); }
	if ( 'envelope' === $case ) {
		cybermaps_representation_assert( 200 === $data['status'] && isset( $data['body']['label'] ), 'Native envelope lost.' );
		foreach ( array( 'ETag', 'Content-Digest', 'Repr-Digest' ) as $name ) { cybermaps_representation_assert( ! isset( $data['headers'][ $name ] ), 'Envelope retained premature validator.' ); }
	} else { cybermaps_representation_assert( 'public, max-age=300, must-revalidate' === $server->captured_headers['cache-control'], 'Public cache policy lost.' ); }
	if ( 'encoding-filter' === $case ) { cybermaps_representation_assert( str_contains( $body, 'café / public' ), 'Native encoding filter lost.' ); }
	if ( 'echo-filter' === $case ) { cybermaps_representation_assert( true === $data['late_filter'], 'Native pre-echo filter lost.' ); }
	$results[] = array( 'case' => $case, 'status' => $server->captured_status, 'bytes' => strlen( $body ), 'premature_validators' => false );
}
echo json_encode( array( 'mode' => $isolated ? 'native-classes-isolated-site-dependencies' : 'wordpress-bootstrap', 'cases' => $results ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";
