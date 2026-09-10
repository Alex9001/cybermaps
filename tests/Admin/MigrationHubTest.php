<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\MigrationHub;
use PHPUnit\Framework\TestCase;

final class MigrationHubTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']     = array();
		$GLOBALS['cybermaps_mock_transients']  = array();
		$GLOBALS['cybermaps_mock_actions']     = array();
		$GLOBALS['cybermaps_mock_filters']     = array();
		$GLOBALS['cybermaps_mock_post_types']  = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_taxonomies']  = array( 'category', 'post_tag' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'name' => 'post', 'public' => true ),
			'page' => (object) array( 'name' => 'page', 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array(
			'category' => (object) array( 'name' => 'category', 'public' => true ),
			'post_tag' => (object) array( 'name' => 'post_tag', 'public' => true ),
		);
		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
	}

	public function test_backup_contains_complete_versioned_json_and_valid_checksum(): void {
		update_option(
			'cybermaps_settings',
			array(
				'agency_name'             => 'Example Studio',
				'llms_custom_instructions' => "Line one\nLine two",
				'api_secret'              => 'fixed-secret',
			)
		);
		update_option(
			'cybermaps_discovery_center',
			wp_json_encode( array( 'archetype' => 'blog', 'overrides' => array(), 'type_intents' => array() ) )
		);
		update_option( 'cybermaps_robots_manager', array( 'takeover_enabled' => true, 'overrides' => array(), 'manual_directives' => '', 'content_signals' => array() ) );
		update_option( 'cybermaps_identity_data', array( 'type' => 'Organization', 'name' => 'Example' ) );
		update_option( 'cybermaps_indexnow_key', 'index-key' );

		$backup  = MigrationHub::get_instance()->generate_backup();
		$payload = json_decode( $backup, true, 512, JSON_THROW_ON_ERROR );

		$this->assertSame( 'cybermaps-configuration-backup', $payload['format'] );
		$this->assertSame( 1, $payload['format_version'] );
		$this->assertTrue( $payload['contains_secrets'] );
		$this->assertSame( "Line one\nLine two", $payload['configuration']['cybermaps_settings']['llms_custom_instructions'] );
		$this->assertSame( 'index-key', $payload['configuration']['cybermaps_indexnow_key'] );
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $payload['checksum'] );
		$this->assertSame( 'backup', MigrationHub::get_instance()->import( $backup, 'merge' )['format'] );
	}

	public function test_full_replace_round_trips_nested_multiline_unicode_and_secrets(): void {
		$settings = array(
			'enable_caching'           => '1',
			'enable_analytics'         => '1',
			'anonymize_analytics_ips'  => '0',
			'static_engine_mode'       => 'all',
			'api_secret'               => 'stable-api-secret',
			'llms_custom_instructions' => "First line\n- enable_caching: 0\nCafé 東京",
			'rss_sitemap_types'        => array( 'post', 'page' ),
			'ai_capabilities'          => array( 'search_content', 'read_articles' ),
			'ai_action_mappings'       => array(
				array(
					'url'  => 'https://example.com/book',
					'type' => 'BuyAction',
					'desc' => 'Book now',
				),
			),
		);
		$discovery = array(
			'archetype'    => 'small-business',
			'overrides'    => array( 'post' => 0.8 ),
			'type_intents' => array( 'post' => 'transactional' ),
		);
		$robots = array(
			'takeover_enabled'  => true,
			'overrides'         => array(
				'amzn-searchbot' => array( 'robots' => false, 'llm' => true, 'tpm' => 700 ),
			),
			'manual_directives' => "User-agent: *\nDisallow: /private/",
			'content_signals'   => array( 'search' => 'yes', 'ai-train' => 'no' ),
		);
		$identity = array(
			'type'             => 'LocalBusiness',
			'precise_type'     => 'ProfessionalService',
			'name'             => 'Example & Co.',
			'description'      => "Local team\nSecond line",
			'image_id'         => 45,
			'address_country'  => 'US',
			'address'          => '123 Main St',
			'city'             => 'Oakland',
			'address_region'   => 'CA',
			'postal_code'      => '94612',
			'phone'            => '+1 555 0100',
			'email'            => 'hello@example.com',
				'latitude'         => '37.8044',
				'longitude'        => '-122.2712',
				'hours'            => array(
					'monday' => array( array( 'open' => '09:00', 'close' => '17:00' ) ),
				),
			'catalogs'         => array(
				array( 'mode' => 'manual', 'name' => 'Services', 'items' => array( 'Consulting' ), 'parent_id' => 0, 'item_type' => 'Service' ),
			),
			'social_profiles'  => array( 'https://example.com/social' ),
			'contact_points'   => array(
				array( 'type' => 'Customer Support', 'phone' => '+1 555 0101', 'email' => 'support@example.com' ),
			),
		);

		update_option( 'cybermaps_settings', $settings );
		update_option( 'cybermaps_discovery_center', wp_json_encode( $discovery ) );
		update_option( 'cybermaps_robots_manager', $robots );
		update_option( 'cybermaps_identity_data', $identity );
		update_option( 'cybermaps_indexnow_key', 'stable-indexnow-key' );
		$backup = MigrationHub::get_instance()->generate_backup();

		update_option( 'cybermaps_settings', array( 'enable_caching' => '0', 'api_secret' => 'wrong-secret' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'wrong-index-key' );

			$result = MigrationHub::get_instance()->import( $backup, 'overwrite' );

			$this->assertSame( 'backup', $result['format'] );
			$this->assertSame( 'overwrite', $result['mode'] );
			$this->assertEquals(
				array_merge(
					$settings,
					array(
						'sitemap_url_base'      => 'sitemap',
						'news_sitemap_url_base' => 'sitemap-news',
						'rss_sitemap_url_base'  => 'sitemap-rss',
					)
				),
				get_option( 'cybermaps_settings' ),
				'Full Replace must restore equivalent route defaults as an explicit collision-safe set.'
			);
		$this->assertEquals(
			array(
				'archetype'    => 'small-business',
				'overrides'    => array( 'post_type:post' => 0.8 ),
				'type_intents' => array( 'post_type:post' => 'transactional' ),
				'disabled'     => array(),
			),
			json_decode( (string) get_option( 'cybermaps_discovery_center' ), true ),
			'Import canonicalizes legacy raw object slugs to kind-aware matrix identities.'
		);
		$this->assertEquals(
			array_merge(
				$robots,
				array(
					'content_usage_enabled'   => false,
					'content_usage_overrides' => array(),
				)
			),
			get_option( 'cybermaps_robots_manager' ),
			'Full Replace restores the canonical robots schema, including the opt-in AIPREF defaults.'
		);
		$this->assertEquals( $identity, get_option( 'cybermaps_identity_data' ) );
		$this->assertSame( 'stable-indexnow-key', get_option( 'cybermaps_indexnow_key' ) );
	}

	public function test_backup_merge_preserves_destination_only_values(): void {
		update_option(
			'cybermaps_settings',
			array(
				'agency_name' => 'Source Studio',
				'api_secret'  => 'source-secret',
			)
		);
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		$backup = MigrationHub::get_instance()->generate_backup();

		update_option(
			'cybermaps_settings',
			array(
				'agency_name'       => 'Destination Studio',
				'frontend_base_url' => 'https://frontend.example/',
				'api_secret'        => 'destination-secret',
			)
		);

		MigrationHub::get_instance()->import( $backup, 'merge' );
		$settings = get_option( 'cybermaps_settings' );

		$this->assertSame( 'Source Studio', $settings['agency_name'] );
		$this->assertSame( 'https://frontend.example/', $settings['frontend_base_url'] );
		$this->assertSame( 'source-secret', $settings['api_secret'] );
	}

	public function test_importing_a_backup_into_its_source_does_not_materialize_defaults(): void {
		update_option(
			'cybermaps_settings',
			array(
				'agency_name' => 'Sparse Source',
				'api_secret'  => 'source-secret',
			)
		);
		update_option( 'cybermaps_discovery_center', '{"archetype":"blog"}' );
		update_option( 'cybermaps_robots_manager', array( 'takeover_enabled' => false ) );
		update_option( 'cybermaps_identity_data', array( 'type' => 'Organization' ) );
		$before = $GLOBALS['cybermaps_mock_options'];

		$result = MigrationHub::get_instance()->import(
			MigrationHub::get_instance()->generate_backup(),
			'merge'
		);

		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame( array(), $result['changed_groups'] );
		$this->assertEqualsCanonicalizing(
			array(
				'cybermaps_settings',
				'cybermaps_discovery_center',
				'cybermaps_robots_manager',
				'cybermaps_identity_data',
				'cybermaps_indexnow_key',
			),
			$result['unchanged_groups']
		);
	}

	public function test_backup_integrity_is_portable_across_serialize_precision_settings(): void {
		update_option( 'cybermaps_settings', array( 'api_secret' => 'portable-secret' ) );
		update_option(
			'cybermaps_discovery_center',
			wp_json_encode(
				array(
					'archetype'    => 'blog',
					'overrides'    => array( 'post' => 0.2 ),
					'type_intents' => array(),
				)
			)
		);
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );

		$previous = ini_get( 'serialize_precision' );
		try {
			foreach ( array( '17', '3', '1', '0', '-1' ) as $precision ) {
				ini_set( 'serialize_precision', $precision );
				$backup = MigrationHub::get_instance()->generate_backup();
				ini_set( 'serialize_precision', '-1' );
				$result = MigrationHub::get_instance()->import( $backup, 'merge' );
				$this->assertSame( 'backup', $result['format'] );
			}
		} finally {
			if ( false !== $previous ) {
				ini_set( 'serialize_precision', (string) $previous );
			}
		}

		$this->assertSame( 0.2, json_decode( (string) get_option( 'cybermaps_discovery_center' ), true )['overrides']['post'] );
	}

	public function test_backup_checksum_preserves_integral_and_negative_zero_float_types(): void {
		update_option(
			'cybermaps_settings',
			array(
				'api_secret'              => 'portable-secret',
				'ai_sitemap_custom_links' => array(
					array(
						'url'      => 'https://example.com/priority',
						'label'    => 'Priority',
						'priority' => 1.0,
					),
				),
			)
		);
		update_option(
			'cybermaps_discovery_center',
			wp_json_encode(
				array(
					'archetype'    => 'blog',
					'overrides'    => array( 'post' => -0.0 ),
					'type_intents' => array(),
				),
				JSON_PRESERVE_ZERO_FRACTION
			)
		);
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );

		$backup = MigrationHub::get_instance()->generate_backup();

		$this->assertStringContainsString( '"priority": 1.0', $backup );
		$this->assertStringContainsString( '"post": -0.0', $backup );
		$this->assertSame( 'backup', MigrationHub::get_instance()->import( $backup, 'merge' )['format'] );
	}

	public function test_checksum_type_tags_prevent_a_float_from_being_replaced_by_a_lookalike_map(): void {
		update_option( 'cybermaps_settings', array( 'api_secret' => 'portable-secret' ) );
		update_option(
			'cybermaps_discovery_center',
			wp_json_encode(
				array(
					'archetype'    => 'blog',
					'overrides'    => array( 'post' => 1.0 ),
					'type_intents' => array(),
				),
				JSON_PRESERVE_ZERO_FRACTION
			)
		);
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'index-key' );

		$payload = json_decode(
			MigrationHub::get_instance()->generate_backup(),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$payload['configuration']['cybermaps_discovery_center']['overrides']['post'] = array(
			'__cybermaps_checksum_type' => 'float64',
			'value'                     => bin2hex( pack( 'E', 1.0 ) ),
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'integrity' );
		MigrationHub::get_instance()->import(
			(string) wp_json_encode( $payload ),
			'overwrite'
		);
	}

	public function test_smart_merge_preserves_destination_only_nested_map_entries(): void {
		update_option( 'cybermaps_settings', array( 'api_secret' => 'source-secret' ) );
		update_option(
			'cybermaps_discovery_center',
			wp_json_encode(
				array(
					'archetype'    => 'blog',
					'overrides'    => array( 'post' => 0.8 ),
					'type_intents' => array( 'post' => 'transactional' ),
				)
			)
		);
		update_option(
			'cybermaps_robots_manager',
			array(
				'takeover_enabled'  => false,
				'overrides'         => array(
					'gptbot' => array( 'robots' => true, 'llm' => false, 'tpm' => 100 ),
				),
				'manual_directives' => '',
				'content_signals'   => array( 'ai-input' => 'no' ),
			)
		);
		update_option(
			'cybermaps_identity_data',
			array(
					'type'  => 'Organization',
					'hours' => array(
						'monday' => array( array( 'open' => '09:00', 'close' => '17:00' ) ),
					),
			)
		);
		$backup = MigrationHub::get_instance()->generate_backup();

		update_option(
			'cybermaps_discovery_center',
			wp_json_encode(
				array(
					'archetype'    => 'small-business',
					'overrides'    => array( 'page' => 0.6 ),
					'type_intents' => array( 'page' => 'informational' ),
				)
			)
		);
		update_option(
			'cybermaps_robots_manager',
			array(
				'takeover_enabled'  => false,
				'overrides'         => array(
					'claudebot' => array( 'robots' => false, 'llm' => true, 'tpm' => 200 ),
				),
				'manual_directives' => '',
				'content_signals'   => array( 'search' => 'yes' ),
			)
		);
		update_option(
			'cybermaps_identity_data',
			array(
					'type'  => 'Organization',
					'hours' => array(
						'tuesday' => array( array( 'open' => '10:00', 'close' => '16:00' ) ),
					),
			)
		);

		MigrationHub::get_instance()->import( $backup, 'merge' );
		$discovery = json_decode( (string) get_option( 'cybermaps_discovery_center' ), true );
		$robots    = get_option( 'cybermaps_robots_manager' );
		$identity  = get_option( 'cybermaps_identity_data' );

		$this->assertSame(
			array( 'post_type:page' => 0.6, 'post_type:post' => 0.8 ),
			$discovery['overrides']
		);
		$this->assertSame(
			array( 'post_type:page' => 'informational', 'post_type:post' => 'transactional' ),
			$discovery['type_intents']
		);
		$this->assertArrayHasKey( 'claudebot', $robots['overrides'] );
		$this->assertArrayHasKey( 'gptbot', $robots['overrides'] );
		$this->assertSame( array( 'search' => 'yes', 'ai-input' => 'no' ), $robots['content_signals'] );
			$this->assertArrayHasKey( 'tuesday', $identity['hours'] );
			$this->assertArrayHasKey( 'monday', $identity['hours'] );
	}

	public function test_full_replace_removes_destination_only_top_level_and_nested_values(): void {
		update_option(
			'cybermaps_settings',
			array(
				'agency_name' => 'Source Studio',
				'api_secret'  => 'source-secret',
			)
		);
		update_option(
			'cybermaps_discovery_center',
			wp_json_encode(
				array(
					'archetype'    => 'blog',
					'overrides'    => array( 'post' => 0.8 ),
					'type_intents' => array( 'post' => 'transactional' ),
				)
			)
		);
		update_option(
			'cybermaps_robots_manager',
			array(
				'takeover_enabled'  => false,
				'overrides'         => array(
					'gptbot' => array( 'robots' => false, 'llm' => true, 'tpm' => 50 ),
				),
				'manual_directives' => '',
				'content_signals'   => array( 'search' => 'yes' ),
			)
		);
		update_option(
			'cybermaps_identity_data',
			array(
				'type'  => 'Organization',
				'name'  => 'Source',
				'hours' => array(
					'monday' => array( array( 'open' => '09:00', 'close' => '17:00' ) ),
				),
			)
		);
		update_option( 'cybermaps_indexnow_key', 'source-index-key' );
		$backup = MigrationHub::get_instance()->generate_backup();

		update_option(
			'cybermaps_settings',
			array(
				'agency_name'       => 'Destination Studio',
				'frontend_base_url' => 'https://destination.example/',
				'api_secret'        => 'destination-secret',
			)
		);
		update_option(
			'cybermaps_discovery_center',
			wp_json_encode(
				array(
					'archetype'    => 'corporate',
					'overrides'    => array( 'page' => 0.4 ),
					'type_intents' => array( 'page' => 'informational' ),
				)
			)
		);
		update_option(
			'cybermaps_robots_manager',
			array(
				'takeover_enabled'  => true,
				'overrides'         => array(
					'claudebot' => array( 'robots' => true, 'llm' => false, 'tpm' => 100 ),
				),
				'manual_directives' => 'Disallow: /old/',
				'content_signals'   => array( 'training' => 'no' ),
			)
		);
		update_option(
			'cybermaps_identity_data',
			array(
				'type'  => 'Organization',
				'name'  => 'Destination',
				'hours' => array(
					'tuesday' => array( array( 'open' => '10:00', 'close' => '16:00' ) ),
				),
			)
		);
		update_option( 'cybermaps_indexnow_key', 'destination-index-key' );

		MigrationHub::get_instance()->import( $backup, 'overwrite' );
		$settings  = get_option( 'cybermaps_settings' );
		$discovery = json_decode( (string) get_option( 'cybermaps_discovery_center' ), true );
		$robots    = get_option( 'cybermaps_robots_manager' );
		$identity  = get_option( 'cybermaps_identity_data' );

		$this->assertSame( 'Source Studio', $settings['agency_name'] );
		$this->assertArrayNotHasKey( 'frontend_base_url', $settings );
		$this->assertSame( array( 'post_type:post' => 0.8 ), $discovery['overrides'] );
		$this->assertSame( array( 'post_type:post' => 'transactional' ), $discovery['type_intents'] );
		$this->assertSame( array( 'gptbot' ), array_keys( $robots['overrides'] ) );
		$this->assertSame( array( 'search' => 'yes' ), $robots['content_signals'] );
		$this->assertSame( array( 'monday' ), array_keys( $identity['hours'] ) );
		$this->assertSame( 'source-index-key', get_option( 'cybermaps_indexnow_key' ) );
	}

	public function test_apply_targets_rolls_back_when_an_option_hook_throws(): void {
		$before_settings = array( 'agency_name' => 'Before' );
		$before_robots   = array( 'takeover_enabled' => false );
		update_option( 'cybermaps_settings', $before_settings );
		update_option( 'cybermaps_robots_manager', $before_robots );

		$thrown = false;
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, $value, string $stage ) use ( &$thrown ): void {
			unset( $value );
			if ( ! $thrown && 'cybermaps_robots_manager' === $option && 'after' === $stage ) {
				$thrown = true;
				throw new \RuntimeException( 'Simulated after-update hook failure.' );
			}
		};

		$method = new \ReflectionMethod( MigrationHub::class, 'apply_targets' );
		try {
			$method->invoke(
				MigrationHub::get_instance(),
				array(
					'cybermaps_settings'       => array( 'agency_name' => 'After' ),
					'cybermaps_robots_manager' => array( 'takeover_enabled' => true ),
				)
			);
			$this->fail( 'A throwing option hook should fail the import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'restored the previous configuration', $error->getMessage() );
		} finally {
			unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		}

		$this->assertSame( $before_settings, get_option( 'cybermaps_settings' ) );
		$this->assertSame( $before_robots, get_option( 'cybermaps_robots_manager' ) );
	}

	public function test_apply_targets_snapshots_every_group_before_option_hooks_can_mutate_it(): void {
		$before_settings = array( 'agency_name' => 'Before' );
		$before_robots   = array( 'takeover_enabled' => false );
		$before_identity = array( 'name' => 'Before' );
		update_option( 'cybermaps_settings', $before_settings );
		update_option( 'cybermaps_robots_manager', $before_robots );
		update_option( 'cybermaps_identity_data', $before_identity );

		$thrown = false;
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, $value, string $stage ) use ( &$thrown ): void {
			if (
				'cybermaps_settings' === $option
				&& 'after' === $stage
				&& array( 'agency_name' => 'After' ) === $value
			) {
				$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager'] = array( 'hook_mutation' => true );
			}
			if ( ! $thrown && 'cybermaps_identity_data' === $option && 'after' === $stage ) {
				$thrown = true;
				throw new \RuntimeException( 'Simulated later hook failure.' );
			}
		};

		$method = new \ReflectionMethod( MigrationHub::class, 'apply_targets' );
		try {
			$method->invoke(
				MigrationHub::get_instance(),
				array(
					'cybermaps_settings'       => array( 'agency_name' => 'After' ),
					'cybermaps_robots_manager' => array( 'takeover_enabled' => true ),
					'cybermaps_identity_data'  => array( 'name' => 'After' ),
				)
			);
			$this->fail( 'A throwing option hook should fail the import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'restored the previous configuration', $error->getMessage() );
		} finally {
			unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		}

		$this->assertSame( $before_settings, get_option( 'cybermaps_settings' ) );
		$this->assertSame( $before_robots, get_option( 'cybermaps_robots_manager' ) );
		$this->assertSame( $before_identity, get_option( 'cybermaps_identity_data' ) );
	}

	public function test_apply_targets_reports_an_incomplete_rollback(): void {
		$before_settings = array( 'agency_name' => 'Before' );
		$before_robots   = array( 'takeover_enabled' => false );
		update_option( 'cybermaps_settings', $before_settings );
		update_option( 'cybermaps_robots_manager', $before_robots );

		$initial_failure = false;
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, $value, string $stage ) use ( &$initial_failure, $before_settings ): void {
			if (
				$initial_failure
				&& 'cybermaps_settings' === $option
				&& 'before' === $stage
				&& $before_settings === $value
			) {
				throw new \RuntimeException( 'Simulated rollback failure.' );
			}
			if ( ! $initial_failure && 'cybermaps_robots_manager' === $option && 'after' === $stage ) {
				$initial_failure = true;
				throw new \RuntimeException( 'Simulated import failure.' );
			}
		};

		$method = new \ReflectionMethod( MigrationHub::class, 'apply_targets' );
		try {
			$method->invoke(
				MigrationHub::get_instance(),
				array(
					'cybermaps_settings'       => array( 'agency_name' => 'After' ),
					'cybermaps_robots_manager' => array( 'takeover_enabled' => true ),
				)
			);
			$this->fail( 'An incomplete rollback should fail the import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'could not fully restore', $error->getMessage() );
			$this->assertStringContainsString( 'cybermaps_settings', $error->getMessage() );
		} finally {
			unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		}

		$this->assertSame( array( 'agency_name' => 'After' ), get_option( 'cybermaps_settings' ) );
		$this->assertSame( $before_robots, get_option( 'cybermaps_robots_manager' ) );
	}

	public function test_nested_import_from_an_option_hook_is_rejected_and_outer_import_rolls_back(): void {
		update_option( 'cybermaps_settings', array( 'agency_name' => 'Source', 'api_secret' => 'source-secret' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'source-index-key' );
		$backup = MigrationHub::get_instance()->generate_backup();

		$before = array( 'agency_name' => 'Before', 'api_secret' => 'before-secret' );
		update_option( 'cybermaps_settings', $before );
		$nested_attempted = false;
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, $value, string $stage ) use ( &$nested_attempted, $backup ): void {
			unset( $value );
			if ( ! $nested_attempted && 'cybermaps_settings' === $option && 'after' === $stage ) {
				$nested_attempted = true;
				MigrationHub::get_instance()->import( $backup, 'merge' );
			}
		};

		try {
			MigrationHub::get_instance()->import( $backup, 'merge' );
			$this->fail( 'The nested import should have failed the outer import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'restored the previous configuration', $error->getMessage() );
		} finally {
			unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		}

		$this->assertTrue( $nested_attempted );
		$this->assertSame( $before, get_option( 'cybermaps_settings' ) );
		$this->assertSame( 'backup', MigrationHub::get_instance()->import( $backup, 'merge' )['format'] );
	}

	public function test_full_replace_does_not_preserve_destination_secrets_missing_from_source(): void {
			update_option( 'cybermaps_settings', array( 'agency_name' => 'Source Studio' ) );
			update_option( 'cybermaps_discovery_center', '{}' );
			update_option( 'cybermaps_robots_manager', array() );
			update_option( 'cybermaps_identity_data', array() );
			update_option( 'cybermaps_indexnow_key', '' );
			$backup = MigrationHub::get_instance()->generate_backup();

			update_option( 'cybermaps_settings', array( 'api_secret' => 'destination-secret' ) );
			update_option( 'cybermaps_indexnow_key', 'destination-index-key' );
			$result = MigrationHub::get_instance()->import( $backup, 'overwrite' );

			$restored_secret = get_option( 'cybermaps_settings' )['api_secret'];
			$this->assertNotSame( 'destination-secret', $restored_secret );
			$this->assertNotSame( '', $restored_secret );
			$this->assertSame( '', get_option( 'cybermaps_indexnow_key' ) );
			$this->assertStringContainsString( 'generated a new one', implode( ' ', $result['warnings'] ) );
	}

	public function test_smart_merge_preserves_destination_secrets_when_source_values_are_empty(): void {
		update_option(
			'cybermaps_settings',
			array(
				'agency_name' => 'Source Studio',
				'api_secret'  => '',
			)
		);
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', '' );
		$backup = MigrationHub::get_instance()->generate_backup();

		update_option(
			'cybermaps_settings',
			array(
				'agency_name' => 'Destination Studio',
				'api_secret'  => 'destination-secret',
			)
		);
		update_option( 'cybermaps_indexnow_key', 'destination-index-key' );

		$result = MigrationHub::get_instance()->import( $backup, 'merge' );

		$this->assertSame( 'Source Studio', get_option( 'cybermaps_settings' )['agency_name'] );
		$this->assertSame( 'destination-secret', get_option( 'cybermaps_settings' )['api_secret'] );
		$this->assertSame( 'destination-index-key', get_option( 'cybermaps_indexnow_key' ) );
		$this->assertStringContainsString( 'destination API secret was preserved', implode( ' ', $result['warnings'] ) );
		$this->assertStringContainsString( 'destination IndexNow key was preserved', implode( ' ', $result['warnings'] ) );
	}

	public function test_backup_scope_contains_only_site_level_configuration_groups(): void {
		update_option( 'cybermaps_settings', array( 'api_secret' => 'site-secret' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'index-key' );

		update_option( 'cybermaps_network_settings', array( 'enable_master_index' => '1' ) );
		update_option( 'cybermaps_last_static_sync_report', array( 'success' => true ) );
		update_option( 'cybermaps_static_hashes', array( '/ai.json' => 'hash' ) );
		update_option( 'cybermaps_audit_schema_version', 3 );

		$backup  = MigrationHub::get_instance()->generate_backup();
		$payload = json_decode( $backup, true, 512, JSON_THROW_ON_ERROR );

		$this->assertLessThanOrEqual( MigrationHub::get_max_import_bytes(), strlen( $backup ) );
		$this->assertSame(
			array(
				'cybermaps_settings',
				'cybermaps_discovery_center',
				'cybermaps_robots_manager',
				'cybermaps_identity_data',
				'cybermaps_indexnow_key',
			),
			array_keys( $payload['configuration'] )
		);
		$this->assertArrayNotHasKey( 'cybermaps_network_settings', $payload['configuration'] );
		$this->assertArrayNotHasKey( 'cybermaps_last_static_sync_report', $payload['configuration'] );
		$this->assertArrayNotHasKey( 'cybermaps_static_hashes', $payload['configuration'] );
		$this->assertArrayNotHasKey( 'cybermaps_audit_schema_version', $payload['configuration'] );
	}

	public function test_invalid_mode_empty_input_and_oversized_input_are_rejected_without_writes(): void {
		update_option( 'cybermaps_settings', array( 'agency_name' => 'Original', 'api_secret' => 'secret' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'index-key' );
		$hub    = MigrationHub::get_instance();
		$backup = $hub->generate_backup();
		$before = $GLOBALS['cybermaps_mock_options'];

		foreach (
			array(
				array( $backup, 'replace' ),
				array( " \n\t", 'merge' ),
				array( str_repeat( 'x', MigrationHub::get_max_import_bytes() + 1 ), 'merge' ),
			) as $attempt
		) {
			try {
				$hub->import( $attempt[0], $attempt[1] );
				$this->fail( 'The invalid import should have been rejected.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertNotSame( '', $error->getMessage() );
			}
			$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
		}
	}

	public function test_export_never_produces_a_backup_larger_than_the_importer_accepts(): void {
		update_option(
			'cybermaps_settings',
			array(
				'api_secret'               => 'secret',
				'llms_custom_instructions' => str_repeat( 'x', MigrationHub::get_max_import_bytes() ),
			)
		);
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'index-key' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'portable-backup limit' );
		MigrationHub::get_instance()->generate_backup();
	}

	public function test_maximum_bounded_configuration_fits_portable_backup_and_round_trips(): void {
		$max_url = static function ( string $host, int $index ): string {
			$prefix = 'https://' . $host . '/' . $index . '/';
			return $prefix . str_repeat(
				'x',
				\Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH - strlen( $prefix )
			);
		};
		$bounded_url = static function ( string $host, int $index ): string {
			$prefix = 'https://' . $host . '/' . $index . '/';
			return $prefix . str_repeat( 'x', 128 - strlen( $prefix ) );
		};

		$external_pages    = array();
		$external_sitemaps = array();
		for ( $index = 0; $index < 1000; ++$index ) {
			$external_pages[] = $bounded_url( 'example.com', $index );
		}
		for ( $index = 0; $index < 100; ++$index ) {
			$external_sitemaps[] = $bounded_url( 'example.com', $index ) . '.xml';
		}

		$custom_links    = array();
		$action_mappings = array();
		for ( $index = 0; $index < \Cybermaps\Discovery\PublicationConstraints::CUSTOM_LINKS_MAX; ++$index ) {
			$custom_links[] = array(
				'url'      => $max_url( 'links.example', $index ),
				'priority' => 1.0,
			);
		}
		for ( $index = 0; $index < \Cybermaps\Discovery\PublicationConstraints::ACTION_MAPPINGS_MAX; ++$index ) {
			$action_mappings[] = array(
				'url'  => $max_url( 'actions.example', $index ),
				'type' => 'ContactAction',
				'desc' => str_pad( 'Action ' . $index . ' ', 256, 'd' ),
			);
		}

		$topics = array();
		for ( $index = 0; $index < \Cybermaps\Discovery\PublicationConstraints::TOPICS_MAX; ++$index ) {
			$topics[] = str_pad(
				'Topic ' . $index . ' ',
				\Cybermaps\Discovery\PublicationConstraints::TOPIC_LABEL_MAX_LENGTH,
				't'
			);
		}

		$settings = \Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer::sanitize_import(
			array(
				'api_secret'               => str_repeat( 's', 32 ),
				'external_pages'            => implode( "\n", $external_pages ),
				'external_sitemaps'         => implode( "\n", $external_sitemaps ),
				'websub_hubs'               => implode(
					"\n",
					array_map(
						static fn( int $index ): string => 'https://hub' . $index . '.example/',
						range( 1, 10 )
					)
				),
				'ai_sitemap_custom_links'   => $custom_links,
				'ai_action_mappings'        => $action_mappings,
				'llms_title_override'       => str_repeat( 'T', 256 ),
				'llms_mission_statement'    => str_repeat(
					'm',
					\Cybermaps\Discovery\PublicationConstraints::MISSION_MAX_LENGTH
				),
				'llms_custom_instructions'  => str_repeat(
					'g',
					\Cybermaps\Discovery\PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH
				),
				'site_guide_instructions'   => str_repeat(
					's',
					\Cybermaps\Discovery\PublicationConstraints::SITE_GUIDE_ADDITION_MAX_LENGTH
				),
				'ai_topics'                 => $topics,
				'ai_sitemap_limit'          => \Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_MAX,
				'ai_feed_limit'             => \Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_MAX,
				'rss_sitemap_limit'         => 1000,
				'llms_tldr_token_budget'    => 200000,
				'audit_post_min_words'      => 10000,
				'audit_post_max_age_days'   => 36500,
				'audit_page_min_words'      => 10000,
				'audit_page_max_age_days'   => 36500,
				'log_retention_days'        => 365,
				'static_engine_mode'        => 'all',
				'enable_discovery_hub'      => '1',
				'enable_analytics'          => '1',
				'anonymize_analytics_ips'   => '1',
				'enable_caching'            => '1',
				'enable_indexnow'           => '1',
				'enable_websub'             => '1',
				'enable_rss_sitemap'        => '1',
				'include_homepage'           => '1',
				'include_authors'            => '1',
				'include_archives'           => '1',
				'include_empty_terms'        => '1',
				'ai_sitemap_types'          => array( 'post', 'page' ),
				'llms_included_types'        => array( 'post', 'page' ),
				'rss_sitemap_types'         => array( 'post', 'page' ),
				'ai_manifest_endpoints'      => array(
					'llms.txt',
					'llms-full.txt',
					'llms-tldr.txt',
					'skill.md',
					'ai-usage.json',
					'ai-actions.json',
					'knowledge-graph.json',
					'feed.json',
					'ai-sitemap.xml',
				),
				'ai_capabilities'           => array( 'search_content', 'read_articles', 'extract_entities' ),
			),
			array()
		);

		$discovery_json = \Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer::sanitize(
			(string) wp_json_encode(
				array(
					'archetype'    => 'medium-business',
					'overrides'    => array(
						'post_type:post'  => 1.0,
						'post_type:page'  => 0.9,
						'taxonomy:category' => 0.8,
						'taxonomy:post_tag' => 0.7,
					),
					'type_intents' => array(
						'post_type:post'  => 'informational',
						'post_type:page'  => 'transactional',
						'taxonomy:category' => 'informational',
						'taxonomy:post_tag' => 'transactional',
					),
				)
			)
		);
		$discovery      = json_decode( $discovery_json, true, 512, JSON_THROW_ON_ERROR );

		$robot_overrides = array();
		foreach ( \Cybermaps\Core\CrawlerRegistry::get_policy_bots() as $bot_id => $bot ) {
			$robot_overrides[ $bot_id ] = array(
				'robots' => empty( $bot->default['robots'] ),
				'llm'    => empty( $bot->default['llm'] ),
				'tpm'    => 10000,
			);
		}
		$robots = \Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer::sanitize(
			array(
				'takeover_enabled'  => true,
				'overrides'         => $robot_overrides,
				'manual_directives' => str_repeat(
					'r',
					\Cybermaps\Discovery\Robots::MAX_MANUAL_DIRECTIVES_BYTES
				),
				'content_signals'   => array(
					'ai-train' => 'no',
					'search'   => 'yes',
					'ai-input' => 'no',
				),
			)
		);

		$hours = array();
		foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
			$hours[ $day ] = array(
				array( 'open' => '00:00', 'close' => '01:00' ),
				array( 'open' => '02:00', 'close' => '03:00' ),
				array( 'open' => '04:00', 'close' => '05:00' ),
				array( 'open' => '06:00', 'close' => '07:00' ),
			);
		}

		$catalogs       = array();
		$remaining_items = \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_TOTAL;
		for ( $catalog_index = 0; $catalog_index < \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS; ++$catalog_index ) {
			$catalog_count = min(
				\Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG,
				(int) ceil(
					$remaining_items
					/ ( \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS - $catalog_index )
				)
			);
			$items = array();
			for ( $item_index = 0; $item_index < $catalog_count; ++$item_index ) {
				$items[] = str_pad(
					'Catalog ' . $catalog_index . ' item ' . $item_index . ' ',
					\Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH,
					'i'
				);
			}
			$catalogs[] = array(
				'mode'      => 'manual',
				'item_type' => 'Service',
				'name'      => str_pad(
					'Catalog ' . $catalog_index . ' ',
					\Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH,
					'c'
				),
				'items'     => $items,
				'parent_id' => 0,
			);
			$remaining_items -= $catalog_count;
		}

		$social_profiles = array();
		for ( $index = 0; $index < \Cybermaps\Core\IdentityEntityBuilder::MAX_SOCIAL_PROFILES; ++$index ) {
			$social_profiles[] = $max_url( 'social.example', $index );
		}
		$contact_points = array();
		for ( $index = 0; $index < \Cybermaps\Core\IdentityEntityBuilder::MAX_CONTACT_POINTS; ++$index ) {
			$contact_points[] = array(
				'type'  => 0 === $index % 2 ? 'Customer Support' : 'Technical Support',
				'phone' => str_pad( '+1-' . $index . '-', 64, '5' ),
				'email' => 'contact-' . $index . '@example.com',
			);
		}

		$identity = ( new \Cybermaps\Admin\IdentityHub() )->sanitize_identity_data(
			array(
				'type'             => 'LocalBusiness',
				'precise_type'     => 'ProfessionalService',
				'name'             => str_repeat( 'N', \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ),
				'description'      => str_repeat( 'D', \Cybermaps\Core\IdentityEntityBuilder::MAX_DESCRIPTION_LENGTH ),
				'image_id'         => PHP_INT_MAX,
				'address_country'  => 'US',
				'address'          => str_repeat( 'A', \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ),
				'city'             => str_repeat( 'C', \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ),
				'address_region'   => str_repeat( 'R', \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ),
				'postal_code'      => str_repeat( 'P', \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ),
				'phone'            => str_repeat( '5', 64 ),
				'email'            => 'identity@example.com',
				'latitude'         => '90',
				'longitude'        => '180',
				'hours'            => $hours,
				'catalogs'         => $catalogs,
				'social_profiles'  => $social_profiles,
				'contact_points'   => $contact_points,
			)
		);

		$this->assertCount( 1000, explode( "\n", $settings['external_pages'] ) );
		$this->assertCount( 100, explode( "\n", $settings['external_sitemaps'] ) );
		$this->assertCount(
			\Cybermaps\Discovery\PublicationConstraints::CUSTOM_LINKS_MAX,
			$settings['ai_sitemap_custom_links']
		);
		$this->assertCount(
			\Cybermaps\Discovery\PublicationConstraints::ACTION_MAPPINGS_MAX,
			$settings['ai_action_mappings']
		);
		$this->assertCount( \Cybermaps\Discovery\PublicationConstraints::TOPICS_MAX, $topics );
		$this->assertSame(
			\Cybermaps\Discovery\PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH,
			strlen( $settings['llms_custom_instructions'] )
		);
		$this->assertSame(
			\Cybermaps\Discovery\Robots::MAX_MANUAL_DIRECTIVES_BYTES,
			strlen( $robots['manual_directives'] )
		);
		$this->assertCount( count( \Cybermaps\Core\CrawlerRegistry::get_policy_bots() ), $robots['overrides'] );
		$this->assertCount( \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS, $identity['catalogs'] );
		$this->assertCount( \Cybermaps\Core\IdentityEntityBuilder::MAX_SOCIAL_PROFILES, $identity['social_profiles'] );
		$this->assertCount( \Cybermaps\Core\IdentityEntityBuilder::MAX_CONTACT_POINTS, $identity['contact_points'] );
		$this->assertSame(
			\Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_TOTAL,
			array_sum( array_map( static fn( array $catalog ): int => count( $catalog['items'] ), $identity['catalogs'] ) )
		);
		$this->assertSame(
			\Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH,
			strlen( $identity['social_profiles'][0] )
		);
		$this->assertSame( 0, $remaining_items );

		update_option( 'cybermaps_settings', $settings );
		update_option( 'cybermaps_discovery_center', $discovery_json );
		update_option( 'cybermaps_robots_manager', $robots );
		update_option( 'cybermaps_identity_data', $identity );
		update_option( 'cybermaps_indexnow_key', str_repeat( 'k', 128 ) );

		$backup = MigrationHub::get_instance()->generate_backup();
		$this->assertGreaterThan( 750000, strlen( $backup ), 'The fixture must remain a meaningful near-limit stress case.' );
		$this->assertLessThan( MigrationHub::get_max_import_bytes(), strlen( $backup ) );

		update_option( 'cybermaps_settings', array( 'api_secret' => 'replacement' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'replacement' );

		$result = MigrationHub::get_instance()->import( $backup, 'overwrite' );

		$this->assertSame( 'overwrite', $result['mode'] );
		$this->assertEquals( $settings, get_option( 'cybermaps_settings' ) );
		$this->assertSame(
			$discovery,
			json_decode( (string) get_option( 'cybermaps_discovery_center' ), true, 512, JSON_THROW_ON_ERROR )
		);
		$this->assertSame( $robots, get_option( 'cybermaps_robots_manager' ) );
		$this->assertSame( $identity, get_option( 'cybermaps_identity_data' ) );
		$this->assertSame( str_repeat( 'k', 128 ), get_option( 'cybermaps_indexnow_key' ) );
	}

	public function test_malformed_backup_types_are_rejected_before_any_write(): void {
		update_option( 'cybermaps_settings', array( 'agency_name' => 'Original', 'api_secret' => 'secret' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		update_option( 'cybermaps_indexnow_key', 'index-key' );
		$hub     = MigrationHub::get_instance();
		$payload = json_decode( $hub->generate_backup(), true, 512, JSON_THROW_ON_ERROR );
		$before  = $GLOBALS['cybermaps_mock_options'];

		$malformed = $payload;
		$malformed['format_version'] = '1';
		$this->assertRejectedBackupPayload( $malformed, $before );

		$malformed = $payload;
		unset( $malformed['configuration']['cybermaps_indexnow_key'] );
		$malformed['checksum'] = $this->checksum( $malformed['configuration'] );
		$this->assertRejectedBackupPayload( $malformed, $before );

		$malformed = $payload;
		$malformed['configuration']['cybermaps_robots_manager'] = array( 'not-a-map' );
		$malformed['checksum'] = $this->checksum( $malformed['configuration'] );
		$this->assertRejectedBackupPayload( $malformed, $before );

		$malformed = $payload;
		$malformed['configuration']['cybermaps_indexnow_key'] = array( 'bad' );
		$malformed['checksum'] = $this->checksum( $malformed['configuration'] );
		$this->assertRejectedBackupPayload( $malformed, $before );

		$malformed = $payload;
		$malformed['configuration']['cybermaps_settings']['api_secret'] = array( 'bad' );
		$malformed['checksum'] = $this->checksum( $malformed['configuration'] );
		$this->assertRejectedBackupPayload( $malformed, $before );

		$malformed = $payload;
		$malformed['configuration']['cybermaps_future_group'] = array( 'enabled' => true );
		$malformed['checksum'] = $this->checksum( $malformed['configuration'] );
		$this->assertRejectedBackupPayload( $malformed, $before );

		$malformed = $payload;
		$malformed['checksum'] = array( 'sha256:not-a-string' );
		$this->assertRejectedBackupPayload( $malformed, $before );
	}

	public function test_earlier_markdown_templates_are_rejected_without_writes(): void {
		update_option(
			'cybermaps_settings',
			array(
				'agency_name' => 'Original',
				'api_secret'  => 'secret',
			)
		);
		$before = $GLOBALS['cybermaps_mock_options'];

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Earlier Markdown templates are no longer supported' );
		try {
			MigrationHub::get_instance()->import( "## Reports & Deliverables\n- agency_name: \"Imported\"\n", 'merge' );
		} finally {
			$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
		}
	}

	public function test_tampered_or_incomplete_backup_is_rejected_without_writes(): void {
		update_option( 'cybermaps_settings', array( 'agency_name' => 'Original', 'api_secret' => 'secret' ) );
		update_option( 'cybermaps_discovery_center', '{}' );
		update_option( 'cybermaps_robots_manager', array() );
		update_option( 'cybermaps_identity_data', array() );
		$before = $GLOBALS['cybermaps_mock_options'];

		$payload = json_decode( MigrationHub::get_instance()->generate_backup(), true, 512, JSON_THROW_ON_ERROR );
		$payload['configuration']['cybermaps_settings']['agency_name'] = 'Tampered';
		$tampered = (string) wp_json_encode( $payload );

		try {
			MigrationHub::get_instance()->import( $tampered, 'overwrite' );
			$this->fail( 'Tampered backup should have been rejected.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'integrity', strtolower( $error->getMessage() ) );
		}
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );

		unset( $payload['configuration']['cybermaps_identity_data'] );
		$checksum_method = new \ReflectionMethod( MigrationHub::class, 'configuration_checksum' );
		$payload['checksum'] = $checksum_method->invoke(
			MigrationHub::get_instance(),
			$payload['configuration']
		);

		$this->expectException( \InvalidArgumentException::class );
		MigrationHub::get_instance()->import( (string) wp_json_encode( $payload ), 'overwrite' );
	}

	public function test_ai_template_uses_json_values_and_null_is_non_destructive(): void {
		update_option(
			'cybermaps_settings',
			array(
				'enable_caching'           => '1',
				'llms_custom_instructions' => 'Old instructions',
			)
		);
		$template = MigrationHub::get_instance()->generate_markdown();
		$template = str_replace(
			'"llms_custom_instructions": null',
			'"llms_custom_instructions": "Line one\\nLine two: keep punctuation"',
			$template
		);

		MigrationHub::get_instance()->import( $template, 'merge' );
		$settings = get_option( 'cybermaps_settings' );

		$this->assertSame( '1', $settings['enable_caching'] );
		$this->assertSame( "Line one\nLine two: keep punctuation", $settings['llms_custom_instructions'] );
	}

	public function test_ai_brief_round_trips_trusted_proxy_cidrs_as_a_bounded_registry_list(): void {
		$stored_cidrs = array_map(
			static fn( int $index ): string => '10.0.' . $index . '.0/24',
			range( 0, 64 )
		);
		update_option(
			'cybermaps_settings',
			array(
				'trusted_proxy_header' => 'x_forwarded_for',
				'trusted_proxy_cidrs'  => implode( "\n", $stored_cidrs ),
			)
		);

		$brief = MigrationHub::get_instance()->generate_markdown();

		$this->assertStringContainsString( '"trusted_proxy_cidrs": [', $brief );
		$this->assertStringContainsString( '"10.0.63.0/24"', $brief );
		$this->assertStringNotContainsString( '"10.0.64.0/24"', $brief );

		$content = (string) wp_json_encode(
			array(
				'format'         => 'cybermaps-ai-configuration-changes',
				'format_version' => 2,
				'plugin_version' => CYBERMAPS_VERSION,
				'changes'        => array(
					'advanced_maintenance' => array(
						'trusted_proxy_cidrs' => array( '10.0.0.0/8', '2001:db8::/32' ),
					),
				),
			)
		);
		$preview = MigrationHub::get_instance()->preview( $content, 'merge' );
		$changes = array_values(
			array_filter(
				$preview['changes'],
				static fn( array $change ): bool => 'trusted_proxy_cidrs' === $change['field']
			)
		);

		$this->assertSame( array(), $preview['errors'] );
		$this->assertCount( 1, $changes );
		$this->assertSame(
			array( '10.0.0.0/8', '2001:db8::/32' ),
			$changes[0]['final']
		);

		MigrationHub::get_instance()->import_previewed(
			$content,
			'merge',
			$preview['content_hash'],
			$preview['configuration_hash']
		);
		$this->assertSame(
			"10.0.0.0/8\n2001:db8::/32",
			get_option( 'cybermaps_settings' )['trusted_proxy_cidrs']
		);
	}

	public function test_ai_template_presents_one_global_id_exclusion_field(): void {
		$template = MigrationHub::get_instance()->generate_markdown();

		$this->assertStringContainsString( '"llms_exclude_ids": null', $template );
		$this->assertStringNotContainsString( '"ai_sitemap_exclude_ids": null', $template );
		$this->assertStringContainsString( 'Global AI excluded post IDs', $template );

	}

	public function test_ai_configuration_catalog_covers_every_manifest_setting(): void {
		$manifest = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/dev/manifest.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$mapped = array_keys(
			array_filter(
				\Cybermaps\Admin\AIConfigurationRegistry::get_fields(),
				static fn( array $field ): bool => 'cybermaps_settings' === $field['option']
			)
		);
		sort( $mapped );
		$manifest_settings = array_values(
			array_diff(
				$manifest['settings'],
				array( 'api_secret', 'delete_data_on_uninstall' )
			)
		);
		sort( $manifest_settings );

		$this->assertSame( $manifest_settings, $mapped );
		$this->assertSame(
			array(),
			array_intersect(
				$mapped,
				array( 'api_secret', 'delete_data_on_uninstall' )
			)
		);
	}

	public function test_ai_preview_reports_unknown_envelope_sections_and_fields_without_writes(): void {
		$hub = MigrationHub::get_instance();
		$payload = array(
			'format'         => 'cybermaps-ai-configuration-changes',
			'format_version' => 2,
			'plugin_version' => CYBERMAPS_VERSION,
			'changes'        => array(
				'unknown_section' => array( 'anything' => true ),
				'ai_publishing' => array( 'unknown_field' => true ),
			),
		);
		$before = $GLOBALS['cybermaps_mock_options'];

		$preview = $hub->preview( (string) wp_json_encode( $payload ), 'merge' );

		$this->assertNotEmpty( $preview['errors'] );
		$this->assertStringContainsString( 'Unknown AI configuration section', implode( ' ', $preview['errors'] ) );
		$this->assertStringContainsString( 'Unknown or misplaced AI configuration field', implode( ' ', $preview['errors'] ) );
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_ai_preview_preserves_metadata_warnings_and_rejects_invalid_cross_field_values(): void {
		$payload = array(
			'format'         => 'cybermaps-ai-configuration-changes',
			'format_version' => 2,
			'plugin_version' => '0.0.0',
			'changes'        => array(
				'site_identity' => array(
					'identity_latitude'  => '37.0',
					'identity_longitude' => '',
				),
				'ai_publishing' => array(
					'rag_chunk_size'    => 100,
					'rag_chunk_overlap' => 60,
				),
			),
		);

		$preview = MigrationHub::get_instance()->preview( (string) wp_json_encode( $payload ), 'merge' );
		$messages = implode( ' ', $preview['errors'] );

		$this->assertStringContainsString( 'targets Cybermaps', implode( ' ', $preview['warnings'] ) );
		$this->assertStringContainsString( 'latitude and longitude must either both be configured', $messages );
		$this->assertStringContainsString( 'RAG chunk overlap cannot exceed half', $messages );
		$this->assertCount( 4, $preview['changes'] );
		$this->assertSame( 'normalized', $preview['changes'][1]['status'] );
	}

	public function test_ai_preview_accepts_null_as_a_non_destructive_field_value(): void {
		$payload = array(
			'format'         => 'cybermaps-ai-configuration-changes',
			'format_version' => 2,
			'plugin_version' => CYBERMAPS_VERSION,
			'changes'        => array(
				'ai_publishing' => array( 'llms_custom_instructions' => null ),
			),
		);

		$preview = MigrationHub::get_instance()->preview( (string) wp_json_encode( $payload ), 'merge' );

		$this->assertSame( array(), $preview['errors'] );
		$this->assertSame( array(), $preview['changes'] );
	}

	/**
	 * @dataProvider aiValueValidationProvider
	 *
	 * @param mixed               $value           Candidate value.
	 * @param array<string,mixed> $schema          Validation schema.
	 * @param string[]            $expected_errors Expected message fragments.
	 */
	public function test_ai_value_validation_families_preserve_diagnostics( $value, array $schema, array $expected_errors ): void {
		$method = new \ReflectionMethod( MigrationHub::class, 'validate_ai_value' );
		$errors = array();
		$method->invokeArgs(
			MigrationHub::get_instance(),
			array( $value, $schema, 'settings.test', &$errors )
		);

		foreach ( $expected_errors as $expected_error ) {
			$this->assertStringContainsString( $expected_error, implode( ' ', $errors ) );
		}
	}

	/**
	 * @return array<string,array{mixed,array<string,mixed>,string[]}>
	 */
	public static function aiValueValidationProvider(): array {
		return array(
			'fixed value' => array(
				'wrong',
				array( 'type' => 'string', 'const' => 'right' ),
				array( 'must use the required fixed value' ),
			),
			'all of' => array(
				'no',
				array( 'allOf' => array( array( 'type' => 'string' ), array( 'minLength' => 3 ) ) ),
				array( 'is shorter than the accepted minimum' ),
			),
			'any of' => array(
				array(),
				array( 'anyOf' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) ),
				array( 'does not match an accepted value shape' ),
			),
			'one of' => array(
				'1',
				array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'string' ) ) ),
				array( 'does not match an accepted value shape' ),
			),
			'type' => array(
				true,
				array( 'type' => 'integer' ),
				array( 'must be a JSON integer' ),
			),
			'enum and numeric bounds' => array(
				11,
				array( 'type' => 'integer', 'enum' => array( 1, 2 ), 'minimum' => 12, 'maximum' => 10 ),
				array( 'outside its allowed choices', 'must be at least 12', 'must not exceed 10' ),
			),
			'string constraints' => array(
				'ab',
				array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 1, 'pattern' => '^z+$' ),
				array( 'shorter than the accepted minimum', 'exceeds the accepted length limit', 'does not match the accepted format' ),
			),
			'email format' => array(
				'not-an-email',
				array( 'type' => 'string', 'format' => 'email' ),
				array( 'must be a valid email address' ),
			),
			'uri constraints' => array(
				'https://user:pass@example.com/path?x=1#part',
				array( 'type' => 'string', 'format' => 'uri', 'x-query-allowed' => false, 'x-fragment-allowed' => false ),
				array( 'public HTTP or HTTPS URL without embedded credentials', 'must not contain a query string', 'must not contain a URL fragment' ),
			),
			'numeric extensions' => array(
				'5',
				array( 'type' => 'string', 'x-numeric-minimum' => 6, 'x-numeric-maximum' => 4 ),
				array( 'must be at least 6', 'must not exceed 4' ),
			),
			'line schema' => array(
				"bad\nline",
				array( 'type' => 'string', 'x-line-schema' => array( 'type' => 'string', 'pattern' => '^ok$' ), 'x-max-lines' => 1 ),
				array( 'contains too many lines' ),
			),
			'array constraints' => array(
				array( 'a', 'a', 'b' ),
				array( 'type' => 'array', 'minItems' => 4, 'maxItems' => 2, 'uniqueItems' => true ),
				array( 'contains too few items', 'contains too many items' ),
			),
			'array item and aggregate constraints' => array(
				array( array( 'items' => array( 'a', 'b' ) ), array( 'items' => array( 'c' ) ) ),
				array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'x-max-total-items' => 2, 'x-max-joined-bytes' => 0, 'x-join-separator' => ',' ),
				array( 'contains too many nested items', 'exceeds the accepted combined size' ),
			),
			'object constraints' => array(
				array( 'same' => 'same', 'unknown' => 1 ),
				array(
					'type'                 => 'object',
					'required'             => array( 'required' ),
					'properties'           => array( 'same' => array( 'type' => 'string' ) ),
					'propertyNames'        => array( 'type' => 'string', 'pattern' => '^known$' ),
					'additionalProperties' => false,
					'x-fields-not-equal'   => array( 'same', 'other' ),
				),
				array( 'is missing required property required', 'contains an invalid property name' ),
			),
			'conditional object branch' => array(
				array( 'kind' => 'special' ),
				array(
					'type'       => 'object',
					'properties' => array( 'kind' => array( 'type' => 'string' ) ),
					'if'         => array( 'properties' => array( 'kind' => array( 'const' => 'special' ) ) ),
					'then'       => array( 'required' => array( 'value' ) ),
				),
				array( 'is missing required property value' ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $configuration Backup configuration payload.
	 */
	private function checksum( array $configuration ): string {
		$method = new \ReflectionMethod( MigrationHub::class, 'configuration_checksum' );
		return (string) $method->invoke( MigrationHub::get_instance(), $configuration );
	}

	/**
	 * @param array<string,mixed> $payload Malformed backup envelope.
	 * @param array<string,mixed> $before  Complete option state before import.
	 */
	private function assertRejectedBackupPayload( array $payload, array $before ): void {
		try {
			MigrationHub::get_instance()->import(
				(string) wp_json_encode( $payload ),
				'overwrite'
			);
			$this->fail( 'The malformed backup should have been rejected.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertNotSame( '', $error->getMessage() );
		}

		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
	}
}
