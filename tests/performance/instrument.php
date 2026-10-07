<?php
declare(strict_types=1);

// Disposable MU-plugin: request instrumentation is never shipped with Core.
$GLOBALS['cybermaps_perf'] = array( 'query_seconds' => 0.0, 'recorded_queries' => 0, 'sql_bytes' => 0, 'slow_queries' => array() );
add_filter(
	'log_query_custom_data',
	static function ( $data, $query, $query_time ) {
		global $wpdb;
		$metrics = &$GLOBALS['cybermaps_perf'];
		++$metrics['recorded_queries'];
		$metrics['sql_bytes'] += strlen( $query );
		$metrics['query_seconds'] += (float) $query_time;
		if ( preg_match( '/^\s*SELECT\b/i', $query ) && strlen( $query ) <= 32768 ) {
			$metrics['slow_queries'][] = array( 'sql' => $query, 'seconds' => (float) $query_time );
			usort( $metrics['slow_queries'], static fn( $a, $b ) => $b['seconds'] <=> $a['seconds'] );
			$metrics['slow_queries'] = array_slice( $metrics['slow_queries'], 0, 12 );
		}
		// SAVEQUERIES would otherwise retain millions of audit write statements.
		$wpdb->queries = array();
		return $data;
	},
	10,
	3
);
add_action( 'init', static function () {
	register_post_type( 'perf_resource', array( 'public' => true, 'rewrite' => array( 'slug' => 'resource' ), 'supports' => array( 'title', 'editor', 'excerpt', 'author' ) ) );
}, 0 );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'perf_network_blocked', 'Outbound HTTP is disabled in this disposable performance fixture.' ) );

function cybermaps_perf_snapshot(): array {
	global $wpdb;
	return array_merge( $GLOBALS['cybermaps_perf'], array(
		'queries' => $wpdb->num_queries,
		'peak_memory_bytes' => memory_get_peak_usage( true ),
		'php_seconds' => microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'],
		'php' => PHP_VERSION,
		'wordpress' => get_bloginfo( 'version' ),
		'plugin' => defined( 'CYBERMAPS_VERSION' ) ? CYBERMAPS_VERSION : null,
	) );
}

if ( isset( $_SERVER['HTTP_X_CYBERMAPS_PERF_ID'] ) ) {
	$id = preg_replace( '/[^a-zA-Z0-9_.-]/', '', substr( $_SERVER['HTTP_X_CYBERMAPS_PERF_ID'], 0, 128 ) );
	// Isolated fixture only: independent client identities prevent rate-limit
	// responses from being mistaken for the cost of rendering a publication.
	$_SERVER['REMOTE_ADDR'] = '2001:db8::' . substr( hash( 'sha256', $id ), 0, 4 ) . ':' . substr( hash( 'sha256', $id ), 4, 4 );
	register_shutdown_function( static function () use ( $id ) {
		$metrics = cybermaps_perf_snapshot();
		$metrics['id'] = $id;
		$metrics['status'] = http_response_code();
		$last = error_get_last();
		$metrics['fatal'] = $last && in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ? $last : null;
		error_log( json_encode( $metrics, JSON_UNESCAPED_SLASHES ) . "\n", 3, '/evidence/http-metrics.jsonl' );
	} );
}
