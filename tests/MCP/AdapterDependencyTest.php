<?php
declare(strict_types=1);
namespace Cybermaps\Tests\MCP;
use Cybermaps\MCP\AdapterDependency;
use Cybermaps\Tests\AdapterFixture;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdapterDependencyTest extends TestCase {
    public function test_outdated_adapter_is_incompatible_even_with_the_expected_classes(): void {
        define( 'WP_MCP_VERSION', '0.6.0' );
        $fixture = AdapterFixture::enable();
        self::assertSame( 'incompatible', AdapterDependency::state() );
    }
    public function test_active_plugin_with_missing_api_is_incompatible(): void {
        define( 'WP_MCP_VERSION', '0.7.0' );
        $GLOBALS['cybermaps_mock_options']['active_plugins'] = array( AdapterDependency::PLUGIN );
        self::assertSame( 'incompatible', AdapterDependency::state() );
    }
    public function test_installed_inactive_plugin_is_identified_without_loading_it(): void {
        define( 'WP_PLUGIN_DIR', ABSPATH . 'plugins' );
        mkdir( WP_PLUGIN_DIR . '/mcp-adapter', 0755, true );
        file_put_contents( WP_PLUGIN_DIR . '/' . AdapterDependency::PLUGIN, '<?php throw new Exception("Must not load");' );
        $GLOBALS['cybermaps_mock_options']['active_plugins'] = array();
        try { self::assertSame( 'inactive', AdapterDependency::state() ); }
        finally { unlink( WP_PLUGIN_DIR . '/' . AdapterDependency::PLUGIN ); rmdir( WP_PLUGIN_DIR . '/mcp-adapter' ); rmdir( WP_PLUGIN_DIR ); }
    }
    public function test_network_activation_satisfies_dependency_but_not_site_consent(): void {
        $fixture = AdapterFixture::enable();
        $GLOBALS['cybermaps_mock_options']['active_plugins'] = array();
        $GLOBALS['cybermaps_mock_is_multisite'] = true;
        $GLOBALS['cybermaps_mock_site_options']['active_sitewide_plugins'] = array( AdapterDependency::PLUGIN => time() );
        self::assertSame( 'ready', AdapterDependency::state() );
        self::assertFalse( \Cybermaps\MCP\WordPressIntegration::is_enabled() );
    }
}
