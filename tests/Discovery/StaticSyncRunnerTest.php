<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Discovery\StaticOwnershipStore;
use Cybermaps\Discovery\StaticSyncRunner;
use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PageOccupancyManifest;
use Cybermaps\Sitemap\ProviderIdentity;
use Cybermaps\Sitemap\ProviderInterface;

final class StaticSyncRunnerTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->reset_endpoint_registry();
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_scheduled']    = array();
		$GLOBALS['cybermaps_mock_options']      = array(
			'blog_public'                 => '1',
			'cybermaps_static_generation' => 3,
			'cybermaps_static_sync_epoch' => 7,
			'cybermaps_settings'          => $this->all_phase_settings(),
		);
		unset(
			$GLOBALS['cybermaps_mock_get_option_observer'],
			$GLOBALS['cybermaps_mock_update_option_behavior']
		);
		ConfigurationStore::reset_memo();
		$this->reset_bridge_state();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_get_option_observer'],
			$GLOBALS['cybermaps_mock_update_option_behavior']
		);
		StaticOwnershipStore::delete_all();
		\delete_option( StaticBridge::OPERATION_LOCK_OPTION );
		\delete_option( 'cybermaps_static_sync_state' );
		\delete_option( 'cybermaps_static_failed_retry' );
		$this->reset_bridge_state();
		$this->reset_endpoint_registry();
		ConfigurationStore::reset_memo();

		parent::tearDown();
	}

	public function test_same_request_collision_preflight_stops_before_any_publication(): void {
		$this->register_static_endpoint_fixture( 'fixture_sitemap_index', '/sitemap.xml' );
		$bridge = $this->recording_bridge();
		$runner = new StaticSyncRunner( $bridge );
		$report = $this->empty_report();

		$result = $runner->run( $this->all_phase_settings(), $report );

		$this->assertSame( array(), $bridge->published );
		$this->assertSame( 'duplicate_static_target', $result['failed']['publication_plan']['code'] );
		$this->assertSame( 'sitemap.xml', $result['failed']['publication_plan']['filename'] );
		$this->assertSame(
			array( 'endpoint:fixture_sitemap_index', 'sitemap:index' ),
			$result['failed']['publication_plan']['producers']
		);
	}

	public function test_resumed_collision_preserves_owned_file_and_discards_continuation(): void {
		$this->register_static_endpoint_fixture( 'fixture_resumed_sitemap', '/sitemap.xml' );
		$state = $this->continuation_state(
			StaticSyncRunner::PHASE_DISCOVERY_LEAF,
			array( 'target_index' => 1 )
		);
		\update_option( 'cybermaps_static_sync_state', $state, false );

		$path     = ABSPATH . 'sitemap.xml';
		$sentinel = "owned-before-resume\n";
		$this->assertTrue( \WP_Filesystem() );
		global $wp_filesystem;
		$this->assertTrue( $wp_filesystem->put_contents( $path, $sentinel ) );
		$this->assertTrue(
			( new StaticOwnershipStore() )->set_hash( 'sitemap.xml', \md5( $sentinel ), 7, true )
		);

		try {
			$report = StaticBridge::get_instance()->sync_all();

			$this->assertSame( 'duplicate_static_target', $report['failed']['publication_plan']['code'] );
			$this->assertSame( $sentinel, $wp_filesystem->get_contents( $path ) );
			$this->assertSame( \md5( $sentinel ), ( new StaticOwnershipStore() )->get_hash( 'sitemap.xml' ) );
			$this->assertFalse( \get_option( 'cybermaps_static_sync_state', false ) );
			$this->assertFalse( \get_option( 'cybermaps_static_failed_retry', false ) );
			$this->assertFalse( \wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
		} finally {
			if ( $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->delete( $path );
			}
		}
	}

	public function test_publication_plan_reserves_dynamic_sitemap_and_rag_families(): void {
		$this->register_static_endpoint_fixture( 'fixture_sitemap_child', '/sitemap-posts-post-1.xml' );
		$result = ( new StaticSyncRunner( StaticBridge::get_instance() ) )->validate_publication_plan(
			$this->all_phase_settings(),
			'all'
		);

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'duplicate_static_target', $result['code'] );
		$this->assertSame( 'sitemap-posts-post-1.xml', $result['filename'] );
		$this->assertSame( array( 'endpoint:fixture_sitemap_child', 'sitemap:children' ), $result['producers'] );

		$this->reset_endpoint_registry();
		$this->register_static_endpoint_fixture( 'fixture_rag_chunk', '/discovery/chunks/123.json' );
		$result = ( new StaticSyncRunner( StaticBridge::get_instance() ) )->validate_publication_plan(
			$this->all_phase_settings(),
			'all'
		);

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'duplicate_static_target', $result['code'] );
		$this->assertSame( 'discovery/chunks/123.json', $result['filename'] );
		$this->assertSame( array( 'endpoint:fixture_rag_chunk', 'rag:chunks' ), $result['producers'] );
	}

	public function test_publication_plan_rejects_an_oversized_exact_claim_inventory(): void {
		$bridge = new class() extends StaticBridge {
			public function __construct() {
			}

			public function runner_get_localized_languages(): array {
				$languages = array();
				for ( $index = 0; $index < 4000; ++$index ) {
					$languages[] = 'l' . $index;
				}
				return $languages;
			}
		};
		$result = ( new StaticSyncRunner( $bridge ) )->validate_publication_plan(
			$this->all_phase_settings(),
			'all'
		);

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'static_target_plan_too_large', $result['code'] );
		$this->assertSame( 10000, $result['claim_count'] );
		$this->assertLessThanOrEqual( 4194304, $result['claim_bytes'] );
	}

	public function test_resume_rejects_corrupt_phase_cursors_and_context(): void {
		$runner   = new StaticSyncRunner( StaticBridge::get_instance() );
		$settings = $this->all_phase_settings();
		$valid    = $this->continuation_state(
			StaticSyncRunner::PHASE_RAG_CHUNKS,
			array(
				'selector'       => array(
					'type_index'    => 0,
					'page'          => 1,
					'position'      => 25,
					'type_selected' => 25,
					'inspected'     => 25,
				),
				'pending_ids'    => array( 26, 27 ),
				'pending_index'  => 1,
				'batch_complete' => false,
				'failed'         => false,
			)
		);
		$this->assertTrue( $runner->can_resume( $valid, $settings, 3, 'all', 7 ) );

		$invalid = array(
			'unknown cursor key'           => array_replace_recursive( $valid, array( 'cursor' => array( 'unknown' => 1 ) ) ),
			'duplicate pending ID'         => array_replace_recursive( $valid, array( 'cursor' => array( 'pending_ids' => array( 26, 26 ) ) ) ),
			'too many pending IDs'         => array_replace_recursive( $valid, array( 'cursor' => array( 'pending_ids' => \range( 1, 26 ) ) ) ),
			'pending offset past batch'    => array_replace_recursive( $valid, array( 'cursor' => array( 'pending_index' => 3 ) ) ),
			'selector position past bound' => array_replace_recursive( $valid, array( 'cursor' => array( 'selector' => array( 'position' => 251 ) ) ) ),
			'selector scan past bound'     => array_replace_recursive( $valid, array( 'cursor' => array( 'selector' => array( 'inspected' => 5001 ) ) ) ),
			'non-boolean failure flag'     => array_replace_recursive( $valid, array( 'cursor' => array( 'failed' => 1 ) ) ),
			'unknown context key'          => array_replace_recursive( $valid, array( 'context' => array( 'unexpected' => true ) ) ),
			'negative aggregate count'     => array_replace_recursive(
				$valid,
				array(
					'context' => array(
						'aggregate' => array(
							'counts' => array( 'written' => -1 ),
						),
					),
				)
			),
		);

		foreach ( $invalid as $label => $state ) {
			$this->assertFalse(
				$runner->can_resume( $state, $settings, 3, 'all', 7 ),
				$label . ' must not be resumed.'
			);
		}

		$localized = $this->continuation_state(
			StaticSyncRunner::PHASE_LOCALIZED,
			array(
				'language_index' => 0,
				'target_index'   => 4,
			)
		);
		$stale     = $this->continuation_state(
			StaticSyncRunner::PHASE_STALE_RECONCILIATION,
			array(
				'shard' => StaticOwnershipStore::SHARD_COUNT + 1,
				'path'  => '',
			)
		);
		$this->assertFalse( $runner->can_resume( $localized, $settings, 3, 'all', 7 ) );
		$this->assertFalse( $runner->can_resume( $stale, $settings, 3, 'all', 7 ) );
	}

	public function test_resume_rejects_malformed_top_level_metadata_and_started_at(): void {
		$runner   = new StaticSyncRunner( StaticBridge::get_instance() );
		$settings = $this->all_phase_settings();
		$valid    = $this->continuation_state( StaticSyncRunner::PHASE_LEGACY_PURGE, array( 'index' => 1 ) );
		$this->assertTrue( $runner->can_resume( $valid, $settings, 3, 'all', 7 ) );

		$invalid = array(
			'unknown top-level key'   => array_replace( $valid, array( 'unexpected' => true ) ),
			'non-integer retry time'  => array_replace( $valid, array( 'retry_at' => 'tomorrow' ) ),
			'negative retry time'     => array_replace( $valid, array( 'retry_at' => -1 ) ),
			'non-integer write count' => array_replace( $valid, array( 'writes' => '1' ) ),
			'negative write count'    => array_replace( $valid, array( 'writes' => -1 ) ),
			'non-string last error'   => array_replace( $valid, array( 'last_error' => array( 'error' ) ) ),
			'non-string start time'   => array_replace( $valid, array( 'started_at' => 1 ) ),
			'invalid start time'      => array_replace( $valid, array( 'started_at' => 'not-a-date' ) ),
			'oversized start time'    => array_replace( $valid, array( 'started_at' => \str_repeat( '2', 257 ) ) ),
		);

		foreach ( $invalid as $label => $state ) {
			$this->assertFalse(
				$runner->can_resume( $state, $settings, 3, 'all', 7 ),
				$label . ' must not be resumed.'
			);
		}
	}

	public function test_resume_rejects_a_changed_ordered_runtime_topology(): void {
		$previous_filter = $GLOBALS['cybermaps_mock_filter_callbacks']['wpml_active_languages'] ?? null;
		$runner          = new StaticSyncRunner( StaticBridge::get_instance() );
		$settings        = $this->all_phase_settings();

		try {
			$GLOBALS['cybermaps_mock_filter_callbacks']['wpml_active_languages'] = array(
				static fn (): array => array(
					'en' => array(),
					'fr' => array(),
				),
			);
			$state             = $this->continuation_state(
				StaticSyncRunner::PHASE_LEGACY_PURGE,
				array( 'index' => 1 )
			);
			$original_topology = $state['context']['topology'];
			$this->assertTrue( $runner->can_resume( $state, $settings, 3, 'all', 7 ) );

			$GLOBALS['cybermaps_mock_filter_callbacks']['wpml_active_languages'] = array(
				static fn (): array => array(
					'fr' => array(),
					'en' => array(),
				),
			);
			$changed_topology = $runner->topology_fingerprint( $settings, 'all' );

			$this->assertNotSame( $original_topology, $changed_topology );
			$this->assertFalse( $runner->can_resume( $state, $settings, 3, 'all', 7 ) );
		} finally {
			if ( null === $previous_filter ) {
				unset( $GLOBALS['cybermaps_mock_filter_callbacks']['wpml_active_languages'] );
			} else {
				$GLOBALS['cybermaps_mock_filter_callbacks']['wpml_active_languages'] = $previous_filter;
			}
		}
	}

	public function test_resume_rejects_non_string_or_oversized_aggregate_samples(): void {
		$runner   = new StaticSyncRunner( StaticBridge::get_instance() );
		$settings = $this->all_phase_settings();
		$samples  = array(
			'non-string list sample' => array( 'desired' => array( 42 ) ),
			'oversized list sample'  => array(
				'desired' => array( \str_repeat( 'a', StaticOwnershipStore::MAX_PATH_LENGTH + 1 ) ),
			),
			'non-string map key'     => array(
				'failed' => array(
					42 => array( 'code' => 'fixture_failure' ),
				),
			),
			'oversized map key'      => array(
				'failed' => array(
					\str_repeat( 'b', StaticOwnershipStore::MAX_PATH_LENGTH + 1 ) => array( 'code' => 'fixture_failure' ),
				),
			),
		);

		foreach ( $samples as $label => $aggregate_samples ) {
			$state = $this->continuation_state(
				StaticSyncRunner::PHASE_LEGACY_PURGE,
				array( 'index' => 1 ),
				array(
					'aggregate' => array(
						'counts'  => array(),
						'samples' => $aggregate_samples,
					),
				)
			);
			$this->assertFalse(
				$runner->can_resume( $state, $settings, 3, 'all', 7 ),
				$label . ' must not be resumed.'
			);
		}
	}

	public function test_legacy_purge_deferral_preserves_and_accepts_the_exact_index(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_bridge( $bridge, 'acquire_operation_lock' ) );
		$this->set_bridge_property( $bridge, 'sync_active', true );
		$this->set_bridge_property( $bridge, 'sync_generation', 3 );
		$this->set_bridge_property( $bridge, 'sync_mode', 'all' );
		$this->set_bridge_property( $bridge, 'sync_epoch', 7 );
		$this->set_bridge_property( $bridge, 'sync_started_at', \microtime( true ) );
		$this->set_bridge_property( $bridge, 'sync_deferred', true );
		$report = $this->empty_report();
		$cursor = array( 'index' => 3 );

		try {
			$this->assertFalse( $bridge->runner_purge_legacy_publications( $report, $cursor ) );
			$this->assertSame( array( 'index' => 3 ), $cursor );

			$state = $this->continuation_state( StaticSyncRunner::PHASE_LEGACY_PURGE, $cursor );
			$this->assertTrue(
				( new StaticSyncRunner( $bridge ) )->can_resume(
					$state,
					$this->all_phase_settings(),
					3,
					'all',
					7
				)
			);

			$this->set_bridge_property( $bridge, 'sync_deferred', false );
			$this->assertTrue( $bridge->runner_purge_legacy_publications( $report, $cursor ) );
			$legacy_files = ( new \ReflectionClass( StaticBridge::class ) )->getConstant( 'LEGACY_GENERATED_FILES' );
			$this->assertIsArray( $legacy_files );
			$this->assertSame( \count( $legacy_files ), $cursor['index'] );
		} finally {
			$this->set_bridge_property( $bridge, 'sync_active', false );
			$this->invoke_bridge( $bridge, 'release_operation_lock' );
		}
	}

	public function test_report_aggregate_survives_multiple_slices_and_finalization(): void {
		$bridge           = StaticBridge::get_instance();
		$runner           = new StaticSyncRunner( $bridge );
		$context          = array();
		$first            = $this->empty_report();
		$first['desired'] = array( 'a.txt', 'b.txt' );
		$first['written'] = array( 'a.txt' );
		$first['failed']  = array(
			'b.txt' => array(
				'code'    => 'first_slice_failure',
				'message' => 'First slice failed.',
			),
		);
		$this->invoke_runner_by_reference( $runner, 'absorb_report', $context, $first );

		$second               = $this->empty_report();
		$second['desired']    = array( 'c.txt' );
		$second['unchanged']  = array( 'c.txt' );
		$second['deleted']    = array( 'stale.txt' );
		$second['conflicted'] = array(
			'foreign.txt' => array(
				'code'    => 'untracked_existing_file',
				'message' => 'Foreign file retained.',
			),
		);
		$this->invoke_runner_by_reference( $runner, 'absorb_report', $context, $second );

		$final = $this->empty_report();
		$this->invoke_runner_hydrate( $runner, $final, $context );
		$final = ( new \ReflectionMethod( StaticBridge::class, 'finish_sync_report' ) )->invoke(
			$bridge,
			$final,
			false
		);

		$this->assertSame( 'partial', $final['status'] );
		$this->assertFalse( $final['success'] );
		$this->assertSame( 3, $final['counts']['desired'] );
		$this->assertSame( 1, $final['counts']['written'] );
		$this->assertSame( 1, $final['counts']['unchanged'] );
		$this->assertSame( 1, $final['counts']['deleted'] );
		$this->assertSame( 1, $final['counts']['failed'] );
		$this->assertSame( 1, $final['counts']['conflicted'] );
		$this->assertArrayHasKey( 'b.txt', $final['failed'] );
		$this->assertArrayHasKey( 'foreign.txt', $final['conflicted'] );
	}

	public function test_final_report_cannot_succeed_when_aggregate_failure_count_has_no_sample(): void {
		$report                      = $this->empty_report();
		$report['_aggregate_counts'] = array( 'failed' => 1 );
		$final                       = ( new \ReflectionMethod( StaticBridge::class, 'finish_sync_report' ) )->invoke(
			StaticBridge::get_instance(),
			$report,
			false
		);

		$this->assertSame( 1, $final['counts']['failed'] );
		$this->assertSame( array(), $final['failed'] );
		$this->assertSame( 'failed', $final['status'] );
		$this->assertFalse( $final['success'] );
	}

	public function test_large_prior_aggregate_counts_include_final_purge_and_omitted_totals_exactly(): void {
		$bridge             = StaticBridge::get_instance();
		$runner             = new StaticSyncRunner( $bridge );
		$context            = array(
			'aggregate' => array(
				'counts'  => array(
					'desired'  => 150,
					'omitted'  => 140,
					'deleted'  => 130,
					'retained' => 125,
					'failed'   => 121,
				),
				'samples' => array(
					'desired'  => $this->list_samples( 'desired', 100 ),
					'omitted'  => $this->list_samples( 'omitted', 100 ),
					'deleted'  => $this->list_samples( 'deleted', 100 ),
					'retained' => $this->map_samples( 'retained', 100 ),
					'failed'   => $this->map_samples( 'failed', 100 ),
				),
			),
		);
		$slice              = $this->empty_report();
		$slice['desired']   = array( 'current-desired.txt' );
		$slice['omitted']   = array( 'current-omitted-1.txt', 'current-omitted-2.txt' );
		$slice['unchanged'] = array( 'current-unchanged.txt' );
		$this->invoke_runner_by_reference( $runner, 'absorb_report', $context, $slice );

		$report = $this->empty_report();
		$this->invoke_runner_hydrate( $runner, $report, $context );
		$this->invoke_bridge_with_report(
			$bridge,
			'merge_purge_result',
			$report,
			array(
				'success'  => false,
				'status'   => 'checkpoint_failed',
				'message'  => 'Final reconciliation failed.',
				'deleted'  => array( 'final-purge-deleted.txt' ),
				'retained' => array( 'final-purge-retained.txt' => 'content_changed' ),
			)
		);
		$report = ( new \ReflectionMethod( StaticBridge::class, 'finish_sync_report' ) )->invoke(
			$bridge,
			$report,
			false
		);
		$report = ( new \ReflectionMethod( StaticBridge::class, 'compact_sync_report' ) )->invoke(
			$bridge,
			$report
		);

		$this->assertSame( 151, $report['counts']['desired'] );
		$this->assertSame( 142, $report['counts']['omitted'] );
		$this->assertSame( 1, $report['counts']['unchanged'] );
		$this->assertSame( 131, $report['counts']['deleted'] );
		$this->assertSame( 126, $report['counts']['retained'] );
		$this->assertSame( 122, $report['counts']['failed'] );
		$this->assertSame( 100, \count( $report['desired'] ) );
		$this->assertSame( 100, \count( $report['omitted'] ) );
		$this->assertSame( 100, \count( $report['deleted'] ) );
		$this->assertSame( 100, \count( $report['retained'] ) );
		$this->assertSame( 100, \count( $report['failed'] ) );
		$expected_truncation = array(
			'desired'  => 51,
			'omitted'  => 42,
			'failed'   => 22,
			'deleted'  => 31,
			'retained' => 26,
		);
		$this->assertCount( \count( $expected_truncation ), $report['truncated'] );
		foreach ( $expected_truncation as $field => $count ) {
			$this->assertSame( $count, $report['truncated'][ $field ] );
		}
	}

	public function test_sitemap_runner_publishes_only_manifest_confirmed_non_empty_pages(): void {
		$provider_id = ProviderIdentity::post_type( 'post' );
		$this->seed_occupancy_manifest(
			$provider_id,
			array(
				'raw_count'       => 6000,
				'raw_page_count'  => 3,
				'non_empty_pages' => array( 2 ),
				'page_lastmod'    => array(),
			)
		);
		$bridge       = $this->recording_bridge();
		$runner       = new StaticSyncRunner( $bridge );
		$orchestrator = $this->orchestrator_for_static_pages( $provider_id, 6000 );
		$report       = $this->empty_report();
		$cursor       = array();

		$this->assertTrue( $this->invoke_sitemap_children_phase( $runner, $report, $orchestrator, $cursor ) );
		$this->assertSame( array( 'site-map-posts-post-2.xml' ), $bridge->published );
		$this->assertSame( array( 'site-map-posts-post-2.xml' ), $report['desired'] );
	}

	public function test_sitemap_runner_falls_back_to_raw_pages_for_malformed_manifest_record(): void {
		$provider_id = ProviderIdentity::post_type( 'post' );
		$this->seed_occupancy_manifest(
			$provider_id,
			array(
				'raw_count'       => 6000,
				'raw_page_count'  => 3,
				'non_empty_pages' => array( 2, 1 ),
				'page_lastmod'    => array(),
			)
		);
		$bridge       = $this->recording_bridge();
		$runner       = new StaticSyncRunner( $bridge );
		$orchestrator = $this->orchestrator_for_static_pages( $provider_id, 6000 );
		$report       = $this->empty_report();
		$cursor       = array();

		$this->assertTrue( $this->invoke_sitemap_children_phase( $runner, $report, $orchestrator, $cursor ) );
		$this->assertSame(
			array(
				'site-map-posts-post-1.xml',
				'site-map-posts-post-2.xml',
				'site-map-posts-post-3.xml',
			),
			$bridge->published
		);
		$this->assertSame( $bridge->published, $report['desired'] );
	}

	public function test_sitemap_runner_records_empty_pages_in_resumable_cursor(): void {
		$provider_id = ProviderIdentity::post_type( 'post' );
		$provider    = new class() implements \Cybermaps\Sitemap\ProviderInterface {
			public function get_urls( int $page ): array {
				return 2 === $page
					? array( array( 'loc' => 'https://example.com/page-two/' ) )
					: array();
			}

			public function get_count(): int {
				return 4000;
			}

			public function get_lastmod(): string {
				return '';
			}
		};
		$orchestrator = new class( $provider_id, $provider ) extends \Cybermaps\Sitemap\Orchestrator {
			public function __construct(
				private string $test_provider_id,
				private \Cybermaps\Sitemap\ProviderInterface $test_provider
			) {
				parent::__construct();
			}

			public function collect_weighted_provider_ids(): array {
				return array( $this->test_provider_id );
			}

			public function get_provider( string $type ) {
				return $type === $this->test_provider_id ? $this->test_provider : null;
			}
		};
		$bridge = new class() extends StaticBridge {
			public function __construct() {
			}

			public function runner_budget_exhausted(): bool {
				return false;
			}

			public function runner_publish(
				array &$report,
				string $filename,
				callable $content_factory,
				bool $allow_omission = false
			): bool {
				$content = $content_factory();
				if ( $allow_omission && null === $content ) {
					$report['omitted'][] = $filename;
					return true;
				}
				$report['desired'][] = $filename;
				return true;
			}

			public function runner_get_problem_count( array $report ): int {
				unset( $report );
				return 0;
			}
		};
		$runner = new StaticSyncRunner( $bridge );
		$report = $this->empty_report();
		$cursor = array();

		$this->assertTrue( $this->invoke_sitemap_children_phase( $runner, $report, $orchestrator, $cursor ) );
		$this->assertSame( array( $provider_id => array( 1 ) ), $cursor['omissions'] );
		$this->assertCount( 1, $report['omitted'] );
	}

	public function test_resume_strictly_validates_sitemap_omissions(): void {
		$runner   = new StaticSyncRunner( StaticBridge::get_instance() );
		$settings = $this->all_phase_settings();
		$valid    = $this->continuation_state(
			StaticSyncRunner::PHASE_SITEMAP_CHILDREN,
			array(
				'provider_index' => 0,
				'page'           => 2,
				'omissions'      => array( ProviderIdentity::post_type( 'post' ) => array( 1 ) ),
			)
		);
		$this->assertTrue( $runner->can_resume( $valid, $settings, 3, 'all', 7 ) );

		$invalid                                  = $valid;
		$invalid['cursor']['omissions']['post:post'] = array( 2, 1 );
		$this->assertFalse( $runner->can_resume( $invalid, $settings, 3, 'all', 7 ) );

		$invalid                                  = $valid;
		$invalid['cursor']['omissions']['post:post'] = array( '1' );
		$this->assertFalse( $runner->can_resume( $invalid, $settings, 3, 'all', 7 ) );
	}

	public function test_sitemap_page_after_the_ceiling_is_a_valid_terminal_cursor(): void {
		$state = $this->continuation_state(
			StaticSyncRunner::PHASE_SITEMAP_CHILDREN,
			array(
				'provider_index' => 0,
				'page'           => 50001,
			)
		);
		$this->assertTrue(
			( new StaticSyncRunner( StaticBridge::get_instance() ) )->can_resume(
				$state,
				$this->all_phase_settings(),
				3,
				'all',
				7
			)
		);

		$provider_id  = ProviderIdentity::post_type( 'post' );
		$bridge       = $this->recording_bridge();
		$runner       = new StaticSyncRunner( $bridge );
		$orchestrator = $this->orchestrator_for_static_pages( $provider_id, 100000000 );
		$report       = $this->empty_report();
		$cursor       = array(
			'provider_index' => 0,
			'page'           => 50001,
		);

		$this->assertTrue( $this->invoke_sitemap_children_phase( $runner, $report, $orchestrator, $cursor ) );
		$this->assertSame( array(), $bridge->published );
		$this->assertSame( array(), $report['failed'] );
	}

	public function test_provider_beyond_sitemap_page_ceiling_surfaces_explicit_failure(): void {
		$provider_id  = ProviderIdentity::post_type( 'post' );
		$bridge       = $this->recording_bridge();
		$runner       = new StaticSyncRunner( $bridge );
		$orchestrator = $this->orchestrator_for_static_pages( $provider_id, 100000001 );
		$report       = $this->empty_report();
		$cursor       = array();

		$this->assertTrue( $this->invoke_sitemap_children_phase( $runner, $report, $orchestrator, $cursor ) );
		$this->assertSame( array(), $bridge->published );
		$this->assertSame(
			'sitemap_provider_page_limit',
			$report['failed'][ 'sitemap-provider-limit:' . $provider_id ]['code']
		);
		$this->assertSame( 50001, $report['failed'][ 'sitemap-provider-limit:' . $provider_id ]['raw_pages'] );
		$this->assertTrue( $cursor['failed'] );
	}

	public function test_dependency_failure_from_an_earlier_slice_fences_discovery_indexes(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_bridge( $bridge, 'acquire_operation_lock' ) );
		$this->set_bridge_property( $bridge, 'sync_active', true );
		$this->set_bridge_property( $bridge, 'sync_generation', 3 );
		$this->set_bridge_property( $bridge, 'sync_mode', 'well_known' );
		$this->set_bridge_property( $bridge, 'sync_epoch', 7 );
		$this->set_bridge_property( $bridge, 'sync_started_at', \microtime( true ) );
		$this->set_bridge_property( $bridge, 'sync_report_started_at', \gmdate( 'c' ) );
		$state         = $this->continuation_state(
			StaticSyncRunner::PHASE_DISCOVERY_INDEX,
			array(),
			array(
				'had_problems' => true,
				'rag_failed'   => true,
				'aggregate'    => array(
					'counts'  => array( 'failed' => 1 ),
					'samples' => array(
						'failed' => array(
							'discovery/chunks/99.json' => array(
								'code'    => 'chunk_generation_failed',
								'message' => 'Earlier slice failed.',
							),
						),
					),
				),
			)
		);
		$state['mode'] = 'well_known';
		\update_option( 'cybermaps_static_sync_state', $state, false );

		try {
			$report = $this->empty_report( 'well_known' );
			( new StaticSyncRunner( $bridge ) )->run( $this->all_phase_settings(), $report );

			$index_skips = $report['skipped'];
			unset( $index_skips['stale_reconciliation'] );
			$this->assertNotEmpty( $index_skips );
			foreach ( $index_skips as $skip ) {
				$this->assertSame( 'dependency_failed', $skip['code'] );
			}
			$this->assertSame( 'dependency_failed', $report['skipped']['stale_reconciliation']['code'] );
			$this->assertSame( 'chunk_generation_failed', $report['failed']['discovery/chunks/99.json']['code'] );
			$this->assertSame( 1, $report['_aggregate_counts']['failed'] );
		} finally {
			$this->set_bridge_property( $bridge, 'sync_active', false );
			$this->invoke_bridge( $bridge, 'release_operation_lock' );
		}
	}

	public function test_budget_exhaustion_at_publication_entry_keeps_the_exact_next_cursor(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_bridge( $bridge, 'acquire_operation_lock' ) );
		$this->set_bridge_property( $bridge, 'sync_active', true );
		$this->set_bridge_property( $bridge, 'sync_generation', 3 );
		$this->set_bridge_property( $bridge, 'sync_mode', 'all' );
		$this->set_bridge_property( $bridge, 'sync_epoch', 7 );
		$this->set_bridge_property( $bridge, 'sync_started_at', \microtime( true ) );
		$this->set_bridge_property( $bridge, 'sync_report_started_at', '2026-08-28T00:00:00+00:00' );
		\update_option(
			'cybermaps_static_sync_state',
			$this->continuation_state( StaticSyncRunner::PHASE_SITEMAP_INDEX, array() ),
			false
		);

		// The outer runner budget check happens first. Simulate the wall-clock
		// boundary being crossed while the sitemap publisher is being prepared,
		// immediately before publish_for_sync() performs its own budget check.
		ConfigurationStore::reset_memo();
		$GLOBALS['cybermaps_mock_get_option_observer'] = function ( string $option ) use ( $bridge ): void {
			if ( 'cybermaps_settings' !== $option ) {
				return;
			}
			unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
			$this->set_bridge_property( $bridge, 'sync_deferred', true );
		};

		try {
			$report = $this->empty_report();
			( new StaticSyncRunner( $bridge ) )->run( $this->all_phase_settings(), $report );
			$checkpoint = \get_option( 'cybermaps_static_sync_state', array() );

			$this->assertSame( 'pending', $checkpoint['status'] );
			$this->assertSame( StaticSyncRunner::PHASE_SITEMAP_INDEX, $checkpoint['phase'] );
			$this->assertSame( array(), $checkpoint['cursor'] );
			$this->assertSame( array(), $report['desired'] );
			$this->assertSame( array(), $report['skipped'] );
		} finally {
			unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
			$this->set_bridge_property( $bridge, 'sync_active', false );
			$this->invoke_bridge( $bridge, 'release_operation_lock' );
			ConfigurationStore::reset_memo();
		}
	}

	public function test_lease_loss_preserves_the_last_pending_checkpoint_and_schedules_retry(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_bridge( $bridge, 'acquire_operation_lock' ) );
		$this->set_bridge_property( $bridge, 'sync_active', true );
		$this->set_bridge_property( $bridge, 'sync_generation', 3 );
		$this->set_bridge_property( $bridge, 'sync_mode', 'all' );
		$this->set_bridge_property( $bridge, 'sync_epoch', 7 );
		$this->set_bridge_property( $bridge, 'sync_report_started_at', '2026-08-28T00:00:00+00:00' );
		$bridge->runner_save_state(
			StaticSyncRunner::PHASE_RAG_CHUNKS,
			array(
				'selector'      => array(
					'type_index' => 0,
					'page'       => 1,
					'position'   => 25,
				),
				'pending_ids'   => array( 26, 27 ),
				'pending_index' => 1,
			),
			true,
			array( 'rag_failed' => false )
		);
		$checkpoint = \get_option( 'cybermaps_static_sync_state', array() );
		$this->assertSame( 'pending', $checkpoint['status'] );

		$successor = array(
			'token' => 'successor-owner',
			'time'  => \time(),
		);
		\update_option( StaticBridge::OPERATION_LOCK_OPTION, $successor, false );
		$this->assertFalse( $bridge->heartbeat() );

		$bridge->recover_interrupted_sync();

		$this->assertSame( $checkpoint, \get_option( 'cybermaps_static_sync_state', array() ) );
		$this->assertNotFalse( \wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
		$this->invoke_bridge( $bridge, 'release_operation_lock' );
		$this->assertSame( $successor, \get_option( StaticBridge::OPERATION_LOCK_OPTION ) );
		$this->set_bridge_property( $bridge, 'sync_active', false );
	}

	public function test_shutdown_recovery_cannot_overwrite_a_successor_before_local_lease_loss_is_observed(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_bridge( $bridge, 'acquire_operation_lock' ) );
		$this->set_bridge_property( $bridge, 'sync_active', true );
		$this->set_bridge_property( $bridge, 'sync_generation', 3 );
		$this->set_bridge_property( $bridge, 'sync_mode', 'all' );
		$this->set_bridge_property( $bridge, 'sync_epoch', 7 );
		$this->set_bridge_property( $bridge, 'sync_report_started_at', '2026-08-28T00:00:00+00:00' );

		$successor_state = array(
			'schema'     => StaticSyncRunner::STATE_SCHEMA,
			'status'     => 'running',
			'generation' => 3,
			'mode'       => 'all',
			'epoch'      => 8,
			'phase'      => StaticSyncRunner::PHASE_DISCOVERY_LEAF,
			'cursor'     => array( 'target_index' => 2 ),
			'context'    => array(),
		);
		\update_option(
			StaticBridge::OPERATION_LOCK_OPTION,
			array(
				'token' => 'successor-owner',
				'time'  => \time(),
			),
			false
		);
		\update_option( 'cybermaps_static_sync_state', $successor_state, false );

		$bridge->recover_interrupted_sync();

		$this->assertSame( $successor_state, \get_option( 'cybermaps_static_sync_state' ) );
		$this->assertNotFalse( \wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
		$this->invoke_bridge( $bridge, 'release_operation_lock' );
		$this->set_bridge_property( $bridge, 'sync_active', false );
	}

	/** @return array<string,mixed> */
	private function all_phase_settings(): array {
		return array(
			'static_engine_mode'      => 'all',
			'enable_discovery_hub'    => '1',
			'enable_rag_chunks'       => '1',
			'enable_multilingual_hub' => '1',
			'enable_llms_full'        => '1',
			'enable_llms_tldr'        => '1',
			'enable_rss_sitemap'      => '1',
		);
	}

	/**
	 * @param array<string,mixed> $cursor
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function continuation_state( string $phase, array $cursor, array $context = array() ): array {
		$context['topology'] = ( new StaticSyncRunner( StaticBridge::get_instance() ) )->topology_fingerprint(
			$this->all_phase_settings(),
			'all'
		);
		return array(
			'schema'     => StaticSyncRunner::STATE_SCHEMA,
			'status'     => 'pending',
			'generation' => 3,
			'mode'       => 'all',
			'epoch'      => 7,
			'phase'      => $phase,
			'cursor'     => $cursor,
			'context'    => $context,
			'started_at' => '2026-08-28T00:00:00+00:00',
			'retry_at'   => 0,
			'writes'     => 0,
			'last_error' => '',
		);
	}

	/** @return array<string,mixed> */
	private function empty_report( string $mode = 'all' ): array {
		return array(
			'success'     => false,
			'status'      => 'running',
			'mode'        => $mode,
			'generation'  => 3,
			'sync_epoch'  => 7,
			'started_at'  => '2026-08-28T00:00:00+00:00',
			'finished_at' => '',
			'desired'     => array(),
			'written'     => array(),
			'unchanged'   => array(),
			'omitted'     => array(),
			'conflicted'  => array(),
			'failed'      => array(),
			'skipped'     => array(),
			'deleted'     => array(),
			'retained'    => array(),
			'counts'      => array(),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 */
	private function invoke_runner_by_reference(
		StaticSyncRunner $runner,
		string $method,
		array &$context,
		array &$report
	): void {
		$arguments = array( &$context, &$report );
		( new \ReflectionMethod( StaticSyncRunner::class, $method ) )->invokeArgs( $runner, $arguments );
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $context
	 */
	private function invoke_runner_hydrate( StaticSyncRunner $runner, array &$report, array $context ): void {
		$arguments = array( &$report, $context );
		( new \ReflectionMethod( StaticSyncRunner::class, 'hydrate_report' ) )->invokeArgs( $runner, $arguments );
	}

	private function invoke_bridge( StaticBridge $bridge, string $method ): mixed {
		return ( new \ReflectionMethod( StaticBridge::class, $method ) )->invoke( $bridge );
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $value
	 */
	private function invoke_bridge_with_report(
		StaticBridge $bridge,
		string $method,
		array &$report,
		array $value
	): void {
		$arguments = array( &$report, $value );
		( new \ReflectionMethod( StaticBridge::class, $method ) )->invokeArgs( $bridge, $arguments );
	}

	/** @return string[] */
	private function list_samples( string $prefix, int $count ): array {
		$samples = array();
		for ( $index = 1; $index <= $count; ++$index ) {
			$samples[] = $prefix . '-' . $index . '.txt';
		}
		return $samples;
	}

	/** @return array<string,array{code:string}> */
	private function map_samples( string $prefix, int $count ): array {
		$samples = array();
		for ( $index = 1; $index <= $count; ++$index ) {
			$samples[ $prefix . '-' . $index . '.txt' ] = array( 'code' => $prefix . '_fixture' );
		}
		return $samples;
	}

	/** @param array<string,mixed> $record */
	private function seed_occupancy_manifest( string $provider_id, array $record ): void {
		$generation = 11;
		$token      = \str_repeat( 'a', 32 );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['sitemap_url_base'] = 'site-map';
		ConfigurationStore::reset_memo();
		\update_option( PageOccupancyManifest::GENERATION_OPTION, $generation, false );
		\update_option( PageOccupancyManifest::TOKEN_OPTION, $token, false );
		\update_option(
			PageOccupancyManifest::MANIFEST_OPTION,
			array(
				'generation' => $generation,
				'token'      => $token,
				'complete'   => true,
				'providers'  => array( $provider_id => $record ),
			),
			false
		);
	}

	private function recording_bridge(): StaticBridge {
		return new class() extends StaticBridge {
			/** @var string[] */
			public array $published = array();

			public function __construct() {
			}

			public function runner_budget_exhausted(): bool {
				return false;
			}

			public function runner_publish(
				array &$report,
				string $filename,
				callable $content_factory,
				bool $allow_omission = false
			): bool {
				unset( $content_factory, $allow_omission );
				$this->published[]   = $filename;
				$report['desired'][] = $filename;
				return true;
			}

			public function runner_get_problem_count( array $report ): int {
				unset( $report );
				return 0;
			}
		};
	}

	private function orchestrator_for_static_pages( string $provider_id, int $count ): Orchestrator {
		$provider = new class( $count ) implements ProviderInterface {
			public function __construct( private int $count ) {
			}

			public function get_urls( int $page ): array {
				return array( array( 'loc' => 'https://example.com/post/' . $page . '/' ) );
			}

			public function get_count(): int {
				return $this->count;
			}

			public function get_lastmod(): string {
				return '2026-08-28T00:00:00+00:00';
			}
		};

		return new class( $provider_id, $provider ) extends Orchestrator {
			public function __construct(
				private string $provider_id,
				private ProviderInterface $provider
			) {
				parent::__construct();
			}

			public function collect_weighted_provider_ids(): array {
				return array( $this->provider_id );
			}

			public function get_provider( string $type ) {
				return $type === $this->provider_id ? $this->provider : null;
			}
		};
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function invoke_sitemap_children_phase(
		StaticSyncRunner $runner,
		array &$report,
		Orchestrator $orchestrator,
		array &$cursor
	): bool {
		$arguments = array( &$report, $orchestrator, &$cursor );
		return (bool) ( new \ReflectionMethod( StaticSyncRunner::class, 'run_sitemap_children_phase' ) )->invokeArgs(
			$runner,
			$arguments
		);
	}

	private function set_bridge_property( StaticBridge $bridge, string $property, mixed $value ): void {
		( new \ReflectionProperty( StaticBridge::class, $property ) )->setValue( $bridge, $value );
	}

	private function reset_endpoint_registry(): void {
		( new \ReflectionProperty( EndpointRegistry::class, 'instance' ) )->setValue( null, null );
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

	private function reset_bridge_state(): void {
		$bridge = StaticBridge::get_instance();
		foreach (
				array(
					'operation_lock_token'       => null,
					'operation_generation'       => null,
					'operation_lock_lost'        => false,
					'ownership_dirty'            => false,
					'ownership_repair_needed'    => false,
					'ownership_ready'            => true,
					'ownership_changes'          => 0,
					'ownership_revision_pending' => false,
					'sync_active'                => false,
					'sync_deferred'              => false,
					'sync_written_count'         => 0,
					'sync_started_at'            => 0.0,
					'sync_epoch'                 => 0,
					'sync_generation'            => 0,
					'sync_mode'                  => 'off',
					'sync_report_started_at'     => '',
					'sync_completed'             => array(),
					'sync_omitted'               => array(),
				) as $property => $value
		) {
			$this->set_bridge_property( $bridge, $property, $value );
		}
		$lock = ( new \ReflectionProperty( StaticBridge::class, 'operation_lock' ) )->getValue( $bridge );
		$lock->reset_local_state();
		$store = ( new \ReflectionProperty( StaticBridge::class, 'ownership_store' ) )->getValue( $bridge );
		$store->clear_local_cache();
	}
}
