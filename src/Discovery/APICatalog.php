<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Catalog Discovery Service
 *
 * Publishes an RFC 9264 Linkset using the RFC 9727 API Catalog profile and
 * relation. The standard well-known location remains dynamic so Cybermaps
 * controls its protocol headers and can observe requests.
 *
 * @package Cybermaps\Discovery
 */
class APICatalog {
	public const PROFILE_URI = 'https://www.rfc-editor.org/info/rfc9727';

	/**
	 * Handle the API Catalog request.
	 */
	public function handle(): void {
		$path = \Cybermaps\Core\URLManager::get_request_path();

		if ( ! self::matches_path( (string) $path ) ) {
			return;
		}

		if ( ! Integrity::is_hub_enabled() ) {
			return;
		}

		$output = \wp_json_encode( $this->get_catalog_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		header( 'Content-Type: ' . self::get_media_type() );
		$this->send_link_header();
		Integrity::send_headers( $output );

		if ( self::request_has_body() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}

		exit;
	}

	/**
	 * Guarantee the catalog relation without duplicating Header Discovery output.
	 */
	private function send_link_header(): void {
		foreach ( \headers_list() as $header_line ) {
			$normalized = \strtolower( (string) $header_line );
			if (
				\str_starts_with( $normalized, 'link:' )
				&& (
					\str_contains( $normalized, 'rel="api-catalog"' )
					|| \str_contains( $normalized, 'rel=api-catalog' )
				)
			) {
				return;
			}
		}

		\header( 'Link: ' . $this->get_link_header(), false );
	}

	/**
	 * Match the standard dynamic catalog path and its documented compatibility
	 * alias. The registry is the canonical source of both paths.
	 */
	private static function matches_path( string $path ): bool {
		return in_array( $path, array( '/.well-known/api-catalog', '/api-catalog' ), true );
	}

	/**
	 * Linkset media type with the RFC 9727 API Catalog profile.
	 */
	public static function get_media_type(): string {
		return 'application/linkset+json; profile="' . self::PROFILE_URI . '"';
	}

	/**
	 * Link relation required on an API Catalog HEAD response.
	 */
	public function get_link_header(): string {
		$catalog_url = \Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'api_catalog' );

		return '<' . $catalog_url . '>; rel="api-catalog"; type="application/linkset+json"; profile="' . self::PROFILE_URI . '"';
	}

	/**
	 * HEAD returns the same representation metadata without a response body.
	 */
	private static function request_has_body(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		return 'HEAD' !== $method;
	}

	/**
	 * Get the API Catalog data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_catalog_data(): array {
		$endpoints   = \Cybermaps\Core\EndpointRegistry::get_instance();
		$catalog_url = $endpoints->get_url( 'api_catalog' );
		$apis        = $this->get_public_apis( $endpoints );
		$catalog     = array(
			'anchor' => $catalog_url,
		);
		$items       = array_map( array( self::class, 'catalog_item' ), $apis );

		if ( ! empty( $items ) ) {
			$catalog['item'] = $items;
		}
		$descriptions = $this->openapi_descriptions( $endpoints );
		if ( array() !== $descriptions ) {
			$catalog['service-desc'] = $descriptions;
		}

		$linkset = array( $catalog );
		foreach ( $apis as $api ) {
			$linkset[] = $this->api_context( $api, $descriptions, $endpoints );
		}
		return array(
			'linkset' => $linkset,
		);
	}

	/**
	 * Get active public, read-only APIs without private integration routes.
	 *
	 * @return array<int,array{id:string,href:string,type:string}>
	 */
	private function get_public_apis( \Cybermaps\Core\EndpointRegistry $endpoints ): array {
		$apis = array();
		foreach ( array( 'rest_root', 'rest_llms_tldr', 'rest_search' ) as $endpoint_id ) {
			$this->append_api( $apis, $endpoints, $endpoint_id );
		}
		$this->append_api( $apis, $endpoints, 'mcp' );
		if ( \Cybermaps\Core\AbilityKernel::get_instance()->has_public_abilities() ) {
			$apis[] = array(
				'id'   => 'wp_abilities',
				'href' => rest_url( 'wp-abilities/v1/abilities' ),
				'type' => 'application/json',
			);
		}

		return $apis;
	}

	/**
	 * Append one enabled API with a nonempty public URL.
	 *
	 * @param array<int,array{id:string,href:string,type:string}> $apis API rows.
	 */
	private function append_api( array &$apis, \Cybermaps\Core\EndpointRegistry $endpoints, string $endpoint_id ): void {
		if ( ! $endpoints->is_enabled( $endpoint_id ) ) {
			return;
		}
		$url = $endpoints->get_url( $endpoint_id );
		if ( '' === $url ) {
			return;
		}
		$apis[] = array(
			'id'   => $endpoint_id,
			'href' => $url,
			'type' => 'application/json',
		);
	}

	/**
	 * Remove internal context needed only while building the Linkset.
	 *
	 * @param array{id:string,href:string,type:string} $api API row.
	 * @return array{href:string,type:string}
	 */
	private static function catalog_item( array $api ): array {
		return array(
			'href' => $api['href'],
			'type' => $api['type'],
		);
	}

	/**
	 * Machine-readable descriptions for the public REST API.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function openapi_descriptions( \Cybermaps\Core\EndpointRegistry $endpoints ): array {
		$openapi_url = $endpoints->get_url( 'openapi' );
		if ( '' === $openapi_url ) {
			return array();
		}

		return array(
			array(
				'href' => $openapi_url,
				'type' => OpenAPI::MEDIA_TYPE,
			),
			array(
				'href'  => add_query_arg( 'version', OpenAPI::COMPATIBILITY_VERSION, $openapi_url ),
				'type'  => OpenAPI::COMPATIBILITY_MEDIA_TYPE,
				'title' => 'OpenAPI 3.1.2 compatibility representation',
			),
		);
	}

	/**
	 * Build an explicit RFC 9264 context for one API.
	 *
	 * @param array{id:string,href:string,type:string} $api API row.
	 * @param array<int,array<string,string>>          $openapi_descriptions OpenAPI links.
	 * @return array<string,mixed>
	 */
	private function api_context( array $api, array $openapi_descriptions, \Cybermaps\Core\EndpointRegistry $endpoints ): array {
		$descriptions = 'mcp' === $api['id']
			? array(
				array(
					'href' => MCPServerCard::get_card_url(),
					'type' => MCPServerCard::MEDIA_TYPE,
				),
			)
			: $openapi_descriptions;

		return array(
			'anchor'       => $api['href'],
			'service-desc' => $descriptions,
			'service-doc'  => array(
				array(
					'href' => $endpoints->get_url( 'rest_root' ),
					'type' => 'application/json',
				),
			),
			'status'       => array(
				array(
					'href' => PublicHealth::get_url(),
					'type' => PublicHealth::MEDIA_TYPE,
				),
			),
		);
	}
}
