<?php
/**
 * Real WordPress publication regression check for a disposable local database.
 * Usage: php tests/integration/publication-runtime.php /tmp/site/wp-load.php
 * Never run against a production database. Fixtures and options are restored.
 */
declare(strict_types=1);

$bootstrap = $argv[1] ?? '';
if ( PHP_SAPI !== 'cli' || ! is_file( $bootstrap ) || ! str_starts_with( realpath( $bootstrap ), sys_get_temp_dir() . '/' ) ) {
	throw new RuntimeException( 'Provide wp-load.php from a disposable WordPress installation under the system temporary directory.' );
}
require $bootstrap;
require_once ( $argv[2] ?? dirname( __DIR__, 2 ) ) . '/cybermaps.php';

function cybermaps_runtime_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

class CybermapsRuntimeCountingCache extends WP_Object_Cache {
	public int $post_reads = 0;
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( 'posts' === $group && is_numeric( $key ) ) {
			++$this->post_reads;
		}
		return parent::get( $key, $group, $force, $found );
	}
}

$original_settings = get_option( 'cybermaps_settings', null );
$original_matrix = get_option( 'cybermaps_discovery_center', null );
$original_public = get_option( 'blog_public', null );
$original_cache = $GLOBALS['wp_object_cache'];
$original_external = wp_using_ext_object_cache();
$created = array();
register_post_type( 'cybermaps_fixture', array( 'public' => true, 'rewrite' => false, 'supports' => array( 'title', 'editor' ) ) );
try {
	$settings = array( 'enable_discovery_hub' => '1', 'enable_llms_tldr' => '1', 'llms_included_types' => array( 'cybermaps_fixture' ), 'llms_link_limit' => 100 );
	update_option( 'cybermaps_settings', $settings );
	update_option( 'cybermaps_discovery_center', array() );
	update_option( 'blog_public', '1' );
	for ( $index = 1; $index <= 305; ++$index ) {
		$id = wp_insert_post( array( 'post_type' => 'cybermaps_fixture', 'post_status' => 'publish', 'post_title' => 'Fixture ' . $index, 'post_content' => 'Literal fixture content.', 'post_date' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00' ), true );
		cybermaps_runtime_assert( ! is_wp_error( $id ), 'Fixture insertion failed.' );
		$created[] = $id;
		if ( 0 === $index % 10 ) { update_post_meta( $id, '_cybermaps_exclude_ai', '1' ); }
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET post_modified = %s, post_modified_gmt = %s WHERE post_type = %s', $wpdb->posts, '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'cybermaps_fixture' ) );
	$expected = array_values( array_filter( $created, static fn( $id, $index ): bool => 0 !== ( $index + 1 ) % 10, ARRAY_FILTER_USE_BOTH ) );
	$measurements = array();
	foreach ( array( false, true ) as $external ) {
		$GLOBALS['wp_object_cache'] = new CybermapsRuntimeCountingCache();
		wp_using_ext_object_cache( $external );
		$start = microtime( true );
		$inventory = new Cybermaps\Discovery\PublicationInventory( $settings );
		$actual = array_column( iterator_to_array( $inventory->iterate_posts(), false ), 'ID' );
		cybermaps_runtime_assert( $actual === $expected, 'Real SQL keyset pagination lost, repeated, or leaked a resource.' );
		cybermaps_runtime_assert( 0 === $GLOBALS['wp_object_cache']->post_reads, 'Inventory re-fetched hydrated rows through the post cache.' );
		$scan = new Cybermaps\Discovery\PublicationScanBudget( 250 );
		$bounded = iterator_to_array( $inventory->iterate_posts( array(), $scan ), false );
		cybermaps_runtime_assert( 250 === $scan->scanned() && $scan->truncated() && 225 === count( $bounded ), 'SEO exclusions escaped the real candidate budget.' );
		$briefing = ( new Cybermaps\Discovery\LLMSTLDR\LLMSTLDRGenerator( $inventory ) )->generate_publication( $settings, 'Fixture site' );
		cybermaps_runtime_assert( 250 === $briefing['scanned_count'] && 225 === $briefing['eligible_count'], 'Briefing candidate accounting changed.' );
		cybermaps_runtime_assert( $briefing['token_estimate'] <= $briefing['token_budget'], 'Briefing exceeded its token budget.' );
		$summary = ( new Cybermaps\Discovery\LLMS() )->get_llms_content( false, true );
		cybermaps_runtime_assert( 100 === preg_match_all( '/^- \\[Fixture /m', $summary ), 'Summary did not respect its link limit.' );
		$measurements[] = array( 'external_cache' => $external, 'complete_eligible_posts' => count( $actual ), 'bounded_candidates' => $scan->scanned(), 'post_cache_reads' => $GLOBALS['wp_object_cache']->post_reads, 'seconds' => round( microtime( true ) - $start, 3 ) );
	}
	echo json_encode( $measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";
} finally {
	wp_using_ext_object_cache( false );
	foreach ( $created as $id ) { wp_delete_post( $id, true ); }
	foreach ( array( 'cybermaps_settings' => $original_settings, 'cybermaps_discovery_center' => $original_matrix, 'blog_public' => $original_public ) as $name => $value ) {
		if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); }
	}
	$GLOBALS['wp_object_cache'] = $original_cache;
	wp_using_ext_object_cache( $original_external );
	unregister_post_type( 'cybermaps_fixture' );
}
