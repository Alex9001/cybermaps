<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer;
use Cybermaps\Sitemap\Orchestrator;
use PHPUnit\Framework\TestCase;

final class AttributionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	public function test_default_upgrade_and_malformed_values_never_opt_in(): void {
		foreach ( array( null, '0', array( '1' ), 'yes', true, 1 ) as $value ) {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'show_sitemap_attribution' => $value );
			$xml = ( new Orchestrator() )->generate_xml( 'index', 1 );
			self::assertStringContainsString( '/sitemap.xsl', $xml );
			self::assertStringNotContainsString( 'sitemap-attribution.xsl', $xml );
		}
		self::assertFalse( AIConfigurationRegistry::get_field( 'show_sitemap_attribution' )['effective_default'] );
	}

	public function test_explicit_opt_in_selects_shared_attribution_stylesheet(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'show_sitemap_attribution' => '1' );
		foreach ( array( 'index', 'network', 'misc' ) as $type ) {
			$xml = ( new Orchestrator() )->generate_xml( $type, 1 );
			self::assertStringContainsString( '/sitemap-attribution.xsl', $xml );
		}
	}

	public function test_other_tabs_preserve_consent_and_unchecking_revokes_it(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'show_sitemap_attribution' => '1' );
		$_POST = array( 'cybermaps_active_tab' => 'ai' );
		self::assertSame( '1', SettingsSanitizer::sanitize( array() )['show_sitemap_attribution'] );
		$_POST['cybermaps_active_tab'] = 'sitemaps';
		self::assertSame( '0', SettingsSanitizer::sanitize( array() )['show_sitemap_attribution'] );
		self::assertSame( '0', SettingsSanitizer::sanitize( array( 'show_sitemap_attribution' => array( '1' ) ) )['show_sitemap_attribution'] );
		self::assertSame( '1', SettingsSanitizer::sanitize( array( 'show_sitemap_attribution' => true ) )['show_sitemap_attribution'] );
	}
}
