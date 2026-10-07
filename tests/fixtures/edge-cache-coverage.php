<?php
declare(strict_types=1);

function wp_remote_request( string $url, array $args ): array {
	$GLOBALS['coverage_http_requests'][] = array( $url, $args['method'] );
	return array( 'response' => array( 'code' => $GLOBALS['coverage_http_status'] ) );
}

require dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/mocks/configuration-database.php';
$GLOBALS['wpdb'] = new CybermapsConfigurationDatabase();

$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_enabled'] = array( static fn(): bool => true );
$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_url'] = array( static fn(): string => home_url( '/' ) );
$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_token'] = array( static fn(): string => 'fixture-secret' );
$summaries = array();
foreach ( array( 'empty', 'overflow', 'partial', 'complete', 'transient' ) as $scenario ) {
	delete_option( 'cybermaps_edge_cache_pending_static' );
	delete_option( 'cybermaps_edge_cache_delivery_status' );
	wp_clear_scheduled_hook( 'cybermaps_edge_cache_retry' );
	$GLOBALS['coverage_http_requests'] = array();
	$GLOBALS['coverage_http_status'] = 'transient' === $scenario ? 503 : 200;
	$count = match ( $scenario ) { 'empty' => 0, 'overflow' => 60, default => 1 };
	$urls = array();
	for ( $index = 0; $index < $count; ++$index ) {
		$urls[] = home_url( '/fixture-' . $index );
	}
	$coordinator = new \Cybermaps\Integration\EdgeCache\Coordinator();
	$coordinator->invalidate( 'chunks', $urls, true, in_array( $scenario, array( 'overflow', 'complete' ), true ) );
	$coordinator->on_static_sync_complete( array( 'status' => 'complete' ) );
	$summary = $coordinator->get_status()[0];
	$summary['http_request_count'] = count( $GLOBALS['coverage_http_requests'] );
	$summary['pending_count'] = count( get_option( 'cybermaps_edge_cache_pending_static', array() ) );
	$summary['retry_scheduled'] = false !== wp_next_scheduled( 'cybermaps_edge_cache_retry' );
	$summary['rest'] = ( new \Cybermaps\Core\RestAPI() )->get_status()->get_data()['edge_invalidation'][0];
	$summaries[ $scenario ] = $summary;
}
$GLOBALS['coverage_http_status'] = 200;
$GLOBALS['coverage_http_requests'] = array();
$urls = array_map( static fn( int $id ): string => home_url( '/direct-' . $id ), range( 1, 60 ) );
$summaries['direct_overflow'] = ( new \Cybermaps\Integration\EdgeCache\VarnishAdapter() )->purge( array( 'urls' => $urls ) );
$summaries['direct_overflow']['http_request_count'] = count( $GLOBALS['coverage_http_requests'] );
echo json_encode( $summaries );
