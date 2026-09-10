<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable description of a public WordPress resource.
 */
final class SeoContext {
	public const POST              = 'post';
	public const TERM              = 'term';
	public const AUTHOR            = 'author';
	public const HOME              = 'home';
	public const DATE_ARCHIVE      = 'date_archive';
	public const POST_TYPE_ARCHIVE = 'post_type_archive';

	/**
	 * @param string $type      Context type.
	 * @param int    $object_id WordPress object ID, when applicable.
	 * @param string $subtype   Post type or taxonomy.
	 * @param string $url       Public source URL before headless rewriting.
	 * @param mixed  $object    Optional hydrated WordPress object.
	 * @param int    $year      Archive year.
	 * @param int    $month     Archive month.
	 */
	public function __construct(
		public readonly string $type,
		public readonly int $object_id = 0,
		public readonly string $subtype = '',
		public readonly string $url = '',
		public readonly mixed $object = null, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Public context constructor signature is retained for named-argument compatibility.
		public readonly int $year = 0,
		public readonly int $month = 0
	) {}

	/**
	 * Build a singular post context.
	 *
	 * @param object|int $post Post object or ID.
	 */
	public static function post( object|int $post ): self {
		$object = is_object( $post ) ? $post : get_post( $post );
		$id     = is_object( $object ) && isset( $object->ID ) ? (int) $object->ID : (int) $post;
		$type   = is_object( $object ) && isset( $object->post_type ) ? (string) $object->post_type : '';
		$url    = $id > 0 ? (string) get_permalink( $object ?? $id ) : '';

		return new self( self::POST, $id, $type, $url, $object );
	}

	/**
	 * Build a taxonomy-term context.
	 *
	 * @param object|int $term     Term object or ID.
	 * @param string     $taxonomy Taxonomy when only an ID is supplied.
	 */
	public static function term( object|int $term, string $taxonomy = '' ): self {
		$object = is_object( $term ) ? $term : null;
		$id     = is_object( $object ) && isset( $object->term_id ) ? (int) $object->term_id : (int) $term;
		$type   = is_object( $object ) && isset( $object->taxonomy ) ? (string) $object->taxonomy : $taxonomy;
		$link   = $id > 0 ? get_term_link( $id, $type ) : '';
		$url    = is_string( $link ) ? $link : '';

		return new self( self::TERM, $id, $type, $url, $object );
	}

	/**
	 * Build an author archive context.
	 */
	public static function author( int $user_id ): self {
		$url = $user_id > 0 ? (string) get_author_posts_url( $user_id ) : '';
		return new self( self::AUTHOR, $user_id, 'author', $url );
	}

	/**
	 * Build the public home context.
	 */
	public static function home(): self {
		return new self( self::HOME, 0, 'home', (string) home_url( '/' ) );
	}

	/**
	 * Build a monthly date archive context.
	 */
	public static function date_archive( int $year, int $month ): self {
		$url = (string) get_month_link( $year, $month );
		return new self( self::DATE_ARCHIVE, 0, 'month', $url, null, $year, $month );
	}

	/**
	 * Build a post-type archive context.
	 */
	public static function post_type_archive( string $post_type ): self {
		$link = function_exists( 'get_post_type_archive_link' ) ? get_post_type_archive_link( $post_type ) : '';
		return new self(
			self::POST_TYPE_ARCHIVE,
			0,
			$post_type,
			is_string( $link ) ? $link : ''
		);
	}

	/**
	 * Stable key for diagnostics and audit history.
	 */
	public function resource_key(): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

		return match ( $this->type ) {
			self::POST => sprintf( 'post:%d:%d', $blog_id, $this->object_id ),
			self::TERM => sprintf( 'term:%d:%s:%d', $blog_id, $this->subtype, $this->object_id ),
			self::AUTHOR => sprintf( 'author:%d:%d', $blog_id, $this->object_id ),
			self::DATE_ARCHIVE => sprintf( 'date:%d:%04d-%02d', $blog_id, $this->year, $this->month ),
			self::POST_TYPE_ARCHIVE => sprintf( 'archive:%d:%s', $blog_id, $this->subtype ),
			default => sprintf( 'home:%d', $blog_id ),
		};
	}
}
