<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a bounded snapshot of published content without OFFSET pagination.
 */
final class PublishedPostSource {
	private const BATCH_SIZE = 100;

	/**
	 * Yield published posts in stable ID order.
	 *
	 * The maximum ID is captured before iteration. Content published during a
	 * report is therefore left for the next report, and deleted or unpublished
	 * rows cannot shift a later page past an unprocessed ID.
	 *
	 * @param array<int,string> $post_types Public post types to inspect.
	 * @return \Generator<int,array<int,object>>
	 */
	public function batches( array $post_types ): \Generator {
		global $wpdb;
		$post_types = $this->normalize_post_types( $post_types );
		if ( empty( $post_types ) ) {
			return;
		}

		$maximum_id = $this->maximum_id( $post_types );
		$last_id    = 0;

		while ( $last_id < $maximum_id ) {
			$ids = $this->next_ids( $post_types, $last_id, $maximum_id );
			if ( empty( $ids ) ) {
				break;
			}

			$last_id = max( $ids );
				yield $this->load_batch( $post_types, $ids );

			if ( count( $ids ) < self::BATCH_SIZE ) {
				break;
			}
		}
	}

	/** @param array<int,string> $post_types @return array<int,string> */
	private function normalize_post_types( array $post_types ): array {
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );
	}

	/** @param array<int,string> $post_types @param array<int,int> $ids @return array<int,object> */
	private function load_batch( array $post_types, array $ids ): array {
		global $wpdb;
		$wpdb->last_error = '';
		$posts            = get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'post__in'               => $ids,
				'posts_per_page'         => count( $ids ),
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			)
		);
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not load a content report batch.', 'cybermaps' ) );
		}

		$by_id = array();
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$by_id[ (int) $post->ID ] = $post;
			}
		}
		$batch = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$batch[] = $by_id[ $id ];
			}
		}
		return $batch;
	}

	/**
	 * Resolve attached images once for a whole report batch.
	 *
	 * Calling get_attached_media() from the evaluator for every resource would
	 * add one unbounded query per post. Report batches are at most 100 items, so
	 * one distinct-parent lookup preserves the same literal attachment signal
	 * while keeping database work bounded.
	 *
	 * @param array<int,object> $posts Report batch.
	 * @return array<int,true> Post IDs with at least one non-trashed image attachment.
	 */
	public function attached_image_parent_ids( array $posts ): array {
		global $wpdb;
		$post_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $post ): int => is_object( $post )
							? max( 0, (int) ( $post->ID ?? 0 ) )
							: 0,
						$posts
					)
				)
			)
		);
		if ( empty( $post_ids ) ) {
			return array();
		}

		$placeholders       = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$image_mime_pattern = (
			method_exists( $wpdb, 'esc_like' )
				? $wpdb->esc_like( 'image/' )
				: addcslashes( 'image/', '_%\\' )
		) . '%';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The variadic list supplies the posts-table identifier, MIME pattern, and one integer per bounded post ID.
		$query = $wpdb->prepare(
			"SELECT DISTINCT post_parent
			FROM %i
			WHERE post_type = 'attachment'
			AND post_status <> 'trash'
			AND post_mime_type LIKE %s
			AND post_parent IN ({$placeholders})",
			...array_merge( array( $wpdb->posts, $image_mime_pattern ), $post_ids )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The complete bounded query is prepared immediately above and must read the persisted attachment relationships.
		$parent_ids = $wpdb->get_col( $query );
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not load attached-image measurements for the content report.', 'cybermaps' ) );
		}

		return array_fill_keys(
			array_values(
				array_filter(
					array_map( 'absint', is_array( $parent_ids ) ? $parent_ids : array() )
				)
			),
			true
		);
	}

	/**
	 * @param array<int,string> $post_types Public post types.
	 */
	private function maximum_id( array $post_types ): int {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The variadic list supplies the posts-table identifier and one value per sanitized post type.
		$query = $wpdb->prepare(
			"SELECT MAX(ID) FROM %i
			WHERE post_status = 'publish'
			AND post_type IN ({$placeholders})",
			...array_merge( array( $wpdb->posts ), $post_types )
		);

		$wpdb->last_error = '';
		$maximum_id       = max( 0, (int) $wpdb->get_var( $query ) );
		// phpcs:enable
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not establish the content report boundary.', 'cybermaps' ) );
		}
		return $maximum_id;
	}

	/**
	 * @param array<int,string> $post_types Public post types.
	 * @return array<int,int>
	 */
	private function next_ids( array $post_types, int $last_id, int $maximum_id ): array {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args         = array_merge( array( $wpdb->posts, $last_id, $maximum_id ), $post_types, array( self::BATCH_SIZE ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The variadic list supplies the posts-table identifier, cursor values, one value per sanitized post type, and the limit.
		$query = $wpdb->prepare(
			"SELECT ID FROM %i
			WHERE ID > %d
			AND ID <= %d
			AND post_status = 'publish'
			AND post_type IN ({$placeholders})
			ORDER BY ID ASC
			LIMIT %d",
			...$args
		);

		$wpdb->last_error = '';
		$ids              = $wpdb->get_col( $query );
		// phpcs:enable
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not read the next content report batch.', 'cybermaps' ) );
		}
		return array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
	}
}
