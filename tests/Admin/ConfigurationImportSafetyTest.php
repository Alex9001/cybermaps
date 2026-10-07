<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\ConfigurationMutationStore;
use Cybermaps\Admin\MigrationHub;
use Cybermaps\Core\RawOptionStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/configuration-database.php';

final class ConfigurationImportSafetyTest extends TestCase {
	use \CybermapsConfigurationDatabaseFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->install_configuration_database();
		$GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'static_engine_mode' => 'off', 'api_secret' => 'test-secret' ) );
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ), 'page' => (object) array( 'name' => 'page', 'public' => true ) );
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		$GLOBALS['wp_hooks'] = array();
		$_POST = array();
	}

	private function apply( array $targets ): array {
		return ( new \ReflectionMethod( MigrationHub::class, 'apply_targets' ) )->invoke( MigrationHub::get_instance(), $targets );
	}

	public function test_existing_empty_option_is_observed_and_replaced_instead_of_inserted(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_indexnow_key'] = '';
		$this->assertSame( array( 'exists' => true, 'value' => '', 'raw' => '' ), ConfigurationMutationStore::read( 'cybermaps_indexnow_key' ) );
		$this->apply( array( 'cybermaps_indexnow_key' => 'replacement-key' ) );
		$this->assertSame( 'replacement-key', RawOptionStore::read( $GLOBALS['wpdb'], 'cybermaps_indexnow_key' ) );
		$writes = array_filter( $GLOBALS['wpdb']->queries, static fn( array $query ): bool => str_starts_with( $query[0], 'INSERT IGNORE' ) );
		$this->assertSame( array(), $writes );
	}

	public function test_maximum_crawler_path_survives_ui_backup_and_ai_import_round_trips(): void {
		$path = '/' . str_repeat( 'a', 2047 );
		$policy = \Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer::sanitize( array( 'content_usage_enabled' => '1', 'content_usage_overrides' => array( array( 'path' => $path, 'search' => 'yes' ) ) ) );
		$this->assertArrayHasKey( $path, $policy['content_usage_overrides'] );
		update_option( 'cybermaps_robots_manager', $policy );
		$hub = MigrationHub::get_instance();
		$backup = $hub->generate_backup();
		update_option( 'cybermaps_robots_manager', array() );
		$preview = $hub->preview( $backup, 'overwrite' );
		$this->assertSame( array(), $preview['errors'] );
		$hub->import_previewed( $backup, 'overwrite', $preview['content_hash'], $preview['configuration_hash'] );
		$this->assertSame( $policy, get_option( 'cybermaps_robots_manager' ) );
		// Content-Usage paths are outside the current AI registry. An AI edit to
		// another crawler-policy field must preserve the full valid stored map.
		$this->assertStringContainsString( 'CYBERMAPS AI CONFIGURATION BRIEF', $hub->generate_markdown() );
		$changes = json_encode( array( 'format' => 'cybermaps-ai-configuration-changes', 'format_version' => 2, 'plugin_version' => CYBERMAPS_VERSION, 'changes' => array( 'robots_control' => array( 'content_signals' => array( 'search' => 'no' ) ) ) ) );
		$preview = $hub->preview( $changes, 'merge' );
		$this->assertSame( array(), $preview['errors'] );
		$hub->import_previewed( $changes, 'merge', $preview['content_hash'], $preview['configuration_hash'] );
		$this->assertSame( $policy['content_usage_overrides'], get_option( 'cybermaps_robots_manager' )['content_usage_overrides'] );
		$this->assertSame( 'no', get_option( 'cybermaps_robots_manager' )['content_signals']['search'] );
	}

	public function test_overlong_crawler_path_is_rejected_in_row_and_map_shapes(): void {
		$path = '/' . str_repeat( 'a', 2048 );
		$saved = array( 'manual_directives' => 'Disallow: /saved/' );
		update_option( 'cybermaps_robots_manager', $saved );
		$row = array( 'content_usage_overrides' => array( array( 'path' => $path, 'search' => 'yes' ) ) );
		$this->assertSame( $saved, \Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer::sanitize( $row ) );
		foreach ( array( $row, array( 'content_usage_overrides' => array( $path => array( 'search' => 'yes' ) ) ), array( 'overrides' => array( str_repeat( 'a', 257 ) => array( 'robots' => true ) ) ) ) as $invalid ) {
			try {
				\Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer::sanitize_import( $invalid );
				$this->fail( 'Overlong domain map keys must remain invalid.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertStringContainsString( 'invalid fields', $error->getMessage() );
			}
		}
		$hub = MigrationHub::get_instance();
		$backup = json_decode( $hub->generate_backup(), true );
		$backup['configuration']['cybermaps_robots_manager'] = array( 'content_usage_overrides' => array( $path => array( 'search' => 'yes' ) ) );
		$backup['checksum'] = ( new \ReflectionMethod( MigrationHub::class, 'configuration_checksum' ) )->invoke( $hub, $backup['configuration'] );
		$this->expectException( \InvalidArgumentException::class );
		$hub->preview( json_encode( $backup ), 'overwrite' );
	}

	public function test_failed_import_restores_an_existing_empty_row_without_deleting_it(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_indexnow_key'] = '';
		$GLOBALS['wpdb']->before_query = static fn( $sql, $option ) => 'cybermaps_robots_manager' === $option ? false : null;
		try {
			$this->apply( array( 'cybermaps_indexnow_key' => 'temporary-key', 'cybermaps_robots_manager' => array( 'takeover_enabled' => true ) ) );
			$this->fail( 'Later write must fail.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'restored the previous configuration', $error->getMessage() );
		}
		$this->assertArrayHasKey( 'cybermaps_indexnow_key', $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame( array( 'exists' => true, 'value' => '', 'raw' => '' ), ConfigurationMutationStore::read( 'cybermaps_indexnow_key' ) );
		$this->assertSame( array( 'exists' => false, 'value' => null, 'raw' => null ), ConfigurationMutationStore::read( 'cybermaps_absent_fixture' ) );
	}

	public function test_fresh_backup_full_replace_resets_all_empty_structured_domains(): void {
		$hub = MigrationHub::get_instance();
		$backup = $hub->generate_backup();
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = '{"archetype":"corporate","disabled":{"post_type:post":true}}';
		$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager'] = array( 'takeover_enabled' => true, 'manual_directives' => 'Disallow: /' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array( 'name' => 'Old identity' );
		$preview = $hub->preview( $backup, 'overwrite' );
		$this->assertSame( array(), $preview['errors'] );
		$result = $hub->import_previewed( $backup, 'overwrite', $preview['content_hash'], $preview['configuration_hash'] );
		$this->assertContains( 'cybermaps_discovery_center', $result['changed_groups'] );
		$this->assertSame( array(), json_decode( get_option( 'cybermaps_discovery_center' ), true )['disabled'] );
		$this->assertFalse( get_option( 'cybermaps_robots_manager' )['takeover_enabled'] );
		$this->assertSame( '', get_option( 'cybermaps_robots_manager' )['manual_directives'] );
		$this->assertSame( '', get_option( 'cybermaps_identity_data' )['name'] );
	}

	public function test_invalid_source_domains_fail_before_merge_or_writes(): void {
		$hub = MigrationHub::get_instance();
		$base = json_decode( $hub->generate_backup(), true );
		$before = $GLOBALS['cybermaps_mock_options'];
		foreach ( array( 'cybermaps_discovery_center', 'cybermaps_robots_manager', 'cybermaps_identity_data' ) as $group ) {
			$payload = $base;
			$payload['configuration'][ $group ] = array( 'unknown' => array( 'nested' => true ) );
			$payload['checksum'] = ( new \ReflectionMethod( MigrationHub::class, 'configuration_checksum' ) )->invoke( $hub, $payload['configuration'] );
			try {
				$hub->preview( json_encode( $payload ), 'merge' );
				$this->fail( 'Malformed backup domain must be rejected.' );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertStringContainsString( 'invalid fields', $error->getMessage() );
			}
			$this->assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
		}
	}

	public function test_legacy_zero_priority_merge_carries_exclusion_into_preview_and_storage(): void {
		$hub = MigrationHub::get_instance();
		foreach ( array( 'post', 'post_type:post' ) as $key ) {
			$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = json_encode( array( 'archetype' => 'blog', 'overrides' => array( $key => 0 ), 'type_intents' => array() ) );
			$backup = $hub->generate_backup();
			$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = json_encode( array( 'archetype' => 'blog', 'overrides' => array( $key => 0.8 ), 'disabled' => array( 'post_type:page' => true ), 'type_intents' => array() ) );
			$preview = $hub->preview( $backup, 'merge' );
			$rows = array_column( $preview['changes'], null, 'field' );
			$this->assertTrue( $rows['discovery_disabled']['final']['post_type:post'] );
			$hub->import_previewed( $backup, 'merge', $preview['content_hash'], $preview['configuration_hash'] );
			$stored = json_decode( get_option( 'cybermaps_discovery_center' ), true );
			$this->assertTrue( $stored['disabled']['post_type:post'] );
			$this->assertTrue( $stored['disabled']['post_type:page'] );
			$this->assertArrayNotHasKey( 'post_type:post', $stored['overrides'] );
		}
	}

	public function test_same_root_competitor_before_cas_is_preserved(): void {
		$before = get_option( 'cybermaps_settings' ) + array( 'agency_name' => 'Before', 'enable_discovery_hub' => '1' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $before;
		$GLOBALS['wpdb']->before_query = static function ( $sql, $option ) use ( $before ): void {
			if ( 'cybermaps_settings' === $option ) {
				$GLOBALS['cybermaps_mock_options'][ $option ] = array_replace( $before, array( 'enable_discovery_hub' => '0' ) );
			}
		};
		try {
			$this->apply( array( 'cybermaps_settings' => array_replace( $before, array( 'agency_name' => 'Imported' ) ) ) );
			$this->fail( 'CAS must reject stale root.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'before any configuration values were written', $error->getMessage() );
		}
		$this->assertSame( '0', get_option( 'cybermaps_settings' )['enable_discovery_hub'] );
		$this->assertSame( 'Before', get_option( 'cybermaps_settings' )['agency_name'] );
	}

	public function test_legacy_exclusion_migration_preserves_explicit_enabling_in_another_group(): void {
		$hub = MigrationHub::get_instance();
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = json_encode( array( 'overrides' => array( 'post' => 0 ), 'disabled' => array( 'post_type:page' => false ) ) );
		$backup = $hub->generate_backup();
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = json_encode( array( 'overrides' => array( 'post' => 0.8 ), 'disabled' => array( 'post_type:page' => true ) ) );
		$hub->import( $backup, 'merge' );
		$stored = json_decode( get_option( 'cybermaps_discovery_center' ), true );
		$this->assertTrue( $stored['disabled']['post_type:post'] );
		$this->assertArrayNotHasKey( 'post_type:page', $stored['disabled'] );
	}

	public function test_absent_row_insert_does_not_overwrite_a_competitor(): void {
		$GLOBALS['wpdb']->before_query = static function ( $sql, $option ): void {
			if ( 'cybermaps_identity_data' === $option ) {
				$GLOBALS['cybermaps_mock_options'][ $option ] = array( 'name' => 'Competitor' );
			}
		};
		try {
			$this->apply( array( 'cybermaps_identity_data' => array( 'name' => 'Import' ) ) );
			$this->fail( 'Competing insert must be retained.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( array( 'name' => 'Competitor' ), get_option( 'cybermaps_identity_data' ) );
		}
	}

	public function test_failed_later_write_rolls_back_only_owned_rows_and_deletes_owned_insert(): void {
		$GLOBALS['wpdb']->before_query = static fn( $sql, $option ) => 'cybermaps_robots_manager' === $option ? false : null;
		try {
			$this->apply( array( 'cybermaps_identity_data' => array( 'name' => 'Import' ), 'cybermaps_robots_manager' => array( 'takeover_enabled' => true ) ) );
			$this->fail( 'Middle write error must fail.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'restored the previous configuration', $error->getMessage() );
		}
		$this->assertFalse( get_option( 'cybermaps_identity_data' ) );
		$this->assertFalse( get_option( 'cybermaps_robots_manager' ) );
	}

	public function test_competitor_after_owned_write_survives_failed_import_rollback(): void {
		$GLOBALS['cybermaps_mock_action_callbacks']['added_option'][] = static function ( $option ): void {
			if ( 'cybermaps_identity_data' === $option ) {
				$GLOBALS['cybermaps_mock_options'][ $option ] = array( 'name' => 'Competitor after CAS' );
				throw new \RuntimeException( 'Observer failed after competing write.' );
			}
		};
		try {
			$this->apply( array( 'cybermaps_identity_data' => array( 'name' => 'Import' ) ) );
			$this->fail( 'Observer must fail import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'Conflicting values were preserved', $error->getMessage() );
		}
		$this->assertSame( array( 'name' => 'Competitor after CAS' ), get_option( 'cybermaps_identity_data' ) );
	}

	public function test_post_option_hooks_use_native_arguments_once_and_can_fail_owned_rollback(): void {
		$seen = array();
		$old = get_option( 'cybermaps_settings' );
		$new = $old + array( 'agency_name' => 'Imported' );
		$GLOBALS['cybermaps_mock_action_callbacks']['update_option_cybermaps_settings'][] = static function ( ...$args ) use ( &$seen ): void { $seen[] = $args; };
		$this->apply( array( 'cybermaps_settings' => $new ) );
		$this->assertSame( array( array( $old, $new, 'cybermaps_settings' ) ), $seen );
		$seen = array();
		$GLOBALS['cybermaps_mock_action_callbacks']['add_option_cybermaps_identity_data'][] = static function ( ...$args ) use ( &$seen ): void { $seen[] = $args; throw new \RuntimeException( 'Observer' ); };
		try {
			$this->apply( array( 'cybermaps_identity_data' => array( 'name' => 'Import' ) ) );
			$this->fail( 'Observer should fail import.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'restored the previous configuration', $error->getMessage() );
		}
		$this->assertSame( array( array( 'cybermaps_identity_data', array( 'name' => 'Import' ) ) ), $seen );
		$this->assertFalse( get_option( 'cybermaps_identity_data' ) );
	}

	public function test_unsupported_database_fails_closed_and_cas_compares_case_exactly(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_indexnow_key'] = 'Case';
		$this->assertSame( 0, RawOptionStore::replace( $GLOBALS['wpdb'], 'cybermaps_indexnow_key', 'case', 'changed' ) );
		$this->assertSame( 'Case', get_option( 'cybermaps_indexnow_key' ) );
		$GLOBALS['wpdb'] = null;
		$this->expectException( \RuntimeException::class );
		ConfigurationMutationStore::read( 'cybermaps_settings' );
	}
}
