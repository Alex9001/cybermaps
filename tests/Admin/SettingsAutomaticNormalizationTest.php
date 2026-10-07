<?php
declare(strict_types=1);

namespace Cybermaps\Admin\Settings {

	// Settings API registration is not supplied by the isolated WP test bootstrap.
	function register_setting( string $group, string $option, mixed $arguments ): void {
		$GLOBALS['cybermaps_automatic_registered_settings'][] = array( $group, $option, $arguments );
	}
}

namespace Cybermaps\Tests\Admin {

use Cybermaps\Admin\Settings;
use Cybermaps\Admin\Settings\SettingsRegistrar;
use Cybermaps\Admin\SettingsPage;
use Cybermaps\Core\ConfigurationStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/configuration-database.php';

final class SettingsAutomaticNormalizationTest extends TestCase {
	private array $previous = array();
	private array $notifications = array();
	private \CybermapsConfigurationDatabase $database;

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'wpdb', 'cybermaps_mock_options', 'cybermaps_mock_options_by_blog', 'cybermaps_mock_current_blog_id', 'cybermaps_mock_action_callbacks', 'cybermaps_mock_filter_callbacks', 'cybermaps_mock_is_multisite', 'cybermaps_mock_object_cache', 'cybermaps_mock_object_cache_expirations', 'cybermaps_mock_update_option_behavior', 'cybermaps_mock_add_option_behavior', 'cybermaps_mock_get_option_observer', 'cybermaps_mock_option_autoload_values', 'cybermaps_automatic_registered_settings', 'wp_hooks' ) as $key ) {
			$this->previous[ $key ] = array( array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null );
			unset( $GLOBALS[ $key ] );
		}
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
		$GLOBALS['cybermaps_mock_action_callbacks'] = array();
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['cybermaps_mock_object_cache'] = array();
		$GLOBALS['cybermaps_mock_is_multisite'] = true;
		$this->database = new \CybermapsConfigurationDatabase();
		$GLOBALS['wpdb'] = $this->database;
		foreach ( array( 'update_option_cybermaps_settings', 'updated_option', 'add_option_cybermaps_settings', 'added_option' ) as $hook ) {
			$GLOBALS['cybermaps_mock_action_callbacks'][ $hook ][] = function ( ...$arguments ) use ( $hook ): void {
				$this->notifications[] = array( $hook, $arguments );
			};
		}
		ConfigurationStore::reset_memo();
	}

	protected function tearDown(): void {
		foreach ( $this->previous as $key => [ $exists, $value ] ) {
			if ( $exists ) {
				$GLOBALS[ $key ] = $value;
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
		ConfigurationStore::reset_memo();
		parent::tearDown();
	}

	private function normalize( string $path ): void {
		if ( 'static' === $path ) {
			( new \ReflectionMethod( Settings::class, 'normalize_static_mode_setting' ) )->invoke( new Settings() );
			return;
		}
		SettingsRegistrar::register_all( new class() extends SettingsPage {
			public function __construct() {}
			public function get_tabs(): array { return array(); }
		} );
	}

	public static function paths(): array {
		return array( 'static' => array( 'static' ), 'translation' => array( 'translation' ) );
	}

	/** @dataProvider paths */
	public function test_concurrent_authorized_save_survives_without_notification_or_retry( string $path ): void {
		$before = array( 'enable_static_engine' => '1', 'site_name_override' => 'Old', 'api_secret' => 'old-secret' );
		$competing = array( 'static_engine_mode' => 'off', 'enable_translation_integrations' => '0', 'site_name_override' => 'New', 'api_secret' => 'new-secret' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $before;
		foreach ( array( 'cybermaps_settings', 'alloptions', 'notoptions' ) as $key ) {
			wp_cache_set( $key, $before, 'options' );
		}
		$memo = new \ReflectionProperty( ConfigurationStore::class, 'settings_memo' );
		$memo->setValue( null, $before );
		$attempts = 0;
		$this->database->before_query = static function () use ( &$attempts, $competing ): void {
			++$attempts;
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $competing;
		};

		$this->normalize( $path );

		$this->assertSame( 1, $attempts );
		$this->assertSame( $competing, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(), $this->notifications );
		$this->assertNull( $memo->getValue() );
		foreach ( array( 'cybermaps_settings', 'alloptions', 'notoptions' ) as $key ) {
			$this->assertFalse( wp_cache_get( $key, 'options' ), $key );
		}
	}

	/** @dataProvider paths */
	public function test_missing_row_initialization_does_not_upsert_a_competing_insert( string $path ): void {
		$competing = array( 'static_engine_mode' => 'off', 'enable_translation_integrations' => '0', 'site_name_override' => 'Inserted elsewhere' );
		$attempts = 0;
		$this->database->before_query = function ( string $sql ) use ( &$attempts, $competing ): void {
			++$attempts;
			$this->assertStringStartsWith( 'INSERT IGNORE ', $sql );
			$this->assertStringNotContainsString( 'ON DUPLICATE', $sql );
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $competing;
		};
		$this->normalize( $path );
		$this->assertSame( 1, $attempts );
		$this->assertSame( $competing, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(), $this->notifications );
	}

	/** @dataProvider paths */
	public function test_successful_write_preserves_unrelated_values_and_replays_native_hooks_once( string $path ): void {
		$before = array( 'site_name_override' => 'Current', 'api_secret' => 'retained', 'extension_key' => array( 'opaque' => 2 ) );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $before;
		$this->normalize( $path );
		$expected = $before + ( 'static' === $path ? array( 'static_engine_mode' => 'well_known' ) : array( 'enable_translation_integrations' => '1' ) );
		$this->assertSame( $expected, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(
			array( 'update_option_cybermaps_settings', array( $before, $expected, 'cybermaps_settings' ) ),
			array( 'updated_option', array( 'cybermaps_settings', $before, $expected ) ),
		), $this->notifications );
		$this->normalize( $path );
		$this->assertCount( 2, $this->notifications );
	}

	/** @dataProvider paths */
	public function test_absent_row_initialization_notifies_add_observers_once( string $path ): void {
		$this->normalize( $path );
		$expected = 'static' === $path ? array( 'static_engine_mode' => 'well_known' ) : array( 'enable_translation_integrations' => '1' );
		$this->assertSame( $expected, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(
			array( 'add_option_cybermaps_settings', array( 'cybermaps_settings', $expected ) ),
			array( 'added_option', array( 'cybermaps_settings', $expected ) ),
		), $this->notifications );
	}

	public static function malformed_roots(): array {
		$cases = array();
		foreach ( array( 'static', 'translation' ) as $path ) {
			foreach ( array( 'scalar', '', false, null, array( 'unexpected-list-item' ) ) as $index => $value ) {
				$cases[ $path . '-' . $index ] = array( $path, $value );
			}
		}
		return $cases;
	}

	/** @dataProvider malformed_roots */
	public function test_malformed_existing_root_is_preserved_without_writes( string $path, mixed $value ): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $value;
		$raw = $this->database->raw( 'cybermaps_settings' );
		$this->normalize( $path );
		$this->assertSame( $raw, $this->database->raw( 'cybermaps_settings' ) );
		$this->assertCount( 1, $this->database->queries );
		$this->assertSame( array(), $this->notifications );
	}

	public static function explicit_translation_choices(): array {
		return array_map( static fn( mixed $value ): array => array( $value ), array( '0', 0, false, null, '', '1' ) );
	}

	/** @dataProvider explicit_translation_choices */
	public function test_translation_preserves_every_present_choice( mixed $value ): void {
		$before = array( 'enable_translation_integrations' => $value, 'site_name_override' => 'Preserved' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $before;
		$this->normalize( 'translation' );
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(), $this->notifications );
	}

	public function test_translation_does_not_enable_without_a_detected_environment(): void {
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$this->normalize( 'translation' );
		$this->assertArrayNotHasKey( 'cybermaps_settings', $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame( array(), $this->notifications );
		$this->assertCount( 3, $GLOBALS['cybermaps_automatic_registered_settings'] );
	}

	public function test_static_keeps_each_valid_mode_and_removes_only_the_retired_key(): void {
		foreach ( array( 'off', 'well_known', 'all' ) as $mode ) {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'static_engine_mode' => $mode, 'enable_static_engine' => '0', 'api_secret' => 'retained' );
			$this->normalize( 'static' );
			$this->assertSame( array( 'static_engine_mode' => $mode, 'api_secret' => 'retained' ), $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		}
	}

	/** @dataProvider paths */
	public function test_empty_record_is_initialized_without_using_a_filtered_option_value( string $path ): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array();
		add_filter( 'pre_option_cybermaps_settings', static fn(): array => array( 'site_name_override' => 'Filtered stale value' ) );
		$this->assertSame( array( 'site_name_override' => 'Filtered stale value' ), get_option( 'cybermaps_settings' ) );
		$this->normalize( $path );
		$expected = 'static' === $path ? array( 'static_engine_mode' => 'well_known' ) : array( 'enable_translation_integrations' => '1' );
		$this->assertSame( $expected, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(), $this->notifications[0][1][0] );
	}

	public function test_invalid_static_mode_defaults_without_reconstructing_the_root(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'static_engine_mode' => 'retired', 'enable_static_engine' => '1', 'api_secret' => 'retained' );
		$this->normalize( 'static' );
		$this->assertSame( array( 'static_engine_mode' => 'well_known', 'api_secret' => 'retained' ), $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
	}

	/** @dataProvider paths */
	public function test_failed_authoritative_read_cannot_become_absent_row_initialization( string $path ): void {
		$before = array( 'site_name_override' => 'Keep' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $before;
		$GLOBALS['wpdb'] = new class() {
			public string $options = 'wp_options';
			public string $last_error = '';
			public array $last_result = array();
			public int $writes = 0;
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function get_var( string $sql ): ?string {
				$this->last_error = 'Simulated read error';
				return null;
			}
			public function query( string $sql ): int { return ++$this->writes; }
		};
		$this->normalize( $path );
		$this->assertSame( 0, $GLOBALS['wpdb']->writes );
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(), $this->notifications );
	}

	/** @dataProvider paths */
	public function test_write_failure_preserves_original_and_does_not_notify( string $path ): void {
		$before = array( 'site_name_override' => 'Keep' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $before;
		$this->database->before_query = static fn(): bool => false;
		$this->normalize( $path );
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(), $this->notifications );
		$this->assertCount( 2, $this->database->queries );
	}

	/** @dataProvider paths */
	public function test_unsupported_reader_leaves_settings_and_registration_intact( string $path ): void {
		$before = array( 'site_name_override' => 'Keep' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $before;
		$GLOBALS['wpdb'] = new \stdClass();
		$this->normalize( $path );
		$this->assertSame( $before, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertSame( array(), $this->notifications );
		if ( 'translation' === $path ) {
			$this->assertCount( 3, $GLOBALS['cybermaps_automatic_registered_settings'] );
		}
	}

	/** @dataProvider paths */
	public function test_observer_exception_propagates_without_rolling_back_another_writer( string $path ): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'site_name_override' => 'Before' );
		$competing = array( 'static_engine_mode' => 'all', 'enable_translation_integrations' => '0', 'site_name_override' => 'After observer' );
		$GLOBALS['cybermaps_mock_action_callbacks']['update_option_cybermaps_settings'][] = static function () use ( $competing ): void {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $competing;
			throw new \RuntimeException( 'Observer failed after its own write.' );
		};
		try {
			$this->normalize( $path );
			$this->fail( 'Observer exception must propagate.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Observer failed after its own write.', $error->getMessage() );
		}
		$this->assertSame( $competing, $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$this->assertCount( 2, $this->database->queries );
		$this->assertCount( 1, $this->notifications );
	}
}
}
