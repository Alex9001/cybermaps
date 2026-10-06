<?php
/**
 * Guided setup for the optional WordPress MCP Adapter integration.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\MCPMigration;
use Cybermaps\MCP\AdapterDependency;
use Cybermaps\MCP\WordPressIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Native WordPress installation links and an explicit Cybermaps opt-in. */
final class MCPSetup {
	/** Render setup inside the existing protected Settings API form. */
	public static function render(): void {
		echo '<p>' . esc_html__( 'Install and activate WordPress MCP Adapter to enable MCP connections. Cybermaps’ sitemaps, AI publications and reports work independently.', 'cybermaps' ) . '</p>';
		$state = AdapterDependency::state();
		if ( 'ready' !== $state ) {
			self::dependency_action( $state );
		} else {
			self::render_opt_in();
		}
		echo '<p><a href="https://cybermaps.dev/docs/mcp/">' . esc_html__( 'MCP connection and migration guide', 'cybermaps' ) . '</a></p>';
	}

	/** Link to native WordPress actions; no automatic installation or activation. */
	private static function dependency_action( string $state ): void {
		$capability = match ( $state ) {
			'missing' => 'install_plugins',
			'inactive' => 'activate_plugins',
			default => 'update_plugins',
		};
		if ( ! current_user_can( $capability ) || ( is_multisite() && ! is_network_admin() ) ) {
			echo '<p>' . esc_html__( 'Ask your site or network administrator to install and activate MCP Adapter 0.7.0 or newer.', 'cybermaps' ) . ' <a href="https://wordpress.org/plugins/mcp-adapter/">' . esc_html__( 'View MCP Adapter by WordPress.org', 'cybermaps' ) . '</a></p>';
			return;
		}
		$label = match ( $state ) {
			'missing' => __( 'Install MCP Adapter', 'cybermaps' ),
			'inactive' => __( 'Activate MCP Adapter', 'cybermaps' ),
			default => __( 'Update or repair MCP Adapter', 'cybermaps' ),
		};
		$url = 'missing' === $state
			? self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=mcp-adapter' )
			: self_admin_url( 'plugins.php?s=mcp-adapter' );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
	}

	/** Render only meaningful controls when the required integration is ready. */
	private static function render_opt_in(): void {
		$settings = ConfigurationStore::settings();
		if ( ! MCPMigration::is_complete() ) {
			echo '<p>' . esc_html__( 'Legacy MCP cleanup is pending. The integration remains unavailable until cleanup succeeds.', 'cybermaps' ) . '</p>';
			return;
		}
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			echo '<p>' . esc_html__( 'Enable and save the AI Publication Hub first, then enable Cybermaps MCP.', 'cybermaps' ) . '</p>';
			return;
		}
		echo '<input type="hidden" name="cybermaps_settings[enable_mcp_adapter]" value="0">';
		\Cybermaps\Admin\Settings\Fields\FieldRenderer::render_checkbox_field(
			array(
				'label_for'   => 'enable_mcp_adapter',
				'label'       => __( 'Enable Cybermaps MCP (read-only)', 'cybermaps' ),
				'description' => __( 'Exposes only public discovery resources and search. Save settings to apply your choice.', 'cybermaps' ),
			)
		);
		if ( WordPressIntegration::is_enabled() ) {
			echo '<p>' . esc_html__( 'MCP endpoint:', 'cybermaps' ) . ' <code>' . esc_html( rest_url( WordPressIntegration::REST_NAMESPACE . WordPressIntegration::REST_ROUTE ) ) . '</code></p>';
			echo '<p>' . esc_html__( 'Connect over HTTPS using a WordPress Application Password for a dedicated Subscriber account. Configure credentials in your client; Cybermaps does not collect them. Clients must support this authentication method or the adapter’s WP-CLI transport.', 'cybermaps' ) . '</p>';
		}
	}

	/** Human-readable status for the Overview and setup completion screen. */
	public static function status(): string {
		if ( WordPressIntegration::is_enabled() ) {
			return __( 'Read-only MCP enabled', 'cybermaps' );
		}
		return 'ready' === AdapterDependency::state()
			? __( 'MCP available; enable in AI Publishing', 'cybermaps' )
			: __( 'Optional MCP requires MCP Adapter', 'cybermaps' );
	}
}
