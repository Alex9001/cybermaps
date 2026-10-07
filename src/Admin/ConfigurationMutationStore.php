<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\RawOptionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Conflict-detecting import persistence; deliberately not a multi-root lock. */
final class ConfigurationMutationStore {
	/** Read authoritative bytes, bypassing WordPress filters and option caches. */
	public static function read( string $option ): array {
		global $wpdb;
		$raw = RawOptionStore::read( $wpdb, $option );
		if ( false === $raw ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not read the stored configuration safely.', 'cybermaps' ) );
		}
		return self::state( $raw );
	}

	public static function state( ?string $raw ): array {
		return array(
			'exists' => null !== $raw,
			'value'  => null === $raw ? null : maybe_unserialize( $raw ),
			'raw'    => $raw,
		);
	}

	public static function target( mixed $value ): array {
		return self::state( (string) maybe_serialize( $value ) );
	}

	/**
	 * Store one reviewed value. Callers record ownership before post-write hooks,
	 * so a hook exception can still be rolled back by exact target bytes.
	 */
	public static function write( string $option, array $before, array $after ): bool {
		global $wpdb;
		try {
			$result = $before['exists']
				? RawOptionStore::replace( $wpdb, $option, $before['raw'], $after['raw'] )
				: RawOptionStore::insert( $wpdb, $option, $after['raw'] );
			return 1 === $result;
		} finally {
			RawOptionStore::invalidate( $option );
		}
	}

	/** A conflicting value is retained, never replaced by a stale rollback. */
	public static function restore( string $option, array $owned, array $before ): bool {
		global $wpdb;
		try {
			$result = $before['exists']
				? RawOptionStore::replace( $wpdb, $option, $owned['raw'], $before['raw'] )
				: RawOptionStore::remove( $wpdb, $option, $owned['raw'] );
			return 1 === $result || self::read( $option ) === $before;
		} finally {
			RawOptionStore::invalidate( $option );
		}
	}

	/**
	 * Notify ordinary option observers after a proven write, using WP arguments.
	 * Pre-update mutation filters are deliberately bypassed: the reviewed target
	 * is authoritative. Core's own handlers are deferred by MigrationHub until
	 * verification. Arbitrary observer effects are not transactionally reversible.
	 */
	public static function notify( string $option, array $before, array $after ): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Replay WordPress's existing post-option compatibility hooks after exact CAS, with native argument order.
		if ( $before['exists'] ) {
			do_action( "update_option_{$option}", $before['value'], $after['value'], $option );
			do_action( 'updated_option', $option, $before['value'], $after['value'] );
		} else {
			do_action( "add_option_{$option}", $option, $after['value'] );
			do_action( 'added_option', $option, $after['value'] );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}
}
