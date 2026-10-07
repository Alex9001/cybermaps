<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

use Cybermaps\Core\BuildUnavailableException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative publication reads must distinguish SQL failure from empty data.
 *
 * WordPress can cache empty results after a failed SELECT. These bounded reads
 * bypass its query-result caches and inspect the candidate error before result
 * filters or post hydration can replace it with a later query's outcome.
 */
final class PublicationQuery {
	private const MARKER = 'cybermaps_publication_query';
	private string $marker;
	private bool $observed = false;

	private function __construct() {
		$this->marker = 'publication-' . spl_object_id( $this );
	}

	/** Run one bounded full-row post selection, preserving ordinary filters. */
	public static function posts( array $args ): array {
		$guard                    = new self();
		$args[ self::MARKER ]     = $guard->marker;
		$args['cache_results']    = false;
		$args['no_found_rows']    = true;
		$args['suppress_filters'] = false;
		$args['fields']           = 'all';
		self::reset_database_error();
		add_filter( 'pre_get_posts', array( $guard, 'prepare_posts' ), PHP_INT_MAX );
		add_filter( 'posts_request', array( $guard, 'before_posts' ), PHP_INT_MAX, 2 );
		add_filter( 'split_the_query', array( $guard, 'split_posts' ), PHP_INT_MAX, 2 );
		add_filter( 'posts_results', array( $guard, 'after_posts' ), PHP_INT_MIN, 2 );
		try {
			$query = new \WP_Query( $args );
			if ( ! $guard->observed ) {
				self::assert_database_result( $query->posts );
			}
			if ( ! is_array( $query->posts ) ) {
				self::assert_database_result( false );
			}
			return $query->posts;
		} finally {
			remove_filter( 'pre_get_posts', array( $guard, 'prepare_posts' ), PHP_INT_MAX );
			remove_filter( 'posts_request', array( $guard, 'before_posts' ), PHP_INT_MAX );
			remove_filter( 'split_the_query', array( $guard, 'split_posts' ), PHP_INT_MAX );
			remove_filter( 'posts_results', array( $guard, 'after_posts' ), PHP_INT_MIN );
		}
	}

	/** Run a term selection without caching an error-derived empty result. */
	public static function terms( array $args ): array {
		$rows = self::term_result( $args, false );
		if ( ! is_array( $rows ) ) {
			self::assert_database_result( false );
		}
		return $rows;
	}

	/** Count terms without caching failed SQL as a successful zero. */
	public static function count_terms( array $args ): int {
		$count = self::term_result( $args, true );
		if ( ! is_numeric( $count ) ) {
			self::assert_database_result( false );
		}
		return max( 0, (int) $count );
	}

	/** Share the scoped term-query cache and error guards for rows and counts. */
	private static function term_result( array $args, bool $count ): mixed {
		$guard                 = new self();
		$args[ self::MARKER ]  = $guard->marker;
		$args['cache_results'] = false;
		self::reset_database_error();
		add_filter( 'get_terms_args', array( $guard, 'prepare_terms' ), PHP_INT_MAX );
		add_filter( 'get_terms', array( $guard, 'after_terms' ), PHP_INT_MIN, 3 );
		try {
			$rows = $count ? wp_count_terms( $args ) : get_terms( $args );
			if ( ! $guard->observed ) {
				self::assert_database_result( $rows );
			}
			return $rows;
		} finally {
			remove_filter( 'get_terms_args', array( $guard, 'prepare_terms' ), PHP_INT_MAX );
			remove_filter( 'get_terms', array( $guard, 'after_terms' ), PHP_INT_MIN );
		}
	}

	/** Reset only the error belonging to the read about to start. */
	public static function reset_database_error(): void {
		global $wpdb;
		if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
	}

	/** Validate immediately, before caching or doing any other database work. */
	public static function assert_database_result( mixed $result, bool $allow_null = false ): void {
		global $wpdb;
		if ( ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) || is_wp_error( $result ) || false === $result || ( null === $result && ! $allow_null ) ) {
			throw new BuildUnavailableException( esc_html__( 'Cybermaps could not read publication candidates safely. Please retry later.', 'cybermaps' ) );
		}
	}

	/** Keep authoritative selection flags after ordinary query setup filters. */
	public function prepare_posts( \WP_Query $query ): void {
		if ( $query->get( self::MARKER ) === $this->marker ) {
			$query->set( 'cache_results', false );
			$query->set( 'no_found_rows', true );
			$query->set( 'suppress_filters', false );
			$query->set( 'fields', 'all' );
		}
	}

	/** Scope the request reset to this selection, leaving nested queries alone. */
	public function before_posts( string $sql, \WP_Query $query ): string {
		if ( $query->get( self::MARKER ) === $this->marker ) {
			self::reset_database_error();
		}
		return $sql;
	}

	/** Avoid a second hydration SELECT replacing the candidate SELECT outcome. */
	public function split_posts( bool $split, \WP_Query $query ): bool {
		return $query->get( self::MARKER ) === $this->marker ? false : $split;
	}

	/** Capture the selection outcome before later result filters run. */
	public function after_posts( mixed $rows, \WP_Query $query ): mixed {
		if ( $query->get( self::MARKER ) === $this->marker ) {
			self::assert_database_result( $rows );
			$this->observed = true;
		}
		return $rows;
	}

	/** Keep authoritative term selection out of the native query-result cache. */
	public function prepare_terms( array $args ): array {
		if ( ( $args[ self::MARKER ] ?? '' ) === $this->marker ) {
			$args['cache_results'] = false;
			self::reset_database_error();
		}
		return $args;
	}

	/** Capture term SQL failure before ordinary term-result filters run. */
	public function after_terms( mixed $rows, array $taxonomies, array $args ): mixed {
		if ( ( $args[ self::MARKER ] ?? '' ) === $this->marker ) {
			self::assert_database_result( $rows );
			$this->observed = true;
		}
		return $rows;
	}
}
