<?php
declare(strict_types=1);

namespace Cybermaps\Discovery {
	// Deliberately return null before the source constructor assigns its typed flag.
	function wp_using_ext_object_cache(): mixed { return null; }
}

namespace {
	require dirname( __DIR__, 2 ) . '/bootstrap.php';
	class WP_Object_Cache { public array $cache = array(); }
	function wp_cache_flush_runtime(): bool { ++$GLOBALS['lease_null_flushes']; return true; }
	$GLOBALS['lease_null_flushes'] = 0;
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	$GLOBALS['wp_object_cache']->cache['post_meta']['caller'] = array( 'preserved' => true );
	$lease = new \Cybermaps\Discovery\PublicationCacheLease();
	$GLOBALS['wp_object_cache']->cache['post_meta']['worker'] = array( 'primed' => true );
	$lease->capture();
	$lease->release();
	echo json_encode( array( 'api_return' => \Cybermaps\Discovery\wp_using_ext_object_cache(), 'remaining' => $GLOBALS['wp_object_cache']->cache['post_meta'], 'runtime_flushes' => $GLOBALS['lease_null_flushes'] ) );
}
