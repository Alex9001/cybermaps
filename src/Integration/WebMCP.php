<?php
/**
 * Opt-in browser WebMCP tool registration.
 *
 * @package Cybermaps\Integration
 */

declare(strict_types=1);

namespace Cybermaps\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues a read-only browser bridge when explicitly enabled.
 */
final class WebMCP {
	/** Register the front-end asset hook. */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/** Enqueue the browser bridge only for an enabled public site. */
	public function enqueue_scripts(): void {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( is_admin() || ! self::is_enabled( $settings ) ) {
			return;
		}

		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		wp_enqueue_script(
			'cybermaps-webmcp',
			CYBERMAPS_PLUGIN_URL . 'assets/js/webmcp.js',
			array(),
			CYBERMAPS_VERSION,
			true
		);
		wp_localize_script(
			'cybermaps-webmcp',
			'cybermapsWebMCP',
			array(
				'searchUrl'    => $registry->get_url( 'rest_search' ),
				'discoveryUrl' => $registry->get_url( 'discovery_index' ),
				'abilities'    => \Cybermaps\Core\AbilityKernel::get_instance()->webmcp_catalog(),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Resolve the explicit runtime gate.
	 *
	 * @param array<string,mixed>|null $settings General plugin settings.
	 */
	public static function is_enabled( ?array $settings = null ): bool {
		$settings = is_array( $settings ) ? $settings : \Cybermaps\Core\ConfigurationStore::settings();
		return ! empty( $settings['enable_discovery_hub'] ) && ! empty( $settings['enable_webmcp'] );
	}
}
