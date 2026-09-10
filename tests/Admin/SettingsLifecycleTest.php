<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings;
use Cybermaps\Core\CacheManager;

final class SettingsLifecycleTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'static_engine_mode' => 'off' ),
		);
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_scheduled']  = array();
		$GLOBALS['cybermaps_mock_post_meta']  = array();
		$GLOBALS['cybermaps_mock_deleted_post_meta_keys'] = array();
		$GLOBALS['wp_hooks'] = array();
	}

	public function test_persisted_public_route_change_schedules_one_rewrite_flush(): void {
		$settings = new Settings();
		$settings->on_settings_updated(
			array(
				'static_engine_mode'      => 'off',
				'sitemap_url_base'        => 'sitemap',
				'news_sitemap_url_base'   => 'sitemap-news',
			),
			array(
				'static_engine_mode'      => 'off',
				'sitemap_url_base'        => 'client-map',
				'news_sitemap_url_base'   => 'client-map-news',
			)
		);

		$shutdown = array_values(
			array_filter(
				$GLOBALS['wp_hooks'],
				static fn ( array $hook ): bool => 'shutdown' === $hook['hook']
			)
		);
		$this->assertCount( 1, $shutdown );
	}

	public function test_first_creation_adapters_use_type_correct_empty_old_values(): void {
		$settings = new class() extends Settings {
			/** @var array<string, array{mixed, mixed}> */
			public array $received = array();

			public function on_settings_updated( $old_value, $new_value ): void {
				$this->received['settings'] = array( $old_value, $new_value );
			}

			public function on_discovery_center_updated( $old_value, $new_value ): void {
				$this->received['discovery'] = array( $old_value, $new_value );
			}

			public function on_robots_manager_updated( $old_value, $new_value ): void {
				$this->received['robots'] = array( $old_value, $new_value );
			}
		};

		$main_payload      = array( 'static_engine_mode' => 'all' );
		$discovery_payload = '{"archetype":"blog"}';
		$robots_payload    = array(
			'overrides' => array(
				'gptbot' => array( 'llm' => false ),
			),
		);

		// These are the two arguments WordPress passes to add_option_{$option}:
		// the option name followed by the inserted value.
		$settings->on_settings_added( 'cybermaps_settings', $main_payload );
		$settings->on_discovery_center_added( 'cybermaps_discovery_center', $discovery_payload );
		$settings->on_robots_manager_added( 'cybermaps_robots_manager', $robots_payload );

		$this->assertSame( array( array(), $main_payload ), $settings->received['settings'] );
		$this->assertSame( array( '', $discovery_payload ), $settings->received['discovery'] );
		$this->assertSame( array( array(), $robots_payload ), $settings->received['robots'] );
	}

	public function test_first_created_main_settings_reconcile_publications(): void {
		$new_value = array(
			'static_engine_mode' => 'all',
			'ai_topics'          => 'product documentation',
		);
		// add_option_{$option} fires after insertion, so runtime lookups already
		// see the new value while the adapter delegates to the update handler.
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $new_value;
		CacheManager::set( 'cybermaps_first_settings_sitemap', '<xml/>', HOUR_IN_SECONDS, 'sitemap' );
		CacheManager::set( 'cybermaps_first_settings_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );

		( new Settings() )->on_settings_added( 'cybermaps_settings', $new_value );

		$this->assertFalse( get_transient( 'cybermaps_first_settings_sitemap' ) );
		$this->assertFalse( get_transient( 'cybermaps_first_settings_discovery' ) );
		$this->assertArrayHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_first_created_structured_configuration_reuses_update_reconciliation(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'all',
		);
		CacheManager::set( 'cybermaps_first_strategy', '{}', HOUR_IN_SECONDS, 'discovery' );
		CacheManager::set( 'cybermaps_first_chunks', array( 'stale' ), HOUR_IN_SECONDS, 'chunks' );

		( new Settings() )->on_discovery_center_added(
			'cybermaps_discovery_center',
			'{"archetype":"blog"}'
		);

		$this->assertFalse( get_transient( 'cybermaps_first_strategy' ) );
		$this->assertFalse( get_transient( 'cybermaps_first_chunks' ) );
		$this->assertArrayHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );

		$GLOBALS['cybermaps_mock_scheduled'] = array();
		CacheManager::set( 'cybermaps_first_policy', '{}', HOUR_IN_SECONDS, 'discovery' );
		( new Settings() )->on_robots_manager_added(
			'cybermaps_robots_manager',
			array(
				'overrides' => array(
					'gptbot' => array( 'llm' => false ),
				),
			)
		);

		$this->assertFalse( get_transient( 'cybermaps_first_policy' ) );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_rag_configuration_change_clears_owned_chunk_cache(): void {
		CacheManager::set(
			'cybermaps_chunks_20_old_config',
			array( 'chunks' => array( 'stale' ) ),
			HOUR_IN_SECONDS,
			'chunks'
		);

		( new Settings() )->on_settings_updated(
			array(
				'static_engine_mode' => 'off',
				'rag_chunk_size'     => 800,
			),
			array(
				'static_engine_mode' => 'off',
				'rag_chunk_size'     => 1200,
			)
		);

		$this->assertFalse( get_transient( 'cybermaps_chunks_20_old_config' ) );
	}

	public function test_media_mode_changes_invalidate_scan_rows_in_constant_time(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_media_audit_generation'] = 4;
		$GLOBALS['cybermaps_mock_post_meta'][20]['_cybermaps_media_audit'] = array(
			array( 'type' => 'video', 'url' => 'https://example.com/advanced.mp4' ),
		);

		( new Settings() )->on_settings_updated(
			array(
				'static_engine_mode'         => 'off',
				'media_discovery_intensity' => 'none',
			),
			array(
				'static_engine_mode'         => 'off',
				'media_discovery_intensity' => 'standard',
			)
		);
		$this->assertSame( 5, $GLOBALS['cybermaps_mock_options']['cybermaps_media_audit_generation'] );

		( new Settings() )->on_settings_updated(
			array(
				'static_engine_mode'         => 'off',
				'media_discovery_intensity' => 'standard',
			),
			array(
				'static_engine_mode'         => 'off',
				'media_discovery_intensity' => 'advanced',
			)
		);
		$this->assertSame( 6, $GLOBALS['cybermaps_mock_options']['cybermaps_media_audit_generation'] );

		$this->assertArrayHasKey(
			'_cybermaps_media_audit',
			$GLOBALS['cybermaps_mock_post_meta'][20]
		);
		$this->assertNotContains(
			'_cybermaps_media_audit',
			$GLOBALS['cybermaps_mock_deleted_post_meta_keys']
		);
	}

	public function test_non_publication_settings_do_not_schedule_or_invalidate_static_output(): void {
		$keys = array(
			'api_secret',
			'ai_feed_full_content',
			'ai_feed_include_authors',
			'ai_feed_limit',
			'audit_post_min_words',
			'agency_name',
			'delete_data_on_uninstall',
			'enable_header_discovery',
			'enable_indexnow',
			'enable_shortcode',
			'enable_video_schema',
			'enable_websub',
			'inject_robots',
			'redirect_wp_sitemap',
			'report_theme',
			'site_guide_instructions',
			'update_comment_post',
			'websub_hubs',
		);

		foreach ( $keys as $key ) {
			$GLOBALS['cybermaps_mock_options'] = array(
				'cybermaps_settings' => array(
					'static_engine_mode' => 'all',
					$key                  => 'old',
				),
			);
			$GLOBALS['cybermaps_mock_scheduled'] = array();

			( new Settings() )->on_settings_updated(
				array(
					'static_engine_mode' => 'all',
					$key                  => 'old',
				),
				array(
					'static_engine_mode' => 'all',
					$key                  => 'new',
				)
			);

			$this->assertArrayNotHasKey(
				'cybermaps_bg_sync_static_files',
				$GLOBALS['cybermaps_mock_scheduled'],
				$key . ' scheduled an unrelated static reconciliation.'
			);
			$this->assertArrayNotHasKey(
				'cybermaps_static_generation',
				$GLOBALS['cybermaps_mock_options'],
				$key . ' invalidated an unrelated static generation.'
			);
		}
	}

	public function test_cache_toggle_clears_only_sitemap_cache_without_static_sync(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'all',
			'enable_caching'     => '0',
		);
		CacheManager::set( 'cybermaps_cached_sitemap', '<xml/>', HOUR_IN_SECONDS, 'sitemap' );
		CacheManager::set( 'cybermaps_cached_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );

		( new Settings() )->on_settings_updated(
			array(
				'static_engine_mode' => 'all',
				'enable_caching'     => '0',
			),
			array(
				'static_engine_mode' => 'all',
				'enable_caching'     => '1',
			)
		);

		$this->assertFalse( get_transient( 'cybermaps_cached_sitemap' ) );
		$this->assertSame( '{}', get_transient( 'cybermaps_cached_discovery' ) );
		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_publication_setting_still_invalidates_and_schedules_full_mode(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'all',
			'ai_topics'          => 'old',
		);
		CacheManager::set( 'cybermaps_cached_sitemap', '<xml/>', HOUR_IN_SECONDS, 'sitemap' );
		CacheManager::set( 'cybermaps_cached_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );

		( new Settings() )->on_settings_updated(
			array(
				'static_engine_mode' => 'all',
				'ai_topics'          => 'old',
			),
			array(
				'static_engine_mode' => 'all',
				'ai_topics'          => 'new',
			)
		);

		$this->assertFalse( get_transient( 'cybermaps_cached_sitemap' ) );
		$this->assertFalse( get_transient( 'cybermaps_cached_discovery' ) );
		$this->assertArrayHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_strategy_change_clears_dynamic_families_without_scheduling_static_off(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'off',
		);
		CacheManager::set( 'cybermaps_strategy_sitemap', '<xml/>', HOUR_IN_SECONDS, 'sitemap' );
		CacheManager::set( 'cybermaps_strategy_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );
		CacheManager::set( 'cybermaps_strategy_chunks', array( 'stale' ), HOUR_IN_SECONDS, 'chunks' );

		( new Settings() )->on_discovery_center_updated( '{"archetype":"blog"}', '{"archetype":"corporate"}' );

		$this->assertFalse( get_transient( 'cybermaps_strategy_sitemap' ) );
		$this->assertFalse( get_transient( 'cybermaps_strategy_discovery' ) );
		$this->assertFalse( get_transient( 'cybermaps_strategy_chunks' ) );
		$this->assertArrayNotHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_strategy_change_invalidates_and_schedules_full_publication(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'all',
		);

		( new Settings() )->on_discovery_center_updated( 'old', 'new' );

		$this->assertArrayHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_dynamic_robots_changes_do_not_rebuild_static_publications(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'all',
		);
		CacheManager::set( 'cybermaps_robots_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );

		( new Settings() )->on_robots_manager_updated(
			array(
				'manual_directives' => 'Disallow: /old/',
				'overrides'         => array( 'gptbot' => array( 'llm' => true ) ),
			),
			array(
				'manual_directives' => 'Disallow: /new/',
				'overrides'         => array( 'gptbot' => array( 'llm' => true ) ),
			)
		);

		$this->assertSame( '{}', get_transient( 'cybermaps_robots_discovery' ) );
		$this->assertArrayNotHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_llm_crawler_policy_change_republishes_well_known_manifest(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'well_known',
		);
		CacheManager::set( 'cybermaps_policy_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );

		( new Settings() )->on_robots_manager_updated(
			array( 'overrides' => array( 'gptbot' => array( 'llm' => true ) ) ),
			array( 'overrides' => array( 'gptbot' => array( 'llm' => false ) ) )
		);

		$this->assertFalse( get_transient( 'cybermaps_policy_discovery' ) );
		$this->assertArrayHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_non_ai_manifest_target_changes_do_not_republish_discovery_files(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'well_known',
		);

		( new Settings() )->on_robots_manager_updated(
			array( 'overrides' => array( 'googlebot' => array( 'llm' => false ) ) ),
			array( 'overrides' => array( 'googlebot' => array( 'llm' => true ) ) )
		);

		$this->assertArrayNotHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}
}
