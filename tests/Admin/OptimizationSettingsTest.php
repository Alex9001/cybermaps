<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareRulesClient;
use Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer;
use Cybermaps\Core\CacheManager;
use Cybermaps\Integration\EdgeCache\LiteSpeedAdapter;
use PHPUnit\Framework\TestCase;

final class OptimizationSettingsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_advanced_optimization_toggles_are_sanitized_independently(): void {
		$_POST = array(
			'option_page'             => 'cybermaps_options_group',
			'cybermaps_active_tab'    => 'advanced',
			'cybermaps_form_complete' => '1',
		);
		$enabled = SettingsSanitizer::sanitize(
			array(
				'enable_litespeed_cache_integration' => '1',
				'enable_apcu_l1_cache'                => '1',
			)
		);
		self::assertSame( '1', $enabled['enable_litespeed_cache_integration'] );
		self::assertSame( '1', $enabled['enable_apcu_l1_cache'] );

		$disabled = SettingsSanitizer::sanitize( array() );
		self::assertSame( '0', $disabled['enable_litespeed_cache_integration'] );
		self::assertSame( '0', $disabled['enable_apcu_l1_cache'] );
	}

	public function test_runtime_integrations_default_on_and_honor_opt_outs(): void {
		self::assertTrue( LiteSpeedAdapter::is_enabled() );
		self::assertTrue( CacheManager::is_apcu_enabled() );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_litespeed_cache_integration' => '0',
			'enable_apcu_l1_cache'                => '0',
		);
		self::assertFalse( LiteSpeedAdapter::is_enabled() );
		self::assertFalse( CacheManager::is_apcu_enabled() );
	}

	public function test_cloudflare_zone_selection_uses_the_longest_matching_suffix(): void {
		$zone = CloudflareRulesClient::select_zone(
			'www.shop.example.com',
			array(
				array( 'id' => 'outer', 'name' => 'example.com' ),
				array( 'id' => 'inner', 'name' => 'shop.example.com' ),
			)
		);
		self::assertSame( 'inner', $zone['id'] );
	}

	public function test_cloudflare_zone_selection_refuses_equal_ambiguous_matches(): void {
		$this->expectException( \RuntimeException::class );
		CloudflareRulesClient::select_zone(
			'www.example.com',
			array(
				array( 'id' => 'first', 'name' => 'example.com' ),
				array( 'id' => 'second', 'name' => 'example.com' ),
			)
		);
	}
}
