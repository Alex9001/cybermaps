<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\CacheManager;
use Cybermaps\Discovery\ADP;
use Cybermaps\Discovery\LLMS;
use Cybermaps\Discovery\LLMSTLDR;
use Cybermaps\Discovery\Manager;
use Cybermaps\Discovery\Robots;
use Cybermaps\Sitemap\Orchestrator;

final class PublicationReconciliationTest extends \WP_UnitTestCase {
	private Manager $manager;
	private Orchestrator $orchestrator;
	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wp_hooks']                    = array();
		$GLOBALS['cybermaps_mock_options']      = array(
			'cybermaps_settings' => array( 'static_engine_mode' => 'well_known' ),
		);
		$GLOBALS['cybermaps_mock_transients']   = array();
		$GLOBALS['cybermaps_mock_scheduled']    = array();
		$GLOBALS['cybermaps_mock_posts']        = array();
		$GLOBALS['cybermaps_mock_comments']     = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post'          => (object) array( 'name' => 'post', 'public' => true, 'taxonomies' => array( 'category' ) ),
			'internal_note' => (object) array( 'name' => 'internal_note', 'public' => false ),
		);
		$GLOBALS['cybermaps_mock_taxonomies']       = array( 'category', 'internal_topic' );
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array(
			'category'       => (object) array( 'name' => 'category', 'public' => true ),
			'internal_topic' => (object) array( 'name' => 'internal_topic', 'public' => false ),
		);
		$GLOBALS['cybermaps_mock_is_multisite'] = false;

		$this->manager      = new Manager( new ADP(), new Robots() );
		$this->orchestrator = new Orchestrator();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_mutation_hooks_keep_complete_wordpress_argument_contracts(): void {
		$this->manager->register_hooks();
		$this->orchestrator->register_hooks();

		$this->assertHookContract( 'save_post', 2, array( $this->manager, 'on_post_saved' ) );
		$this->assertHookContract( 'transition_post_status', 3, array( $this->manager, 'on_post_status_transition' ) );
		$this->assertHookContract( 'delete_post', 2, array( $this->manager, 'on_post_deleted' ) );
		$this->assertHookContract( 'set_object_terms', 6, array( $this->manager, 'on_object_terms_set' ) );
		$this->assertHookContract( 'save_post', 2, array( $this->orchestrator, 'handle_published_post_save' ) );
		$this->assertHookContract( 'transition_post_status', 3, array( $this->orchestrator, 'handle_post_status_transition' ) );
		$this->assertHookContract( 'delete_post', 2, array( $this->orchestrator, 'handle_published_post_delete' ) );
		$this->assertHookContract( 'set_object_terms', 6, array( $this->orchestrator, 'handle_published_post_terms' ) );
	}

	public function test_revision_draft_and_private_saves_do_not_invalidate_or_schedule(): void {
		foreach (
			array(
				$this->post( 11, 'inherit', 'revision' ),
				$this->post( 12, 'draft' ),
				$this->post( 13, 'private' ),
			) as $post
		) {
			$this->seed_publication_caches();
			$this->manager->on_post_saved( $post->ID, $post );
			$this->orchestrator->handle_published_post_save( $post->ID, $post );

			$this->assertSame( 'summary', get_transient( LLMS::SUMMARY_CACHE_KEY ) );
			$this->assertSame( 'full', get_transient( LLMS::FULL_CACHE_KEY ) );
			$this->assertSame( 'tldr', get_transient( LLMSTLDR::CACHE_KEY ) );
			$this->assertSame( '<rss/>', get_transient( 'cybermaps_rss_sitemap' ) );
			$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
		}
	}

	public function test_published_non_public_post_type_does_not_invalidate_or_schedule(): void {
		$post = $this->post( 14, 'publish', 'internal_note' );
		$this->seed_publication_caches();

		$this->manager->on_post_saved( $post->ID, $post );
		$this->orchestrator->handle_published_post_save( $post->ID, $post );
		$this->manager->on_post_status_transition( 'draft', 'publish', $post );
		$this->orchestrator->handle_post_status_transition( 'draft', 'publish', $post );

		$this->assertSame( 'summary', get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertSame( '<rss/>', get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_published_save_clears_dynamic_caches_without_rebuilding_well_known_files(): void {
		$post = $this->post( 21, 'publish' );
		$this->seed_publication_caches();

		$this->manager->on_post_saved( $post->ID, $post );
		$this->orchestrator->handle_published_post_save( $post->ID, $post );

		$this->assertFalse( get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertFalse( get_transient( LLMS::FULL_CACHE_KEY ) );
		$this->assertFalse( get_transient( LLMSTLDR::CACHE_KEY ) );
		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_published_save_queues_one_full_static_reconciliation_in_all_mode(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$post = $this->post( 31, 'publish' );

		$this->manager->on_post_saved( $post->ID, $post );
		$this->manager->on_post_saved( $post->ID, $post );

		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertSame(
			2,
			get_option( 'cybermaps_static_generation' ),
			'Every content mutation must fence a generation that captured the old inventory.'
		);
	}

	public function test_leaving_publish_and_force_deleting_publish_invalidate_but_other_deletes_do_not(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$published = $this->post( 41, 'publish' );
		$draft     = $this->post( 42, 'draft' );

		$this->seed_publication_caches();
		$this->manager->on_post_status_transition( 'draft', 'publish', $published );
		$this->orchestrator->handle_post_status_transition( 'draft', 'publish', $published );
		$this->assertFalse( get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );

		$GLOBALS['cybermaps_mock_scheduled'] = array();
		$this->seed_publication_caches();
		$this->manager->on_post_deleted( $draft->ID, $draft );
		$this->orchestrator->handle_published_post_delete( $draft->ID, $draft );
		$this->assertSame( 'summary', get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertSame( '<rss/>', get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );

		$this->manager->on_post_deleted( $published->ID, $published );
		$this->orchestrator->handle_published_post_delete( $published->ID, $published );
		$this->assertFalse( get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_direct_term_assignment_invalidates_published_inventory_only_when_relationships_change(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$published = $this->post( 51, 'publish' );
		$GLOBALS['cybermaps_mock_posts'][ $published->ID ] = $published;

		$this->seed_publication_caches();
		$this->manager->on_object_terms_set( $published->ID, array(), array( 2, 1 ), 'category', false, array( 1, 2 ) );
		$this->orchestrator->handle_published_post_terms( $published->ID, array(), array( 2, 1 ), 'category', false, array( 1, 2 ) );
		$this->assertSame( 'summary', get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertSame( '<rss/>', get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );

		$this->manager->on_object_terms_set( $published->ID, array(), array( 2, 3 ), 'internal_topic', false, array( 1, 2 ) );
		$this->orchestrator->handle_published_post_terms( $published->ID, array(), array( 2, 3 ), 'internal_topic', false, array( 1, 2 ) );
		$this->assertSame( 'summary', get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertSame( '<rss/>', get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );

		$this->manager->on_object_terms_set( $published->ID, array(), array( 2, 3 ), 'category', false, array( 1, 2 ) );
		$this->orchestrator->handle_published_post_terms( $published->ID, array(), array( 2, 3 ), 'category', false, array( 1, 2 ) );
		$this->assertFalse( get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_irrelevant_term_inventory_change_does_not_invalidate_publications(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$this->seed_publication_caches();

		$this->manager->on_term_changed( 7, 9, 'internal_topic' );
		$this->orchestrator->handle_term_inventory_change( 7, 9, 'internal_topic' );

		$this->assertSame( 'summary', get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertSame( 'full', get_transient( LLMS::FULL_CACHE_KEY ) );
		$this->assertSame( '<rss/>', get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_author_inventory_change_respects_include_authors_setting(): void {
		$this->seed_publication_caches();
		$this->orchestrator->handle_author_inventory_change( 7 );

		$this->assertSame( '<rss/>', get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'include_authors'    => '1',
			'static_engine_mode' => 'all',
		);
		$this->seed_publication_caches();
		( new Orchestrator() )->handle_author_inventory_change( 7 );

		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertArrayHasKey( 'cybermaps_bg_build_sitemap_occupancy', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_term_inventory_change_clears_dynamic_discovery_cache_and_syncs_only_full_mode(): void {
		$this->seed_publication_caches();
		$this->manager->on_term_changed( 7, 9, 'category' );
		$this->assertFalse( get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertFalse( get_transient( LLMSTLDR::CACHE_KEY ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$this->seed_publication_caches();
		$this->manager->on_term_changed( 7, 9, 'category' );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_comment_lastmod_update_expires_timestamped_publication_caches(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode'  => 'all',
			'update_comment_post' => '1',
		);
		$post = $this->post( 61, 'publish' );
		$GLOBALS['cybermaps_mock_posts'][ $post->ID ] = $post;
		$GLOBALS['cybermaps_mock_comments'][5] = (object) array( 'comment_post_ID' => $post->ID );
		$GLOBALS['wpdb'] = new class() {
			public string $posts = 'wp_posts';

			public function update( mixed $table, array $data, array $where ): int {
				unset( $table, $data, $where );
				return 1;
			}
		};
		$this->seed_publication_caches();

		( new Orchestrator() )->update_lastmod_on_comment( 5, 1 );

		$this->assertFalse( get_transient( LLMS::SUMMARY_CACHE_KEY ) );
		$this->assertFalse( get_transient( LLMS::FULL_CACHE_KEY ) );
		$this->assertFalse( get_transient( LLMSTLDR::CACHE_KEY ) );
		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	private function seed_publication_caches(): void {
		set_transient( LLMS::SUMMARY_CACHE_KEY, 'summary', HOUR_IN_SECONDS );
		set_transient( LLMS::FULL_CACHE_KEY, 'full', HOUR_IN_SECONDS );
		set_transient( LLMSTLDR::CACHE_KEY, 'tldr', HOUR_IN_SECONDS );
		CacheManager::set( 'cybermaps_rss_sitemap', '<rss/>', HOUR_IN_SECONDS, 'sitemap' );
	}

	private function post( int $id, string $status, string $type = 'post' ): object {
		return (object) array(
			'ID'          => $id,
			'post_status' => $status,
			'post_type'   => $type,
		);
	}

	/**
	 * @param array{0:object|string,1:string} $callback Expected hook callback.
	 */
	private function assertHookContract( string $hook, int $accepted_args, array $callback ): void {
		$matches = array_values(
			array_filter(
				$GLOBALS['wp_hooks'],
				static fn( array $record ): bool => $hook === $record['hook']
					&& $record['callback'] === $callback
			)
		);

		$this->assertCount( 1, $matches );
		$this->assertSame( $accepted_args, $matches[0]['accepted_args'] );
	}
}
