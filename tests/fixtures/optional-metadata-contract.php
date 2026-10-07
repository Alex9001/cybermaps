<?php
/** Simulate a size rejection with a one-byte source, without lowering PHP memory. */
declare(strict_types=1);
namespace Cybermaps\Discovery {
	function wp_convert_hr_to_bytes( $value ): int {
		if ( 'ordinary-error' === $GLOBALS['fixture_mode'] ) { throw new \RuntimeException( 'An ordinary dependency error.' ); }
		return memory_get_usage( true ) + 16 * 1024 * 1024;
	}
	function get_the_excerpt( $post ): string { throw new \RuntimeException( 'Unsafe excerpt fallback was called.' ); }
}
namespace {
	if ( 'cli' !== PHP_SAPI ) { exit; }
	require dirname( __DIR__ ) . '/bootstrap.php';
	$GLOBALS['fixture_mode'] = $argv[1];
	$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'public' => true ) );
	$post = new \WP_Post( array( 'ID' => 901, 'post_status' => 'publish', 'post_type' => 'post', 'post_title' => 'Public hit', 'post_content' => 'x', 'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-10-01 00:00:00' ) );
	$GLOBALS['cybermaps_mock_posts'][901] = $post;
	update_option( 'cybermaps_settings', array( 'media_discovery_intensity' => 'none', 'enable_ai_snippets' => '1' ) );
	update_post_meta( 901, '_cybermaps_ai_meta', array( 'snippet' => 'outdated' ) );
	update_post_meta( 901, '_cybermaps_ai_meta_ts', 1 );
	update_post_meta( 901, '_cybermaps_ai_meta_version', '1' );
	$result = array();
	foreach ( array( 'save', 'search' ) as $operation ) {
		try {
			if ( 'save' === $operation ) {
				( new \Cybermaps\Core\Plugin( new \Cybermaps\Core\Container() ) )->on_save_post( 901, $post );
				$result['save'] = array( 'content' => $post->post_content, 'stale_meta' => get_post_meta( 901, '_cybermaps_ai_meta', true ), 'transition' => get_post_meta( 901, \Cybermaps\Discovery\AIMetadata::TRANSITION_META_KEY, true ) );
			} else {
				$result['search'] = ( new \ReflectionMethod( \Cybermaps\Discovery\Search::class, 'build_result' ) )->invoke( new \Cybermaps\Discovery\Search(), $post );
			}
		} catch ( \RuntimeException $error ) { $result[ $operation ] = array( 'error' => $error->getMessage() ); }
	}
	echo json_encode( $result, JSON_THROW_ON_ERROR );
}
