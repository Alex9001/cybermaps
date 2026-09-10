<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PageOccupancyBuilder;
use Cybermaps\Sitemap\PageOccupancyManifest;
use Cybermaps\Sitemap\ProviderIdentity;
use Cybermaps\Sitemap\ProviderInterface;

final class PageOccupancyTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']           = array(
			'cybermaps_settings' => array(
				'include_homepage' => '1',
				'include_authors'  => '0',
				'include_archives' => '0',
				'sitemap_url_base' => 'site-map',
			),
		);
		$GLOBALS['cybermaps_mock_post_types']        = array();
		$GLOBALS['cybermaps_mock_taxonomies']        = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		$GLOBALS['cybermaps_mock_taxonomy_objects']  = array();
		$GLOBALS['cybermaps_mock_scheduled']         = array();
		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		\delete_option( PageOccupancyManifest::MANIFEST_OPTION );
		\delete_option( PageOccupancyBuilder::WORK_OPTION );
		\delete_option( PageOccupancyBuilder::STATE_OPTION );
		\delete_option( PageOccupancyBuilder::LOCK_OPTION );
		\update_option( PageOccupancyManifest::GENERATION_OPTION, 0, false );
		\update_option( PageOccupancyManifest::TOKEN_OPTION, str_repeat( 'a', 32 ), false );
	}

	public function test_completed_manifest_omits_known_empty_raw_page_from_index(): void {
		$GLOBALS['cybermaps_mock_post_types']                = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);

		$provider = new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				return 2 === $page
					? array(
						array(
							'loc'     => 'https://example.com/p/1',
							'lastmod' => '2026-01-02T00:00:00+00:00',
						),
					)
					: array();
			}

			public function get_count(): int {
				return 4000;
			}

			public function get_lastmod(): string {
				return '2026-01-01T00:00:00+00:00';
			}
		};

		$orchestrator = new Orchestrator();
		$this->inject_providers(
			$orchestrator,
			array( ProviderIdentity::post_type( 'post' ) => $provider )
		);

		$generation = 3;
		\update_option( PageOccupancyManifest::GENERATION_OPTION, $generation, false );
		\update_option(
			PageOccupancyManifest::MANIFEST_OPTION,
			array(
				'generation' => $generation,
				'token'      => PageOccupancyManifest::current_token(),
				'complete'   => true,
				'providers'  => array(
					ProviderIdentity::post_type( 'post' ) => array(
						'raw_count'       => 4000,
						'raw_page_count'  => 2,
						'non_empty_pages' => array( 2 ),
						'page_lastmod'    => array(
							2 => '2026-01-02T00:00:00+00:00',
						),
					),
				),
			),
			false
		);

		$entries = $orchestrator->get_internal_sitemap_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 2, $entries[0]['page'] );
		$this->assertSame( 'site-map-posts-post-2.xml', $entries[0]['filename'] );

		$index = $orchestrator->generate_xml( 'index', 1 );
		$this->assertStringContainsString( 'site-map-posts-post-2.xml', $index );
		$this->assertStringNotContainsString( 'site-map-posts-post-1.xml', $index );
	}

	public function test_raw_upper_bound_fallback_while_manifest_is_incomplete(): void {
		$GLOBALS['cybermaps_mock_post_types']                = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);

		$provider = new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				unset( $page );
				return array();
			}

			public function get_count(): int {
				return 4000;
			}

			public function get_lastmod(): string {
				return '';
			}
		};

		$orchestrator = new Orchestrator();
		$this->inject_providers(
			$orchestrator,
			array( ProviderIdentity::post_type( 'post' ) => $provider )
		);

		$entries      = $orchestrator->get_internal_sitemap_entries();
		$post_entries = array_values(
			array_filter(
				$entries,
				static fn( array $entry ): bool => ProviderIdentity::post_type( 'post' ) === ( $entry['provider_id'] ?? '' )
			)
		);
		$this->assertCount( 2, $post_entries );
		$this->assertSame( 1, $post_entries[0]['page'] );
		$this->assertSame( 2, $post_entries[1]['page'] );
	}

	public function test_builder_replaces_raw_fallback_with_page_two_only_advertisement(): void {
		$GLOBALS['cybermaps_mock_post_types']                = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);

		$provider = new class() implements ProviderInterface {
			/** @var int[] */
			public array $visited_pages = array();

			public function get_urls( int $page ): array {
				$this->visited_pages[] = $page;
				return 2 === $page
					? array(
						array(
							'loc'     => 'https://example.com/page-two/',
							'lastmod' => '2026-08-28T00:00:00+00:00',
						),
					)
					: array();
			}

			public function get_count(): int {
				return 4000;
			}

			public function get_lastmod(): string {
				return '2026-08-28T00:00:00+00:00';
			}
		};

		$fallback_orchestrator = new Orchestrator();
		$this->inject_providers(
			$fallback_orchestrator,
			array( ProviderIdentity::post_type( 'post' ) => $provider )
		);
		$fallback_entries = array_values(
			array_filter(
				$fallback_orchestrator->get_internal_sitemap_entries(),
				static fn( array $entry ): bool => ProviderIdentity::post_type( 'post' ) === $entry['provider_id']
			)
		);
		$this->assertSame( array( 1, 2 ), array_column( $fallback_entries, 'page' ) );

		$builder_orchestrator = $this->orchestrator_for_provider(
			ProviderIdentity::post_type( 'post' ),
			$provider
		);
		( new PageOccupancyBuilder( $builder_orchestrator ) )->process_slice();

		$this->assertSame( array( 1, 2 ), $provider->visited_pages );
		$this->assertTrue( PageOccupancyManifest::is_usable() );

		$completed_orchestrator = new Orchestrator();
		$this->inject_providers(
			$completed_orchestrator,
			array( ProviderIdentity::post_type( 'post' ) => $provider )
		);
		$completed_entries = array_values(
			array_filter(
				$completed_orchestrator->get_internal_sitemap_entries(),
				static fn( array $entry ): bool => ProviderIdentity::post_type( 'post' ) === $entry['provider_id']
			)
		);

		$this->assertCount( 1, $completed_entries );
		$this->assertSame( 2, $completed_entries[0]['page'] );
		$this->assertSame( 'site-map-posts-post-2.xml', $completed_entries[0]['filename'] );
	}

	public function test_current_manifest_rejects_malformed_provider_records(): void {
		$generation = 8;
		\update_option( PageOccupancyManifest::GENERATION_OPTION, $generation, false );
		\update_option( PageOccupancyManifest::TOKEN_OPTION, str_repeat( 'b', 32 ), false );
		$manifest = array(
			'generation' => $generation,
			'token'      => str_repeat( 'b', 32 ),
			'complete'   => true,
			'providers'  => array(
				ProviderIdentity::post_type( 'post' ) => array(
					'raw_count'       => 4000,
					'raw_page_count'  => 2,
					'non_empty_pages' => array( 2, 1 ),
					'page_lastmod'    => array(),
				),
			),
		);

		$this->assertFalse( PageOccupancyManifest::is_usable( $manifest ) );
		$this->assertNull( PageOccupancyManifest::provider_pages( $manifest, ProviderIdentity::post_type( 'post' ) ) );
	}

	public function test_usable_manifest_treats_missing_provider_as_confirmed_empty(): void {
		$generation = 9;
		\update_option( PageOccupancyManifest::GENERATION_OPTION, $generation, false );
		\update_option( PageOccupancyManifest::TOKEN_OPTION, str_repeat( 'c', 32 ), false );
		$manifest = array(
			'generation' => $generation,
			'token'      => str_repeat( 'c', 32 ),
			'complete'   => true,
			'providers'  => array(),
		);

		$this->assertTrue( PageOccupancyManifest::is_usable( $manifest ) );
		$this->assertSame( array(), PageOccupancyManifest::provider_pages( $manifest, ProviderIdentity::post_type( 'post' ) ) );
	}

	public function test_resumed_static_omissions_filter_a_fresh_index_inventory(): void {
		$orchestrator = new Orchestrator();
		$property     = new \ReflectionProperty( Orchestrator::class, 'internal_sitemap_entries' );
		$property->setValue(
			$orchestrator,
			array(
				array(
					'provider_id'   => ProviderIdentity::post_type( 'post' ),
					'provider_kind' => 'post_type',
					'provider_name' => 'post',
					'page'          => 1,
					'filename'      => 'site-map-posts-post-1.xml',
					'loc'           => 'https://example.com/site-map-posts-post-1.xml',
					'lastmod'       => '',
				),
				array(
					'provider_id'   => ProviderIdentity::post_type( 'post' ),
					'provider_kind' => 'post_type',
					'provider_name' => 'post',
					'page'          => 2,
					'filename'      => 'site-map-posts-post-2.xml',
					'loc'           => 'https://example.com/site-map-posts-post-2.xml',
					'lastmod'       => '',
				),
			)
		);

		$orchestrator->apply_internal_sitemap_omissions(
			array( ProviderIdentity::post_type( 'post' ) => array( 1 ) )
		);

		$entries = $orchestrator->get_internal_sitemap_entries();
		$this->assertSame( array( 2 ), array_column( $entries, 'page' ) );
	}

	public function test_manifest_requires_the_current_generation_token(): void {
		$generation = 7;
		\update_option( PageOccupancyManifest::GENERATION_OPTION, $generation, false );
		\update_option( PageOccupancyManifest::TOKEN_OPTION, str_repeat( 'a', 32 ), false );
		$manifest = array(
			'generation' => $generation,
			'token'      => str_repeat( 'a', 32 ),
			'complete'   => true,
			'providers'  => array(),
		);

		$this->assertTrue( PageOccupancyManifest::is_usable( $manifest ) );
		\update_option( PageOccupancyManifest::TOKEN_OPTION, str_repeat( 'b', 32 ), false );
		$this->assertFalse( PageOccupancyManifest::is_usable( $manifest ) );
	}

	public function test_lock_contention_schedules_a_retry_instead_of_dropping_the_slice(): void {
		\update_option(
			PageOccupancyBuilder::LOCK_OPTION,
			array(
				'token' => 'another-worker',
				'time'  => time(),
			),
			false
		);
		$builder = new PageOccupancyBuilder( new Orchestrator() );

		$builder->process_slice();

		$this->assertArrayHasKey( PageOccupancyBuilder::HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertSame( 'another-worker', get_option( PageOccupancyBuilder::LOCK_OPTION )['token'] );
	}

	public function test_token_change_during_scan_prevents_stale_manifest_publication(): void {
		$provider_id = ProviderIdentity::post_type( 'post' );
		$provider    = new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				unset( $page );
				\update_option( PageOccupancyManifest::TOKEN_OPTION, str_repeat( 'b', 32 ), false );
				return array( array( 'loc' => 'https://example.com/post/' ) );
			}

			public function get_count(): int {
				return 1;
			}

			public function get_lastmod(): string {
				return '2026-08-28T00:00:00+00:00';
			}
		};
		$builder     = new PageOccupancyBuilder( $this->orchestrator_for_provider( $provider_id, $provider ) );

		$builder->process_slice();

		$this->assertNull( PageOccupancyManifest::load() );
		$state = get_option( PageOccupancyBuilder::STATE_OPTION );
		$this->assertSame( 'stale', $state['status'] );
		$this->assertSame( str_repeat( 'b', 32 ), $state['token'] );
		$this->assertArrayHasKey( PageOccupancyBuilder::HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_lost_lease_does_not_delete_checkpoint_or_overwrite_successor_state(): void {
		$provider_id = ProviderIdentity::post_type( 'post' );
		$checkpoint  = array(
			'generation'        => PageOccupancyManifest::current_generation(),
			'token'             => PageOccupancyManifest::current_token(),
			'provider_ids'      => array( $provider_id ),
			'provider_index'    => 0,
			'next_page'         => 1,
			'providers'         => array(),
			'scanned_raw_pages' => 0,
			'non_empty_pages'   => 0,
			'empty_pages'       => 0,
			'complete'          => false,
		);
		update_option( PageOccupancyBuilder::WORK_OPTION, $checkpoint, false );
		$provider = new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				unset( $page );
				update_option(
					PageOccupancyBuilder::LOCK_OPTION,
					array(
						'token' => 'successor-worker',
						'time'  => time(),
					),
					false
				);
				update_option(
					PageOccupancyBuilder::STATE_OPTION,
					array( 'status' => 'successor-building' ),
					false
				);
				return array( array( 'loc' => 'https://example.com/post/' ) );
			}

			public function get_count(): int {
				return 1;
			}

			public function get_lastmod(): string {
				return '';
			}
		};
		$builder  = new PageOccupancyBuilder( $this->orchestrator_for_provider( $provider_id, $provider ) );

		$builder->process_slice();

		$this->assertSame( $checkpoint, get_option( PageOccupancyBuilder::WORK_OPTION ) );
		$this->assertSame( 'successor-building', get_option( PageOccupancyBuilder::STATE_OPTION )['status'] );
		$this->assertSame( 'successor-worker', get_option( PageOccupancyBuilder::LOCK_OPTION )['token'] );
		$this->assertArrayHasKey( PageOccupancyBuilder::HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_exact_global_ceiling_completes_without_entering_limited_state(): void {
		$provider_id = ProviderIdentity::post_type( 'post' );
		$provider    = $this->page_provider();
		$this->seed_boundary_work( $provider_id, 50000 );
		$builder = new PageOccupancyBuilder( $this->orchestrator_for_provider( $provider_id, $provider ) );

		$builder->process_slice();

		$this->assertTrue( PageOccupancyManifest::is_usable() );
		$state = get_option( PageOccupancyBuilder::STATE_OPTION );
		$this->assertSame( 'ready', $state['status'] );
		$this->assertSame( 50000, $state['scanned_raw_pages'] );
		$this->assertSame( 0, $state['unknown_pages'] );
	}

	public function test_page_beyond_global_ceiling_is_terminal_and_counted_unknown(): void {
		$provider_id = ProviderIdentity::post_type( 'post' );
		$provider    = $this->page_provider();
		$this->seed_boundary_work( $provider_id, 50001 );
		$builder = new PageOccupancyBuilder( $this->orchestrator_for_provider( $provider_id, $provider ) );

		$builder->process_slice();

		$this->assertFalse( PageOccupancyManifest::is_usable() );
		$state = get_option( PageOccupancyBuilder::STATE_OPTION );
		$this->assertSame( 'limited', $state['status'] );
		$this->assertSame( 50000, $state['scanned_raw_pages'] );
		$this->assertSame( 1, $state['unknown_pages'] );
		$this->assertArrayNotHasKey( PageOccupancyBuilder::HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
	}

	/**
	 * @param array<string, ProviderInterface> $providers
	 */
	private function inject_providers( Orchestrator $orchestrator, array $providers ): void {
		$property = new \ReflectionProperty( Orchestrator::class, 'providers' );
		$property->setValue( $orchestrator, $providers );
	}

	private function orchestrator_for_provider( string $provider_id, ProviderInterface $provider ): Orchestrator {
		return new class( $provider_id, $provider ) extends Orchestrator {
			public function __construct(
				private string $test_provider_id,
				private ProviderInterface $test_provider
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
	}

	private function page_provider(): ProviderInterface {
		return new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				return array(
					array(
						'loc'     => 'https://example.com/page/' . $page . '/',
						'lastmod' => '2026-08-28T00:00:00+00:00',
					),
				);
			}

			public function get_count(): int {
				return 50001;
			}

			public function get_lastmod(): string {
				return '2026-08-28T00:00:00+00:00';
			}
		};
	}

	private function seed_boundary_work( string $provider_id, int $raw_page_count ): void {
		\update_option(
			PageOccupancyBuilder::WORK_OPTION,
			array(
				'generation'        => PageOccupancyManifest::current_generation(),
				'token'             => PageOccupancyManifest::current_token(),
				'provider_ids'      => array( $provider_id ),
				'provider_index'    => 0,
				'next_page'         => 50000,
				'providers'         => array(
					$provider_id => array(
						'raw_count'       => $raw_page_count,
						'raw_page_count'  => $raw_page_count,
						'non_empty_pages' => array(),
						'page_lastmod'    => array(),
					),
				),
				'scanned_raw_pages' => 49999,
				'non_empty_pages'   => 0,
				'empty_pages'       => 49999,
				'complete'          => false,
			),
			false
		);
	}
}
