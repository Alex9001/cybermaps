<?php
/**
 * Header-Based Discovery Service (RFC 8288)
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HeaderDiscovery {
	/**
	 * @var array<int, array{url:string, relation:string, type:string}>|null
	 */
	private ?array $content_link_definition_cache = null;

	private int $content_link_definition_post_id = 0;

	/**
	 * @var array<string, mixed>
	 */
	private array $content_link_definition_settings = array();

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'send_headers', array( $this, 'inject_discovery_headers' ) );
		add_action( 'wp_head', array( $this, 'render_content_links' ), 2 );
	}

	/**
	 * Inject Link headers for discovery manifests.
	 */
	public function inject_discovery_headers(): void {
		// Only on frontend.
		if ( is_admin() ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return;
		}

		foreach ( $this->get_response_links( $this->current_post_id(), $settings ) as $link ) {
			header( 'Link: ' . $link, false );
		}
	}

	/**
	 * Render page-level discovery relations in eligible singular HTML documents.
	 */
	public function render_content_links(): void {
		if ( is_admin() ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$post_id  = $this->current_post_id();
		foreach ( $this->get_content_link_definitions( $post_id, $settings ) as $definition ) {
			echo '<link rel="' . esc_attr( $definition['relation'] ) . '" href="' . esc_url( $definition['url'] ) . '"';
			if ( '' !== $definition['type'] ) {
				echo ' type="' . esc_attr( $definition['type'] ) . '"';
			}
			echo " />\n";
		}
	}

	/**
	 * Build llms.txt v2-style relations for one eligible HTML resource.
	 *
	 * @param array<string, mixed>|null $settings Settings snapshot.
	 * @return string[]
	 */
	public function get_content_links( int $post_id, ?array $settings = null ): array {
		$links = array();
		foreach ( $this->get_content_link_definitions( $post_id, $settings ) as $definition ) {
			$link = $this->format_link(
				$definition['url'],
				$definition['relation'],
				$definition['type']
			);
			if ( null !== $link ) {
				$links[] = $link;
			}
		}
		return $links;
	}

	/**
	 * Build the Link field values emitted in HTTP response headers.
	 *
	 * @param array<string, mixed>|null $settings Settings snapshot.
	 * @return string[]
	 */
	public function get_response_links( int $post_id, ?array $settings = null ): array {
		$settings = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
		if (
			empty( $settings['enable_discovery_hub'] )
			|| ! isset( $settings['enable_header_discovery'] )
			|| ! is_scalar( $settings['enable_header_discovery'] )
			|| '1' !== (string) $settings['enable_header_discovery']
		) {
			return array();
		}

		return array_merge(
			$this->get_content_links( $post_id, $settings ),
			$this->get_discovery_links()
		);
	}

	/**
	 * Build canonical RFC 8288 Link field values.
	 *
	 * Only registered relation types are emitted here. Vendor-defined site
	 * guides remain directly reachable through the Discovery Index rather than
	 * claiming globally registered RFC 8288 relation tokens.
	 *
	 * @return string[]
	 */
	public function get_discovery_links(): array {
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		$links    = array();

		foreach ( array( 'feed' => 'alternate' ) as $endpoint_id => $relation ) {
			$definition = $registry->get( $endpoint_id );
			$url        = $registry->get_url( $endpoint_id );
			$type       = is_array( $definition ) ? (string) ( $definition['type'] ?? '' ) : '';
			$link       = $this->format_link( $url, $relation, $type );
			if ( null !== $link ) {
				$links[] = $link;
			}
		}

		if ( $registry->is_enabled( 'api_catalog' ) ) {
			$links[] = ( new APICatalog() )->get_link_header();
		}

		return $links;
	}

	/**
	 * Format a safe Link field value.
	 */
	private function format_link( string $url, string $relation, string $type ): ?string {
		if (
			'' === $url
			|| '' === $relation
			|| str_contains( $url, "\r" )
			|| str_contains( $url, "\n" )
		) {
			return null;
		}

		$link = '<' . $url . '>; rel="' . $relation . '"';
		return '' !== $type ? $link . '; type="' . $type . '"' : $link;
	}

	/**
	 * Build structured page-level relations after applying AI eligibility.
	 *
	 * @param array<string, mixed>|null $settings Settings snapshot.
	 * @return array<int, array{url:string, relation:string, type:string}>
	 */
	private function get_content_link_definitions( int $post_id, ?array $settings = null ): array {
		$settings = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
		if (
			null !== $this->content_link_definition_cache
			&& $post_id === $this->content_link_definition_post_id
			&& $settings === $this->content_link_definition_settings
		) {
			return $this->content_link_definition_cache;
		}

		if ( $post_id < 1 || empty( $settings['enable_discovery_hub'] ) ) {
			$this->content_link_definition_cache    = array();
			$this->content_link_definition_post_id  = $post_id;
			$this->content_link_definition_settings = $settings;
			return array();
		}

		$post = get_post( $post_id );
		if (
			! is_object( $post )
			|| ! ( new PublicationEligibility( null, $settings ) )->post( $post, PublicationEligibility::AI )->indexable
		) {
			$this->content_link_definition_cache    = array();
			$this->content_link_definition_post_id  = $post_id;
			$this->content_link_definition_settings = $settings;
			return array();
		}

		$markdown = MarkdownAlternate::url_for_post( $post );
		$llms     = ( new MarkdownAlternate() )->llms_url();
		$links    = array();
		if ( '' !== $markdown ) {
			$links[] = array(
				'url'      => $markdown,
				'relation' => 'alternate',
				'type'     => 'text/markdown',
			);
		}
		if ( '' !== $llms ) {
			$links[] = array(
				'url'      => $llms,
				'relation' => 'describedby',
				'type'     => 'text/markdown',
			);
		}

		$this->content_link_definition_cache    = $links;
		$this->content_link_definition_post_id  = $post_id;
		$this->content_link_definition_settings = $settings;

		return $links;
	}

	/**
	 * Resolve the current singular post after the main query is available.
	 */
	private function current_post_id(): int {
		if ( ! is_singular() ) {
			return 0;
		}
		if ( function_exists( 'get_queried_object_id' ) ) {
			return absint( get_queried_object_id() );
		}
		return function_exists( 'get_the_ID' ) ? absint( get_the_ID() ) : 0;
	}
}
