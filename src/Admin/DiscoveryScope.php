<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiscoveryScope {
	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box_data' ) );
	}

	/**
	 * Add meta box to all public post types.
	 */
	public function add_meta_box() {
		$post_types = \Cybermaps\Core\PublicationPostTypes::names();
		add_meta_box(
			'cybermaps_discovery_scope',
			__( 'Cybermaps: Discovery Scope', 'cybermaps' ),
			array( $this, 'render_meta_box' ),
			$post_types,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'cybermaps_discovery_scope_action', 'cybermaps_discovery_scope_nonce' );
		$exclude_sitemap = get_post_meta( $post->ID, '_cybermaps_exclude_sitemap', true );
		$exclude_ai      = get_post_meta( $post->ID, '_cybermaps_exclude_ai', true );
		$intent          = get_post_meta( $post->ID, '_cybermaps_intent_override', true );
		$priority        = (float) get_post_meta( $post->ID, '_cybermaps_sitemap_priority', true );
		$changefreq      = (string) get_post_meta( $post->ID, '_cybermaps_sitemap_changefreq', true );

		echo '<p><label><input type="checkbox" name="cybermaps_include_sitemap" value="1" ' . checked( '1' !== $exclude_sitemap, true, false ) . '> ' . esc_html__( 'Allow in XML sitemaps at item level', 'cybermaps' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="cybermaps_include_ai" value="1" ' . checked( '1' !== $exclude_ai, true, false ) . '> ' . esc_html__( 'Allow in AI discovery at item level', 'cybermaps' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'These controls remove only this item\'s exclusion. Content-group Publish and each publication channel\'s settings still determine eligibility.', 'cybermaps' ) . '</p>';

		echo '<p style="border-top: 1px solid #f0f0f1; padding-top: 10px; margin-top: 10px;"><label for="cybermaps_intent_override"><strong>' . esc_html__( 'Discovery intent override', 'cybermaps' ) . '</strong></label><br>';
		echo '<select id="cybermaps_intent_override" name="cybermaps_intent_override" style="width: 100%; margin-top: 5px;">';
		echo '<option value="" ' . selected( empty( $intent ), true, false ) . '>' . esc_html__( 'Use content-group default', 'cybermaps' ) . '</option>';
		echo '<option value="informational" ' . selected( $intent, 'informational', false ) . '>' . esc_html__( 'Informational', 'cybermaps' ) . '</option>';
		echo '<option value="transactional" ' . selected( $intent, 'transactional', false ) . '>' . esc_html__( 'Commercial', 'cybermaps' ) . '</option>';
		echo '</select></p>';

		echo '<p class="description">' . esc_html__( 'This item-level intent label is included in Cybermaps discovery output.', 'cybermaps' ) . '</p>';

		echo '<p><label for="cybermaps_sitemap_priority"><strong>' . esc_html__( 'Sitemap priority override', 'cybermaps' ) . '</strong></label><br>';
		echo '<select id="cybermaps_sitemap_priority" name="cybermaps_sitemap_priority" style="width: 100%; margin-top: 5px;">';
		echo '<option value="0" ' . selected( $priority, 0.0, false ) . '>' . esc_html__( 'Use content-group weight', 'cybermaps' ) . '</option>';
		for ( $step = 1; $step <= 10; ++$step ) {
			$value = $step / 10;
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $priority, $value, false ) . '>' . esc_html( number_format( $value, 1 ) ) . '</option>';
		}
		echo '</select></p>';

		$frequencies = array(
			''        => __( 'Default (weekly)', 'cybermaps' ),
			'always'  => __( 'Always', 'cybermaps' ),
			'hourly'  => __( 'Hourly', 'cybermaps' ),
			'daily'   => __( 'Daily', 'cybermaps' ),
			'weekly'  => __( 'Weekly', 'cybermaps' ),
			'monthly' => __( 'Monthly', 'cybermaps' ),
			'yearly'  => __( 'Yearly', 'cybermaps' ),
			'never'   => __( 'Never', 'cybermaps' ),
		);
		echo '<p><label for="cybermaps_sitemap_changefreq"><strong>' . esc_html__( 'Change frequency', 'cybermaps' ) . '</strong></label><br>';
		echo '<select id="cybermaps_sitemap_changefreq" name="cybermaps_sitemap_changefreq" style="width: 100%; margin-top: 5px;">';
		foreach ( $frequencies as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $changefreq, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * Save meta box data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_box_data( $post_id ) {
		if ( ! self::can_save_meta_box( (int) $post_id ) ) {
			return;
		}

		// Checkboxes are absent from the request when the item is excluded.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- can_save_meta_box() verifies the discovery-scope nonce.
		$include_sitemap = isset( $_POST['cybermaps_include_sitemap'] )
			&& is_scalar( $_POST['cybermaps_include_sitemap'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_POST['cybermaps_include_sitemap'] ) );
		$include_ai      = isset( $_POST['cybermaps_include_ai'] )
			&& is_scalar( $_POST['cybermaps_include_ai'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_POST['cybermaps_include_ai'] ) );
		$intent          = isset( $_POST['cybermaps_intent_override'] ) && is_scalar( $_POST['cybermaps_intent_override'] )
			? sanitize_key( wp_unslash( (string) $_POST['cybermaps_intent_override'] ) )
			: '';
		$priority        = isset( $_POST['cybermaps_sitemap_priority'] ) && is_scalar( $_POST['cybermaps_sitemap_priority'] )
			? \Cybermaps\Core\Plugin::sanitize_priority_meta(
				sanitize_text_field( wp_unslash( (string) $_POST['cybermaps_sitemap_priority'] ) )
			)
			: 0.0;
		$changefreq      = isset( $_POST['cybermaps_sitemap_changefreq'] ) && is_scalar( $_POST['cybermaps_sitemap_changefreq'] )
			? \Cybermaps\Core\Plugin::sanitize_changefreq_meta(
				sanitize_key( wp_unslash( (string) $_POST['cybermaps_sitemap_changefreq'] ) )
			)
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$exclude_sitemap = $include_sitemap ? '0' : '1';
		$exclude_ai      = $include_ai ? '0' : '1';
		if ( ! in_array( $intent, array( '', 'informational', 'transactional' ), true ) ) {
			$intent = '';
		}

		update_post_meta( $post_id, '_cybermaps_exclude_sitemap', $exclude_sitemap );
		delete_post_meta( $post_id, '_cybermaps_exclude_search' );
		update_post_meta( $post_id, '_cybermaps_exclude_ai', $exclude_ai );
		self::persist_overrides( (int) $post_id, $intent, (float) $priority, $changefreq );
	}

	/**
	 * Validate the save request before reading any discovery-scope fields.
	 */
	private static function can_save_meta_box( int $post_id ): bool {
		$nonce = isset( $_POST['cybermaps_discovery_scope_nonce'] ) && is_scalar( $_POST['cybermaps_discovery_scope_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['cybermaps_discovery_scope_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'cybermaps_discovery_scope_action' ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}
		$post = get_post( (int) $post_id );
		if (
			! is_object( $post )
			|| ! \Cybermaps\Core\PublicationPostTypes::contains(
				(string) ( $post->post_type ?? '' )
			)
		) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Persist optional overrides, deleting empty values exactly as before.
	 */
	private static function persist_overrides( int $post_id, string $intent, float $priority, string $changefreq ): void {
		if ( ! empty( $intent ) ) {
			update_post_meta( $post_id, '_cybermaps_intent_override', $intent );
		} else {
			delete_post_meta( $post_id, '_cybermaps_intent_override' );
		}
		if ( $priority > 0 ) {
			update_post_meta( $post_id, '_cybermaps_sitemap_priority', $priority );
		} else {
			delete_post_meta( $post_id, '_cybermaps_sitemap_priority' );
		}
		if ( '' !== $changefreq ) {
			update_post_meta( $post_id, '_cybermaps_sitemap_changefreq', $changefreq );
		} else {
			delete_post_meta( $post_id, '_cybermaps_sitemap_changefreq' );
		}
	}
}
