<?php
/**
 * AI Action Discovery Handler
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Actions {

	/**
	 * Handle requests for /ai-actions.json.
	 *
	 * @return void
	 */
	public function handle() {
		$path = \Cybermaps\Core\URLManager::get_request_path();
		if ( '/ai-actions.json' !== $path ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return;
		}

		$data   = $this->get_action_data();
		$output = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		Integrity::send_headers( $output );
		header( 'Content-Type: application/ld+json' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
		exit;
	}

	/**
	 * Get the action manifest data.
	 *
	 * @return array
	 */
	public function get_action_data() {
		$options           = \Cybermaps\Core\ConfigurationStore::settings();
		$potential_actions = $this->build_actions( $this->action_mappings( $options ) );
		$search_url        = \Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'rest_search' );
		if ( ! $this->has_search_action( $potential_actions ) && '' !== $search_url ) {
			$potential_actions[] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => \Cybermaps\Core\URLManager::append_query_template(
						$search_url,
						'q={search_term_string}'
					),
				),
				'query-input' => 'required name=search_term_string',
			);
		}

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'WebSite',
			'name'            => get_bloginfo( 'name' ),
			'url'             => \Cybermaps\Core\URLManager::get_home_url( '/' ),
			'potentialAction' => $potential_actions,
		);
	}

	/**
	 * @param array<string,mixed> $options Current settings.
	 * @return array<int,mixed>
	 */
	private function action_mappings( array $options ): array {
		$mappings = isset( $options['ai_action_mappings'] ) && is_array( $options['ai_action_mappings'] )
			? $options['ai_action_mappings']
			: array();
		return array_slice( $mappings, 0, PublicationConstraints::ACTION_MAPPINGS_MAX );
	}

	/**
	 * @param array<int,mixed> $actions_raw Configured mappings.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_actions( array $actions_raw ): array {
		$actions = array();
		foreach ( $actions_raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['url'] ) ) {
				continue;
			}
			$action_url  = \Cybermaps\Core\URLManager::sanitize_http_url( $entry['url'] );
			$action_type = PublicationConstraints::action_type( $entry['type'] ?? '' );
			if ( '' === $action_url || '' === $action_type ) {
				continue;
			}

			$description = is_scalar( $entry['desc'] ?? null )
				? sanitize_text_field( (string) $entry['desc'] )
				: '';
			$actions[]   = array(
				'@type'       => $action_type,
				'target'      => array(
					'@type'          => 'EntryPoint',
					'urlTemplate'    => \Cybermaps\Core\URLManager::rewrite_url( $action_url ),
					'actionPlatform' => array(
						'http://schema.org/DesktopWebPlatform',
						'http://schema.org/MobileWebPlatform',
						'http://schema.org/IOSPlatform',
						'http://schema.org/AndroidPlatform',
					),
				),
				'description' => PublicationConstraints::bounded_text(
					$description,
					\Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH
				),
			);
		}
		return $actions;
	}

	/**
	 * @param array<int,array<string,mixed>> $actions Actions to inspect.
	 */
	private function has_search_action( array $actions ): bool {
		foreach ( $actions as $action ) {
			if ( 'SearchAction' === $action['@type'] ) {
				return true;
			}
		}
		return false;
	}
}
