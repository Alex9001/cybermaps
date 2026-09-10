<?php
/**
 * Unified Priority Engine
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PriorityEngine {
	/**
	 * @var array<string,array<string,float|int>>
	 */
	private static array $archetype_defaults = array();

	/**
	 * Get calculated priority for a given type.
	 *
	 * @param string $type The object type (post type, taxonomy, or archive type).
	 * @return float
	 */
	public static function calculate( $type ) {
		$discovery = \Cybermaps\Core\ConfigurationStore::discovery();

		$identity    = self::resolve_identity( (string) $type );
		$lookup_type = self::legacy_lookup_type( (string) $type, $identity );

		$keys = self::lookup_keys( $identity, $lookup_type );

		// 1. Explicit publication status. This is independent from the positive
		// weight so disabling and re-enabling a group does not erase its tuning.
		$disabled = isset( $discovery['disabled'] ) && is_array( $discovery['disabled'] )
			? $discovery['disabled']
			: array();
		// The miscellaneous sitemap inherits the Pages weight because its primary
		// entries are the homepage and other page-like URLs. Publication remains
		// independent: turning Pages off must not suppress the homepage or custom
		// external URLs owned by the miscellaneous provider.
		if ( self::is_disabled( $disabled, $identity, $keys ) ) {
			return 0.0;
		}

		// 2. Manual positive-weight override.
		$overrides = isset( $discovery['overrides'] ) && is_array( $discovery['overrides'] )
			? $discovery['overrides']
			: array();

		$override = self::override_for_keys( $overrides, $keys );
		if ( null !== $override ) {
			return $override;
		}

		// 3. Baseline from archetype.
		$archetype = self::archetype( $discovery );
		self::load_archetype_defaults( $archetype );
		$defaults = self::$archetype_defaults[ $archetype ];
		$priority = isset( $defaults[ $lookup_type ] ) ? floatval( $defaults[ $lookup_type ] ) : 0.5;

		// 4. Clamp result. Zero remains readable for historical saved profiles;
		// current profiles use the separate disabled map for publication status.
		return max( 0.0, min( 1.0, $priority ) );
	}

	private static function lookup_keys( string $identity, string $lookup_type ): array {
		$keys = '' !== $identity ? array( $identity ) : array();
		if ( ProviderIdentity::MISC === $identity ) {
			$keys[] = ProviderIdentity::post_type( 'page' );
		}
		$keys[] = $lookup_type;
		if ( 'post_tag' === $lookup_type ) {
			$keys[] = 'tag';
		}
		return \array_values( \array_unique( \array_filter( $keys ) ) );
	}

	private static function is_disabled( array $disabled, string $identity, array $keys ): bool {
		foreach ( ProviderIdentity::MISC === $identity ? array( $identity ) : $keys as $key ) {
			if ( ! empty( $disabled[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function override_for_keys( array $overrides, array $keys ): ?float {
		foreach ( $keys as $key ) {
			if ( isset( $overrides[ $key ] ) && \is_numeric( $overrides[ $key ] ) ) {
				return \max( 0.0, \min( 1.0, (float) $overrides[ $key ] ) );
			}
		}
		return null;
	}

	private static function archetype( array $discovery ): string {
		$archetype = isset( $discovery['archetype'] ) && is_scalar( $discovery['archetype'] ) ? sanitize_key( (string) $discovery['archetype'] ) : '';
		return '' !== $archetype ? $archetype : 'medium-business';
	}

	private static function load_archetype_defaults( string $archetype ): void {
		if ( ! isset( self::$archetype_defaults[ $archetype ] ) ) {
			$auditor                                = new \Cybermaps\Admin\DiscoveryAuditor();
			self::$archetype_defaults[ $archetype ] = $auditor->get_archetype_defaults( $archetype );
		}
	}

	/**
	 * Resolve explicit identities first and legacy raw slugs only when their
	 * current WordPress object kind is unambiguous.
	 */
	private static function resolve_identity( string $type ): string {
		if ( '' !== ProviderIdentity::kind( $type ) ) {
			return $type;
		}

		$type   = \sanitize_key( $type );
		$system = ProviderIdentity::system( $type );
		if ( '' !== $system ) {
			return $system;
		}

		return ProviderIdentity::unambiguous_public_object( $type );
	}

	/**
	 * Return the raw key used by legacy settings and archetype defaults.
	 */
	private static function legacy_lookup_type( string $type, string $identity ): string {
		$name = '' !== $identity
			? ProviderIdentity::name( $identity )
			: \sanitize_key( $type );

		return ProviderIdentity::MISC === $identity || 'misc' === $name
			? 'page'
			: $name;
	}
}
