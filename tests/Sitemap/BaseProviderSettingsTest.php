<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\BaseProvider;
use PHPUnit\Framework\TestCase;

final class BaseProviderSettingsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	public function test_absent_or_malformed_option_does_not_enable_optional_inventories(): void {
		$provider = $this->provider();
		$this->assertSame( array(), $provider->settings() );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = 'malformed';
		$this->assertSame( array(), $provider->settings() );
	}

	public function test_partial_option_is_preserved_without_merging_contradictory_defaults(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'well_known',
		);
		$settings = $this->provider()->settings();

		$this->assertSame( array( 'static_engine_mode' => 'well_known' ), $settings );
		$this->assertArrayNotHasKey( 'include_homepage', $settings );
		$this->assertArrayNotHasKey( 'include_authors', $settings );
		$this->assertArrayNotHasKey( 'include_archives', $settings );
	}

	private function provider(): BaseProvider {
		return new class() extends BaseProvider {
			public function get_urls( int $page ): array {
				unset( $page );
				return array();
			}

			public function get_count(): int {
				return 0;
			}

			public function get_lastmod(): string {
				return '';
			}

			public function settings(): array {
				return $this->get_settings();
			}
		};
	}
}
