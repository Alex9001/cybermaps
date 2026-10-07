<?php
declare(strict_types=1);

/** Isolated HTTP adapter so the optional OPTIONS API does not leak across tests. */
function wp_safe_remote_request( string $url, array $args ): array|WP_Error {
	$GLOBALS['status_protocol_options_calls'][] = array( 'url' => $url, 'args' => $args );
	if ( ! empty( $GLOBALS['status_protocol_options_fail'] ) ) {
		return new WP_Error( 'http_request_failed', 'Unsafe redirect rejected.' );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => 'Ordinary ignored response body.' );
}
function wp_remote_request( string $url, array $args ): never {
	throw new RuntimeException( 'The unsafe HTTP transport must not be called.' );
}
require dirname( __DIR__, 2 ) . '/bootstrap.php';

$status_protocol_service = new \Cybermaps\Admin\DiscoveryStatus();
$status_protocol_results = array();
foreach ( array( 200, 304 ) as $status_protocol_code ) {
	$GLOBALS['cybermaps_mock_safe_remote_get_response'] = array( 'response' => array( 'code' => $status_protocol_code ), 'body' => 'Ordinary ignored response body.' );
	$status_protocol_results[] = ( new ReflectionMethod( $status_protocol_service, 'edge_diagnostics' ) )->invoke(
		$status_protocol_service,
		array( 'url' => 'https://example.com/llms-full.txt', 'type' => 'text/markdown' ),
		array( 'response' => array( 'code' => 200 ), 'headers' => array( 'etag' => '"ordinary"' ) )
	);
}
$GLOBALS['status_protocol_options_fail'] = true;
$status_protocol_rejected = ( new ReflectionMethod( $status_protocol_service, 'probe_options_status' ) )->invoke( $status_protocol_service, 'https://example.com/redirect' );
echo json_encode( array( 'results' => $status_protocol_results, 'conditional' => $GLOBALS['cybermaps_mock_safe_remote_get_calls'], 'head' => $GLOBALS['cybermaps_mock_safe_remote_head_calls'], 'options' => $GLOBALS['status_protocol_options_calls'], 'rejected' => $status_protocol_rejected ) );
