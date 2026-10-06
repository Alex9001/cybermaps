<?php
declare(strict_types=1);
namespace Cybermaps\Tests;
/** Scoped dependency fixture. It does not implement the protocol; real WP tests use the official ZIP. */
final class AdapterFixture {
    private array $before;
    public static function enable(): self { return new self(); }
    private function __construct() {
        require_once __DIR__ . '/mocks/mcp-adapter.php';
        if ( ! defined( 'WP_MCP_VERSION' ) ) define( 'WP_MCP_VERSION', '0.7.0' );
        $this->before = $GLOBALS['cybermaps_mock_options'];
        $GLOBALS['cybermaps_mock_options']['active_plugins'] = array( 'mcp-adapter/mcp-adapter.php' );
        $GLOBALS['cybermaps_mock_options']['cybermaps_mcp_retired'] = '1';
        ( new \ReflectionProperty( \Cybermaps\MCP\WordPressIntegration::class, 'registration_failed' ) )->setValue( null, false );
    }
    public function __destruct() { $GLOBALS['cybermaps_mock_options'] = $this->before; }
}
