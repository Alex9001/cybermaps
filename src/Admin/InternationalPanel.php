<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InternationalPanel {
	private \Cybermaps\Core\TranslationRegistry $registry;

	public function __construct( ?\Cybermaps\Core\TranslationRegistry $registry = null ) {
		$this->registry = $registry ?? new \Cybermaps\Core\TranslationRegistry();
	}

	/**
	 * Register hooks for the international panel.
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box_data' ) );
		add_action( 'rest_api_init', array( $this, 'register_editor_route' ) );
	}

	/**
	 * Register the capability-protected block-editor relationship action.
	 */
	public function register_editor_route(): void {
		register_rest_route(
			\Cybermaps\Core\EndpointRegistry::REST_NAMESPACE,
			'/editor/translation/(?P<post_id>[1-9][0-9]*)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_editor_update' ),
				'permission_callback' => static function ( $request ): bool {
					$post_id = is_object( $request ) && method_exists( $request, 'get_param' )
						? absint( $request->get_param( 'post_id' ) )
						: 0;
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
				},
				'args'                => array(
					'action'   => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'assign', 'create', 'unlink', 'resume' ),
					),
					'group_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Add the meta box to supported post types.
	 */
	public function add_meta_box() {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_translation_integrations'] ) ) {
			return;
		}

		$post_types = \Cybermaps\Core\PublicationPostTypes::names();
		add_meta_box(
			'cybermaps_international_panel',
			__( 'Cybermaps International', 'cybermaps' ),
			array( $this, 'render_meta_box' ),
			$post_types,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Return current relationship state for the iframe-safe document panel.
	 *
	 * @return array{group_id:int,sync_paused:bool}
	 */
	public function get_editor_state( int $post_id ): array {
		$translations = $this->registry->get_translations( get_current_blog_id(), $post_id, 'post' );
		$group_id     = ! empty( $translations ) ? (int) ( $translations[0]['group_id'] ?? 0 ) : 0;

		return array(
			'group_id'    => max( 0, $group_id ),
			'sync_paused' => '1' === (string) get_post_meta(
				$post_id,
				\Cybermaps\Integration\TranslationManager::SYNC_DISABLED_META,
				true
			),
		);
	}

	/**
	 * Apply a document-panel relationship action immediately and return its state.
	 */
	public function handle_editor_update( $request ): \WP_REST_Response|\WP_Error {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_translation_integrations'] ) ) {
			return new \WP_Error(
				'cybermaps_translation_disabled',
				__( 'Translation integrations are disabled.', 'cybermaps' ),
				array( 'status' => 404 )
			);
		}

		$post_id  = is_object( $request ) && method_exists( $request, 'get_param' )
			? absint( $request->get_param( 'post_id' ) )
			: 0;
		$action   = is_object( $request ) && method_exists( $request, 'get_param' )
			? sanitize_key( (string) $request->get_param( 'action' ) )
			: '';
		$group_id = is_object( $request ) && method_exists( $request, 'get_param' )
			? absint( $request->get_param( 'group_id' ) )
			: 0;
		$post     = get_post( $post_id );
		if (
			$post_id < 1
			|| ! $post instanceof \WP_Post
			|| ! \Cybermaps\Core\PublicationPostTypes::contains( (string) $post->post_type )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return new \WP_Error( 'cybermaps_translation_forbidden', __( 'This post cannot be updated through the translation panel.', 'cybermaps' ), array( 'status' => 403 ) );
		}
		if ( ! $this->apply_relationship_action( $post_id, $action, $group_id ) ) {
			return new \WP_Error( 'cybermaps_translation_invalid', __( 'The translation relationship action could not be applied.', 'cybermaps' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( $this->get_editor_state( $post_id ) );
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'cybermaps_international_save', 'cybermaps_international_nonce' );

		$translations = $this->registry->get_translations( get_current_blog_id(), $post->ID, 'post' );
		$group_id     = ! empty( $translations ) ? $translations[0]['group_id'] : '';
		$sync_paused  = '1' === (string) get_post_meta(
			(int) $post->ID,
			\Cybermaps\Integration\TranslationManager::SYNC_DISABLED_META,
			true
		);

		echo '<p><label for="cybermaps_group_id"><strong>' . esc_html__( 'Translation group ID', 'cybermaps' ) . '</strong></label></p>';
		echo '<input type="number" min="1" id="cybermaps_group_id" name="cybermaps_group_id" value="' . esc_attr( $group_id ) . '" style="width: 100%;" placeholder="' . esc_attr__( 'Enter an existing group ID', 'cybermaps' ) . '">';
		if ( $group_id ) {
			echo '<p><label><input type="checkbox" name="cybermaps_unlink_translation" value="1"> ' . esc_html__( 'Remove this post and pause automatic translation sync', 'cybermaps' ) . '</label></p>';
		} elseif ( $sync_paused ) {
			echo '<p><label><input type="checkbox" name="cybermaps_resume_translation_sync" value="1"> ' . esc_html__( 'Resume automatic translation sync', 'cybermaps' ) . '</label></p>';
		} else {
			echo '<p><label><input type="checkbox" name="cybermaps_create_translation_group" value="1"> ' . esc_html__( 'Create a new translation group for this post', 'cybermaps' ) . '</label></p>';
		}
		echo '<p class="description">' . esc_html__( 'To connect translations across sites, create a group on the original post and enter its displayed ID on each translated post.', 'cybermaps' ) . '</p>';
		if ( $sync_paused ) {
			echo '<p class="description">' . esc_html__( 'Automatic WPML or Polylang grouping is paused for this post until you resume it or assign a group manually.', 'cybermaps' ) . '</p>';
		}
	}

	/**
	 * Save the meta box data.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_box_data( $post_id ) {
		$settings = $this->save_settings_if_allowed( (int) $post_id );
		if ( null === $settings ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by save_settings_if_allowed().
		$group_id = isset( $_POST['cybermaps_group_id'] ) && is_scalar( $_POST['cybermaps_group_id'] )
			? absint( wp_unslash( (string) $_POST['cybermaps_group_id'] ) )
			: 0;
		$unlink   = isset( $_POST['cybermaps_unlink_translation'] )
			&& is_scalar( $_POST['cybermaps_unlink_translation'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_POST['cybermaps_unlink_translation'] ) );
		$create   = isset( $_POST['cybermaps_create_translation_group'] )
			&& is_scalar( $_POST['cybermaps_create_translation_group'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_POST['cybermaps_create_translation_group'] ) );
		$resume   = isset( $_POST['cybermaps_resume_translation_sync'] )
			&& is_scalar( $_POST['cybermaps_resume_translation_sync'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_POST['cybermaps_resume_translation_sync'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$lang   = self::configured_language( $settings );
		$action = self::relationship_action( $group_id, $unlink, $create, $resume );

		$this->apply_relationship_action( (int) $post_id, $action, $group_id, $lang );
	}

	/**
	 * Validate classic-editor save preconditions and return the settings snapshot.
	 *
	 * @return array<string, mixed>|null
	 */
	private function save_settings_if_allowed( int $post_id ): ?array {
		$nonce = isset( $_POST['cybermaps_international_nonce'] ) && is_scalar( $_POST['cybermaps_international_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['cybermaps_international_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'cybermaps_international_save' ) ) {
			return null;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return null;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return null;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! \Cybermaps\Core\PublicationPostTypes::contains( (string) $post->post_type ) ) {
			return null;
		}
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		return empty( $settings['enable_translation_integrations'] ) ? null : $settings;
	}

	/**
	 * Resolve the canonical language from a settings snapshot and site fallback.
	 *
	 * @param array<string, mixed> $settings General settings.
	 */
	private static function configured_language( array $settings ): string {
		$configured_language = $settings['site_language'] ?? '';
		$lang                = \Cybermaps\Core\TranslationHelper::normalize_hreflang(
			is_scalar( $configured_language ) ? (string) $configured_language : ''
		);
		if ( '' === $lang ) {
			$lang = \Cybermaps\Core\TranslationHelper::normalize_hreflang(
				(string) get_bloginfo( 'language' )
			);
		}
		return $lang;
	}

	/**
	 * Preserve the classic-editor action precedence in an explicit dispatcher.
	 */
	private static function relationship_action( int $group_id, bool $unlink, bool $create, bool $resume ): string {
		if ( $unlink ) {
			return 'unlink';
		}
		if ( $create ) {
			return 'create';
		}
		if ( $resume ) {
			return 'resume';
		}
		return $group_id > 0 ? 'assign' : '';
	}

	/**
	 * Apply one normalized relationship action for classic or block editors.
	 */
	private function apply_relationship_action( int $post_id, string $action, int $group_id = 0, string $lang = '' ): bool {
		if ( '' === $lang ) {
			$settings            = \Cybermaps\Core\ConfigurationStore::settings();
			$configured_language = $settings['site_language'] ?? '';
			$lang                = \Cybermaps\Core\TranslationHelper::normalize_hreflang(
				is_scalar( $configured_language ) ? (string) $configured_language : ''
			);
			if ( '' === $lang ) {
				$lang = \Cybermaps\Core\TranslationHelper::normalize_hreflang( (string) get_bloginfo( 'language' ) );
			}
		}

		if ( 'unlink' === $action ) {
			update_post_meta(
				$post_id,
				\Cybermaps\Integration\TranslationManager::SYNC_DISABLED_META,
				'1'
			);
			$this->registry->delete_relationship( get_current_blog_id(), $post_id, 'post' );
			return true;
		}
		if ( in_array( $action, array( 'assign', 'create' ), true ) && '' !== $lang && ( 'create' === $action || $group_id > 0 ) ) {
			delete_post_meta(
				$post_id,
				\Cybermaps\Integration\TranslationManager::SYNC_DISABLED_META
			);
			$this->registry->update_relationship( $group_id, get_current_blog_id(), $post_id, $lang, 'post' );
			return true;
		}
		if ( 'resume' === $action ) {
			delete_post_meta(
				$post_id,
				\Cybermaps\Integration\TranslationManager::SYNC_DISABLED_META
			);
			return true;
		}

		return false;
	}
}
