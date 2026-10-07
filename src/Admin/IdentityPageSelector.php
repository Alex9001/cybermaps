<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded public-page selection for automatic identity catalogs. */
final class IdentityPageSelector {
	public const PAGE_SIZE       = 20;
	public const MAX_QUERY_BYTES = 200;

	public static function enqueue(): void {
		wp_enqueue_script( 'cybermaps-identity-pages', CYBERMAPS_PLUGIN_URL . 'assets/js/identity-page-selector.js', array(), CYBERMAPS_VERSION, true );
		wp_localize_script(
			'cybermaps-identity-pages',
			'cybermapsIdentityPages',
			array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cybermaps_identity_pages' ),
				'error' => __( 'Page search failed. Please try again.', 'cybermaps' ),
				'empty' => __( 'No matching public pages.', 'cybermaps' ),
				'found' => __( 'Choose a page from the matching results.', 'cybermaps' ),
				'query' => __( 'Enter at least two characters to search public pages.', 'cybermaps' ),
			)
		);
	}

	/** Render only the current selection. The hidden repeater template performs no query. */
	public static function render( string $name, string $id, int $selected ): void {
		$post   = $selected > 0 ? get_post( $selected ) : null;
		$public = is_object( $post ) && 'page' === $post->post_type && 'publish' === $post->post_status && '' === (string) $post->post_password;
		/* translators: %d: saved page identifier that is no longer publicly selectable. */
		$unavailable = sprintf( __( 'Page #%d (unavailable)', 'cybermaps' ), $selected );
		?>
		<div class="cybermaps-page-selector">
			<input type="search" class="cybermaps-page-query" id="<?php echo esc_attr( $id . '-query' ); ?>" maxlength="100" aria-label="<?php esc_attr_e( 'Search public pages by title', 'cybermaps' ); ?>">
			<button type="button" class="button cybermaps-page-search"><?php esc_html_e( 'Search pages', 'cybermaps' ); ?></button>
			<button type="button" class="button cybermaps-page-next" hidden><?php esc_html_e( 'Next results', 'cybermaps' ); ?></button>
			<select class="catalog-parent-select" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>">
				<option value="0"><?php esc_html_e( 'Select a parent page...', 'cybermaps' ); ?></option>
				<?php if ( $selected > 0 ) : ?>
					<option value="<?php echo esc_attr( (string) $selected ); ?>" selected><?php echo esc_html( $public ? $post->post_title : $unavailable ); ?></option>
				<?php endif; ?>
			</select>
			<span class="cybermaps-page-feedback" role="status" aria-live="polite"></span>
		</div>
		<?php
	}

	/** Authorize before reading the bounded search payload. */
	public function ajax_search(): void {
		// Project only the exact method comparison; never normalize an invalid verb into POST.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! boolval( 'POST' === $_SERVER['REQUEST_METHOD'] ) ) {
			wp_send_json_error( array( 'message' => __( 'POST required.', 'cybermaps' ) ), 405 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not authorized.', 'cybermaps' ) ), 403 );
		}
		$request = self::search_request();
		$query   = $request['query'];
		$cursor  = $request['cursor'];
		$result  = $this->search( $query, $cursor );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 'identity_page_query' === $result->get_error_code() ? 400 : 500 );
		}
		wp_send_json_success( $result );
	}

	/** @return array{query:string,cursor:int} Nonce-checked bounded search fields. */
	private static function search_request(): array {
		check_ajax_referer( 'cybermaps_identity_pages', 'nonce' );
		// Character-index existence enforces the original slashed byte ceiling.
		$query  = isset( $_POST['query'] ) && is_string( $_POST['query'] ) && ! isset( $_POST['query'][ self::MAX_QUERY_BYTES ] )
			? sanitize_text_field( wp_unslash( $_POST['query'] ) )
			: '';
		$digits = isset( $_POST['cursor'] ) && is_string( $_POST['cursor'] ) && ! isset( $_POST['cursor'][18] ) ? sanitize_key( $_POST['cursor'] ) : '';
		// A sanitizer may remove punctuation. Accept only an unchanged raw string,
		// then require the original digit grammar before numeric conversion.
		$cursor = isset( $_POST['cursor'] ) && boolval( $digits === $_POST['cursor'] ) ? self::parse_cursor( $digits ) : -1;
		return array(
			'query'  => $query,
			'cursor' => $cursor,
		);
	}

	private static function parse_cursor( string $digits ): int {
		return 1 === preg_match( '/^[0-9]{1,18}$/D', $digits ) ? (int) $digits : -1;
	}

	/** @return array<string,mixed>|\WP_Error Bounded keyset results or a truthful failure. */
	public function search( string $query, int $cursor ): array|\WP_Error {
		if ( strlen( $query ) < 2 || strlen( $query ) > self::MAX_QUERY_BYTES || $cursor < 0 ) {
			return new \WP_Error( 'identity_page_query', __( 'Enter a shorter title search of at least two characters.', 'cybermaps' ) );
		}
		global $wpdb;
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded live administrator page search must reflect current publication status.
			$wpdb->prepare(
				'SELECT ID, post_title FROM %i WHERE post_type = %s AND post_status = %s AND post_password = %s AND ID > %d AND post_title LIKE %s ORDER BY ID ASC LIMIT %d',
				$wpdb->posts,
				'page',
				'publish',
				'',
				$cursor,
				'%' . $wpdb->esc_like( $query ) . '%',
				self::PAGE_SIZE + 1
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'identity_page_database', __( 'Page search is unavailable. Please try again.', 'cybermaps' ) );
		}
		$more  = count( $rows ) > self::PAGE_SIZE;
		$pages = array();
		foreach ( array_slice( $rows, 0, self::PAGE_SIZE ) as $row ) {
			$pages[] = array(
				'id'    => (int) $row['ID'],
				'title' => (string) $row['post_title'],
			);
		}
		return array(
			'pages'       => $pages,
			'has_more'    => $more,
			'next_cursor' => empty( $pages ) ? $cursor : $pages[ count( $pages ) - 1 ]['id'],
		);
	}
}
