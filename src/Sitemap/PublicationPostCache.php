<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Restore bounded object/metadata priming without caching failed SQL as empty. */
final class PublicationPostCache {
	private const META_BATCH_SIZE = 100;
	private const POST_COLUMNS    = array(
		'ID',
		'post_author',
		'post_date',
		'post_date_gmt',
		'post_content',
		'post_title',
		'post_excerpt',
		'post_status',
		'comment_status',
		'ping_status',
		'post_password',
		'post_name',
		'to_ping',
		'pinged',
		'post_modified',
		'post_modified_gmt',
		'post_content_filtered',
		'post_parent',
		'guid',
		'menu_order',
		'post_type',
		'post_mime_type',
		'comment_count',
	);

	/** Prime only the actual, full database rows belonging to this selection. */
	public static function prime( array $selected, \WP_Query $query ): void {
		$rows = self::database_rows( $selected, $query );
		if ( empty( $rows ) || wp_suspend_cache_addition() ) {
			return;
		}
		$metadata = $query->get( 'update_post_meta_cache' ) ? self::metadata( array_keys( $rows ) ) : array();
		// All metadata reads succeeded before installing even empty cache values.
		wp_cache_add_multiple( $metadata, 'post_meta' );
		$posts = array_values( $rows );
		update_post_cache( $posts );
	}

	/** Reject virtual, partial, broader, or unrelated results as cache provenance. */
	private static function database_rows( array $selected, \WP_Query $query ): array {
		global $wpdb;
		if ( ! isset( $wpdb->last_query, $wpdb->last_result, $query->request ) || $wpdb->last_query !== $query->request || ! is_array( $wpdb->last_result ) ) {
			return array();
		}
		if ( count( $selected ) !== count( $wpdb->last_result ) || count( $selected ) > max( 0, (int) $query->get( 'posts_per_page' ) ) ) {
			return array();
		}
		$rows = array();
		foreach ( $wpdb->last_result as $index => $row ) {
			if ( ! self::full_post_row( $row ) || ! isset( $selected[ $index ]->ID ) || (int) $row->ID <= 0 || (int) $row->ID !== (int) $selected[ $index ]->ID ) {
				return array();
			}
			$rows[ (int) $row->ID ] = clone $row;
		}
		return $rows;
	}

	/** A posts_fields filter may return a projection despite fields='all'. */
	private static function full_post_row( mixed $row ): bool {
		foreach ( self::POST_COLUMNS as $column ) {
			if ( ! isset( $row->$column ) ) {
				return false;
			}
		}
		return true;
	}

	/** Stage native metadata results with cache additions suspended throughout. */
	private static function metadata( array $ids ): array {
		$previous = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );
		try {
			$data = array();
			foreach ( array_chunk( $ids, self::META_BATCH_SIZE ) as $batch ) {
				$data += self::metadata_batch( $batch );
			}
			return $data;
		} finally {
			wp_suspend_cache_addition( $previous );
		}
	}

	/** Preserve existing cache keys and intentional metadata-filter short circuits. */
	private static function metadata_batch( array $ids ): array {
		$existing = wp_cache_get_multiple( $ids, 'post_meta' );
		PublicationQuery::reset_database_error();
		$data = update_meta_cache( 'post', $ids );
		// Core returns boolean values for intentional hook short circuits.
		PublicationQuery::assert_database_result( is_bool( $data ) ? array() : $data );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$missing = array();
		foreach ( $ids as $id ) {
			if ( false === ( $existing[ $id ] ?? false ) && isset( $data[ $id ] ) && is_array( $data[ $id ] ) ) {
				$missing[ $id ] = $data[ $id ];
			}
		}
		return $missing;
	}
}
