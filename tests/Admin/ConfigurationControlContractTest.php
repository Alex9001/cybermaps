<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Admin\IdentityHub;
use Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer;
use PHPUnit\Framework\TestCase;

final class ConfigurationControlContractTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 2 );
		$GLOBALS['cybermaps_mock_options'] = array();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_every_current_control_is_visible_has_one_storage_owner_and_survives_its_own_sanitizer(): void {
		$controls = $this->current_controls();

		self::assertCount( 123, $controls );
		self::assertCount( 123, array_unique( array_keys( $controls ) ) );

		foreach ( $controls as $id => $control ) {
			$field  = (string) $control['field'];
			$option = (string) $control['option'];
			self::assertStringContainsString(
				$field,
				$this->ui_sources( $option ),
				$id . ' must remain reachable from its visible administration workspace.'
			);
			self::assertGreaterThan(
				0,
				$this->runtime_usage_count( $field ),
				$id . ' must have a production consumer outside its editor and configuration plumbing.'
			);
			$this->assert_stored_by_owning_sanitizer( $id, $control );
		}
	}

	/**
	 * A visible settings control must also survive the same tab-aware Settings API
	 * callback used by a normal administrator save. Import sanitization is a
	 * separate path and must not be the only proven way to store a field.
	 */
	public function test_each_shared_setting_survives_an_individual_live_tab_save(): void {
		foreach ( $this->current_controls() as $id => $control ) {
			if ( 'cybermaps_settings' !== $control['option'] ) {
				continue;
			}

			$field = (string) $control['field'];
			$untouched = '__cybermaps_control_contract_untouched__';
			$GLOBALS['cybermaps_mock_options'] = array(
				'cybermaps_settings' => array( $field => $untouched ),
			);
			$_POST = array(
				'cybermaps_active_tab' => $this->settings_tab_for( $control, $field ),
			);
			$stored = SettingsSanitizer::sanitize( array( $field => $this->live_save_value( $control ) ) );

			self::assertArrayHasKey(
				$field,
				$stored,
				$id . ' must survive an individual save through its visible settings tab.'
			);
			self::assertNotSame(
				$untouched,
				$stored[ $field ],
				$id . ' must be processed by its active tab rather than silently preserving the previous value.'
			);
		}
	}

	/**
	 * @return array<string,array{option:string,field:string,example:mixed}>
	 */
	private function current_controls(): array {
		$controls = AIConfigurationRegistry::get_fields();
		$controls['api_secret'] = array(
			'option'  => 'cybermaps_settings',
			'field'   => 'api_secret',
			'example' => 'control-contract-secret',
		);
		$controls['delete_data_on_uninstall'] = array(
			'option'  => 'cybermaps_settings',
			'field'   => 'delete_data_on_uninstall',
			'example' => true,
		);

		return $controls;
	}

	/**
	 * @param array{option:string,field:string,example:mixed} $control
	 */
	private function assert_stored_by_owning_sanitizer( string $id, array $control ): void {
		$option  = $control['option'];
		$field   = $control['field'];
		$example = $control['example'];

		if ( 'cybermaps_settings' === $option ) {
			$stored = SettingsSanitizer::sanitize_import( array( $field => $example ), array() );
			self::assertArrayHasKey( $field, $stored, $id );
			return;
		}

		if ( 'cybermaps_discovery_center' === $option ) {
			$stored = json_decode(
				DiscoveryCenterSanitizer::sanitize( wp_json_encode( array( $field => $example ) ) ),
				true
			);
			self::assertIsArray( $stored, $id );
			self::assertArrayHasKey( $field, $stored, $id );
			return;
		}

		if ( 'cybermaps_identity_data' === $option ) {
			$stored = ( new IdentityHub() )->sanitize_identity_data( array( $field => $example ) );
			self::assertArrayHasKey( $field, $stored, $id );
			return;
		}

		self::assertSame( 'cybermaps_robots_manager', $option, $id );
		$stored = RobotsManagerSanitizer::sanitize( array( $field => $example ) );
		self::assertArrayHasKey( $field, $stored, $id );
	}

	/**
	 * @param array{option:string,field:string,example:mixed,section_id?:string} $control
	 */
	private function settings_tab_for( array $control, string $field ): string {
		if ( 'enable_shortcode' === $field ) {
			return 'shortcode';
		}

		return match ( $control['section_id'] ?? '' ) {
			'core_settings'          => 'sitemaps',
			'ai_publishing'          => 'ai',
			'reports_deliverables'   => 'review',
			'analytics'              => 'analytics',
			'advanced_maintenance'   => 'advanced',
			default                  => 'advanced',
		};
	}

	/**
	 * Prefer a value that differs from the field's normal default. A control
	 * accidentally omitted from the sanitizer must not pass merely because the
	 * old value and the UI default happen to agree.
	 *
	 * @param array{option:string,field:string,example:mixed,effective_default?:mixed} $control
	 * @return mixed
	 */
	private function live_save_value( array $control ) {
		if ( is_bool( $control['example'] ) ) {
			return ! $control['example'];
		}

		if ( 'static_engine_mode' === $control['field'] ) {
			return 'all';
		}

		return $control['example'];
	}

	private function ui_sources( string $option ): string {
		$paths = match ( $option ) {
			'cybermaps_settings' => array(
				'src/Admin/Settings/Tabs/Sitemaps/SitemapsSections.php',
				'src/Admin/Settings/Tabs/Discovery.php',
				'src/Admin/Settings/Tabs/Discovery/DiscoveryFields.php',
				'src/Admin/Settings/Tabs/ContentReview.php',
				'src/Admin/Settings/Tabs/Advanced.php',
				'src/Admin/Settings/Fields/FieldRenderer.php',
				'src/Admin/ShortcodeBuilder.php',
				'src/Admin/DiscoveryAnalytics.php',
			),
			'cybermaps_discovery_center' => array( 'src/Admin/Settings/Tabs/Sitemaps/SitemapsSections.php' ),
			'cybermaps_identity_data'    => array( 'src/Admin/IdentityHub.php' ),
			'cybermaps_robots_manager'   => array( 'src/Admin/Settings/Tabs/Robots/RobotsFields.php' ),
		};

		return implode(
			"\n",
			array_map( fn( string $path ): string => $this->source( $path ), $paths )
		);
	}

	private function runtime_usage_count( string $field ): int {
		$count = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root . '/src', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = str_replace( $this->root . '/', '', $file->getPathname() );
			if ( $this->is_configuration_or_editor_path( $path ) ) {
				continue;
			}
			$count += substr_count( (string) file_get_contents( $file->getPathname() ), $field );
		}

		return $count;
	}

	private function is_configuration_or_editor_path( string $path ): bool {
		return in_array(
			$path,
			array(
				'src/Admin/AIConfigurationRegistry.php',
				'src/Admin/MigrationHub.php',
				'src/Admin/Settings.php',
				'src/Admin/SettingsPage.php',
				'src/Admin/IdentityHub.php',
				'src/Admin/ShortcodeBuilder.php',
			),
			true
		)
			|| str_starts_with( $path, 'src/Admin/Settings/' );
	}

	private function source( string $path ): string {
		return (string) file_get_contents( $this->root . '/' . $path );
	}
}
