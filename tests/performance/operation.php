<?php
declare(strict_types=1);

$operation = (string) ( $args[0] ?? '' );
global $wpdb;

if ( 'reset_audit_lease' === $operation ) {
	$option = \Cybermaps\Audit\AuditRunRepository::RUN_LOCK_OPTION;
	$previous = get_option( $option, null );
	$deleted = delete_option( $option );
	echo json_encode( array( 'option' => $option, 'previous' => $previous, 'deleted' => $deleted ) ) . "\n";
	return;
}
if ( 'limits_probe' === $operation ) {
	$limits = file_get_contents( '/proc/self/limits' );
	preg_match( '/Max address space\s+(\d+)\s+(\d+)/', $limits, $matches );
	echo json_encode( array( 'address_space_bytes' => (int) ( $matches[1] ?? 0 ), 'limits' => $limits, 'status' => file_get_contents( '/proc/self/status' ) ) ) . "\n";
	return;
}
if ( 'configure' === $operation ) {
	$settings = (array) get_option( 'cybermaps_settings', array() );
	foreach ( array( 'enable_discovery_hub', 'enable_llms_full', 'enable_llms_tldr', 'enable_rag_chunks', 'include_posts', 'include_pages' ) as $key ) {
		$settings[ $key ] = '1';
	}
	$settings['enable_caching'] = (string) ( $args[1] ?? '1' );
	$settings['static_engine_mode'] = 'off';
	$settings['enable_indexnow'] = '0';
	$settings['enable_websub'] = '0';
	$settings['enable_analytics'] = '0';
	$settings['ai_sitemap_types'] = array( 'post', 'page', 'perf_resource' );
	$settings['ai_sitemap_limit'] = 100;
	update_option( 'cybermaps_settings', $settings, false );
	update_option( 'blog_public', '1' );
	update_option( 'permalink_structure', '/%postname%/' );
	flush_rewrite_rules( false );
	echo json_encode( $settings ) . "\n";
	return;
}
if ( 'fixture_reset' === $operation ) {
	delete_option( \Cybermaps\Sitemap\PageOccupancyManifest::MANIFEST_OPTION );
	\Cybermaps\Discovery\StaticBridge::get_instance()->invalidate();
	return;
}
if ( 'clear' === $operation ) {
	foreach ( array( 'admin', 'analytics', 'chunks', 'discovery', 'legacy', 'schema', 'settings', 'sitemap', 'translations' ) as $family ) {
		\Cybermaps\Core\CacheManager::clear_family( $family );
	}
	wp_cache_flush();
	return;
}
if ( 'cache_probe' === $operation ) {
	echo json_encode( array( 'external' => (bool) wp_using_ext_object_cache(), 'value' => wp_cache_get( 'probe', 'perf' ) ) ) . "\n";
	wp_cache_set( 'probe', 'persisted', 'perf', 60 );
	return;
}
if ( 'inventory' === $operation ) {
	$selector = new \Cybermaps\Discovery\AIContentSelector();
	$posts = $selector->get_posts();
	$orchestrator = new \Cybermaps\Sitemap\Orchestrator();
	echo json_encode( array(
		'counts' => $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) AS n FROM {$wpdb->posts} GROUP BY post_type, post_status", ARRAY_A ),
		'entries' => $orchestrator->get_internal_sitemap_entries(),
		'rag_id' => isset( $posts[0] ) ? (int) $posts[0]->ID : 0,
		'per_page' => $orchestrator->get_per_page(),
		'endpoints' => \Cybermaps\Core\EndpointRegistry::get_instance()->all(),
	) ) . "\n";
	return;
}

$GLOBALS['cybermaps_perf'] = array( 'query_seconds' => 0.0, 'recorded_queries' => 0, 'sql_bytes' => 0, 'slow_queries' => array() );
$before_queries = $wpdb->num_queries;
$start = hrtime( true );
$result = null;
$error = null;
try {
	switch ( $operation ) {
		case 'ownership':
			$lease = new \Cybermaps\Core\OptionLeaseLock( 'cybermaps_perf_ownership_lock', 120, 15, true );
			if ( ! $lease->acquire() ) {
				throw new RuntimeException( 'Ownership fixture lock unavailable' );
			}
			$store = new \Cybermaps\Discovery\StaticOwnershipStore( $lease );
			$store->migrate_if_needed();
			$count = (int) ( $args[1] ?? 1000 );
			for ( $i = 0; $i < $count; ++$i ) {
				if ( ! $store->set_hash( '/perf-ownership-' . $count . '-' . $i . '.txt', md5( (string) $i ), 1, true ) ) {
					throw new RuntimeException( 'Ownership write failed at ' . $i );
				}
			}
			$lease->release();
			$result = array( 'written' => $count );
			break;
		case 'queue_prepare':
			$repository = new \Cybermaps\Discovery\IndexNowQueueRepository();
			$result = $repository->enqueue( array( home_url( '/worker-contention/' ) ) );
			break;
		case 'queue_worker':
			$repository = new \Cybermaps\Discovery\IndexNowQueueRepository();
			$claim = $repository->claim_due( 1000, 60, true );
			if ( count( $claim['urls'] ) > 0 ) {
				echo "LOCK_ACQUIRED\n";
				flush();
			}
			usleep( (int) ( $args[1] ?? 0 ) * 1000 );
			$result = array( 'claimed' => count( $claim['urls'] ), 'acknowledged' => $repository->acknowledge( $claim['urls'], $claim['token'] ) );
			break;
		case 'lock':
			$lock = new \Cybermaps\Core\DatabaseSessionLock( 'performance-worker-contention' );
			$acquired = $lock->acquire();
			$result = array( 'acquired' => $acquired, 'maintained' => $acquired && $lock->maintain(), 'database' => $wpdb->get_var( 'SELECT VERSION()' ) );
			if ( $acquired ) {
				echo "LOCK_ACQUIRED\n";
				flush();
				usleep( (int) ( $args[1] ?? 0 ) * 1000 );
				$lock->release();
			}
			break;
		case 'audit':
			$service = new \Cybermaps\Audit\ContentAuditService();
			$id = $service->run();
			$result = $service->get_run_for_display( $id, 5 );
			break;
		case 'links':
			$result = ( new \Cybermaps\Audit\InternalLinkAnalyzer() )->analyze( array( 'post', 'page', 'perf_resource' ), static function () { wp_cache_flush_runtime(); } );
			$result = array( 'analysis' => $result['analysis'] ?? null, 'measured_resources' => count( $result['measurements'] ?? array() ) );
			break;
		case 'audit_batch':
			foreach ( ( new \Cybermaps\Audit\PublishedPostSource() )->batches( array( 'post', 'page', 'perf_resource' ) ) as $posts ) {
				$result = array( 'count' => count( $posts ), 'first' => $posts[0]->ID ?? null );
				break;
			}
			break;
		case 'static_sync':
			$settings = (array) get_option( 'cybermaps_settings' );
			$settings['static_engine_mode'] = 'all';
			update_option( 'cybermaps_settings', $settings, false );
			\Cybermaps\Core\ConfigurationStore::reset_memo();
			$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
			$result = array( 'report' => $bridge->sync_all(), 'state' => $bridge->get_sync_state() );
			break;
		case 'queue':
			$repository = new \Cybermaps\Discovery\IndexNowQueueRepository();
			$urls = array();
			for ( $i = 1; $i <= 1000; ++$i ) {
				$urls[] = home_url( '/perf-queue-' . $i . '/' );
			}
			$enqueued = $repository->enqueue( $urls );
			$claim = $repository->claim_due( 1000 );
			$result = array( 'enqueue' => $enqueued, 'claimed' => count( $claim['urls'] ), 'acknowledged' => $repository->acknowledge( $claim['urls'], $claim['token'] ), 'health' => $repository->health() );
			break;
		default:
			throw new InvalidArgumentException( 'Unknown benchmark operation: ' . $operation );
	}
} catch ( Throwable $caught ) {
	$error = array( 'class' => get_class( $caught ), 'message' => $caught->getMessage() );
}
$metrics = cybermaps_perf_snapshot();
$metrics['operation_seconds'] = ( hrtime( true ) - $start ) / 1e9;
$metrics['operation_queries'] = $wpdb->num_queries - $before_queries;
$metrics['result'] = $result;
$metrics['error'] = $error;
echo json_encode( $metrics, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
