<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Admin\Settings\Sanitizers\SettingsDiscoveryNormalizer;
use Cybermaps\Integration\WebMCP;
use PHPUnit\Framework\TestCase;

final class WebMCPIntegrationTest extends TestCase {
	public function test_webmcp_is_explicitly_opt_in_and_requires_the_hub(): void {
		self::assertFalse( WebMCP::is_enabled( array() ) );
		self::assertFalse( WebMCP::is_enabled( array( 'enable_webmcp' => '1' ) ) );
		self::assertFalse( WebMCP::is_enabled( array( 'enable_discovery_hub' => '1' ) ) );
		self::assertTrue(
			WebMCP::is_enabled(
				array(
					'enable_discovery_hub' => '1',
					'enable_webmcp'        => '1',
				)
			)
		);
	}

	public function test_discovery_normalizer_sanitizes_new_settings_and_preserves_inactive_values(): void {
		$enabled = SettingsDiscoveryNormalizer::normalize(
			array(
				'enable_webmcp'           => '1',
				'agent_registration_mode' => 'user_claimed',
			),
			array(),
			array(),
			'ai'
		);
		self::assertSame( '1', $enabled['enable_webmcp'] );
		self::assertSame( 'user_claimed', $enabled['agent_registration_mode'] );

		$invalid = SettingsDiscoveryNormalizer::normalize(
			array( 'agent_registration_mode' => 'automatic' ),
			array(),
			array(),
			'ai'
		);
		self::assertSame( 'off', $invalid['agent_registration_mode'] );

		$preserved = SettingsDiscoveryNormalizer::normalize(
			array(),
			array(
				'enable_webmcp'           => '1',
				'agent_registration_mode' => 'user_claimed',
			),
			array(),
			'advanced'
		);
		self::assertSame( '1', $preserved['enable_webmcp'] );
		self::assertSame( 'user_claimed', $preserved['agent_registration_mode'] );
	}

	public function test_ai_configuration_registry_exposes_safe_defaults_and_enum(): void {
		$webmcp      = AIConfigurationRegistry::get_field( 'enable_webmcp' );
		$registration = AIConfigurationRegistry::get_field( 'agent_registration_mode' );

		self::assertFalse( $webmcp['effective_default'] );
		self::assertSame( 'off', $registration['effective_default'] );
		self::assertSame( array( 'off', 'user_claimed' ), $registration['allowed']['enum'] );
	}

	public function test_browser_asset_prefers_document_api_and_registers_only_read_only_tools(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/webmcp.js' );

		self::assertLessThan( strpos( $script, 'navigator.modelContext.registerTool' ), strpos( $script, 'document.modelContext.registerTool' ) );
		self::assertSame( 3, substr_count( $script, 'name: "cybermaps.' ) );
		self::assertStringContainsString( 'name: "cybermaps.search_site"', $script );
		self::assertStringContainsString( 'name: "cybermaps.get_page_markdown"', $script );
		self::assertStringContainsString( 'name: "cybermaps.list_discovery_resources"', $script );
		self::assertStringNotContainsString( 'cybermaps.update', $script );
		self::assertStringNotContainsString( 'cybermaps.delete', $script );
		self::assertStringContainsString( 'if (!modelContext)', $script );
	}
}
