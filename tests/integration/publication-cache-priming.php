<?php
declare(strict_types=1);

/** Disposable native gate: wp eval with normal require (not eval-file). */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'CYBERMAPS_VERSION' ) || '1' !== getenv( 'CYBERMAPS_PRIMING_PROBE' ) ) {
	throw new RuntimeException( 'Run explicitly in the disposable native WordPress performance fixture.' );
}
global $wpdb;

function cybermaps_priming_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$ids = array();
$created_ids = array();
$original_posts = array();
$original_meta = array();
$prior_suspension = wp_suspend_cache_addition();
$prior_error_suppression = $wpdb->suppress_errors( true );
$delete_selected = static function () use ( &$ids ): void {
	foreach ( $ids as $id ) { wp_cache_delete( $id, 'posts' ); wp_cache_delete( $id, 'post_meta' ); }
};
$result = array( 'wordpress' => get_bloginfo( 'version' ), 'external_object_cache' => wp_using_ext_object_cache() );
$failure_hook = null;
try {
	$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 201, 'fields' => 'ids', 'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) );
	$original_posts = wp_cache_get_multiple( $ids, 'posts' );
	$original_meta = wp_cache_get_multiple( $ids, 'post_meta' );
	if ( count( $ids ) < 201 ) {
		cybermaps_priming_assert( '1' === getenv( 'CYBERMAPS_PRIMING_PROBE_SEED' ), 'Insufficient posts; explicit disposable seed opt-in is required.' );
		while ( count( $ids ) < 201 ) {
			$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Cybermaps owned priming regression ' . count( $created_ids ), 'post_content' => 'Disposable publication cache regression.' ), true, false );
			cybermaps_priming_assert( is_int( $id ) && $id > 0, 'Could not create owned priming fixture post.' );
			$created_ids[] = $id;
			$ids[] = $id;
		}
	}
	$result['owned_seed_posts'] = count( $created_ids );
	$original_posts += wp_cache_get_multiple( $ids, 'posts' );
	$original_meta += wp_cache_get_multiple( $ids, 'post_meta' );
	$args = array( 'post_type' => 'post', 'post_status' => 'publish', 'post__in' => $ids, 'posts_per_page' => 201, 'orderby' => 'ID', 'order' => 'ASC', 'update_post_meta_cache' => true, 'update_post_term_cache' => false );
	wp_suspend_cache_addition( false );
	$delete_selected();
	$preserved_id = $ids[0];
	$preserved = array( '_cybermaps_probe_existing' => array( 'must survive' ) );
	wp_cache_add( $preserved_id, $preserved, 'post_meta' );
	$start = $wpdb->num_queries;
	$rows = \Cybermaps\Sitemap\PublicationQuery::posts( $args );
	foreach ( $rows as $row ) { get_post( $row->ID ); get_post_meta( $row->ID, '_cybermaps_probe_missing', true ); }
	$result['cold_queries'] = $wpdb->num_queries - $start;
	$result['selected'] = count( $rows );
	cybermaps_priming_assert( 201 === count( $rows ) && $result['cold_queries'] <= 6, 'Selected row cache reads grew per item instead of bounded metadata batches.' );
	cybermaps_priming_assert( $preserved === wp_cache_get( $preserved_id, 'post_meta' ), 'Existing metadata was overwritten.' );
	$result['existing_metadata_preserved'] = true;

	$delete_selected();
	$start = $wpdb->num_queries;
	$without_meta = \Cybermaps\Sitemap\PublicationQuery::posts( array_replace( $args, array( 'update_post_meta_cache' => false ) ) );
	$result['false_flag_queries'] = $wpdb->num_queries - $start;
	cybermaps_priming_assert( false === wp_cache_get( $ids[1], 'post_meta' ) && $result['false_flag_queries'] <= 3, 'False metadata priming flag was ignored.' );
	$result['false_flag_preserved'] = true;

	$delete_selected();
	$metadata_reads = 0;
	$failure_hook = static function ( string $sql ) use ( &$metadata_reads ): string {
		global $wpdb;
		if ( preg_match( '/^\s*SELECT\b/i', $sql ) && str_contains( $sql, $wpdb->postmeta ) ) {
			++$metadata_reads;
			if ( 2 === $metadata_reads ) { return 'SELECT * FROM cybermaps_probe_missing_metadata_table'; }
		}
		return $sql;
	};
	add_filter( 'query', $failure_hook );
	try {
		\Cybermaps\Sitemap\PublicationQuery::posts( $args );
		throw new RuntimeException( 'Failed metadata SELECT returned publishable rows.' );
	} catch ( \Cybermaps\Core\BuildUnavailableException ) {
		cybermaps_priming_assert( 2 === $metadata_reads, 'Unavailable result did not reach the injected second metadata SELECT.' );
		$result['metadata_failure_unavailable'] = true;
		$result['failed_metadata_selects'] = $metadata_reads;
	}
	remove_filter( 'query', $failure_hook ); $failure_hook = null;
	foreach ( wp_cache_get_multiple( $ids, 'post_meta' ) as $value ) { cybermaps_priming_assert( false === $value, 'A metadata failure installed a healthy-empty cache entry.' ); }
	cybermaps_priming_assert( ! wp_suspend_cache_addition(), 'Metadata failure did not restore cache-add suspension.' );
	$result['failed_metadata_cache_empty'] = true;
	$result['healthy_retry_rows'] = count( \Cybermaps\Sitemap\PublicationQuery::posts( $args ) );
	cybermaps_priming_assert( 201 === $result['healthy_retry_rows'], 'Healthy retry did not recover.' );

	$delete_selected();
	wp_suspend_cache_addition( true );
	\Cybermaps\Sitemap\PublicationQuery::posts( $args );
	cybermaps_priming_assert( wp_suspend_cache_addition() && false === wp_cache_get( $ids[1], 'post_meta' ), 'Prior cache-add suspension was overridden.' );
	$result['prior_suspension_preserved'] = true;
} finally {
	try {
		if ( null !== $failure_hook ) { remove_filter( 'query', $failure_hook ); }
		wp_suspend_cache_addition( false );
		$delete_selected();
		foreach ( $original_posts as $id => $value ) { if ( false !== $value ) { wp_cache_set( $id, $value, 'posts' ); } }
		foreach ( $original_meta as $id => $value ) { if ( false !== $value ) { wp_cache_set( $id, $value, 'post_meta' ); } }
	} finally {
		try {
			$cleanup_failed = false;
			foreach ( $created_ids as $id ) {
				try {
					wp_delete_post( $id, true );
					$cleanup_failed = null !== get_post( $id ) || $cleanup_failed;
				} catch ( Throwable ) { $cleanup_failed = true; }
			}
			cybermaps_priming_assert( ! $cleanup_failed, 'Owned priming fixture post cleanup failed.' );
		} finally {
			wp_suspend_cache_addition( $prior_suspension );
			$wpdb->suppress_errors( $prior_error_suppression );
		}
	}
}
$result['publication_priming_passed'] = true;
echo wp_json_encode( $result ) . "\n";
