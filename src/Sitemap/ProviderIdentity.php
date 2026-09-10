<?php
/**
 * Collision-proof sitemap provider identities.
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps WordPress object slugs separate from Cybermaps' system providers.
 *
 * WordPress permits a post type and taxonomy to use the same slug, and it also
 * permits either object kind to use names such as "news" or "authors". Raw
 * slugs therefore cannot safely identify a sitemap provider.
 */
final class ProviderIdentity {
	public const NEWS     = 'system:news';
	public const MISC     = 'system:misc';
	public const AUTHORS  = 'system:authors';
	public const ARCHIVES = 'system:archives';

	public const POST_TYPE_PREFIX = 'post_type:';
	public const TAXONOMY_PREFIX  = 'taxonomy:';

	private const SYSTEM_NAMES = array(
		'news'     => self::NEWS,
		'misc'     => self::MISC,
		'authors'  => self::AUTHORS,
		'archives' => self::ARCHIVES,
	);

	/**
	 * Build a post-type provider identity.
	 */
	public static function post_type( string $name ): string {
		$name = self::normalize_object_name( $name );
		return '' !== $name ? self::POST_TYPE_PREFIX . $name : '';
	}

	/**
	 * Build a taxonomy provider identity.
	 */
	public static function taxonomy( string $name ): string {
		$name = self::normalize_object_name( $name );
		return '' !== $name ? self::TAXONOMY_PREFIX . $name : '';
	}

	/**
	 * Return the system-provider identity for a legacy system name.
	 */
	public static function system( string $name ): string {
		return self::SYSTEM_NAMES[ \sanitize_key( $name ) ] ?? '';
	}

	/**
	 * Return enabled system provider identities in their canonical order.
	 *
	 * @return string[]
	 */
	public static function system_providers( bool $include_news ): array {
		$providers = array( self::MISC, self::AUTHORS, self::ARCHIVES );
		if ( $include_news ) {
			\array_unshift( $providers, self::NEWS );
		}

		return $providers;
	}

	/**
	 * Determine an identity's provider kind.
	 *
	 * @return 'system'|'post_type'|'taxonomy'|''
	 */
	public static function kind( string $identity ): string {
		if ( \in_array( $identity, self::SYSTEM_NAMES, true ) ) {
			return 'system';
		}
		if ( self::has_valid_suffix( $identity, self::POST_TYPE_PREFIX ) ) {
			return 'post_type';
		}
		if ( self::has_valid_suffix( $identity, self::TAXONOMY_PREFIX ) ) {
			return 'taxonomy';
		}

		return '';
	}

	/**
	 * Return the system, post-type, or taxonomy name carried by an identity.
	 */
	public static function name( string $identity ): string {
		$kind = self::kind( $identity );
		if ( 'system' === $kind ) {
			$name = \array_search( $identity, self::SYSTEM_NAMES, true );
			return \is_string( $name ) ? $name : '';
		}
		if ( 'post_type' === $kind ) {
			return \substr( $identity, \strlen( self::POST_TYPE_PREFIX ) );
		}
		if ( 'taxonomy' === $kind ) {
			return \substr( $identity, \strlen( self::TAXONOMY_PREFIX ) );
		}

		return '';
	}

	/**
	 * Determine whether an identity names a particular system provider.
	 */
	public static function is_system( string $identity, string $name = '' ): bool {
		if ( 'system' !== self::kind( $identity ) ) {
			return false;
		}

		return '' === $name || self::system( $name ) === $identity;
	}

	/**
	 * Return the Discovery Center priority key for an identity.
	 */
	public static function priority_key( string $identity ): string {
		return $identity;
	}

	/**
	 * Return every currently registered public object identity for a raw slug.
	 *
	 * A two-item result is intentional: WordPress can register a post type and a
	 * taxonomy under the same name, and callers must not choose one by order.
	 *
	 * @return string[]
	 */
	public static function public_object_identities( string $name ): array {
		$name = self::normalize_object_name( $name );
		if ( '' === $name ) {
			return array();
		}

		$identities = array();
		if ( \Cybermaps\Core\PublicationPostTypes::contains( $name ) ) {
			$identities[] = self::post_type( $name );
		}

		$taxonomy = \get_taxonomy( $name );
		if ( \is_object( $taxonomy ) && ! empty( $taxonomy->public ) ) {
			$identities[] = self::taxonomy( $name );
		}

		return $identities;
	}

	/**
	 * Resolve a raw public object slug only when exactly one kind owns it.
	 */
	public static function unambiguous_public_object( string $name ): string {
		$identities = self::public_object_identities( $name );
		return 1 === \count( $identities ) ? $identities[0] : '';
	}

	/**
	 * News and miscellaneous publications have one canonical, unnumbered file.
	 */
	public static function is_single_page( string $identity ): bool {
		return self::NEWS === $identity || self::MISC === $identity;
	}

	/**
	 * Build the canonical public filename for a provider.
	 *
	 * Post types and taxonomies have explicit public namespaces so equal slugs
	 * remain separate. System filenames stay stable and human-readable.
	 *
	 * @param array{sitemap_url_base:string,news_sitemap_url_base:string,rss_sitemap_url_base:string} $routes
	 */
	public static function filename( string $identity, int $page, array $routes ): string {
		$page = \max( 1, $page );
		$base = (string) ( $routes['sitemap_url_base'] ?? '' );
		if ( '' === $base ) {
			return '';
		}
		$system = self::system_filename( $identity, $base, $page, $routes );
		return null !== $system ? $system : self::object_filename( $identity, $base, $page );
	}

	private static function system_filename( string $identity, string $base, int $page, array $routes ): ?string {
		if ( self::NEWS === $identity ) {
			$news_base = (string) ( $routes['news_sitemap_url_base'] ?? '' );
			return '' !== $news_base ? $news_base . '.xml' : '';
		}
		$names = array(
			self::MISC     => 'misc',
			self::AUTHORS  => 'authors',
			self::ARCHIVES => 'archives',
		);
		if ( ! isset( $names[ $identity ] ) ) {
			return null;
		}
		return self::MISC === $identity ? $base . '-misc.xml' : $base . '-' . $names[ $identity ] . '-' . $page . '.xml';
	}

	private static function object_filename( string $identity, string $base, int $page ): string {
		$name = self::name( $identity );
		$kind = self::kind( $identity );
		if ( '' === $name || ! \in_array( $kind, array( 'post_type', 'taxonomy' ), true ) ) {
			return '';
		}
		$prefix = 'post_type' === $kind ? 'posts' : 'taxonomies';
		return $base . '-' . $prefix . '-' . $name . '-' . $page . '.xml';
	}

	/**
	 * Verify a namespaced identity suffix without silently altering it.
	 */
	private static function has_valid_suffix( string $identity, string $prefix ): bool {
		if ( ! \str_starts_with( $identity, $prefix ) ) {
			return false;
		}

		$name = \substr( $identity, \strlen( $prefix ) );
		return '' !== $name && self::normalize_object_name( $name ) === $name;
	}

	/**
	 * Normalize a WordPress post-type or taxonomy name.
	 */
	private static function normalize_object_name( string $name ): string {
		$name = \sanitize_key( $name );
		return 1 === \preg_match( '/^[a-z0-9_-]+$/', $name ) ? $name : '';
	}
}
