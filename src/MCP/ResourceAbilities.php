<?php
/**
 * Explicit public discovery resources for the optional MCP server.
 *
 * @package Cybermaps\MCP
 */

declare(strict_types=1);

namespace Cybermaps\MCP;

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Discovery\DiscoveryPublicationGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Each resource has an owned callback; client input never selects an arbitrary ability. */
final class ResourceAbilities {
	/** Reviewed Core publications. New resources require an explicit policy review. */
	public const IDS       = array(
		'manifest',
		'adp_discovery',
		'discovery_index',
		'llms',
		'llms_full',
		'llms_tldr',
		'knowledge_graph',
		'feed',
		'updates',
		'adp_news_llms',
		'adp_news_speakable',
		'adp_news_changelog',
		'adp_news_archive',
		'ai_sitemap',
		'usage_policy',
		'actions',
		'skill',
		'agent_skills',
		'api_catalog',
		'ai_catalog',
		'mcp_server_card',
		'openapi',
	);
	public const MAX_BYTES = 4194304;

	/** @return array<string,array<string,mixed>> Enabled, reviewed Core publications. */
	public static function definitions(): array {
		$registry = EndpointRegistry::get_instance();
		$result   = array();
		foreach ( self::IDS as $id ) {
			$definition = $registry->get( $id );
			if ( null !== $definition && ! empty( $definition['advertise'] ) && $registry->is_enabled( $id ) ) {
				$result[ $id ] = $definition;
			}
		}
		return $result;
	}

	/** @return string[] Explicit names passed to the adapter's resource registration. */
	public static function names(): array {
		return array_map( array( self::class, 'name' ), array_keys( self::definitions() ) );
	}

	/** Build a stable resource ability name from an internally selected ID. */
	private static function name( string $id ): string {
		return 'cybermaps/resource-' . str_replace( '_', '-', $id );
	}

	/** Register wrappers only when the optional integration is configured and compatible. */
	public static function register(): void {
		if ( 'read_only' !== WordPressIntegration::mode() ) {
			return;
		}
		foreach ( self::definitions() as $id => $definition ) {
			wp_register_ability( self::name( $id ), self::arguments( $id, $definition ) );
		}
	}

	/**
	 * Build a resource registration with no native REST or automatic MCP exposure.
	 *
	 * @param array<string,mixed> $definition Registered Core publication.
	 * @return array<string,mixed>
	 */
	private static function arguments( string $id, array $definition ): array {
		return array(
			'label'               => (string) $definition['label'],
			'description'         => (string) $definition['description'],
			'category'            => 'cybermaps-discovery',
			'execute_callback'    => static fn(): array|\WP_Error => self::read( $id ),
			'permission_callback' => static fn(): bool => WordPressIntegration::can_read() && EndpointRegistry::get_instance()->is_enabled( $id ),
			'meta'                => array(
				'public'       => false,
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'mcp'          => array(
					'public'   => false,
					'type'     => 'resource',
					'uri'      => EndpointRegistry::get_instance()->get_url( $id ),
					'mimeType' => (string) $definition['type'],
				),
			),
		);
	}

	/** @return array<int,array<string,string>>|\WP_Error Public text resource contents. */
	public static function read( string $id ): array|\WP_Error {
		$definition = self::definitions()[ $id ] ?? null;
		if ( null === $definition || ! WordPressIntegration::can_read() ) {
			return new \WP_Error( 'cybermaps_resource_unavailable', __( 'This public resource is unavailable.', 'cybermaps' ) );
		}
		try {
			$body = ( new DiscoveryPublicationGenerator() )->generate(
				$id,
				array(
					'id'   => $id,
					'path' => $definition['path'],
				),
				ConfigurationStore::settings()
			);
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'cybermaps_resource_failed', __( 'This public resource could not be generated.', 'cybermaps' ) );
		}
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new \WP_Error( 'cybermaps_resource_too_large', __( 'This public resource exceeds the response size limit.', 'cybermaps' ) );
		}
		return array(
			array(
				'uri'      => EndpointRegistry::get_instance()->get_url( $id ),
				'mimeType' => (string) $definition['type'],
				'text'     => $body,
			),
		);
	}
}
