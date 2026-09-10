<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Admin\MigrationHub;
use Cybermaps\Admin\Settings;
use PHPUnit\Framework\TestCase;

final class AIConfigurationBriefTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']    = array();
		$GLOBALS['cybermaps_mock_actions']    = array();
		$GLOBALS['cybermaps_mock_filters']    = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'category', 'post_tag' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'name' => 'post', 'public' => true, 'show_in_rest' => true ),
			'page' => (object) array( 'name' => 'page', 'public' => true, 'show_in_rest' => true ),
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array(
			'category' => (object) array( 'name' => 'category', 'public' => true ),
			'post_tag' => (object) array( 'name' => 'post_tag', 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['wp_hooks'] = array();
		unset(
			$GLOBALS['cybermaps_mock_update_option_behavior'],
			$GLOBALS['cybermaps_mock_get_option_observer']
		);
	}

	public function test_generated_brief_is_site_aware_bounded_and_excludes_private_values(): void {
		$GLOBALS['cybermaps_mock_post_types'][] = 'attachment';
		$GLOBALS['cybermaps_mock_post_type_objects']['attachment'] = (object) array(
			'name'         => 'attachment',
			'public'       => true,
			'show_in_rest' => true,
		);
		update_option(
			'cybermaps_settings',
			array(
				'api_secret'               => 'private-api-value-should-never-leak',
				'delete_data_on_uninstall'  => '1',
				'agency_url'                => 'https://admin:top-level-password@example.com/reports',
				'llms_custom_instructions'  => 'Prefer primary product documentation.',
				'llms_exclude_ids'          => '12, 45',
				'sitemap_url_base'          => 'custom-map',
			)
		);
		update_option( 'cybermaps_indexnow_key', 'private-indexnow-value-should-never-leak' );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option(
			'cybermaps_identity_data',
			array(
				'type'         => 'Organization',
				'name'         => 'Public Example Studio',
				'email'        => 'hello@example.com',
				'social_profiles' => array( 'https://social-user:nested-password@example.com/profile' ),
				'rogue_secret' => 'unknown-identity-value-should-never-leak',
			)
		);

		$brief    = MigrationHub::get_instance()->generate_markdown();
		$envelope = $this->extract_changes_envelope( $brief );

		$this->assertLessThanOrEqual( MigrationHub::get_max_import_bytes(), strlen( $brief ) );
		$this->assertStringContainsString( 'CYBERMAPS-AI-CONFIG-BRIEF: 2', $brief );
		$this->assertStringContainsString( 'https://cybermaps.dev/docs/ai-configuration/', $brief );
		$this->assertStringContainsString( 'https://cybermaps.dev/specs/ai-configuration/' . rawurlencode( CYBERMAPS_VERSION ) . '/schema.json', $brief );
		$this->assertStringContainsString( 'https://cybermaps.dev/specs/ai-configuration/' . rawurlencode( CYBERMAPS_VERSION ) . '/catalog.json', $brief );
		$this->assertStringContainsString( 'Public Example Studio', $brief );
		$this->assertStringContainsString( 'hello@example.com', $brief );
		$this->assertStringContainsString( '"llms_exclude_ids": [', $brief );
		$this->assertStringContainsString( '"discovery_overrides": {}', $brief );
		$this->assertStringContainsString( '"contains_secrets": false', $brief );
		$this->assertStringContainsString( 'private Cybermaps REST API secret, IndexNow key', $brief );
		$this->assertStringNotContainsString( 'integration credentials', $brief );
		$this->assertStringNotContainsString( 'integration secrets', $brief );
		$this->assertStringNotContainsString( 'private-api-value-should-never-leak', $brief );
		$this->assertStringNotContainsString( 'private-indexnow-value-should-never-leak', $brief );
		$this->assertStringNotContainsString( 'top-level-password', $brief );
		$this->assertStringNotContainsString( 'nested-password', $brief );
		$this->assertStringNotContainsString( 'unknown-identity-value-should-never-leak', $brief );
		$this->assertStringNotContainsString( 'post_type:attachment', $brief );

		$this->assertSame( 'cybermaps-ai-configuration-changes', $envelope['format'] );
		$this->assertSame( 2, $envelope['format_version'] );
		$this->assertSame( CYBERMAPS_VERSION, $envelope['plugin_version'] );
		$this->assertArrayHasKey( 'core_settings', $envelope['changes'] );
		$this->assertArrayHasKey( 'site_identity', $envelope['changes'] );
		$this->assertArrayNotHasKey( 'api_secret', $envelope['changes']['advanced_maintenance'] );
		$this->assertArrayNotHasKey( 'delete_data_on_uninstall', $envelope['changes']['advanced_maintenance'] );
		foreach ( $envelope['changes'] as $fields ) {
			foreach ( $fields as $value ) {
				$this->assertNull( $value );
			}
		}
	}

	public function test_preview_is_non_mutating_and_apply_uses_the_reviewed_sanitized_values(): void {
		update_option(
			'cybermaps_settings',
			array(
				'agency_name'            => 'Old Studio',
				'anonymize_analytics_ips' => '1',
				'api_secret'             => 'preserve-this-secret',
			)
		);
		$before  = $GLOBALS['cybermaps_mock_options'];
		$content = $this->changes_json(
			array(
				'reports_deliverables' => array( 'agency_name' => '<b>New Studio</b>' ),
				'analytics'            => array( 'anonymize_analytics_ips' => false ),
			)
		);

		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );

		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame( array(), $preview['errors'] );
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $preview['content_hash'] );
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $preview['configuration_hash'] );
		$agency = $this->preview_change( $preview, 'agency_name' );
		$this->assertSame( 'Old Studio', $agency['before'] );
		$this->assertSame( '<b>New Studio</b>', $agency['proposed'] );
		$this->assertSame( 'New Studio', $agency['final'] );
		$this->assertSame( 'normalized', $agency['status'] );
		$privacy = $this->preview_change( $preview, 'anonymize_analytics_ips' );
		$this->assertTrue( $privacy['high_impact'] );

		$result = MigrationHub::get_instance()->import_previewed(
			$content,
			'merge',
			$preview['content_hash'],
			$preview['configuration_hash']
		);
		$settings = get_option( 'cybermaps_settings' );

		$this->assertSame( 'New Studio', $settings['agency_name'] );
		$this->assertSame( '0', $settings['anonymize_analytics_ips'] );
		$this->assertSame( 'preserve-this-secret', $settings['api_secret'] );
		$this->assertContains( 'cybermaps_settings', $result['changed_groups'] );
	}

	public function test_canonical_ai_exclusion_preview_uses_one_saved_value(): void {
		update_option(
			'cybermaps_settings',
			array( 'llms_exclude_ids' => '12' )
		);
		$content = $this->changes_json(
			array(
				'ai_publishing' => array( 'llms_exclude_ids' => array( 13 ) ),
			)
		);

		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );
		$change  = $this->preview_change( $preview, 'llms_exclude_ids' );

		$this->assertSame( array( 12 ), $change['before'] );
		$this->assertSame( array( 13 ), $change['final'] );
		$this->assertSame( 'changed', $change['status'] );
		$this->assertTrue( $change['high_impact'] );
		$this->assertContains( 'llms_exclude_ids', array_column( $preview['high_impact_changes'], 'field' ) );
	}

	public function test_preview_fingerprint_binds_content_and_mode(): void {
		$content = $this->changes_json(
			array( 'reports_deliverables' => array( 'agency_name' => 'Reviewed Studio' ) )
		);
		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );

		$attempts = array(
			array( $content . "\n", 'merge' ),
			array( $content, 'overwrite' ),
		);
		foreach ( $attempts as $attempt ) {
			try {
				MigrationHub::get_instance()->import_previewed(
					$attempt[0],
					$attempt[1],
					$preview['content_hash'],
					$preview['configuration_hash']
				);
				$this->fail( 'A changed preview request must be rejected.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertStringContainsString( 'changed after preview', $error->getMessage() );
			}
		}
	}

	public function test_apply_rejects_a_stale_destination_configuration(): void {
		$content = $this->changes_json(
			array( 'reports_deliverables' => array( 'agency_name' => 'Reviewed Studio' ) )
		);
		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );
		update_option( 'cybermaps_settings', array( 'agency_name' => 'Changed Elsewhere' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'destination configuration changed' );
		MigrationHub::get_instance()->import_previewed(
			$content,
			'merge',
			$preview['content_hash'],
			$preview['configuration_hash']
		);
	}

	public function test_apply_rejects_destination_drift_during_the_prewrite_snapshot(): void {
		update_option(
			'cybermaps_settings',
			array(
				'agency_name'          => 'Before',
				'enable_discovery_hub' => '1',
			)
		);
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', '' );
		$content = $this->changes_json(
			array( 'reports_deliverables' => array( 'agency_name' => 'Imported' ) )
		);
		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );
		$mutated = false;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$mutated ): void {
			if ( ! $mutated && 'cybermaps_settings' === $option && MigrationHub::is_applying_prepared_import() ) {
				$mutated = true;
				$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '0';
			}
		};

		try {
			MigrationHub::get_instance()->import_previewed(
				$content,
				'merge',
				$preview['content_hash'],
				$preview['configuration_hash']
			);
			$this->fail( 'Destination drift during the final snapshot must block the import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'before any configuration values were written', $error->getMessage() );
		} finally {
			unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
		}

		$this->assertSame( 'Before', get_option( 'cybermaps_settings' )['agency_name'] );
		$this->assertSame( '0', get_option( 'cybermaps_settings' )['enable_discovery_hub'] );
	}

	public function test_failed_import_defers_core_side_effects_and_does_not_advance_media_generation(): void {
		$before_settings = array(
			'media_discovery_intensity' => 'none',
			'static_engine_mode'         => 'off',
		);
		update_option( 'cybermaps_settings', $before_settings );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array( 'takeover_enabled' => false ) );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', '' );
		update_option( 'cybermaps_media_audit_generation', 10 );
		$content = $this->changes_json(
			array(
				'core_settings' => array( 'media_discovery_intensity' => 'advanced' ),
				'robots_control' => array( 'takeover_enabled' => true ),
			)
		);
		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );
		$thrown  = false;
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, $value, string $stage ) use ( &$thrown, $before_settings ): void {
			if ( 'after' !== $stage ) {
				return;
			}
			if ( 'cybermaps_settings' === $option ) {
				( new Settings() )->on_settings_updated( $before_settings, $value );
			}
			if ( ! $thrown && 'cybermaps_robots_manager' === $option && ! empty( $value['takeover_enabled'] ) ) {
				$thrown = true;
				throw new \RuntimeException( 'Simulated later option-hook failure.' );
			}
		};

		try {
			MigrationHub::get_instance()->import_previewed(
				$content,
				'merge',
				$preview['content_hash'],
				$preview['configuration_hash']
			);
			$this->fail( 'The simulated option-hook failure must fail the import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'restored the previous configuration', $error->getMessage() );
		} finally {
			unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		}

		$this->assertSame( 10, get_option( 'cybermaps_media_audit_generation' ) );
		$this->assertSame( 'none', get_option( 'cybermaps_settings' )['media_discovery_intensity'] );
		$this->assertFalse( get_option( 'cybermaps_robots_manager' )['takeover_enabled'] );
	}

	public function test_route_preview_is_pure_and_commit_schedules_one_rewrite_flush(): void {
		update_option(
			'cybermaps_settings',
			array(
				'sitemap_url_base'      => 'sitemap',
				'news_sitemap_url_base' => 'sitemap-news',
				'rss_sitemap_url_base'  => 'sitemap-rss',
				'static_engine_mode'     => 'off',
			)
		);
		$content = $this->changes_json(
			array( 'core_settings' => array( 'sitemap_url_base' => 'client-map' ) )
		);

		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );
		$this->assertNotContains( 'shutdown', array_column( $GLOBALS['wp_hooks'], 'hook' ) );

		MigrationHub::get_instance()->import_previewed(
			$content,
			'merge',
			$preview['content_hash'],
			$preview['configuration_hash']
		);
		$this->assertSame( 1, array_count_values( array_column( $GLOBALS['wp_hooks'], 'hook' ) )['shutdown'] ?? 0 );
	}

	public function test_apply_rolls_back_an_unreviewed_mutation_to_an_untargeted_root(): void {
		update_option( 'cybermaps_settings', array( 'agency_name' => 'Before' ) );
		update_option( 'cybermaps_robots_manager', array( 'takeover_enabled' => false ) );
		$content = $this->changes_json(
			array( 'reports_deliverables' => array( 'agency_name' => 'Reviewed' ) )
		);
		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );
		$mutated = false;
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, $value, string $stage ) use ( &$mutated ): void {
			unset( $value );
			if ( ! $mutated && 'cybermaps_settings' === $option && 'after' === $stage ) {
				$mutated = true;
				$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager'] = array(
					'takeover_enabled' => true,
					'manual_directives' => 'UNREVIEWED',
				);
			}
		};

		try {
			MigrationHub::get_instance()->import_previewed(
				$content,
				'merge',
				$preview['content_hash'],
				$preview['configuration_hash']
			);
			$this->fail( 'An unreviewed mutation to another Cybermaps root must fail the import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'restored the previous configuration', $error->getMessage() );
		} finally {
			unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		}

		$this->assertSame( array( 'agency_name' => 'Before' ), get_option( 'cybermaps_settings' ) );
		$this->assertSame( array( 'takeover_enabled' => false ), get_option( 'cybermaps_robots_manager' ) );
	}

	public function test_invalid_or_invented_v2_values_are_errors_and_cannot_write(): void {
		update_option( 'cybermaps_settings', array( 'agency_name' => 'Unchanged' ) );
		$before  = $GLOBALS['cybermaps_mock_options'];
		$content = $this->changes_json(
			array(
				'reports_deliverables' => array( 'agency_url' => 'https://user:pass@example.com/private' ),
				'site_identity'       => array(
					'identity_type'            => 'Person',
					'identity_precise_type'    => 'ProfessionalService',
					'identity_address_country' => 'USA',
					'identity_latitude'        => '999',
					'identity_hours'          => array(
						'monday' => array( array( 'open' => '09:00', 'close' => '09:00' ) ),
					),
					'identity_catalogs'       => array(
						array( 'mode' => 'manual', 'item_type' => 'Service', 'items' => array() ),
					),
					'identity_contact_points' => array(
						array( 'type' => 'Sales', 'phone' => '', 'email' => '' ),
					),
				),
				'robots_control' => array(
					'crawler_overrides' => array(
						'invented-bot' => array( 'robots' => true, 'llm' => true, 'tpm' => 10 ),
					),
				),
			)
		);

		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );

		$this->assertNotEmpty( $preview['errors'] );
		$this->assertStringContainsString( 'invented-bot', implode( ' ', $preview['errors'] ) );
		$this->assertStringContainsString( 'differ', implode( ' ', $preview['errors'] ) );
		$this->assertStringContainsString( 'not compatible', implode( ' ', $preview['errors'] ) );
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );

		try {
			MigrationHub::get_instance()->import( $content, 'merge' );
			$this->fail( 'A v2 envelope with validation errors must not import.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertNotSame( '', $error->getMessage() );
		}
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_unknown_properties_and_missing_plugin_version_are_blocked(): void {
		$payload = json_decode( $this->changes_json( array() ), true, 512, JSON_THROW_ON_ERROR );
		unset( $payload['plugin_version'] );
		$payload['invented'] = true;

		$preview = MigrationHub::get_instance()->preview( (string) wp_json_encode( $payload ), 'merge' );
		$errors  = implode( ' ', $preview['errors'] );

		$this->assertStringContainsString( 'Unknown AI configuration envelope property', $errors );
		$this->assertStringContainsString( 'plugin version is missing', $errors );
	}

	public function test_publication_destinations_reject_non_public_hosts_before_sanitizing(): void {
		$content = $this->changes_json(
			array(
				'core_settings' => array(
					'external_sitemaps' => "http://127.0.0.1/private.xml",
					'external_pages'    => "http://localhost/private-page",
					'websub_hubs'       => "https://192.168.1.10/hub",
				),
			)
		);

		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );
		$errors  = implode( ' ', $preview['errors'] );

		$this->assertSame( 3, substr_count( $errors, 'publicly routable' ) );
		$this->assertSame( array(), $preview['changes'] );
	}

	public function test_line_schema_does_not_treat_zero_as_an_empty_line(): void {
		$preview = MigrationHub::get_instance()->preview(
			$this->changes_json(
				array(
					'core_settings' => array( 'external_pages' => '0' ),
				)
			),
			'merge'
		);

		$this->assertNotEmpty( $preview['errors'] );
		$this->assertStringContainsString( 'External pages[1]', implode( ' ', $preview['errors'] ) );
	}

	public function test_required_nested_labels_cannot_be_whitespace_only(): void {
		update_option(
			'cybermaps_settings',
			array( 'ai_topics' => 'SEO, WordPress' )
		);
		update_option(
			'cybermaps_identity_data',
			array(
				'catalogs' => array(
					array(
						'mode'      => 'manual',
						'item_type' => 'Service',
						'items'     => array( 'Technical SEO audit' ),
						'parent_id' => 0,
					),
				),
				'contact_points' => array(
					array( 'type' => 'Sales', 'phone' => '+1-415-555-0100', 'email' => '' ),
				),
			)
		);
		$before = $GLOBALS['cybermaps_mock_options'];
		$preview = MigrationHub::get_instance()->preview(
			$this->changes_json(
				array(
					'ai_publishing' => array( 'ai_topics' => array( '   ' ) ),
					'site_identity' => array(
						'identity_catalogs' => array(
							array(
								'mode'      => 'manual',
								'item_type' => 'Service',
								'items'     => array( '   ' ),
								'parent_id' => 0,
							),
						),
						'identity_contact_points' => array(
							array( 'type' => 'Sales', 'phone' => '   ', 'email' => '' ),
						),
					),
				)
			),
			'merge'
		);

		$this->assertGreaterThanOrEqual( 3, substr_count( implode( ' ', $preview['errors'] ), 'accepted format' ) );
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_exclusion_lists_and_strategy_maps_have_strict_resource_bounds(): void {
		$too_many_ids   = range( 1, \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX + 1 );
		$too_many_terms = array_map(
			static fn ( int $index ): string => 'term-' . $index,
			$too_many_ids
		);
		$too_many_types = array_map(
			static fn ( int $index ): string => 'type_' . $index,
			range( 0, \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX )
		);
		$too_many_taxonomies = implode(
			', ',
			array_map(
				static fn ( int $index ): string => 'taxonomy_' . $index,
				range( 0, \Cybermaps\Discovery\PublicationConstraints::TAXONOMY_FILTER_ITEMS_MAX )
			)
		);
		$too_many_groups = array();
		for ( $index = 0; $index <= \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX; ++$index ) {
			$too_many_groups[ 'post_type:type_' . $index ] = 0.5;
		}

		$preview = MigrationHub::get_instance()->preview(
			$this->changes_json(
				array(
					'core_settings' => array(
						'exclude_post_ids'       => $too_many_ids,
						'news_publication_name' => str_repeat( 'N', \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH + 1 ),
						'rss_sitemap_types'     => $too_many_types,
					),
					'ai_publishing' => array(
						'llms_exclude_ids'        => $too_many_ids,
						'ai_sitemap_exclude_terms' => $too_many_terms,
						'llms_filter_taxonomies'  => $too_many_taxonomies,
						'llms_included_types'      => $too_many_types,
						'ai_sitemap_types'         => $too_many_types,
					),
					'discovery_strategy' => array( 'discovery_overrides' => $too_many_groups ),
				)
			),
			'merge'
		);
		$errors = implode( ' ', $preview['errors'] );

		$this->assertGreaterThanOrEqual( 6, substr_count( $errors, 'too many items' ) );
		$this->assertStringContainsString( 'exceeds the accepted length limit', $errors );
		$this->assertStringContainsString( 'does not match the accepted format', $errors );
		$this->assertStringContainsString( 'too many properties', $errors );
	}

	public function test_oversized_invalid_array_stops_at_the_cardinality_error_budget(): void {
		$content = $this->changes_json(
			array(
				'ai_publishing' => array(
					'llms_exclude_ids' => array_fill( 0, 200000, 0 ),
				),
			)
		);
		$this->assertLessThan( MigrationHub::get_max_import_bytes(), strlen( $content ) );

		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );

		$this->assertLessThanOrEqual( 64, count( $preview['errors'] ) );
		$this->assertStringContainsString( 'too many items', implode( ' ', $preview['errors'] ) );
		$this->assertSame( array(), $preview['changes'] );
	}

	public function test_changing_chunk_size_cannot_invalidate_the_stored_overlap(): void {
		update_option(
			'cybermaps_settings',
			array(
				'rag_chunk_size'    => 800,
				'rag_chunk_overlap' => 100,
			)
		);

		$preview = MigrationHub::get_instance()->preview(
			$this->changes_json(
				array(
					'ai_publishing' => array( 'rag_chunk_size' => 100 ),
				)
			),
			'merge'
		);

		$this->assertStringContainsString(
			'overlap cannot exceed half',
			implode( ' ', $preview['errors'] )
		);
		$this->assertSame( 800, get_option( 'cybermaps_settings' )['rag_chunk_size'] );
	}

	public function test_publication_routes_are_sanitized_and_previewed_as_one_atomic_set(): void {
		update_option(
			'cybermaps_settings',
			array(
				'sitemap_url_base'      => 'main',
				'news_sitemap_url_base' => 'news',
				'rss_sitemap_url_base'  => 'rss',
			)
		);
		$content = $this->changes_json(
			array(
				'core_settings' => array( 'sitemap_url_base' => 'news' ),
			)
		);

		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );

		$this->assertSame( array(), $preview['errors'] );
		$this->assertSame( 'news', $this->preview_change( $preview, 'sitemap_url_base' )['final'] );
		$this->assertSame( 'news-news', $this->preview_change( $preview, 'news_sitemap_url_base' )['final'] );
		$this->assertSame( 'normalized', $this->preview_change( $preview, 'news_sitemap_url_base' )['status'] );
		$this->assertSame( 'rss', $this->preview_change( $preview, 'rss_sitemap_url_base' )['final'] );

		MigrationHub::get_instance()->import_previewed(
			$content,
			'merge',
			$preview['content_hash'],
			$preview['configuration_hash']
		);
		$stored = get_option( 'cybermaps_settings' );
		$this->assertSame( 'news', $stored['sitemap_url_base'] );
		$this->assertSame( 'news-news', $stored['news_sitemap_url_base'] );
		$this->assertSame( 'rss', $stored['rss_sitemap_url_base'] );
	}

	public function test_identity_coordinates_must_be_supplied_or_cleared_as_a_pair(): void {
		$preview = MigrationHub::get_instance()->preview(
			$this->changes_json(
				array(
					'site_identity' => array( 'identity_latitude' => '37.7749' ),
				)
			),
			'merge'
		);

		$this->assertStringContainsString(
			'latitude and longitude must either both be configured',
			implode( ' ', $preview['errors'] )
		);

		$paired = MigrationHub::get_instance()->preview(
			$this->changes_json(
				array(
					'site_identity' => array(
						'identity_type'         => 'Organization',
						'identity_precise_type' => 'ProfessionalService',
						'identity_latitude'     => '37.7749',
						'identity_longitude'    => '-122.4194',
					),
				)
			),
			'merge'
		);

		$this->assertSame( array(), $paired['errors'] );
	}

	public function test_complete_backup_preview_redacts_credentials(): void {
		update_option( 'cybermaps_settings', array( 'api_secret' => 'source-api-secret' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'source-index-key' );
		$backup = MigrationHub::get_instance()->generate_backup();

		update_option( 'cybermaps_settings', array( 'api_secret' => 'destination-api-secret' ) );
		update_option( 'cybermaps_indexnow_key', 'destination-index-key' );
		$preview = MigrationHub::get_instance()->preview( $backup, 'overwrite' );
		$encoded = (string) wp_json_encode( $preview );

		$this->assertStringNotContainsString( 'source-api-secret', $encoded );
		$this->assertStringNotContainsString( 'destination-api-secret', $encoded );
		$this->assertStringNotContainsString( 'source-index-key', $encoded );
		$this->assertStringNotContainsString( 'destination-index-key', $encoded );
		$this->assertStringContainsString( '[redacted]', $encoded );
	}

	public function test_full_replace_preview_includes_destination_values_the_backup_removes(): void {
		update_option( 'cybermaps_settings', array( 'api_secret' => 'source-secret' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', '' );
		$backup = MigrationHub::get_instance()->generate_backup();

		update_option(
			'cybermaps_settings',
			array(
				'api_secret'               => 'destination-secret',
				'agency_name'              => 'Destination Agency',
				'enable_discovery_hub'     => '1',
				'delete_data_on_uninstall' => '1',
			)
		);

		$preview = MigrationHub::get_instance()->preview( $backup, 'overwrite' );

		$this->assertSame( '', $this->preview_change( $preview, 'agency_name' )['final'] );
		$this->assertFalse( $this->preview_change( $preview, 'enable_discovery_hub' )['final'] );
		$this->assertFalse( $this->preview_change( $preview, 'delete_data_on_uninstall' )['final'] );
		$high_impact_fields = array_column( $preview['high_impact_changes'], 'field' );
		$this->assertContains( 'enable_discovery_hub', $high_impact_fields );
		$this->assertContains( 'delete_data_on_uninstall', $high_impact_fields );

		MigrationHub::get_instance()->import_previewed(
			$backup,
			'overwrite',
			$preview['content_hash'],
			$preview['configuration_hash']
		);
		$stored = get_option( 'cybermaps_settings' );
		$this->assertArrayNotHasKey( 'agency_name', $stored );
		$this->assertArrayNotHasKey( 'enable_discovery_hub', $stored );
		$this->assertArrayNotHasKey( 'delete_data_on_uninstall', $stored );
	}

	public function test_generated_markdown_changes_block_can_be_previewed_and_imported(): void {
		update_option( 'cybermaps_settings', array( 'agency_name' => 'Before' ) );
		$brief = MigrationHub::get_instance()->generate_markdown();
		$brief = str_replace( '"agency_name": null', '"agency_name": "After"', $brief );
		$preview = MigrationHub::get_instance()->preview( $brief, 'merge' );

		$this->assertSame( array(), $preview['errors'] );
		$this->assertSame( 'After', $this->preview_change( $preview, 'agency_name' )['final'] );

		MigrationHub::get_instance()->import_previewed(
			$brief,
			'merge',
			$preview['content_hash'],
			$preview['configuration_hash']
		);
		$this->assertSame( 'After', get_option( 'cybermaps_settings' )['agency_name'] );
	}

	public function test_every_documented_example_survives_the_canonical_import_pipeline(): void {
		$changes = array();
		foreach ( AIConfigurationRegistry::get_fields() as $field_id => $field ) {
			$changes[ $field['section_id'] ][ $field_id ] = $field['example'];
		}

		$preview = MigrationHub::get_instance()->preview( $this->changes_json( $changes ), 'merge' );

		$this->assertSame( array(), $preview['errors'] );
		$this->assertCount( 121, $preview['changes'] );
		$this->assertSame(
			'x_forwarded_for',
			$this->preview_change( $preview, 'trusted_proxy_header' )['final']
		);
		$this->assertSame(
			array( '10.0.0.0/8', '2001:db8::/32' ),
			$this->preview_change( $preview, 'trusted_proxy_cidrs' )['final']
		);
		foreach ( $preview['changes'] as $change ) {
			$this->assertNotSame(
				'normalized',
				$change['status'],
				(string) $change['field'] . ' has a documented example that the owning sanitizer changes.'
			);
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $changes Changes keyed by section ID.
	 */
	private function changes_json( array $changes ): string {
		return (string) wp_json_encode(
			array(
				'format'         => 'cybermaps-ai-configuration-changes',
				'format_version' => 2,
				'plugin_version' => CYBERMAPS_VERSION,
				'changes'        => $changes,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extract_changes_envelope( string $brief ): array {
		$begin = '<!-- CYBERMAPS-CHANGES-BEGIN -->';
		$end   = '<!-- CYBERMAPS-CHANGES-END -->';
		$start = strpos( $brief, $begin );
		$finish = false === $start ? false : strpos( $brief, $end, $start + strlen( $begin ) );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $finish );
		$block = trim( substr( $brief, (int) $start + strlen( $begin ), (int) $finish - ( (int) $start + strlen( $begin ) ) ) );
		$block = (string) preg_replace( '/^```json\s*|\s*```$/', '', $block );
		return json_decode( trim( $block ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * @param array<string,mixed> $preview Preview result.
	 * @return array<string,mixed>
	 */
	private function preview_change( array $preview, string $field ): array {
		foreach ( $preview['changes'] as $change ) {
			if ( is_array( $change ) && $field === ( $change['field'] ?? null ) ) {
				return $change;
			}
		}

		$this->fail( 'Preview did not include field ' . $field . '.' );
	}
}
