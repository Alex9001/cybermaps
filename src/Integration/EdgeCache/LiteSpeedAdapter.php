<?php
/**
 * LiteSpeed Cache bridge.
 *
 * @package Cybermaps\Integration\EdgeCache
 */

declare(strict_types=1);

namespace Cybermaps\Integration\EdgeCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses only LiteSpeed's documented tag and purge integration points.
 */
final class LiteSpeedAdapter implements AdapterInterface {
	/** Whether the administrator permits automatic LiteSpeed integration. */
	public static function is_enabled(): bool {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		return ! isset( $settings['enable_litespeed_cache_integration'] ) || ! empty( $settings['enable_litespeed_cache_integration'] );
	}

	/** Whether LiteSpeed is both available and enabled for Cybermaps. */
	public static function is_active(): bool {
		return self::is_available() && self::is_enabled();
	}

	/**
	 * Whether LiteSpeed Cache's WordPress integration is available.
	 */
	public static function is_available(): bool {
		if ( defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Core' ) || function_exists( 'litespeed_purge' ) ) {
			return true;
		}

		return function_exists( 'has_action' ) && false !== has_action( 'litespeed_purge' );
	}

	/**
	 * Attach public tags when the LiteSpeed integration is active. The plugin
	 * intentionally does not send an X-LiteSpeed-Cache-Control override: that
	 * header would supersede the portable Cache-Control policy.
	 *
	 * @param string[] $tags Safe bounded tags.
	 */
	public static function emit_tags( array $tags ): void {
		if ( ! self::is_active() || empty( $tags ) ) {
			return;
		}

		header( 'X-LiteSpeed-Tag: ' . implode( ',', $tags ), false );
	}

	/**
	 * Prevent a negotiated response that reached PHP from entering LSCache.
	 * A request-time server rule is still required to bypass an existing HTML hit.
	 */
	public static function mark_negotiated_request_nocache(): void {
		if ( self::is_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official LiteSpeed Cache integration action.
			do_action( 'litespeed_control_set_nocache', 'Cybermaps Markdown negotiation' );
		}
	}

	/**
	 * Purge pre-negotiation page objects after the feature changes state.
	 */
	public static function purge_all_for_negotiation_change(): void {
		if ( self::is_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official LiteSpeed Cache integration action.
			do_action( 'litespeed_purge_all' );
		}
	}

	/**
	 * @param array<string,mixed> $event Invalidation event.
	 * @return array<string,mixed>
	 */
	public function purge( array $event ): array {
		$tags = isset( $event['tags'] ) && is_array( $event['tags'] ) ? $event['tags'] : array();
		if ( ! self::is_available() ) {
			return array(
				'adapter' => 'litespeed',
				'status'  => 'unavailable',
				'count'   => 0,
			);
		}
		if ( ! self::is_enabled() ) {
			return array(
				'adapter' => 'litespeed',
				'status'  => 'disabled',
				'count'   => 0,
			);
		}

		$count = 0;
		foreach ( array_slice( $tags, 0, 8 ) as $tag ) {
			if ( ! is_string( $tag ) || '' === $tag ) {
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Official LiteSpeed Cache integration action.
			do_action( 'litespeed_purge', $tag );
			++$count;
		}

		return array(
			'adapter' => 'litespeed',
			'status'  => $count > 0 ? 'sent' : 'skipped',
			'count'   => $count,
		);
	}
}
