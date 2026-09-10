<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\ConfigurationStore;
use PHPUnit\Framework\TestCase;

final class ConfigurationStoreTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	public function test_each_array_configuration_root_fails_closed_on_malformed_storage(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings'       => 'not-an-array',
			'cybermaps_robots_manager' => false,
			'cybermaps_identity_data'  => (object) array( 'name' => 'Unexpected' ),
		);

		$this->assertSame( array(), ConfigurationStore::settings() );
		$this->assertSame( array(), ConfigurationStore::robots() );
		$this->assertSame( array(), ConfigurationStore::identity() );
	}

	public function test_settings_do_not_leak_a_prior_test_fixture_value_after_malformed_storage(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'enable_caching' => '1' );
		$this->assertSame( array( 'enable_caching' => '1' ), ConfigurationStore::settings() );

		// PHPUnit mocks mutate option storage directly and do not emit WordPress's
		// option-change hooks. ConfigurationStore intentionally uses uncached reads
		// under CYBERMAPS_PHPUNIT so malformed follow-up fixtures fail closed.
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = 'malformed';
		$this->assertSame( array(), ConfigurationStore::settings() );
	}

	public function test_discovery_reader_supports_canonical_json_and_legacy_arrays(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = '{"archetype":"blog"}';
		$this->assertSame( array( 'archetype' => 'blog' ), ConfigurationStore::discovery() );

		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = array(
			'archetype' => 'corporate',
		);
		$this->assertSame( array( 'archetype' => 'corporate' ), ConfigurationStore::discovery() );
	}

	public function test_discovery_reader_fails_closed_without_cast_warnings(): void {
		foreach ( array( '{bad json', 42, false, null ) as $stored ) {
			$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = $stored;
			$this->assertSame( array(), ConfigurationStore::discovery() );
		}
	}

	public function test_robots_reader_applies_retired_control_aliases_without_mutating_storage(): void {
		$stored = array(
			'takeover_enabled' => true,
			'overrides'        => array(
				'claude-web' => array(
					'robots' => false,
					'llm'    => true,
				),
			),
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager'] = $stored;

		$runtime = ConfigurationStore::robots();

		$this->assertArrayNotHasKey( 'claude-web', $runtime['overrides'] );
		$this->assertSame( $stored['overrides']['claude-web'], $runtime['overrides']['claude-user'] );
		$this->assertSame( $stored, $GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager'] );
	}
}
