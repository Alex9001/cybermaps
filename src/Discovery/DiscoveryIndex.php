<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovery Index — /ai-discovery
 *
 * Serves a unified vendor-defined JSON index of advertised publications,
 * their content types, and classifications. Availability does not imply that
 * an external client knows or consumes this index.
 */
class DiscoveryIndex {
	/**
	 * Serve the canonical discovery index.
	 */
	public function handle(): void {
		$path = (string) \Cybermaps\Core\URLManager::get_request_path();
		if ( '/ai-discovery' !== $path || ! Integrity::is_hub_enabled() ) {
			return;
		}

		$output = \wp_json_encode(
			$this->get_index(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		$output = \is_string( $output ) ? $output : '{}';

		Integrity::send_headers( $output, HOUR_IN_SECONDS );
		\header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate JSON response.
			echo $output;
		}
		exit;
	}

	public function get_index(): array {
		$endpoints = \Cybermaps\Core\EndpointRegistry::get_instance()->get_advertised_endpoints();
		$guidance  = PublisherGuidance::get();

		$index = array(
			'version'   => CYBERMAPS_VERSION,
			'site'      => get_bloginfo( 'name' ),
			'endpoints' => $endpoints,
		);

		if ( '' !== $guidance ) {
			$index['publisher_guidance'] = $guidance;
		}

		return $index;
	}
}
