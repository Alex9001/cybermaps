<?php
declare(strict_types=1);

namespace Cybermaps\Tests\SEO;

use Cybermaps\Core\CacheManager;
use Cybermaps\SEO\IndexabilityInvalidator;

final class IndexabilityInvalidatorTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['wp_hooks']                    = array();
		$GLOBALS['cybermaps_mock_options']      = array(
			'cybermaps_settings' => array( 'static_engine_mode' => 'well_known' ),
		);
		$GLOBALS['cybermaps_mock_transients']   = array();
		$GLOBALS['cybermaps_mock_scheduled']    = array();
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post'          => (object) array( 'name' => 'post', 'public' => true ),
			'internal_note' => (object) array( 'name' => 'internal_note', 'public' => false ),
		);
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'category', 'internal_topic' );
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array(
			'category'       => (object) array( 'name' => 'category', 'public' => true ),
			'internal_topic' => (object) array( 'name' => 'internal_topic', 'public' => false ),
		);
		$GLOBALS['cybermaps_mock_terms'] = array(
			'category'       => array( (object) array( 'term_id' => 31, 'taxonomy' => 'category' ) ),
			'internal_topic' => array( (object) array( 'term_id' => 32, 'taxonomy' => 'internal_topic' ) ),
		);
		$GLOBALS['cybermaps_mock_posts'] = array(
			20 => (object) array( 'ID' => 20, 'post_type' => 'post', 'post_status' => 'publish' ),
			21 => (object) array( 'ID' => 21, 'post_type' => 'post', 'post_status' => 'publish' ),
		);
	}

	public function test_all_publication_meta_keys_register_with_full_meta_hook_contract(): void {
		$invalidator = new IndexabilityInvalidator();
		$invalidator->register_hooks();

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			$matches = array_values(
				array_filter(
					$GLOBALS['wp_hooks'],
					static fn( array $record ): bool => $hook === $record['hook']
				)
			);
			$this->assertCount( 1, $matches );
			$this->assertSame( 4, $matches[0]['accepted_args'] );
		}
	}

	public function test_content_meta_clears_dynamic_caches_without_syncing_well_known_mode(): void {
		$this->seed_caches();
		$invalidator = new IndexabilityInvalidator();

		$invalidator->on_post_meta( 1, 20, '_cybermaps_sitemap_priority', 0.8 );

		$this->assertFalse( get_transient( 'cybermaps_test_sitemap' ) );
		$this->assertFalse( get_transient( 'cybermaps_test_discovery' ) );
		$this->assertFalse( get_transient( 'cybermaps_test_chunks' ) );
		$this->assertArrayNotHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayHasKey( 'cybermaps_bg_build_sitemap_occupancy', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_meta_on_draft_or_non_public_post_type_does_not_invalidate(): void {
		$GLOBALS['cybermaps_mock_posts'][22] = (object) array(
			'ID'          => 22,
			'post_type'   => 'post',
			'post_status' => 'draft',
		);
		$GLOBALS['cybermaps_mock_posts'][23] = (object) array(
			'ID'          => 23,
			'post_type'   => 'internal_note',
			'post_status' => 'publish',
		);
		$this->seed_caches();
		$invalidator = new IndexabilityInvalidator();

		$invalidator->on_post_meta( 1, 22, '_cybermaps_intent_override', 'transactional' );
		$invalidator->on_post_meta( 2, 23, '_cybermaps_sitemap_priority', 0.7 );

		$this->assertSame( '<xml/>', get_transient( 'cybermaps_test_sitemap' ) );
		$this->assertSame( '{}', get_transient( 'cybermaps_test_discovery' ) );
		$this->assertSame( array( 'chunks' ), get_transient( 'cybermaps_test_chunks' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_generated_ai_cache_meta_does_not_invalidate_publications(): void {
		$this->seed_caches();
		$invalidator = new IndexabilityInvalidator();

		$invalidator->on_post_meta(
			1,
			20,
			'_cybermaps_ai_meta',
			array( 'snippet' => 'Save-time derived metadata' )
		);

		$this->assertSame( '<xml/>', get_transient( 'cybermaps_test_sitemap' ) );
		$this->assertSame( '{}', get_transient( 'cybermaps_test_discovery' ) );
		$this->assertSame( array( 'chunks' ), get_transient( 'cybermaps_test_chunks' ) );
		$this->assertArrayNotHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_irrelevant_term_meta_is_ignored_but_unresolved_term_is_conservative(): void {
		$this->seed_caches();
		$invalidator = new IndexabilityInvalidator();

		$invalidator->on_term_meta( 1, 32, 'noindex', '1' );

		$this->assertSame( '<xml/>', get_transient( 'cybermaps_test_sitemap' ) );
		$this->assertSame( '{}', get_transient( 'cybermaps_test_discovery' ) );
		$this->assertSame( array( 'chunks' ), get_transient( 'cybermaps_test_chunks' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );

		$invalidator->on_term_meta( 2, 999, 'noindex', '1' );

		$this->assertFalse( get_transient( 'cybermaps_test_sitemap' ) );
		$this->assertArrayHasKey( 'cybermaps_bg_build_sitemap_occupancy', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_user_meta_invalidation_respects_include_authors_setting(): void {
		$this->seed_caches();
		$invalidator = new IndexabilityInvalidator();
		$invalidator->on_user_meta( 1, 7, 'noindex', '1' );

		$this->assertSame( '<xml/>', get_transient( 'cybermaps_test_sitemap' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'include_authors'    => '1',
			'static_engine_mode' => 'all',
		);
		$this->seed_caches();
		( new IndexabilityInvalidator() )->on_user_meta( 2, 7, 'noindex', '1' );

		$this->assertFalse( get_transient( 'cybermaps_test_sitemap' ) );
		$this->assertArrayHasKey( 'cybermaps_bg_build_sitemap_occupancy', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_content_meta_invalidates_and_schedules_full_static_mode_once(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$this->seed_caches();
		$invalidator = new IndexabilityInvalidator();

		$invalidator->on_post_meta( 1, 20, '_cybermaps_media_audit', array() );
		$generation = get_option( 'cybermaps_static_generation', 0 );
		$invalidator->on_post_meta( 2, 21, '_cybermaps_intent_override', 'transactional' );

		$this->assertSame( 1, $generation );
		$this->assertSame( $generation, get_option( 'cybermaps_static_generation', 0 ) );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( 'cybermaps_bg_build_sitemap_occupancy', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertCount( 2, $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_global_change_still_syncs_well_known_after_content_invalidation(): void {
		$this->seed_caches();
		$invalidator = new IndexabilityInvalidator();
		$invalidator->on_post_meta( 1, 20, '_cybermaps_intent_override', 'transactional' );
		$invalidator->on_updated_option( 'active_plugins', array(), array( 'example/plugin.php' ) );

		$this->assertFalse( get_transient( 'cybermaps_test_chunks' ) );
		$this->assertSame( 1, get_option( 'cybermaps_static_generation', 0 ) );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	private function seed_caches(): void {
		CacheManager::set( 'cybermaps_test_sitemap', '<xml/>', HOUR_IN_SECONDS, 'sitemap' );
		CacheManager::set( 'cybermaps_test_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );
		CacheManager::set( 'cybermaps_test_chunks', array( 'chunks' ), HOUR_IN_SECONDS, 'chunks' );
	}
}
