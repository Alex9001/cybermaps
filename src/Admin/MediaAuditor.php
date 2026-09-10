<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MediaAuditor {
	public function register_hooks() {
		add_action( 'wp_ajax_cybermaps_get_sync_stats', array( $this, 'get_sync_stats' ) );
		add_action( 'wp_ajax_cybermaps_process_sync_batch', array( $this, 'process_sync_batch' ) );
	}

	public function get_sync_stats() {
		global $wpdb;
		self::require_ajax_access();

		$post_types = $this->get_included_post_types();
		$settings   = \Cybermaps\Core\ConfigurationStore::settings();
		$intensity  = self::normalize_intensity(
			$settings['media_discovery_intensity'] ?? 'none'
		);

		if ( 'none' === $intensity ) {
			wp_send_json_error(
				array(
					'message' => __( 'Media discovery is disabled. Save Standard or Advanced mode before starting a rescan.', 'cybermaps' ),
				),
				409
			);
		}

		if ( empty( $post_types ) ) {
			wp_send_json_success(
				array(
					'total'   => 0,
					'audited' => 0,
				)
			);
		}

		$post_types_placeholder = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The explicit rescan request needs a current total; caching could report obsolete progress.
		$total_result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM %i WHERE post_type IN ($post_types_placeholder) AND post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder is generated for each sanitized post type.
				array_merge( array( $wpdb->posts ), $post_types )
			)
		);

		if ( null === $total_result || false === $total_result ) {
			self::send_database_error();
			return;
		}
		$total = max( 0, (int) $total_result );

		wp_send_json_success(
			array(
				'total'   => $total,
				'audited' => 0,
			)
		);
	}

	public function process_sync_batch() {
		global $wpdb;
		self::require_ajax_access();

		$post_types = $this->get_included_post_types();
		$settings   = \Cybermaps\Core\ConfigurationStore::settings();
		$intensity  = self::normalize_intensity(
			$settings['media_discovery_intensity'] ?? 'none'
		);
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by require_ajax_access().
		$cursor = isset( $_POST['cursor'] ) && is_scalar( $_POST['cursor'] )
			? absint( wp_unslash( (string) $_POST['cursor'] ) )
			: 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'none' === $intensity ) {
			wp_send_json_error(
				array(
					'message' => __( 'Media discovery is disabled. Save Standard or Advanced mode before starting a rescan.', 'cybermaps' ),
				),
				409
			);
		}

		if ( empty( $post_types ) ) {
			wp_send_json_success(
				array(
					'processed'   => 0,
					'next_cursor' => $cursor,
					'done'        => true,
				)
			);
		}

		$post_types_placeholder = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$query_args             = array_merge( array( $wpdb->posts ), $post_types, array( $cursor ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Every bounded cursor page is consumed once; caching unique pages would add stale state without reusable work.
		$ids = $wpdb->get_col(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The argument array supplies the Core identifier, one value per sanitized post type, and the cursor.
			$wpdb->prepare(
				"SELECT p.ID FROM %i p
					WHERE p.post_type IN ($post_types_placeholder) AND p.post_status = 'publish'
					AND p.ID > %d
					ORDER BY p.ID ASC
					LIMIT 50",
				$query_args
			)
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		);

		if ( ! is_array( $ids ) ) {
			self::send_database_error();
			return;
		}

		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'processed'   => 0,
					'next_cursor' => $cursor,
					'done'        => true,
				)
			);
		}

		$scanner = new \Cybermaps\Sitemap\MediaScanner();
		foreach ( $ids as $post_id ) {
			$scanner->run_audit( (int) $post_id, $intensity );
		}

		wp_send_json_success(
			array(
				'processed'   => count( $ids ),
				'next_cursor' => (int) end( $ids ),
				'done'        => count( $ids ) < 50,
			)
		);
	}

	/**
	 * Enforce the shared mutation request contract for media rescan AJAX calls.
	 */
	private static function require_ajax_access(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid request method.', 'cybermaps' ) ),
				405
			);
		}
		check_ajax_referer( 'cybermaps_media_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'cybermaps' ) ), 403 );
		}
	}

	/**
	 * Return a safe database failure without exposing SQL or server details.
	 */
	private static function send_database_error(): void {
		wp_send_json_error(
			array(
				'message' => __( 'The media audit database query failed. Check database health and try again.', 'cybermaps' ),
			),
			500
		);
	}

	/**
	 * Accept only the two enabled scan modes; malformed values fail closed.
	 */
	private static function normalize_intensity( mixed $value ): string {
		$intensity = is_scalar( $value ) ? sanitize_key( (string) $value ) : 'none';
		return in_array( $intensity, array( 'standard', 'advanced' ), true )
			? $intensity
			: 'none';
	}

	private function get_included_post_types() {
		return array_values(
			array_filter(
				\Cybermaps\Core\PublicationPostTypes::names(),
				static fn ( string $post_type ): bool => \Cybermaps\Sitemap\PriorityEngine::calculate(
					\Cybermaps\Sitemap\ProviderIdentity::post_type( $post_type )
				) > 0
			)
		);
	}
}
