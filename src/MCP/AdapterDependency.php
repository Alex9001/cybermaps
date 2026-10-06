<?php
/**
 * Optional WordPress MCP Adapter availability.
 *
 * @package Cybermaps\MCP
 */

declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared dependency resolver; never loads or activates another plugin. */
final class AdapterDependency {
	public const PLUGIN          = 'mcp-adapter/mcp-adapter.php';
	public const MINIMUM_VERSION = '0.7.0';

	/** Resolve the separately installed dependency. */
	public static function state(): string {
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		if ( ! in_array( self::PLUGIN, $active, true ) && ! isset( $network[ self::PLUGIN ] ) ) {
			return defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/' . self::PLUGIN ) ? 'inactive' : 'missing';
		}
		if ( ! defined( 'WP_MCP_VERSION' ) || version_compare( (string) WP_MCP_VERSION, self::MINIMUM_VERSION, '<' ) ) {
			return 'incompatible';
		}
		return self::has_api() ? 'ready' : 'incompatible';
	}

	/** Check the loaded API, including shared-autoloader conflicts. */
	private static function has_api(): bool {
		return class_exists( \WP\MCP\Core\McpAdapter::class )
			&& method_exists( \WP\MCP\Core\McpAdapter::class, 'create_server' )
			&& class_exists( \WP\MCP\Transport\HttpTransport::class );
	}
}
