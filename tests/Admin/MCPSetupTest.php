<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;
use Cybermaps\Admin\MCPSetup;
use Cybermaps\Tests\AdapterFixture;
use PHPUnit\Framework\TestCase;
final class MCPSetupTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_mcp_adapter' => '0' ) );
        $GLOBALS['cybermaps_mock_current_user_capabilities'] = array();
        $GLOBALS['cybermaps_mock_is_multisite'] = false;
    }
    protected function tearDown(): void {
        $GLOBALS['cybermaps_mock_options'] = array();
        $GLOBALS['cybermaps_mock_current_user_capabilities'] = array();
        $GLOBALS['cybermaps_mock_is_multisite'] = false;
    }
    private function panel(): string { ob_start(); MCPSetup::render(); return (string) ob_get_clean(); }
    public function test_missing_dependency_points_authorized_admin_to_native_install_screen(): void {
        $GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'install_plugins' );
        $html = $this->panel();
        self::assertStringContainsString( 'plugin-install.php', $html );
        self::assertStringContainsString( 'plugin=mcp-adapter', $html );
        self::assertStringNotContainsString( 'name="cybermaps_settings[enable_mcp_adapter]"', $html );
        self::assertSame( '0', get_option( 'cybermaps_settings' )['enable_mcp_adapter'] );
    }
    public function test_site_admin_without_install_authority_gets_guidance_only(): void {
        $html = $this->panel();
        self::assertStringContainsString( 'Ask your site or network administrator', $html );
        self::assertStringNotContainsString( 'plugin-install.php', $html );
    }
    public function test_ready_adapter_requires_opt_in_and_reports_connection_instructions(): void {
        $fixture = AdapterFixture::enable();
        $html = $this->panel();
        self::assertStringContainsString( 'name="cybermaps_settings[enable_mcp_adapter]"', $html );
        self::assertStringNotContainsString( 'MCP endpoint:', $html );
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_mcp_adapter'] = '1';
        $html = $this->panel();
        self::assertStringContainsString( '/wp-json/mcp/cybermaps', $html );
        self::assertStringContainsString( 'Subscriber', $html );
        self::assertStringContainsString( 'Application Password', $html );
    }
    public function test_incomplete_migration_has_no_opt_in_control(): void {
        $fixture = AdapterFixture::enable();
        unset( $GLOBALS['cybermaps_mock_options']['cybermaps_mcp_retired'] );
        $html = $this->panel();
        self::assertStringContainsString( 'cleanup is pending', $html );
        self::assertStringNotContainsString( 'name="cybermaps_settings[enable_mcp_adapter]"', $html );
    }
}
