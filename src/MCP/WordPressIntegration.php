<?php
/**
 * Optional read-only integration with WordPress MCP Adapter.
 *
 * @package Cybermaps\MCP
 */

declare(strict_types=1);

namespace Cybermaps\MCP;

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Discovery\StaticBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Delegates transport and authentication to WordPress and exposes only owned reads. */
final class WordPressIntegration {
	public const SERVER_ID                   = 'cybermaps';
	public const REST_NAMESPACE              = 'mcp';
	public const REST_ROUTE                  = '/cybermaps';
	public const TOOLS                       = array( 'cybermaps/search' );
	private static bool $registration_failed = false;

	/** Attach before the adapter initializes, independently of activation order. */
	public function register_hooks(): void {
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
		add_action( 'update_option_active_plugins', array( $this, 'dependency_changed' ), 10, 2 );
		add_action( 'update_site_option_active_sitewide_plugins', array( $this, 'network_dependency_changed' ), 10, 3 );
	}

	/**
	 * Register the explicit server using the adapter's public API.
	 *
	 * @param object $adapter WordPress MCP Adapter instance.
	 */
	public function register_server( object $adapter ): void {
		if ( 'off' === self::mode() ) {
			return;
		}
		$result                    = $adapter->create_server(
			self::SERVER_ID,
			self::REST_NAMESPACE,
			ltrim( self::REST_ROUTE, '/' ),
			'Cybermaps',
			__( 'Read-only public Cybermaps discovery and search.', 'cybermaps' ),
			CYBERMAPS_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			null,
			null,
			self::TOOLS,
			ResourceAbilities::names(),
			array(),
			array( self::class, 'can_read' )
		);
		self::$registration_failed = is_wp_error( $result );
	}

	/**
	 * Resolve availability without interpreting retired modes as consent.
	 *
	 * @param array<string,mixed>|null $settings General settings.
	 */
	public static function mode( ?array $settings = null ): string {
		$settings = $settings ?? ConfigurationStore::settings();
		if ( '1' !== (string) get_option( \Cybermaps\Core\MCPMigration::DONE_OPTION, '' ) ) {
			return 'off';
		}
		if ( empty( $settings['enable_discovery_hub'] ) || 'read_only' !== ( $settings['mcp_mode'] ?? 'off' ) ) {
			return 'off';
		}
		return ! self::$registration_failed && 'ready' === AdapterDependency::state() ? 'read_only' : 'off';
	}

	/** Evaluate the current WordPress identity on every MCP request and resource read. */
	public static function can_read(): bool {
		return 'read_only' === self::mode() && is_user_logged_in() && current_user_can( 'read' );
	}

	/** Reconcile a network activation change using the same dependency boundary. */
	public function network_dependency_changed( string $option, array $new_plugins, array $old_plugins ): void {
		unset( $option );
		$this->dependency_changed( array_keys( $old_plugins ), array_keys( $new_plugins ) );
	}

	/** Reconcile owned discovery files when plugin availability changes. */
	public function dependency_changed( array $old_plugins, array $new_plugins ): void {
		if ( in_array( AdapterDependency::PLUGIN, $old_plugins, true ) === in_array( AdapterDependency::PLUGIN, $new_plugins, true ) ) {
			return;
		}
		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		$bridge = StaticBridge::get_instance();
		$bridge->cancel_and_purge( 'discovery' );
		$bridge->request_sync();
	}
}
