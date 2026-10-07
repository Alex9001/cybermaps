<?php
declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Object_Cache', false ) ) {
		class WP_Object_Cache {
			public array $cache = array();
			public function delete( $key, $group ): bool { unset( $this->cache[$group][$key] ); return true; }
		}
	}
	if ( ! function_exists( 'wp_cache_flush_runtime' ) ) {
		function wp_cache_flush_runtime(): bool {
			++$GLOBALS['lease_flushes'];
			$GLOBALS['wp_object_cache']->cache = array();
			return true;
		}
	}
}
namespace Cybermaps\Tests\Discovery {
	use Cybermaps\Discovery\PublicationInventory;
	use Cybermaps\Discovery\PublicationScanBudget;
	use Cybermaps\Discovery\PublicationSizeLimitException;

	final class PublicationCacheLeaseTest extends \WP_UnitTestCase {
		public function test_private_magic_cache_releases_raw_keys_with_one_writeback(): void {
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/cache-lease-private.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			$output = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $error );
			$evidence = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( array( '3:old' => array( 'caller' => true ), '3:changed' => array( 'caller_changed' => true ) ), $evidence['remaining']['post_meta'] );
			self::assertSame( array(), $evidence['remaining']['category_relationships'] );
			self::assertSame( 'Caller term', $evidence['remaining']['terms']['3:term']['name'] );
			self::assertSame( array( 'preserved' => true ), $evidence['remaining']['unrelated'] );
			self::assertSame( 1, $evidence['release_writebacks'] );
		}

		public function test_null_native_backend_flag_keeps_selective_runtime_cleanup(): void {
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/cache-lease-null.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			$output = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $error );
			$evidence = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
			self::assertNull( $evidence['api_return'] );
			self::assertSame( array( 'caller' => array( 'preserved' => true ) ), $evidence['remaining'] );
			self::assertSame( 0, $evidence['runtime_flushes'] );
		}

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['wp_object_cache'] = new \WP_Object_Cache();
			$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1' );
			$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
			$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'public' => true ) );
			$GLOBALS['cybermaps_mock_posts'] = array();
			$GLOBALS['cybermaps_mock_post_meta'] = array();
			$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
			$GLOBALS['cybermaps_mock_cache_capabilities'] = array();
			$GLOBALS['lease_flushes'] = 0;
			$GLOBALS['lease_persistent'] = array();
			for ( $id = 1; $id <= 225; ++$id ) {
				$GLOBALS['cybermaps_mock_posts'][$id] = (object) array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_modified_gmt' => '2026-01-01 00:00:00' );
			}
			$GLOBALS['cybermaps_mock_prime_post_meta_observer'] = static function( array $ids ): void {
				foreach ( $ids as $id ) {
					foreach ( array( 'post_meta', 'posts', 'category_relationships', 'terms', 'term_meta' ) as $group ) {
						$GLOBALS['wp_object_cache']->cache[$group][$id] ??= array( 'primed' => $id );
					}
					$GLOBALS['lease_persistent'][$id] = 'backend-value';
				}
			};
		}
		protected function tearDown(): void {
			unset( $GLOBALS['wp_object_cache'], $GLOBALS['cybermaps_mock_prime_post_meta_observer'], $GLOBALS['cybermaps_mock_post_meta_observer'] );
			$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
			$GLOBALS['cybermaps_mock_cache_capabilities'] = array();
			parent::tearDown();
		}
		private function inventory(): PublicationInventory { return new PublicationInventory( array( 'llms_included_types' => array( 'post' ), 'ai_sitemap_exclude_terms' => 'hidden' ) ); }

		public function test_several_batches_release_metadata_relationship_terms_and_child_posts(): void {
			$GLOBALS['wp_object_cache']->cache['post_meta'][999] = array( 'caller' => true );
			$peak = 0;
			$GLOBALS['cybermaps_mock_post_meta_observer'] = static function( $id ) use ( &$peak ): void {
				$GLOBALS['wp_object_cache']->cache['posts'][10000 + $id] = (object) array( 'ID' => 10000 + $id );
				$peak = max( $peak, count( $GLOBALS['wp_object_cache']->cache['post_meta'] ) );
			};
			foreach ( $GLOBALS['cybermaps_mock_posts'] as $id => $post ) { $GLOBALS['cybermaps_mock_post_meta'][$id]['_cybermaps_exclude_ai'] = '1'; }
			self::assertSame( array(), iterator_to_array( $this->inventory()->iterate_posts(), false ) );
			self::assertLessThanOrEqual( 101, $peak );
			self::assertSame( array( 999 => array( 'caller' => true ) ), $GLOBALS['wp_object_cache']->cache['post_meta'] );
			foreach ( array( 'posts', 'category_relationships', 'terms', 'term_meta' ) as $group ) { self::assertSame( array(), $GLOBALS['wp_object_cache']->cache[$group] ); }
		}

		public function test_abandoned_generator_preserves_changed_and_preexisting_caller_values(): void {
			$GLOBALS['wp_object_cache']->cache['post_meta'][2] = array( 'preexisting' => true );
			$iterator = $this->inventory()->iterate_posts();
			self::assertSame( 1, $iterator->current()->ID );
			$GLOBALS['wp_object_cache']->cache['post_meta'][1] = array( 'caller_changed' => true );
			$GLOBALS['wp_object_cache']->cache['posts'][999] = array( 'caller_added' => true );
			unset( $iterator );
			self::assertSame( array( 2 => array( 'preexisting' => true ), 1 => array( 'caller_changed' => true ) ), $GLOBALS['wp_object_cache']->cache['post_meta'] );
			self::assertSame( array( 999 => array( 'caller_added' => true ) ), $GLOBALS['wp_object_cache']->cache['posts'] );
		}

		public function test_prefixed_native_keys_are_removed_exactly_and_changed_objects_remain(): void {
			$GLOBALS['wp_object_cache']->cache['post_meta']['3:old'] = array( 'preexisting' => true );
			$lease = new \Cybermaps\Discovery\PublicationCacheLease();
			$GLOBALS['wp_object_cache']->cache['post_meta']['3:worker'] = array( 'worker' => true );
			$GLOBALS['wp_object_cache']->cache['terms']['3:term'] = (object) array( 'name' => 'Worker term' );
			$lease->capture();
			$GLOBALS['wp_object_cache']->cache['terms']['3:term']->name = 'Caller term';
			$lease->release();
			self::assertSame( array( '3:old' => array( 'preexisting' => true ) ), $GLOBALS['wp_object_cache']->cache['post_meta'] );
			self::assertSame( 'Caller term', $GLOBALS['wp_object_cache']->cache['terms']['3:term']->name );
		}

		public function test_exception_during_priming_releases_entries_already_created(): void {
			$GLOBALS['cybermaps_mock_prime_post_meta_observer'] = static function(): void {
				$GLOBALS['wp_object_cache']->cache['post_meta'][1] = array( 'primed' => true );
				throw new \RuntimeException( 'Priming failed' );
			};
			try { iterator_to_array( $this->inventory()->iterate_posts(), false ); self::fail( 'Expected filter failure.' ); }
			catch ( \RuntimeException $error ) { self::assertSame( 'Priming failed', $error->getMessage() ); }
			self::assertSame( array(), $GLOBALS['wp_object_cache']->cache['post_meta'] );
		}

		/** @dataProvider external_capabilities */
		public function test_persistent_backends_are_preserved_with_or_without_runtime_flush( bool $supported ): void {
			$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
			$GLOBALS['cybermaps_mock_cache_capabilities'] = $supported ? array( 'flush_runtime' ) : array();
			$scan = new PublicationScanBudget( 125 );
			iterator_to_array( $this->inventory()->iterate_posts( array(), $scan ), false );
			self::assertSame( 125, $scan->scanned() );
			self::assertCount( 125, $GLOBALS['lease_persistent'] );
			self::assertSame( $supported ? 2 : 0, $GLOBALS['lease_flushes'] );
			self::assertSame( $supported ? array() : range( 1, 125 ), array_keys( $GLOBALS['wp_object_cache']->cache['post_meta'] ?? array() ) );
		}
		public static function external_capabilities(): array { return array( array( true ), array( false ) ); }

		public function test_low_existing_memory_headroom_fails_before_another_query_without_allocating(): void {
			$original = ini_get( 'memory_limit' );
			$limit = (int) ceil( memory_get_usage( true ) / 1048576 ) + 8;
			ini_set( 'memory_limit', $limit . 'M' );
			try {
				$this->expectException( PublicationSizeLimitException::class );
				$this->inventory()->iterate_posts()->current();
			} finally { ini_set( 'memory_limit', $original ); }
		}
	}
}
