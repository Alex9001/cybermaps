<?php
/**
 * Unified Search Intent Engine
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IntentEngine {
	/**
	 * @var array<string,array<string,string>>
	 */
	private static array $archetype_intents = array();

	/**
	 * Get search intent for a given item or content type.
	 *
	 * @param int|string $id_or_type ID of post/term, or type string.
	 * @param string     $scope       Scope of the ID ('post', 'term', or 'type').
	 * @param string     $base_type   Base content type for fallback.
	 * @return string
	 */
	public static function calculate( int|string $id_or_type, string $scope = 'type', string $base_type = '' ): string {
		$discovery_data = \Cybermaps\Core\ConfigurationStore::discovery();
		$override       = self::item_override( $id_or_type, $scope );
		if ( '' !== $override ) {
			return $override;
		}

		// 2. Fall back to the kind-aware content-type matrix. Explicit identities
		// win; legacy raw slugs remain a deterministic shared fallback.
		$candidate   = empty( $base_type ) ? (string) $id_or_type : $base_type;
		$identity    = self::resolve_identity( $candidate, $scope );
		$lookup_type = '' !== $identity
			? \Cybermaps\Sitemap\ProviderIdentity::name( $identity )
			: sanitize_key( $candidate );
		if ( 'misc' === $lookup_type ) {
			$lookup_type = 'page';
		}

		$archetype = self::archetype( $discovery_data );
		$intent    = self::configured_intent( $discovery_data, $archetype, $identity, $lookup_type );
		return self::normalize_intent( $intent );
	}

	private static function item_override( int|string $id_or_type, string $scope ): string {
		if ( 'post' !== $scope ) {
			return '';
		}
		$intent = (string) get_post_meta( (int) $id_or_type, '_cybermaps_intent_override', true );
		return in_array( $intent, array( 'informational', 'transactional' ), true ) ? $intent : '';
	}

	/**
	 * @param array<string,mixed> $data Discovery data.
	 */
	private static function archetype( array $data ): string {
		$archetype = isset( $data['archetype'] ) && is_scalar( $data['archetype'] )
			? sanitize_key( (string) $data['archetype'] )
			: 'medium-business';
		if ( '' === $archetype ) {
			$archetype = 'medium-business';
		}
		if ( ! isset( self::$archetype_intents[ $archetype ] ) ) {
			self::$archetype_intents[ $archetype ] = ( new \Cybermaps\Admin\DiscoveryAuditor() )
				->get_archetype_intents( $archetype );
		}
		return $archetype;
	}

	/**
	 * @param array<string,mixed> $data Discovery data.
	 */
	private static function configured_intent(
		array $data,
		string $archetype,
		string $identity,
		string $lookup_type
	): string {
		$intents = isset( $data['type_intents'] ) && is_array( $data['type_intents'] )
			? $data['type_intents']
			: array();
		$intent  = (string) ( self::$archetype_intents[ $archetype ][ $lookup_type ] ?? 'informational' );
		foreach ( array_filter( array( $identity, $lookup_type, 'post_tag' === $lookup_type ? 'tag' : '' ) ) as $key ) {
			if ( array_key_exists( $key, $intents ) ) {
				return (string) $intents[ $key ];
			}
		}
		return $intent;
	}

	private static function normalize_intent( string $intent ): string {
		if ( 'commercial' === $intent ) {
			return 'transactional';
		}
		if ( 'navigational' === $intent ) {
			return 'informational';
		}

		return in_array( $intent, array( 'informational', 'transactional' ), true )
			? $intent
			: 'informational';
	}

	/**
	 * Resolve a content matrix identity from an explicit key or call scope.
	 */
	private static function resolve_identity( string $candidate, string $scope ): string {
		$kind = \Cybermaps\Sitemap\ProviderIdentity::kind( $candidate );
		if ( \in_array( $kind, array( 'post_type', 'taxonomy' ), true ) ) {
			return $candidate;
		}

		$name = sanitize_key( $candidate );
		if ( 'post' === $scope ) {
			return \Cybermaps\Sitemap\ProviderIdentity::post_type( $name );
		}
		if ( 'term' === $scope ) {
			return \Cybermaps\Sitemap\ProviderIdentity::taxonomy( $name );
		}

		return \Cybermaps\Sitemap\ProviderIdentity::unambiguous_public_object( $name );
	}
}
