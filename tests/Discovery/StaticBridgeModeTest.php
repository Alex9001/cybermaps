<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use PHPUnit\Framework\TestCase;

class StaticBridgeModeTest extends TestCase {

	public function setUp(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', true );
		}
		global $cybermaps_mock_options;
		$cybermaps_mock_options = array();
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		require_once dirname( __DIR__ ) . '/mocks/mock-wp.php';
		require_once dirname( __DIR__, 2 ) . '/src/Discovery/StaticBridge.php';
	}

	public function test_get_mode_well_known_explicit(): void {
		$mode = \Cybermaps\Discovery\StaticBridge::get_mode(
			array(
				'static_engine_mode' => 'well_known',
			)
		);
		$this->assertSame( 'well_known', $mode );
	}

	public function test_get_mode_all_explicit(): void {
		$mode = \Cybermaps\Discovery\StaticBridge::get_mode(
			array(
				'static_engine_mode' => 'all',
			)
		);
		$this->assertSame( 'all', $mode );
	}

	public function test_get_mode_does_not_infer_mode_from_legacy_boolean(): void {
		$mode = \Cybermaps\Discovery\StaticBridge::get_mode(
			array(
				'enable_static_engine' => '1',
			)
		);
		$this->assertSame( 'well_known', $mode );
	}

	public function test_get_mode_does_not_map_legacy_when_mode_key_present(): void {
		$mode = \Cybermaps\Discovery\StaticBridge::get_mode(
			array(
				'static_engine_mode'   => 'well_known',
				'enable_static_engine' => '1',
			)
		);
		$this->assertSame( 'well_known', $mode );
	}

	public function test_get_mode_defaults_to_well_known(): void {
		$this->assertSame( 'well_known', \Cybermaps\Discovery\StaticBridge::get_mode( array() ) );
	}

	public function test_get_mode_forces_dynamic_delivery_on_multisite(): void {
		$GLOBALS['cybermaps_mock_is_multisite'] = true;

		$this->assertSame(
			'off',
			\Cybermaps\Discovery\StaticBridge::get_mode(
				array( 'static_engine_mode' => 'all' )
			)
		);
	}
}
