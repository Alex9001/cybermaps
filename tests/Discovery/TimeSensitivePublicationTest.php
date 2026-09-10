<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

	use Cybermaps\Core\ConfigurationStore;
	use Cybermaps\Core\EndpointRegistry;
	use Cybermaps\Discovery\AIMetadata;
	use Cybermaps\Discovery\StaticBridge;
	use Cybermaps\Discovery\StaticOwnershipStore;

final class TimeSensitivePublicationTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$registry = new \ReflectionProperty( EndpointRegistry::class, 'instance' );
		$registry->setValue( null, null );
		$GLOBALS['cybermaps_mock_is_multisite']      = false;
		$GLOBALS['cybermaps_mock_scheduled']         = array();
		$GLOBALS['cybermaps_mock_transients']        = array();
		$GLOBALS['cybermaps_mock_post_meta']         = array();
		$GLOBALS['cybermaps_mock_post_types']        = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'public' => true,
			),
		);
		$GLOBALS['cybermaps_mock_options']           = array(
			'cybermaps_settings'                           => array(
				'static_engine_mode'   => 'all',
				'enable_discovery_hub' => '1',
				'enable_rag_chunks'    => '1',
				'ai_sitemap_types'     => array( 'post' ),
			),
			'cybermaps_discovery_center'                   => wp_json_encode(
				array( 'overrides' => array( 'post' => 0.8 ) )
			),
			'cybermaps_last_time_sensitive_static_refresh' => time() - ( 2 * DAY_IN_SECONDS ),
			'cybermaps_last_static_sync_report'            => array( 'status' => 'sentinel' ),
		);
		$GLOBALS['cybermaps_mock_posts']             = array(
			41 => new \WP_Post(
				array(
					'ID'                => 41,
					'post_type'         => 'post',
					'post_status'       => 'publish',
					'post_password'     => '',
					'post_title'        => 'Aged publication',
					'post_content'      => 'Literal content for a time-sensitive static publication.',
					'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - ( 8 * DAY_IN_SECONDS ) ),
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 8 * DAY_IN_SECONDS ) ),
				)
			),
		);
		unset(
			$GLOBALS['cybermaps_mock_get_option_observer'],
			$GLOBALS['cybermaps_mock_get_posts_callback'],
			$GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback']
		);
		$GLOBALS['cybermaps_mock_get_posts_args'] = array();
		ConfigurationStore::reset_memo();
		$this->delete_generated_files();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_get_option_observer'],
			$GLOBALS['cybermaps_mock_get_posts_callback'],
			$GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback']
		);
		ConfigurationStore::reset_memo();
		$this->delete_generated_files();
		parent::tearDown();
	}

	public function test_transition_refresh_updates_only_affected_ai_publications(): void {
		$report = StaticBridge::get_instance()->refresh_time_sensitive_publications();

		$this->assertSame( 'complete', $report['status'] );
		$this->assertTrue( $report['success'] );
		$this->assertSame(
			array( 'discovery/chunks/41.json', 'ai-sitemap.xml' ),
			$report['desired']
		);
		$this->assertFileExists( ABSPATH . 'discovery/chunks/41.json' );
		$this->assertFileExists( ABSPATH . 'ai-sitemap.xml' );
		$this->assertStringContainsString(
			'"freshness": "established"',
			(string) file_get_contents( ABSPATH . 'discovery/chunks/41.json' )
		);
		$this->assertStringContainsString(
			'<ai:freshness>established</ai:freshness>',
			(string) file_get_contents( ABSPATH . 'ai-sitemap.xml' )
		);
		$this->assertSame(
			array( 'status' => 'sentinel' ),
			get_option( 'cybermaps_last_static_sync_report' ),
			'A targeted timer must not replace the administrator-facing full-sync report.'
		);
		$this->assertGreaterThan(
			time() - 10,
			(int) get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 )
		);
	}

	public function test_collision_preflight_runs_before_time_sensitive_collection_or_writes(): void {
		$bridge    = StaticBridge::get_instance();
		$sentinels = array(
			'discovery/chunks/41.json' => "owned chunk before collision\n",
			'ai-sitemap.xml'           => "owned index before collision\n",
		);
		foreach ( $sentinels as $filename => $content ) {
			$this->assertTrue( $bridge->write_file( $filename, $content ) );
		}
		$ownership_before = ( new StaticOwnershipStore() )->read_flat_hashes();
		$this->register_static_endpoint_fixture( 'fixture_time_sensitive_sitemap', '/sitemap.xml' );

		$collection_calls = 0;
		$write_calls      = 0;

		$GLOBALS['cybermaps_mock_get_posts_args'] = array();

		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function () use ( &$collection_calls ): array {
			++$collection_calls;
			throw new \RuntimeException( 'The transition collector must not run after a failed publication preflight.' );
		};

		$GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback'] = static function () use ( &$write_calls ): null {
			++$write_calls;
			return null;
		};

		$report = $bridge->refresh_time_sensitive_publications();

		$this->assertFalse( $report['success'] );
		$this->assertSame( 'duplicate_static_target', $report['failed']['publication_plan']['code'] );
		$this->assertSame( 'sitemap.xml', $report['failed']['publication_plan']['filename'] );
		$this->assertSame(
			array( 'endpoint:fixture_time_sensitive_sitemap', 'sitemap:index' ),
			$report['failed']['publication_plan']['producers']
		);
		$this->assertSame( 0, $collection_calls );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_posts_args'] );
		$this->assertSame( 0, $write_calls );
		$this->assertSame( array(), $report['desired'] );
		$this->assertSame( array(), $report['written'] );
		foreach ( $sentinels as $filename => $content ) {
			$this->assertSame( $content, \file_get_contents( ABSPATH . $filename ) );
		}
		$this->assertSame( $ownership_before, ( new StaticOwnershipStore() )->read_flat_hashes() );
	}

	public function test_inactive_full_static_mode_clears_orphaned_timer(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode']    = 'well_known';
		$GLOBALS['cybermaps_mock_scheduled'][ StaticBridge::TIME_SENSITIVE_REFRESH_HOOK ] = time() + 60;

		$report = StaticBridge::get_instance()->refresh_time_sensitive_publications();

		$this->assertSame( 'skipped', $report['status'] );
		$this->assertSame( 'full_static_mode_inactive', $report['skipped']['operation']['code'] );
		$this->assertArrayNotHasKey(
			StaticBridge::TIME_SENSITIVE_REFRESH_HOOK,
			$GLOBALS['cybermaps_mock_scheduled']
		);
	}

	public function test_all_to_off_race_before_post_lease_snapshot_publishes_nothing_stale(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_static_generation']                 = 0;
		$GLOBALS['cybermaps_mock_scheduled'][ StaticBridge::TIME_SENSITIVE_REFRESH_HOOK ] = time() + 60;
		$invalidated                                   = false;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$invalidated ): void {
			if ( $invalidated || 'cybermaps_static_generation' !== $option ) {
				return;
			}

			$invalidated = true;
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'off';
			$GLOBALS['cybermaps_mock_options']['cybermaps_static_generation']              = 1;
			ConfigurationStore::reset_memo();
		};

			$report = StaticBridge::get_instance()->refresh_time_sensitive_publications();

			$this->assertTrue( $invalidated, 'The fixture must invalidate settings after lease acquisition and before the stable snapshot.' );
			$this->assertSame( 'off', $report['mode'] );
			$this->assertSame( 1, $report['generation'] );
			$this->assertSame( 'skipped', $report['status'] );
			$this->assertTrue( $report['success'] );
			$this->assertSame( 'full_static_mode_inactive', $report['skipped']['operation']['code'] );
			$this->assertSame( array(), $report['desired'] );
			$this->assertSame( array(), $report['written'] );
			$this->assertFileDoesNotExist( ABSPATH . 'discovery/chunks/41.json' );
			$this->assertFileDoesNotExist( ABSPATH . 'ai-sitemap.xml' );
			$this->assertArrayNotHasKey( StaticBridge::OPERATION_LOCK_OPTION, $GLOBALS['cybermaps_mock_options'] );
			$this->assertArrayNotHasKey(
				StaticBridge::TIME_SENSITIVE_REFRESH_HOOK,
				$GLOBALS['cybermaps_mock_scheduled']
			);
	}

	public function test_transition_collector_does_not_mutate_scheduler_state(): void {
		$now        = \time();
		$checkpoint = $now - ( 2 * DAY_IN_SECONDS );
		$post       = $this->transition_post( 41, $now );
		$due        = \strtotime( $post->post_date_gmt . ' UTC' ) + ( 90 * DAY_IN_SECONDS );

		$GLOBALS['cybermaps_mock_posts'] = array( 41 => $post );
		$GLOBALS['cybermaps_mock_post_meta'][41][ AIMetadata::TRANSITION_META_KEY ] = $due;
		\update_option( 'cybermaps_last_time_sensitive_static_refresh', $checkpoint, false );
		$this->install_transition_query_adapter();
		$before_meta    = $GLOBALS['cybermaps_mock_post_meta'];
		$before_options = $GLOBALS['cybermaps_mock_options'];

		$state = ( new \ReflectionMethod( StaticBridge::class, 'collect_time_sensitive_state' ) )->invoke(
			StaticBridge::get_instance(),
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'],
			$checkpoint,
			$now
		);

		$this->assertTrue( $state['pending'] );
		$this->assertSame( array( 41 ), $state['index_post_ids'] );
		$this->assertSame( array( 41 ), \array_map( static fn ( object $item ): int => (int) $item->ID, $state['ai_changed_posts'] ) );
		$this->assertSame( $before_meta, $GLOBALS['cybermaps_mock_post_meta'] );
		$this->assertSame( $before_options, $GLOBALS['cybermaps_mock_options'] );
		$this->assertFileDoesNotExist( ABSPATH . 'discovery/chunks/41.json' );
		$this->assertFileDoesNotExist( ABSPATH . 'ai-sitemap.xml' );
	}

	public function test_failed_publication_preserves_due_state_and_retry_republishes(): void {
		$checkpoint = (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 );
		$post       = $GLOBALS['cybermaps_mock_posts'][41];
		$due        = \strtotime( $post->post_date_gmt . ' UTC' ) + ( 7 * DAY_IN_SECONDS ) + 1;
		$GLOBALS['cybermaps_mock_post_meta'][41][ AIMetadata::TRANSITION_META_KEY ] = $due;
		\WP_Filesystem();
		global $wp_filesystem;
		$this->assertTrue( \wp_mkdir_p( ABSPATH . 'discovery/chunks' ) );
		$this->assertTrue( $wp_filesystem->put_contents( ABSPATH . 'discovery/chunks/41.json', 'foreign body', 0644 ) );

		$failed = StaticBridge::get_instance()->refresh_time_sensitive_publications();

		$this->assertFalse( $failed['success'] );
		$this->assertSame( $due, $GLOBALS['cybermaps_mock_post_meta'][41][ AIMetadata::TRANSITION_META_KEY ] );
		$this->assertSame( $checkpoint, (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 ) );
		$this->assertNotFalse( \wp_next_scheduled( StaticBridge::TIME_SENSITIVE_REFRESH_HOOK ) );
		$this->assertSame( 'foreign body', $wp_filesystem->get_contents( ABSPATH . 'discovery/chunks/41.json' ) );

		\wp_delete_file( ABSPATH . 'discovery/chunks/41.json' );
		$retried = StaticBridge::get_instance()->refresh_time_sensitive_publications();

		$this->assertTrue( $retried['success'] );
		$this->assertSame( 'complete', $retried['status'] );
		$this->assertContains( 'discovery/chunks/41.json', $retried['written'] );
		$this->assertContains( 'ai-sitemap.xml', $retried['written'] );
		$this->assertNotSame( $due, $GLOBALS['cybermaps_mock_post_meta'][41][ AIMetadata::TRANSITION_META_KEY ] );
		$this->assertGreaterThan( $checkpoint, (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 ) );
	}

	public function test_limit_plus_one_backlog_drains_before_global_checkpoint_advances(): void {
		$now        = \time();
		$checkpoint = $now - ( 2 * DAY_IN_SECONDS );
		$posts      = array();
		$limit      = (int) ( new \ReflectionClass( StaticBridge::class ) )->getConstant( 'TRANSITION_CANDIDATE_LIMIT' );
		for ( $post_id = 1; $post_id <= $limit + 1; ++$post_id ) {
			$post              = $this->transition_post( $post_id, $now );
			$posts[ $post_id ] = $post;
			$GLOBALS['cybermaps_mock_post_meta'][ $post_id ][ AIMetadata::TRANSITION_META_KEY ] =
				\strtotime( $post->post_date_gmt . ' UTC' ) + ( 90 * DAY_IN_SECONDS );
		}
		$GLOBALS['cybermaps_mock_posts'] = $posts;
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_rag_chunks'] = '0';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_sitemap_limit']  = 1000;
		\update_option( 'cybermaps_last_time_sensitive_static_refresh', $checkpoint, false );
		ConfigurationStore::reset_memo();
		$this->install_transition_query_adapter();

		$first = StaticBridge::get_instance()->refresh_time_sensitive_publications();

		$this->assertFalse( $first['success'] );
		$this->assertSame( 'pending', $first['status'] );
		$this->assertSame( 'transition_backlog_pending', $first['skipped']['transition_backlog']['code'] );
		$this->assertSame( $checkpoint, (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 ) );
		$this->assertSame( 1, $this->due_transition_count( $checkpoint, $now ) );
		$retry = (int) \wp_next_scheduled( StaticBridge::TIME_SENSITIVE_REFRESH_HOOK );
		$this->assertGreaterThanOrEqual( $now + 59, $retry );
		$this->assertLessThanOrEqual( $now + 120, $retry );

		$second = StaticBridge::get_instance()->refresh_time_sensitive_publications();

		$this->assertTrue( $second['success'] );
		$this->assertSame( 'complete', $second['status'] );
		$this->assertSame( 0, $this->due_transition_count( $checkpoint, $now ) );
		$this->assertGreaterThan( $checkpoint, (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 ) );
	}

	public function test_budget_deferral_preserves_checkpoint_and_schedules_retry(): void {
		$bridge     = StaticBridge::get_instance();
		$checkpoint = (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 );
		$post       = $GLOBALS['cybermaps_mock_posts'][41];
		$due        = \strtotime( $post->post_date_gmt . ' UTC' ) + ( 7 * DAY_IN_SECONDS ) + 1;
		$GLOBALS['cybermaps_mock_post_meta'][41][ AIMetadata::TRANSITION_META_KEY ] = $due;
		$forced = false;

		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function () use ( $bridge, &$forced ): ?array {
			if ( ! $forced ) {
				$forced = true;
				( new \ReflectionProperty( StaticBridge::class, 'sync_written_count' ) )->setValue( $bridge, 250 );
			}
			return null;
		};

		$report = $bridge->refresh_time_sensitive_publications();

		$this->assertTrue( $forced );
		$this->assertFalse( $report['success'] );
		$this->assertSame( 'pending', $report['status'] );
		$this->assertSame( $checkpoint, (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 ) );
		$this->assertSame( $due, $GLOBALS['cybermaps_mock_post_meta'][41][ AIMetadata::TRANSITION_META_KEY ] );
		$this->assertNotFalse( \wp_next_scheduled( StaticBridge::TIME_SENSITIVE_REFRESH_HOOK ) );
		$this->assertFileDoesNotExist( ABSPATH . 'discovery/chunks/41.json' );
	}

	public function test_successful_batch_advances_transition_index_and_checkpoint_monotonically(): void {
		$now        = \time();
		$checkpoint = $now - ( 2 * DAY_IN_SECONDS );
		$post       = $this->transition_post( 41, $now );
		$due        = \strtotime( $post->post_date_gmt . ' UTC' ) + ( 90 * DAY_IN_SECONDS );
		$next       = \strtotime( $post->post_modified_gmt . ' UTC' ) + ( 30 * DAY_IN_SECONDS ) + 1;

		$GLOBALS['cybermaps_mock_posts'] = array( 41 => $post );
		$GLOBALS['cybermaps_mock_post_meta'][41][ AIMetadata::TRANSITION_META_KEY ] = $due;
		\update_option( 'cybermaps_last_time_sensitive_static_refresh', $checkpoint, false );
		$this->install_transition_query_adapter();

		$report = StaticBridge::get_instance()->refresh_time_sensitive_publications();

		$this->assertTrue( $report['success'] );
		$this->assertSame( $next, $GLOBALS['cybermaps_mock_post_meta'][41][ AIMetadata::TRANSITION_META_KEY ] );
		$advanced = (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 );
		$this->assertGreaterThan( $checkpoint, $advanced );

		$newer = $advanced + DAY_IN_SECONDS;
		\update_option( 'cybermaps_last_time_sensitive_static_refresh', $newer, false );
		$second = StaticBridge::get_instance()->refresh_time_sensitive_publications();

		$this->assertTrue( $second['success'] );
		$this->assertSame( $newer, (int) \get_option( 'cybermaps_last_time_sensitive_static_refresh', 0 ) );
	}

	private function transition_post( int $post_id, int $now ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_password'     => '',
				'post_title'        => 'Transition publication ' . $post_id,
				'post_content'      => 'Literal transition content for post ' . $post_id . '.',
				'post_date_gmt'     => \gmdate( 'Y-m-d H:i:s', $now - ( 91 * DAY_IN_SECONDS ) ),
				'post_modified_gmt' => \gmdate( 'Y-m-d H:i:s', $now - ( 10 * DAY_IN_SECONDS ) ),
			)
		);
	}

	private function install_transition_query_adapter(): void {
		$GLOBALS['cybermaps_mock_get_posts_callback'] = function ( array $args ): ?array {
			$key        = AIMetadata::TRANSITION_META_KEY;
			$meta_query = $args['meta_query'][0] ?? null;
			$compare    = \is_array( $meta_query ) ? (string) ( $meta_query['compare'] ?? '' ) : '';
			$posts      = \array_values( (array) $GLOBALS['cybermaps_mock_posts'] );

			if ( 'BETWEEN' === $compare && (string) ( $meta_query['key'] ?? '' ) === $key ) {
				$range = (array) ( $meta_query['value'] ?? array() );
				$posts = \array_values(
					\array_filter(
						$posts,
						static function ( object $post ) use ( $key, $range ): bool {
							$value = $GLOBALS['cybermaps_mock_post_meta'][ (int) $post->ID ][ $key ] ?? null;
							return \is_numeric( $value )
								&& (int) $value >= (int) ( $range[0] ?? 0 )
								&& (int) $value <= (int) ( $range[1] ?? 0 );
						}
					)
				);
				\usort(
					$posts,
					static fn ( object $left, object $right ): int =>
						(int) $GLOBALS['cybermaps_mock_post_meta'][ (int) $left->ID ][ $key ]
						<=> (int) $GLOBALS['cybermaps_mock_post_meta'][ (int) $right->ID ][ $key ]
				);
				return \array_slice( $posts, 0, (int) ( $args['posts_per_page'] ?? 5 ) );
			}

			if ( 'NOT EXISTS' === $compare && (string) ( $meta_query['key'] ?? '' ) === $key ) {
				$posts = \array_values(
					\array_filter(
						$posts,
						static fn ( object $post ): bool => ! \array_key_exists(
							AIMetadata::TRANSITION_META_KEY,
							$GLOBALS['cybermaps_mock_post_meta'][ (int) $post->ID ] ?? array()
						)
					)
				);
				return \array_slice( $posts, 0, (int) ( $args['posts_per_page'] ?? 5 ) );
			}

			$meta_compare = (string) ( $args['meta_compare'] ?? '' );
			if ( (string) ( $args['meta_key'] ?? '' ) === $key && \in_array( $meta_compare, array( '<', '>' ), true ) ) {
				$boundary = (int) ( $args['meta_value'] ?? 0 );
				$posts    = \array_values(
					\array_filter(
						$posts,
						static function ( object $post ) use ( $key, $boundary, $meta_compare ): bool {
							$value = (int) ( $GLOBALS['cybermaps_mock_post_meta'][ (int) $post->ID ][ $key ] ?? 0 );
							return '<' === $meta_compare ? $value < $boundary : $value > $boundary;
						}
					)
				);
				\usort(
					$posts,
					static fn ( object $left, object $right ): int =>
						(int) $GLOBALS['cybermaps_mock_post_meta'][ (int) $left->ID ][ $key ]
						<=> (int) $GLOBALS['cybermaps_mock_post_meta'][ (int) $right->ID ][ $key ]
				);
				$posts = \array_slice( $posts, 0, (int) ( $args['posts_per_page'] ?? 5 ) );
				return 'ids' === ( $args['fields'] ?? '' )
					? \array_map( static fn ( object $post ): int => (int) $post->ID, $posts )
					: $posts;
			}

			return null;
		};
	}

	private function due_transition_count( int $checkpoint, int $now ): int {
		$count = 0;
		foreach ( (array) $GLOBALS['cybermaps_mock_post_meta'] as $metadata ) {
			$value = $metadata[ AIMetadata::TRANSITION_META_KEY ] ?? null;
			if ( \is_numeric( $value ) && (int) $value >= $checkpoint && (int) $value <= $now ) {
				++$count;
			}
		}
		return $count;
	}

	private function register_static_endpoint_fixture( string $id, string $path ): void {
		$this->assertTrue(
			EndpointRegistry::get_instance()->register(
				$id,
				array(
					'kind'           => 'path',
					'path'           => $path,
					'type'           => 'application/octet-stream',
					'format'         => 'text',
					'static_targets' => array(
						array(
							'path'   => $path,
							'bucket' => 'all',
						),
					),
				)
			)
		);
	}

	private function delete_generated_files(): void {
		\WP_Filesystem();
		global $wp_filesystem;
		foreach ( array( 'discovery/chunks/41.json', 'ai-sitemap.xml' ) as $file ) {
			$path = ABSPATH . $file;
			if ( $wp_filesystem->exists( $path ) ) {
				\wp_delete_file( $path );
			}
		}
		$chunk_dir = ABSPATH . 'discovery/chunks';
		if ( $wp_filesystem->is_dir( $chunk_dir ) ) {
			$wp_filesystem->delete( $chunk_dir, false, 'd' );
		}
		$discovery_dir = ABSPATH . 'discovery';
		if ( $wp_filesystem->is_dir( $discovery_dir ) ) {
			$wp_filesystem->delete( $discovery_dir, false, 'd' );
		}
	}
}
