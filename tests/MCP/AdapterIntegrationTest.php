<?php
declare(strict_types=1);
namespace Cybermaps\Tests\MCP;
use Cybermaps\Core\AbilityKernel;
use Cybermaps\MCP\AdapterDependency;
use Cybermaps\MCP\ResourceAbilities;
use Cybermaps\MCP\WordPressIntegration;
use Cybermaps\Tests\AdapterFixture;
use PHPUnit\Framework\TestCase;
final class AdapterIntegrationTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_mcp_adapter' => '1' ) );
        $GLOBALS['cybermaps_mock_abilities'] = array();
        $GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'read' );
        $GLOBALS['cybermaps_mock_user_id'] = 1;
    }
    protected function tearDown(): void {
        $GLOBALS['cybermaps_mock_options'] = array(); $GLOBALS['cybermaps_mock_abilities'] = array();
        $GLOBALS['cybermaps_mock_current_user_capabilities'] = array();
        unset( $GLOBALS['cybermaps_mock_user_id'] );
    }
    public function test_missing_dependency_never_advertises_or_registers_mcp(): void {
        self::assertFalse( WordPressIntegration::is_enabled() );
        self::assertFalse( WordPressIntegration::can_read() );
        self::assertFalse( \Cybermaps\Discovery\MCPServerCard::is_available() );
        ResourceAbilities::register(); self::assertSame( array(), $GLOBALS['cybermaps_mock_abilities'] );
    }
    public function test_adapter_alone_and_legacy_modes_never_enable_integration(): void {
        $fixture = AdapterFixture::enable();
        unset( $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_mcp_adapter'] );
        foreach ( array( 'off', 'discovery', 'read_only', 'operations', 'invalid' ) as $mode ) {
            $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = $mode;
            self::assertFalse( WordPressIntegration::is_enabled() );
        }
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_mcp_adapter'] = '1';
        unset( $GLOBALS['cybermaps_mock_options']['cybermaps_mcp_retired'] );
        self::assertFalse( WordPressIntegration::is_enabled() );
    }
    public function test_explicit_server_has_only_owned_read_only_components_even_with_hostile_abilities(): void {
        $fixture = AdapterFixture::enable(); $called = false;
        wp_register_ability( 'evil/delete-site', array( 'execute_callback' => function () use ( &$called ) { $called = true; }, 'meta' => array( 'public' => true, 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ) ) );
        AbilityKernel::get_instance()->register_categories(); AbilityKernel::get_instance()->register_abilities();
        $adapter = new \WP\MCP\Core\McpAdapter(); ( new WordPressIntegration() )->register_server( $adapter );
        self::assertCount( 1, $adapter->calls ); $args = $adapter->calls[0];
        self::assertSame( array( 'cybermaps', 'mcp', 'cybermaps' ), array_slice( $args, 0, 3 ) );
        self::assertSame( array( 'cybermaps/search' ), $args[9] );
        self::assertNotEmpty( $args[10] ); self::assertSame( array(), $args[11] ); self::assertFalse( $called );
        foreach ( $args[10] as $name ) {
            self::assertStringStartsWith( 'cybermaps/resource-', $name );
            $meta = wp_get_ability( $name )->get_meta();
            self::assertTrue( $meta['annotations']['readonly'] ); self::assertFalse( $meta['public'] );
            self::assertFalse( $meta['show_in_rest'] ); self::assertFalse( $meta['mcp']['public'] );
        }
        foreach ( array( 'run-audit', 'submit-indexnow', 'purge-static-publications', 'reconcile-static-publications' ) as $name ) self::assertNull( wp_get_ability( 'cybermaps/' . $name ) );
    }
    public function test_permissions_and_advertising_follow_opt_in_hub_and_dependency(): void {
        $fixture = AdapterFixture::enable();
        self::assertTrue( WordPressIntegration::can_read() );
        $GLOBALS['cybermaps_mock_user_id'] = 0; self::assertFalse( WordPressIntegration::can_read() );
        $GLOBALS['cybermaps_mock_user_id'] = 1;
        $GLOBALS['cybermaps_mock_current_user_capabilities'] = array(); self::assertFalse( WordPressIntegration::can_read() );
        $GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'read' );
        $GLOBALS['cybermaps_mock_options']['active_plugins'] = array(); self::assertFalse( WordPressIntegration::is_enabled() );
        self::assertFalse( \Cybermaps\Discovery\MCPServerCard::is_available() );
        $GLOBALS['cybermaps_mock_options']['active_plugins'] = array( AdapterDependency::PLUGIN );
        self::assertTrue( WordPressIntegration::is_enabled() );
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '0'; self::assertFalse( WordPressIntegration::is_enabled() );
    }
    public function test_resource_reads_reject_arbitrary_ids_and_disabled_publications(): void {
        $fixture = AdapterFixture::enable();
        foreach ( array( 'https://evil.example', '../../wp-config.php', 'evil/delete-site', 'llms_full' ) as $id ) self::assertInstanceOf( \WP_Error::class, ResourceAbilities::read( $id ) );
        self::assertArrayNotHasKey( 'auth_md', ResourceAbilities::definitions() );
        self::assertArrayNotHasKey( 'oauth_authorization_server', ResourceAbilities::definitions() );
    }
}
