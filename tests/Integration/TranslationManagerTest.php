<?php
declare(strict_types=1);

namespace {
	if ( ! function_exists( 'get_current_blog_id' ) ) {
		function get_current_blog_id(): int {
			return (int) ( $GLOBALS['cybermaps_mock_current_blog_id'] ?? 1 );
		}
	}

	if ( ! function_exists( 'has_filter' ) ) {
		function has_filter( $hook_name, $callback = false ) {
			unset( $callback );
			return empty( $GLOBALS['cybermaps_mock_filter_callbacks'][ $hook_name ] )
				? false
				: 10;
		}
	}
}

namespace Cybermaps\Tests\Integration {

	use Cybermaps\Core\TranslationRegistry;
	use Cybermaps\Integration\TranslationManager;

	final class TranslationManagerTest extends \WP_UnitTestCase {
		private array $original_post_type_objects;
		private array $original_posts;
		private array $original_options;
		private array $original_options_by_blog;
		private bool $original_is_multisite;

		protected function setUp(): void {
			parent::setUp();
			$this->original_post_type_objects = $GLOBALS['cybermaps_mock_post_type_objects'] ?? array();
			$this->original_posts = $GLOBALS['cybermaps_mock_posts'] ?? array();
			$this->original_options = $GLOBALS['cybermaps_mock_options'] ?? array();
			$this->original_options_by_blog = $GLOBALS['cybermaps_mock_options_by_blog'] ?? array();
			$this->original_is_multisite = (bool) ( $GLOBALS['cybermaps_mock_is_multisite'] ?? false );
			$GLOBALS['cybermaps_mock_post_type_objects'] = array(
				'post'       => (object) array( 'public' => true ),
				'page'       => (object) array( 'public' => true ),
				'attachment' => (object) array( 'public' => true ),
				'internal'   => (object) array( 'public' => false ),
			);
			$GLOBALS['cybermaps_mock_posts'] = array(
				10 => (object) array( 'ID' => 10, 'post_status' => 'publish', 'post_type' => 'post' ),
				20 => (object) array( 'ID' => 20, 'post_status' => 'publish', 'post_type' => 'post' ),
				55 => (object) array( 'ID' => 55, 'post_status' => 'publish', 'post_type' => 'page' ),
				77 => (object) array( 'ID' => 77, 'post_status' => 'publish', 'post_type' => 'page' ),
			);
			$GLOBALS['cybermaps_mock_current_blog_id'] = 7;
			$GLOBALS['cybermaps_mock_is_multisite'] = true;
			$GLOBALS['cybermaps_mock_options'] = array(
				'cybermaps_settings' => array(
					'enable_translation_integrations' => '1',
				),
			);
			$GLOBALS['cybermaps_mock_options_by_blog'] = array();
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
			$GLOBALS['wp_hooks'] = array();
		}

		protected function tearDown(): void {
			$GLOBALS['cybermaps_mock_post_type_objects'] = $this->original_post_type_objects;
			$GLOBALS['cybermaps_mock_posts'] = $this->original_posts;
			$GLOBALS['cybermaps_mock_options'] = $this->original_options;
			$GLOBALS['cybermaps_mock_options_by_blog'] = $this->original_options_by_blog;
			$GLOBALS['cybermaps_mock_is_multisite'] = $this->original_is_multisite;
			parent::tearDown();
		}

		public function test_registers_deleted_post_cleanup_with_full_hook_contract(): void {
			$manager = new TranslationManager( new TranslationRegistrySpy() );
			$manager->register_hooks();

			$cleanup_hooks = array_values(
				array_filter(
					$GLOBALS['wp_hooks'],
					static fn( array $hook ): bool => 'before_delete_post' === $hook['hook']
				)
			);

			$this->assertCount( 1, $cleanup_hooks );
			$this->assertSame( 2, $cleanup_hooks[0]['accepted_args'] );
		}

		public function test_wpml_sync_uses_documented_filters_and_one_group(): void {
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array(
				'wpml_element_trid' => array(
					static function ( $value, $post_id, $element_type ) {
						unset( $value );
						return 55 === $post_id && 'post_page' === $element_type ? 900 : null;
					},
				),
				'wpml_get_element_translations' => array(
					static function ( $value, $trid, $element_type ) {
						unset( $value );
						if ( 900 !== $trid || 'post_page' !== $element_type ) {
							return array();
						}
						return array(
							'en' => (object) array(
								'element_id'   => 55,
								'language_code' => 'en-US',
							),
							'fr' => array(
								'element_id'   => 77,
								'language_code' => 'fr-FR',
							),
						);
					},
				),
			);
			$registry = new TranslationRegistrySpy();
			$manager = new TranslationManager( $registry );

			$manager->sync_post_translations(
				55,
				(object) array(
					'ID'          => 55,
					'post_status' => 'publish',
					'post_type'   => 'page',
				)
			);

			$this->assertSame(
				array(
					array( 0, 7, 55, 'en-US', 'post' ),
					array( 4000, 7, 77, 'fr-FR', 'post' ),
				),
				$registry->updates
			);
			$this->assertSame(
				array( array( 4000, 7, array( 55, 77 ), 'post' ) ),
				$registry->prunes
			);
		}

		public function test_deleted_post_removes_exact_site_item_and_type_identity(): void {
			$registry = new TranslationRegistrySpy();
			$manager = new TranslationManager( $registry );

			$manager->delete_post_relationship( 91, (object) array( 'ID' => 91 ) );

			$this->assertSame( array( array( 7, 91, 'post' ) ), $registry->deletions );
		}

		public function test_failed_group_creation_stops_remaining_writes(): void {
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array(
				'wpml_element_trid' => array( static fn() => 42 ),
				'wpml_get_element_translations' => array(
					static fn() => array(
						'en' => (object) array( 'element_id' => 10 ),
						'fr' => (object) array( 'element_id' => 20 ),
					),
				),
			);
			$registry = new TranslationRegistrySpy();
			$registry->fail_updates = true;

			( new TranslationManager( $registry ) )->sync_post_translations(
				10,
				(object) array(
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$this->assertCount( 1, $registry->updates );
			$this->assertSame( array(), $registry->prunes );
		}

		public function test_malformed_provider_language_is_skipped_and_pruned_without_stopping_valid_sync(): void {
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array(
				'wpml_element_trid' => array( static fn() => 42 ),
				'wpml_get_element_translations' => array(
					static fn() => array(
						'en_US' => (object) array( 'element_id' => 10 ),
						'bad'   => (object) array(
							'element_id'   => 55,
							'language_code' => 'not a language tag',
						),
						'nested-id' => (object) array(
							'element_id'   => array( 77 ),
							'language_code' => 'de-DE',
						),
						'nested-language' => array(
							'element_id'   => 77,
							'language_code' => array( 'de-DE' ),
						),
						'fr_FR' => (object) array( 'element_id' => 20 ),
					),
				),
			);
			$registry = new TranslationRegistrySpy();

			( new TranslationManager( $registry ) )->sync_post_translations(
				10,
				(object) array(
					'ID'          => 10,
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$this->assertSame(
				array(
					array( 0, 7, 10, 'en-US', 'post' ),
					array( 4000, 7, 20, 'fr-FR', 'post' ),
				),
				$registry->updates
			);
			$this->assertSame(
				array( array( 4000, 7, array( 10, 20 ), 'post' ) ),
				$registry->prunes
			);
		}

		public function test_manual_sync_pause_is_respected_for_current_and_related_posts(): void {
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array(
				'wpml_element_trid' => array( static fn() => 42 ),
				'wpml_get_element_translations' => array(
					static fn() => array(
						'en' => (object) array( 'element_id' => 10 ),
						'fr' => (object) array( 'element_id' => 20 ),
					),
				),
			);
			$GLOBALS['cybermaps_mock_post_meta'][20][TranslationManager::SYNC_DISABLED_META] = '1';
			$registry = new TranslationRegistrySpy();
			$manager  = new TranslationManager( $registry );

			$manager->sync_post_translations(
				10,
				(object) array(
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$this->assertSame( array( array( 0, 7, 10, 'en', 'post' ) ), $registry->updates );
			$this->assertSame( array( array( 4000, 7, array( 10 ), 'post' ) ), $registry->prunes );

			$GLOBALS['cybermaps_mock_post_meta'][10][TranslationManager::SYNC_DISABLED_META] = '1';
			$registry->updates = array();
			$manager->sync_post_translations(
				10,
				(object) array(
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$this->assertSame( array(), $registry->updates );
		}

		public function test_automatic_sync_ignores_non_publication_post_types(): void {
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array(
				'wpml_element_trid' => array( static fn() => 42 ),
				'wpml_get_element_translations' => array(
					static fn() => array(
						'en' => (object) array( 'element_id' => 10 ),
					),
				),
			);
			$registry = new TranslationRegistrySpy();
			$manager  = new TranslationManager( $registry );

			foreach ( array( 'attachment', 'internal' ) as $post_type ) {
				$manager->sync_post_translations(
					10,
					(object) array(
						'post_status' => 'publish',
						'post_type'   => $post_type,
					)
				);
			}

			$this->assertSame( array(), $registry->updates );
			$this->assertSame( array(), $registry->prunes );
		}

		public function test_saved_manual_relationship_invalidates_connected_publications(): void {
			$registry = new TranslationRegistrySpy();
			$manager  = new TranslationManager( $registry );

			$manager->invalidate_post_publications(
				10,
				(object) array(
					'ID'          => 10,
					'post_status' => 'draft',
					'post_type'   => 'post',
				)
			);

			$this->assertSame( array( array( 7, 10, 'post' ) ), $registry->invalidations );
		}

		public function test_relevant_metadata_terms_and_options_propagate_but_noise_does_not(): void {
			$registry = new TranslationRegistrySpy();
			$manager  = new TranslationManager( $registry );

			$manager->invalidate_post_metadata( 1, 10, '_cybermaps_exclude_sitemap', '1' );
			$manager->invalidate_post_metadata( 2, 10, 'unrelated', 'value' );
			$manager->invalidate_post_terms( 10, array(), array( 2 ), 'category', false, array( 1 ) );
			$manager->invalidate_post_terms( 10, array(), array( 2 ), 'category', false, array( 2 ) );
			$manager->invalidate_term_metadata( 3, 44, 'noindex', '1' );
			$manager->invalidate_changed_option( 'cybermaps_settings', array(), array() );
			$manager->invalidate_changed_option( 'unrelated', null, null );

			$this->assertSame(
				array(
					array( 7, 10, 'post' ),
					array( 7, 10, 'post' ),
					array( 7, 44, 'term' ),
				),
				$registry->invalidations
			);
			$this->assertSame( array( 7 ), $registry->site_invalidations );
		}

		public function test_deleted_term_removes_exact_registry_identity(): void {
			$registry = new TranslationRegistrySpy();

			( new TranslationManager( $registry ) )->delete_term_relationship( 44 );

			$this->assertSame( array( array( 7, 44, 'term' ) ), $registry->deletions );
		}

		public function test_disabled_integration_keeps_invalidation_but_skips_automatic_mapping(): void {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_translation_integrations'] = '0';
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array(
				'wpml_element_trid' => array( static fn() => 42 ),
				'wpml_get_element_translations' => array(
					static fn() => array(
						'en' => (object) array( 'element_id' => 10 ),
					),
				),
			);
			$registry = new TranslationRegistrySpy();
			$manager  = new TranslationManager( $registry );

			$manager->sync_post_translations(
				10,
				(object) array(
					'ID'          => 10,
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);
			$manager->invalidate_post_publications(
				10,
				(object) array(
					'ID'          => 10,
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			);

			$this->assertSame( array(), $registry->updates );
			$this->assertSame( array( array( 7, 10, 'post' ) ), $registry->invalidations );
		}
	}

	final class TranslationRegistrySpy extends TranslationRegistry {
		/** @var array<int,array{int,int,int,string,string}> */
		public array $updates = array();

		/** @var array<int,array{int,int,string}> */
		public array $deletions = array();

		/** @var array<int,array{int,int,int[],string}> */
		public array $prunes = array();

		/** @var array<int,array{int,int,string}> */
		public array $invalidations = array();

		/** @var int[] */
		public array $site_invalidations = array();

		public bool $fail_updates = false;

		public function update_relationship( $group_id, $site_id, $item_id, $lang, $type = 'post' ) {
			$this->updates[] = array(
				(int) $group_id,
				(int) $site_id,
				(int) $item_id,
				(string) $lang,
				(string) $type,
			);
			return $this->fail_updates ? 0 : 4000;
		}

		public function delete_relationship( $site_id, $item_id, $type = 'post' ) {
			$this->deletions[] = array( (int) $site_id, (int) $item_id, (string) $type );
			return true;
		}

		public function prune_group_relationships(
			int $group_id,
			int $site_id,
			array $item_ids,
			string $type = 'post'
		): int {
			$this->prunes[] = array( $group_id, $site_id, $item_ids, $type );
			return 0;
		}

		public function invalidate_relationship( $site_id, $item_id, $type = 'post' ): bool {
			$this->invalidations[] = array( (int) $site_id, (int) $item_id, (string) $type );
			return true;
		}

		public function invalidate_site_relationships( $site_id ): bool {
			$this->site_invalidations[] = (int) $site_id;
			return true;
		}
	}
}
