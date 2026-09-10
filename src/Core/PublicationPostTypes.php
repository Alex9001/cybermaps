<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical inventory of public post types that represent publishable pages.
 *
 * WordPress registers attachments as a public post type even though media rows
 * normally use the inherited status and attachment URLs are not independent
 * Cybermaps publications. Keeping that exception here prevents XML, AI, HTML,
 * reporting, and administration surfaces from drifting apart.
 */
final class PublicationPostTypes {
	private const EXCLUDED = array( 'attachment' );

	/**
	 * Return every currently registered public publication post type.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return self::filter_names(
			(array) get_post_types( array( 'public' => true ), 'names' )
		);
	}

	/**
	 * Return public publication post-type objects keyed by their names.
	 *
	 * @return array<string, object>
	 */
	public static function objects(): array {
		$objects = array();
		foreach ( self::names() as $name ) {
			$object = get_post_type_object( $name );
			if ( is_object( $object ) ) {
				$objects[ $name ] = $object;
			}
		}

		return $objects;
	}

	/**
	 * Determine whether one currently registered type is a public Cybermaps
	 * publication type.
	 */
	public static function contains( string $post_type ): bool {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type || in_array( $post_type, self::EXCLUDED, true ) ) {
			return false;
		}

		$object = get_post_type_object( $post_type );
		return is_object( $object ) && ! empty( $object->public );
	}

	/**
	 * Normalize a configured type list while removing non-publication types.
	 *
	 * This deliberately does not require the type to be registered at the moment
	 * of sanitization. A companion plugin may register its post type later in the
	 * request lifecycle; runtime eligibility still verifies that it is public.
	 *
	 * @param array<int|string, mixed> $types Candidate post types.
	 * @return string[]
	 */
	public static function filter_names( array $types ): array {
		$normalized = array();

		foreach ( $types as $key => $candidate ) {
			if ( is_object( $candidate ) && isset( $candidate->name ) ) {
				$name = (string) $candidate->name;
			} elseif ( is_scalar( $candidate ) ) {
				$name = (string) $candidate;
			} elseif ( is_string( $key ) ) {
				$name = $key;
			} else {
				continue;
			}

			$name = sanitize_key( $name );
			if (
				'' === $name
				|| in_array( $name, self::EXCLUDED, true )
				|| in_array( $name, $normalized, true )
			) {
				continue;
			}

			$normalized[] = $name;
		}

		return $normalized;
	}
}
