<?php
/**
 * WebSub Push Discovery Service
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebSub {

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * WebSub constructor.
	 */
	public function __construct() {
		$this->settings = \Cybermaps\Core\ConfigurationStore::settings();
	}

	/**
	 * Notify the canonical feed topic after post-commit reconciliation.
	 */
	public function notify_change(): void {
		if ( empty( $this->settings['enable_websub'] ) ) {
			return;
		}

		$this->ping_hubs_bulk();
	}

	/**
	 * Ping every configured hub for the canonical feed topic.
	 */
	private function ping_hubs_bulk(): void {
		if (
			empty( $this->settings['enable_websub'] )
			|| empty( $this->settings['enable_discovery_hub'] )
		) {
			return;
		}

		$hubs = $this->get_hubs();
		if ( empty( $hubs ) ) {
			return;
		}

		// JSON Feed is the one canonical WebSub topic. Subscribers discover the
		// same self URL and hub list from that representation, so every publish
		// notification has an unambiguous topic contract.
		$topic_url = \Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'feed' );
		if ( '' === $topic_url ) {
			return;
		}

		foreach ( $hubs as $hub ) {
			wp_safe_remote_post(
				$hub,
				array(
					'body'     => array(
						'hub.mode' => 'publish',
						'hub.url'  => $topic_url,
					),
					'blocking' => false,
					'timeout'  => 1,
				)
			);
		}
	}

	/**
	 * Advertise the canonical topic and configured hubs on a dynamic feed.
	 */
	public function send_discovery_headers( string $topic_url ): void {
		if (
			empty( $this->settings['enable_websub'] )
			|| empty( $this->settings['enable_discovery_hub'] )
		) {
			return;
		}

		$topic_url = \Cybermaps\Core\URLManager::sanitize_http_url( $topic_url );
		if ( '' === $topic_url ) {
			return;
		}

		header( 'Link: <' . $topic_url . '>; rel="self"; type="application/feed+json"', false );
		foreach ( $this->get_hubs() as $hub ) {
			header( 'Link: <' . $hub . '>; rel="hub"', false );
		}
	}

	/**
	 * Get registered hubs.
	 *
	 * @return string[]
	 */
	public function get_hubs() {
		$stored   = $this->settings['websub_hubs']
			?? "https://pubsubhubbub.appspot.com/\nhttps://pubsubhubbub.superfeedr.com/";
		$hubs_str = is_scalar( $stored ) ? (string) $stored : '';
		return self::normalize_hubs( $hubs_str );
	}

	/**
	 * Normalize the configured hub list to unique, public HTTPS endpoints.
	 *
	 * @return string[]
	 */
	public static function normalize_hubs( string $hubs_str ): array {
		$lines = (array) preg_split( '/\r\n|\r|\n/', $hubs_str );
		$hubs  = array();

		foreach ( $lines as $line ) {
			$hub = \Cybermaps\Core\URLManager::sanitize_http_url( $line );
			if (
				'' === $hub
				|| 'https' !== strtolower( (string) wp_parse_url( $hub, PHP_URL_SCHEME ) )
				|| ! wp_http_validate_url( $hub )
			) {
				continue;
			}

			$hubs[ $hub ] = $hub;
			if ( count( $hubs ) >= 10 ) {
				break;
			}
		}

		return array_values( $hubs );
	}
}
