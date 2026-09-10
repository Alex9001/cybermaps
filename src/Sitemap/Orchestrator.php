<?php
/**
 * Sitemap Orchestrator
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orchestrator {
	public const URLS_PER_PAGE = 2000;

	/**
	 * Provider registry.
	 *
	 * @var array
	 */
	private $providers = array();

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Internal sitemap entries shared by the index and static materializer.
	 *
	 * @var array<int, array{provider_id:string,provider_kind:string,provider_name:string,page:int,filename:string,loc:string,lastmod:string}>|null
	 */
	private ?array $internal_sitemap_entries = null;

	/**
	 * Orchestrator constructor.
	 */
	public function __construct() {
		$this->settings = \Cybermaps\Core\ConfigurationStore::settings();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'parse_request', array( $this, 'handle_legacy_redirects' ), 0, 1 );
		add_action( 'parse_request', array( $this, 'handle_sitemap_request' ), 1, 1 );
		add_action( 'admin_post_cybermaps_regenerate_sitemaps', array( $this, 'handle_regeneration' ) );

		add_action( 'comment_post', array( $this, 'update_lastmod_on_comment' ), 10, 2 );
		add_action( 'wp_set_comment_status', array( $this, 'update_lastmod_on_comment_status' ), 10, 2 );

		add_action( 'save_post', array( $this, 'handle_published_post_save' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 10, 3 );
		add_action( 'delete_post', array( $this, 'handle_published_post_delete' ), 10, 2 );
		add_action( 'set_object_terms', array( $this, 'handle_published_post_terms' ), 10, 6 );
		add_action( 'created_term', array( $this, 'handle_term_inventory_change_created' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'handle_term_inventory_change_edited' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'handle_term_inventory_change_deleted' ), 10, 4 );
		add_action( 'user_register', array( $this, 'handle_author_inventory_change' ) );
		add_action( 'profile_update', array( $this, 'handle_author_inventory_change' ) );
		add_action( 'deleted_user', array( $this, 'handle_author_inventory_change' ) );

		$this->get_page_occupancy_builder()->register_hooks();
	}

	/**
	 * Refresh author sitemap output after a user inventory change.
	 *
	 * @param mixed $user_id User identifier supplied by the WordPress hook.
	 */
	public function handle_author_inventory_change( $user_id = 0 ): void {
		unset( $user_id );
		if ( empty( $this->settings['include_authors'] ) ) {
			return;
		}
		$this->clear_sitemap_cache();
		$this->invalidate_occupancy();
		$this->request_content_sync();
	}

	/**
	 * Add rewrite rules.
	 */
	public static function add_rewrite_rules() {
		$routes    = PublicationRouteSlugs::current();
		$base      = \preg_quote( $routes['sitemap_url_base'], '#' );
		$news_base = \preg_quote( $routes['news_sitemap_url_base'], '#' );

		add_rewrite_rule( '^' . $base . '\.xml$', 'index.php?cybermaps_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap-network\.xml$', 'index.php?cybermaps_sitemap=network', 'top' );
		add_rewrite_rule(
			'^' . $news_base . '\.xml$',
			'index.php?cybermaps_sitemap=' . \rawurlencode( ProviderIdentity::NEWS ) . '&cybermaps_page=1',
			'top'
		);
		add_rewrite_rule(
			'^' . $base . '-misc\.xml$',
			'index.php?cybermaps_sitemap=' . \rawurlencode( ProviderIdentity::MISC ) . '&cybermaps_page=1',
			'top'
		);
		add_rewrite_rule(
			'^' . $base . '-authors-([1-9][0-9]*)\.xml$',
			'index.php?cybermaps_sitemap=' . \rawurlencode( ProviderIdentity::AUTHORS ) . '&cybermaps_page=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . $base . '-archives-([1-9][0-9]*)\.xml$',
			'index.php?cybermaps_sitemap=' . \rawurlencode( ProviderIdentity::ARCHIVES ) . '&cybermaps_page=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . $base . '-posts-([a-z0-9_-]+)-([1-9][0-9]*)\.xml$',
			'index.php?cybermaps_sitemap=' . \rawurlencode( ProviderIdentity::POST_TYPE_PREFIX ) . '$matches[1]&cybermaps_page=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^' . $base . '-taxonomies-([a-z0-9_-]+)-([1-9][0-9]*)\.xml$',
			'index.php?cybermaps_sitemap=' . \rawurlencode( ProviderIdentity::TAXONOMY_PREFIX ) . '$matches[1]&cybermaps_page=$matches[2]',
			'top'
		);
	}

	/**
	 * Add query variables.
	 *
	 * @param array $vars Query variables.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'cybermaps_sitemap';
		$vars[] = 'cybermaps_page';
		return $vars;
	}

	/**
	 * Clear sitemap cache.
	 */
	public function clear_sitemap_cache() {
		\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		$this->internal_sitemap_entries = null;
	}

	/**
	 * Invalidate generation-scoped occupancy and schedule a rebuild.
	 */
	public function invalidate_occupancy(): void {
		$this->get_page_occupancy_builder()->invalidate();
		$this->internal_sitemap_entries = null;
	}

	/**
	 * @return PageOccupancyBuilder
	 */
	private function get_page_occupancy_builder(): PageOccupancyBuilder {
		return new PageOccupancyBuilder( $this );
	}

	/**
	 * Handle taxonomy inventory changes that can affect sitemap output.
	 *
	 * @param mixed $term_id  Term identifier.
	 * @param mixed $tt_id    Term-taxonomy identifier.
	 * @param mixed $taxonomy Taxonomy name.
	 * @param mixed $action   WordPress hook action name.
	 */
	public function handle_term_inventory_change( $term_id, $tt_id, $taxonomy, $action = 'edit' ): void {
		unset( $term_id, $tt_id );
		$taxonomy = \is_scalar( $taxonomy ) ? (string) $taxonomy : '';
		if ( ! PublicationTaxonomies::is_relevant_taxonomy_change( $taxonomy, (string) $action ) ) {
			return;
		}

		$this->clear_sitemap_cache();
		$this->invalidate_occupancy();
	}

	/**
	 * @param mixed $term_id
	 * @param mixed $tt_id
	 * @param mixed $taxonomy
	 */
	public function handle_term_inventory_change_created( $term_id, $tt_id, $taxonomy ): void {
		$this->handle_term_inventory_change( $term_id, $tt_id, $taxonomy, 'create' );
	}

	/**
	 * @param mixed $term_id
	 * @param mixed $tt_id
	 * @param mixed $taxonomy
	 */
	public function handle_term_inventory_change_edited( $term_id, $tt_id, $taxonomy ): void {
		$this->handle_term_inventory_change( $term_id, $tt_id, $taxonomy, 'edit' );
	}

	/**
	 * @param mixed $term_id
	 * @param mixed $tt_id
	 * @param mixed $taxonomy
	 * @param mixed $deleted_term
	 */
	public function handle_term_inventory_change_deleted( $term_id, $tt_id, $taxonomy, $deleted_term ): void {
		unset( $deleted_term );
		$this->handle_term_inventory_change( $term_id, $tt_id, $taxonomy, 'delete' );
	}

	/**
	 * Clear sitemap output after a published post is stored.
	 *
	 * @param mixed $post_id Post identifier.
	 * @param mixed $post    Saved post object.
	 */
	public function handle_published_post_save( $post_id, $post = null ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) {
			return;
		}

		$post = \is_object( $post ) ? $post : \get_post( (int) $post_id );
		if ( ! $this->is_published_post( $post ) ) {
			return;
		}

		$this->clear_sitemap_cache();
		$this->invalidate_occupancy();
	}

	/**
	 * Clear sitemap output when a post leaves the published inventory.
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status.
	 * @param mixed $post       Transitioned post object.
	 */
	public function handle_post_status_transition( $new_status, $old_status, $post ): void {
		if (
			'publish' !== (string) $old_status
			|| 'publish' === (string) $new_status
			|| ! $this->is_publication_post_type( $post )
		) {
			return;
		}

		$this->clear_sitemap_cache();
		$this->invalidate_occupancy();
	}

	/**
	 * Clear sitemap output only when a directly deleted row was public.
	 *
	 * @param mixed $post_id Deleted post identifier.
	 * @param mixed $post    Deleted post object.
	 */
	public function handle_published_post_delete( $post_id, $post = null ): void {
		$post = \is_object( $post ) ? $post : \get_post( (int) $post_id );
		if ( $this->is_published_post( $post ) ) {
			$this->clear_sitemap_cache();
			$this->invalidate_occupancy();
		}
	}

	/**
	 * Direct taxonomy assignment changes can alter cached term inventories.
	 *
	 * @param mixed $object_id  Object identifier.
	 * @param mixed $terms      Submitted terms.
	 * @param mixed $tt_ids     New term-taxonomy IDs.
	 * @param mixed $taxonomy   Taxonomy name.
	 * @param mixed $append     Whether terms were appended.
	 * @param mixed $old_tt_ids Previous term-taxonomy IDs.
	 */
	public function handle_published_post_terms(
		$object_id,
		$terms = array(),
		$tt_ids = array(),
		$taxonomy = '',
		$append = false,
		$old_tt_ids = array()
	): void {
		unset( $terms, $append );
		$taxonomy = \is_scalar( $taxonomy ) ? (string) $taxonomy : '';
		if ( ! PublicationTaxonomies::is_relevant_taxonomy_change( $taxonomy ) ) {
			return;
		}
		if ( ! $this->term_relationships_changed( $tt_ids, $old_tt_ids ) ) {
			return;
		}

		$post = \get_post( (int) $object_id );
		if ( $this->is_published_post( $post ) ) {
			$this->clear_sitemap_cache();
			$this->invalidate_occupancy();
		}
	}

	/**
	 * Update lastmod on comment.
	 *
	 * @param int $comment_id       Comment ID.
	 * @param int|string $comment_approved Approved status.
	 */
	public function update_lastmod_on_comment( $comment_id, $comment_approved ) {
		if ( 1 === $comment_approved || '1' === $comment_approved ) {
			$this->touch_post_on_comment( $comment_id );
		}
	}

	/**
	 * Update lastmod on comment status change.
	 *
	 * @param int    $comment_id     Comment ID.
	 * @param string $comment_status New status.
	 */
	public function update_lastmod_on_comment_status( $comment_id, $comment_status ) {
		if ( 'approve' === $comment_status ) {
			$this->touch_post_on_comment( $comment_id );
		}
	}

	/**
	 * Touch post modified date when comment is added/approved.
	 *
	 * @param int $comment_id Comment ID.
	 */
	private function touch_post_on_comment( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		$post_id = $comment->comment_post_ID;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( 'post' === $post->post_type && empty( $this->settings['update_comment_post'] ) ) {
			return;
		}
		if ( 'page' === $post->post_type && empty( $this->settings['update_comment_page'] ) ) {
			return;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Comment-driven freshness must update the exact persisted post timestamps.
		$updated = $wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			),
			array( 'ID' => $post_id )
		);
		// phpcs:enable
		clean_post_cache( $post_id );
		if ( false !== $updated ) {
			$this->clear_sitemap_cache();
			$this->invalidate_occupancy();
			\Cybermaps\Discovery\LLMS::invalidate_cache();
			\Cybermaps\Discovery\LLMSTLDR::invalidate_cache();
			$this->request_content_sync();
		}
	}

	/**
	 * Content inventory exists on disk only in full static mode.
	 */
	private function request_content_sync(): void {
		if ( 'all' === \Cybermaps\Discovery\StaticBridge::get_mode() ) {
			$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
			$bridge->invalidate();
			$bridge->request_sync();
		}
	}

	/**
	 * @param mixed $post Candidate post.
	 */
	private function is_published_post( $post ): bool {
		if (
			! \is_object( $post )
			|| 'publish' !== (string) ( $post->post_status ?? '' )
			|| 'revision' === (string) ( $post->post_type ?? '' )
		) {
			return false;
		}

		return $this->is_publication_post_type( $post );
	}

	/**
	 * @param mixed $post Candidate post.
	 */
	private function is_publication_post_type( $post ): bool {
		if ( ! \is_object( $post ) ) {
			return false;
		}

		return \Cybermaps\Core\PublicationPostTypes::contains(
			(string) ( $post->post_type ?? '' )
		);
	}

	/**
	 * @param mixed $current  Current term-taxonomy IDs.
	 * @param mixed $previous Previous term-taxonomy IDs.
	 */
	private function term_relationships_changed( $current, $previous ): bool {
		$current  = \array_values( \array_unique( \array_map( 'intval', (array) $current ) ) );
		$previous = \array_values( \array_unique( \array_map( 'intval', (array) $previous ) ) );
		\sort( $current, SORT_NUMERIC );
		\sort( $previous, SORT_NUMERIC );

		return $current !== $previous;
	}

	/**
	 * Handle sitemap regeneration request.
	 */
	public function handle_regeneration(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Unauthorized', 'cybermaps' ),
				'',
				array( 'response' => 403 )
			);
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_die(
				esc_html__( 'Invalid request method.', 'cybermaps' ),
				'',
				array( 'response' => 405 )
			);
		}
		check_admin_referer( 'cybermaps_regenerate', 'cybermaps_regenerate_nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The action nonce was verified immediately above.
		$return_tab = isset( $_POST['cybermaps_return_tab'] ) && is_scalar( $_POST['cybermaps_return_tab'] )
			? sanitize_key( wp_unslash( (string) $_POST['cybermaps_return_tab'] ) )
			: 'dashboard';
		if ( ! in_array( $return_tab, array( 'dashboard', 'sitemaps', 'shortcode', 'ai', 'schema', 'review', 'advanced' ), true ) ) {
			$return_tab = 'dashboard';
		}

		// 1. Clear every publication cache only after authorization succeeds.
		$this->clear_sitemap_cache();
		$this->invalidate_occupancy();
		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		\Cybermaps\Core\CacheManager::clear_family( 'chunks' );

		// 2. Flush rewrite rules
		self::add_rewrite_rules();
		flush_rewrite_rules();

		// 3. Sync physical files
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$report = $bridge->request_sync( true );
		if ( ! is_array( $report ) ) {
			$report = array(
				'status'  => 'failed',
				'success' => false,
				'mode'    => \Cybermaps\Discovery\StaticBridge::get_mode(),
				'counts'  => array( 'failed' => 1 ),
			);
		}

		// 4. Redirect back with a bounded, structured result for a truthful notice.
		$base_url = add_query_arg(
			array(
				'page' => 'cybermaps-settings',
				'tab'  => $return_tab,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect(
			add_query_arg(
				self::get_regeneration_notice_args( $report ),
				$base_url
			)
		);
		exit;
	}

	/**
	 * Convert a sync report into bounded redirect arguments for the admin notice.
	 *
	 * The complete report remains persisted by StaticBridge. The redirect carries
	 * only its status, mode, success flag, and aggregate counts, avoiding a large
	 * or path-bearing payload in the URL.
	 *
	 * @param array<string, mixed> $report Structured StaticBridge report.
	 * @return array<string, string>
	 */
	public static function get_regeneration_notice_args( array $report ): array {
		$status = (string) ( $report['status'] ?? 'failed' );
		if ( ! in_array( $status, array( 'complete', 'partial', 'failed', 'busy', 'skipped', 'pending' ), true ) ) {
			$status = 'failed';
		}

		$mode = (string) ( $report['mode'] ?? 'off' );
		if ( ! in_array( $mode, array( 'off', 'well_known', 'all' ), true ) ) {
			$mode = 'off';
		}

		$counts = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();
		$args   = array(
			'cybermaps_regenerated'          => '1',
			'cybermaps_regeneration_status'  => $status,
			'cybermaps_regeneration_success' => ! empty( $report['success'] )
				&& in_array( $status, array( 'complete', 'skipped' ), true )
				? '1'
				: '0',
			'cybermaps_regeneration_mode'    => $mode,
		);

		foreach ( array( 'desired', 'written', 'unchanged', 'conflicted', 'failed', 'skipped', 'deleted', 'retained' ) as $count_key ) {
			$args[ 'cybermaps_regeneration_' . $count_key ] = (string) max( 0, (int) ( $counts[ $count_key ] ?? 0 ) );
		}

		return $args;
	}

	public function handle_legacy_redirects() {
		$path             = \Cybermaps\Core\URLManager::get_request_path();
		$base             = $this->get_sitemap_base();
		$news_base        = $this->get_news_sitemap_base();
		$target_slug      = $base . '.xml';
		$target_news_slug = $news_base . '.xml';
		$target_url       = \Cybermaps\Core\URLManager::get_home_url( '/' . $target_slug );
		$target_news_url  = \Cybermaps\Core\URLManager::get_home_url( '/' . $target_news_slug );
		$is_core_path     = 1 === \preg_match( '/^\/wp-sitemap(-[a-z0-9-]+)?\.xml$/i', $path );
		$is_legacy_index  = '/sitemap.xml' === $path && 'sitemap.xml' !== $target_slug;
		$is_legacy_news   = '/sitemap-news.xml' === $path && 'sitemap-news.xml' !== $target_news_slug;

		if ( ! $is_core_path && ! $is_legacy_index && ! $is_legacy_news ) {
			return;
		}
		\Cybermaps\Core\ReadOnlyRequest::enforce();

		// 1. Core WP sitemaps (/wp-sitemap.xml)
		if ( $is_core_path ) {
			$redirect_core = ! array_key_exists( 'redirect_wp_sitemap', $this->settings )
				|| ! empty( $this->settings['redirect_wp_sitemap'] );
			if ( $redirect_core ) {
				wp_safe_redirect( $target_url, 301 );
				exit;
			} else {
				// Explicitly 404 Core sitemaps if not redirected, ensures no "ghost" sitemaps
				$this->issue_404();
			}
		}

		// 2. Legacy/Default sitemap.xml
		if ( $is_legacy_index ) {
			if ( ! empty( $this->settings['redirect_default_sitemap'] ) ) {
				wp_safe_redirect( $target_url, 301 );
				exit;
			} else {
				$this->issue_404();
			}
		}

		// 3. Legacy/Default sitemap-news.xml
		if ( $is_legacy_news ) {
			if ( ! empty( $this->settings['redirect_news_sitemap'] ) ) {
				wp_safe_redirect( $target_news_url, 301 );
				exit;
			} else {
				$this->issue_404();
			}
		}
	}

	/**
	 * Issue a clean 404 response.
	 */
	private function issue_404(): never {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			esc_html_e( 'Sitemap not found.', 'cybermaps' );
		}
		exit;
	}

	/**
	 * Handle sitemap request.
	 */
	public function handle_sitemap_request( $wp = null ) {
		$query_vars   = $this->request_query_vars( $wp );
		$sitemap_type = $this->requested_sitemap_type( $query_vars );
		if ( empty( $sitemap_type ) ) {
			return;
		}
		\Cybermaps\Discovery\Integrity::handle_preflight();
		\Cybermaps\Core\ReadOnlyRequest::enforce();

		list( $provider_id, $provider ) = $this->resolve_request_provider( $sitemap_type );
		$this->validate_network_request( $sitemap_type );
		$page = $this->requested_sitemap_page( $query_vars );
		$this->validate_sitemap_page( $sitemap_type, $provider_id, $provider, $page );

		$response = $this->prepare_sitemap_response( $sitemap_type, $provider_id, $provider, $page );
		$this->begin_sitemap_response();
		if ( null !== $response['cached'] ) {
			$this->serve_sitemap_xml( $response['cached'], $response['canonical_type'] );
		}
		$this->render_sitemap_response( $response, $page );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function request_query_vars( mixed $wp ): array {
		return is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars )
			? $wp->query_vars
			: array();
	}

	/**
	 * @param array<string,mixed> $query_vars Request query variables.
	 */
	private function requested_sitemap_type( array $query_vars ): string {
		return isset( $query_vars['cybermaps_sitemap'] )
			? (string) $query_vars['cybermaps_sitemap']
			: (string) get_query_var( 'cybermaps_sitemap' );
	}

	/**
	 * @return array{0:string,1:ProviderInterface|null}
	 */
	private function resolve_request_provider( string $sitemap_type ): array {
		if ( \in_array( $sitemap_type, array( 'index', 'network' ), true ) ) {
			return array( '', null );
		}

		$provider_id = $this->resolve_provider_id( $sitemap_type );
		if ( ProviderIdentity::NEWS === $provider_id && empty( $this->settings['enable_google_news'] ) ) {
			$this->issue_404();
		}
		$provider = $this->get_provider( $provider_id );
		if ( ! $provider ) {
			$this->issue_404();
		}

		return array( $provider_id, $provider );
	}

	private function validate_network_request( string $sitemap_type ): void {
		if ( 'network' !== $sitemap_type ) {
			return;
		}
		$network_settings = is_multisite()
			? (array) get_site_option( 'cybermaps_network_settings', array() )
			: array();
		if ( ! is_multisite() || ! is_main_site() || empty( $network_settings['enable_master_index'] ) ) {
			$this->issue_404();
		}
	}

	/**
	 * @param array<string,mixed> $query_vars Request query variables.
	 */
	private function requested_sitemap_page( array $query_vars ): int {
		return isset( $query_vars['cybermaps_page'] )
			? (int) $query_vars['cybermaps_page']
			: (int) get_query_var( 'cybermaps_page', 1 );
	}

	private function validate_sitemap_page(
		string $sitemap_type,
		string $provider_id,
		?ProviderInterface $provider,
		int $page
	): void {
		if ( $page < 1 ) {
			$this->issue_404();
		}
		if ( $this->validate_index_page( $sitemap_type, $page ) ) {
			return;
		}

		$pages = $this->child_page_count( $provider_id, $provider, $page );
		if ( $page > $pages ) {
			$this->issue_404();
		}
	}

	private function validate_index_page( string $sitemap_type, int $page ): bool {
		if ( ! \in_array( $sitemap_type, array( 'index', 'network' ), true ) ) {
			return false;
		}
		if ( 1 !== $page ) {
			$this->issue_404();
		}
		if ( 'index' === $sitemap_type ) {
			$this->get_page_occupancy_builder()->ensure_scheduled();
		}
		return true;
	}

	private function child_page_count( string $provider_id, ?ProviderInterface $provider, int $page ): int {
		$manifest = PageOccupancyManifest::load();
		if ( PageOccupancyManifest::is_usable( $manifest ) ) {
			return $this->manifest_page_count( $manifest, $provider_id, $page );
		}

		$count = $provider ? $provider->get_count() : 0;
		return ProviderIdentity::is_single_page( $provider_id )
			? ( $count > 0 || ProviderIdentity::NEWS === $provider_id ? 1 : 0 )
			: (int) \ceil( $count / $this->get_per_page() );
	}

	/**
	 * @param array<string,mixed> $manifest Usable occupancy manifest.
	 */
	private function manifest_page_count( array $manifest, string $provider_id, int $page ): int {
		$record = \is_array( $manifest['providers'][ $provider_id ] ?? null )
			? $manifest['providers'][ $provider_id ]
			: array();
		$pages  = PageOccupancyManifest::raw_page_count( $record );
		if ( $pages < 1 && ProviderIdentity::NEWS === $provider_id ) {
			$pages = 1;
		}
		if (
			ProviderIdentity::NEWS !== $provider_id
			&& ! $this->manifest_contains_child_page( $record, $page )
		) {
			$this->issue_404();
		}
		return $pages;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function prepare_sitemap_response(
		string $sitemap_type,
		string $provider_id,
		?ProviderInterface $provider,
		int $page
	): array {
		$canonical_type   = '' !== $provider_id ? $provider_id : $sitemap_type;
		$cache_key        = $this->get_response_cache_key( $canonical_type, $page );
		$cache_enabled    = $this->is_response_cache_enabled( $canonical_type );
		$cache_generation = $cache_enabled ? \Cybermaps\Core\CacheManager::get_generation( 'sitemap', true ) : 0;
		$is_regular_child = $this->is_regular_child( $sitemap_type, $provider_id );
		$cached           = $this->cached_sitemap_response( $cache_enabled, $cache_key, $is_regular_child );
		$preloaded_urls   = $this->preloaded_child_urls( $provider, $page, $is_regular_child, $cached );

		return array(
			'canonical_type'   => $canonical_type,
			'cache_key'        => $cache_key,
			'cache_enabled'    => $cache_enabled,
			'cache_generation' => $cache_generation,
			'is_regular_child' => $is_regular_child,
			'cached'           => $cached,
			'preloaded_urls'   => $preloaded_urls,
		);
	}

	private function is_regular_child( string $sitemap_type, string $provider_id ): bool {
		return ! \in_array( $sitemap_type, array( 'index', 'network' ), true )
			&& ProviderIdentity::NEWS !== $provider_id;
	}

	private function cached_sitemap_response( bool $cache_enabled, string $cache_key, bool $is_regular_child ): ?string {
		$cached = $cache_enabled ? $this->get_cached_response( $cache_key ) : null;
		if ( ! $is_regular_child || null === $cached || $this->child_xml_contains_url( $cached ) ) {
			return $cached;
		}

		// A malformed derived value is never served. Do not touch a raw legacy
		// transient here: CacheManager owns generation-scoped cache lifecycle.
		return null;
	}

	/**
	 * @return array<int,array<string,mixed>>|null
	 */
	private function preloaded_child_urls(
		?ProviderInterface $provider,
		int $page,
		bool $is_regular_child,
		?string $cached
	): ?array {
		if ( ! $is_regular_child || null !== $cached ) {
			return null;
		}
		$urls = $provider ? $provider->get_urls( $page ) : array();
		if ( empty( $urls ) ) {
			$this->issue_404();
		}
		return $urls;
	}

	private function begin_sitemap_response(): void {
		\Cybermaps\Discovery\StaticBridge::get_instance()->request_repair_for_filename(
			\ltrim( (string) \Cybermaps\Core\URLManager::get_request_path(), '/' ),
			'all'
		);
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		ob_start();

		header( 'Content-Type: application/xml; charset=utf-8', true );
		header( 'X-Robots-Tag: noindex, follow', true );
		$diagnostic_header = isset( $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC_CHALLENGE'] )
			? \sanitize_text_field( \wp_unslash( (string) $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC_CHALLENGE'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only cache-busting challenge; no state mutation.
		$diagnostic_query = isset( $_GET['cybermaps_php_path_probe'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only challenge value validated against the request header.
			? \sanitize_text_field( \wp_unslash( (string) $_GET['cybermaps_php_path_probe'] ) )
			: '';
		$diagnostic = self::validate_diagnostic_challenge( $diagnostic_header, $diagnostic_query );
		if ( '' !== $diagnostic ) {
			\nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'X-Cybermaps-Diagnostic-Response: ' . $diagnostic, true );
		}
	}

	/**
	 * @param array<string,mixed> $response Prepared response context.
	 */
	private function render_sitemap_response( array $response, int $page ): never {
		$xml = $this->generate_xml(
			$response['canonical_type'],
			$page,
			$response['preloaded_urls']
		);
		if ( $response['is_regular_child'] && ! $this->child_xml_contains_url( $xml ) ) {
			$this->issue_404();
		}
		if ( $response['cache_enabled'] ) {
			\Cybermaps\Core\CacheManager::set_if_current(
				$response['cache_key'],
				$xml,
				12 * HOUR_IN_SECONDS,
				'sitemap',
				$response['cache_generation']
			);
		}

		$this->serve_sitemap_xml( $xml, $response['canonical_type'] );
	}

	private function serve_sitemap_xml( string $xml, string $canonical_type ): never {
		$not_modified = \Cybermaps\Discovery\Integrity::send_representation_headers(
			$xml,
			\Cybermaps\Discovery\PublicationCachePolicy::for_publication( 'sitemap', $canonical_type )
		);
		if ( $not_modified ) {
			ob_end_clean();
			exit;
		}

		ob_end_clean();
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}

	/**
	 * Keep cache identities namespaced by the resolved provider kind.
	 */
	private function get_response_cache_key( string $canonical_type, int $page ): string {
		return 'cybermaps_v7_' . \md5( $canonical_type . '_' . \max( 1, $page ) );
	}

	/**
	 * Read only complete string responses through the generation-scoped cache.
	 * CacheManager handles its deliberately fenced fixed-key compatibility path;
	 * dynamic sitemap response keys never bypass that fence with a raw transient.
	 */
	private function get_cached_response( string $cache_key ): ?string {
		$found  = false;
		$cached = \Cybermaps\Core\CacheManager::get( $cache_key, 'sitemap', $found );
		if ( ! $found ) {
			return null;
		}
		if ( ! \is_string( $cached ) || '' === $cached ) {
			return null;
		}

		return $cached;
	}

	/**
	 * A complete occupancy record is authoritative for ordinary child pages.
	 *
	 * @param array<string, mixed> $record Provider occupancy record.
	 */
	private function manifest_contains_child_page( array $record, int $page ): bool {
		$non_empty_pages = \is_array( $record['non_empty_pages'] ?? null )
			? \array_map( 'intval', $record['non_empty_pages'] )
			: array();

		return \in_array( $page, $non_empty_pages, true );
	}

	/**
	 * Never accept a cached or newly rendered ordinary child without a URL row.
	 */
	private function child_xml_contains_url( string $xml ): bool {
		return \str_contains( $xml, '<url>' );
	}

	/**
	 * Accept a diagnostic only when its unguessable request header and unique
	 * query argument match exactly. This prevents cached marker replay.
	 */
	private static function validate_diagnostic_challenge( string $header, string $query ): string {
		if (
			1 !== \preg_match( '/\A[A-Za-z0-9]{32,64}\z/D', $header )
			|| 1 !== \preg_match( '/\A[A-Za-z0-9]{32,64}\z/D', $query )
			|| ! \hash_equals( $header, $query )
		) {
			return '';
		}

		return $header;
	}

	/**
	 * The network index depends on every site, so a main-site transient cannot
	 * represent its freshness. Generate that low-frequency publication live.
	 */
	private function is_response_cache_enabled( string $canonical_type ): bool {
		return 'network' !== $canonical_type && ! empty( $this->settings['enable_caching'] );
	}

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Get provider for a specific type.
	 *
	 * Namespaced identities are authoritative. Legacy raw system names remain
	 * supported, and a raw WordPress object slug is accepted only when it maps
	 * unambiguously to one post type or one taxonomy.
	 *
	 * @param string $type Provider identity or unambiguous legacy type.
	 * @return ProviderInterface|null
	 */
	public function get_provider( string $type ) {
		$provider_id = $this->resolve_provider_id( $type );
		if ( '' === $provider_id ) {
			return null;
		}
		if ( isset( $this->providers[ $provider_id ] ) ) {
			return $this->providers[ $provider_id ];
		}
		if (
			ProviderIdentity::is_system( $provider_id )
			&& PriorityEngine::calculate( ProviderIdentity::priority_key( $provider_id ) ) <= 0
		) {
			return null;
		}

		$provider = $this->create_provider( $provider_id );
		if ( $provider ) {
			$this->providers[ $provider_id ] = $provider;
		}
		return $provider;
	}

	private function create_provider( string $provider_id ): ?ProviderInterface {
		$system_classes = array(
			ProviderIdentity::NEWS     => NewsProvider::class,
			ProviderIdentity::MISC     => MiscProvider::class,
			ProviderIdentity::AUTHORS  => AuthorProvider::class,
			ProviderIdentity::ARCHIVES => ArchiveProvider::class,
		);
		if ( isset( $system_classes[ $provider_id ] ) ) {
			$class_name = $system_classes[ $provider_id ];
			return new $class_name();
		}

		$name = ProviderIdentity::name( $provider_id );
		if ( 'post_type' === ProviderIdentity::kind( $provider_id ) ) {
			return $this->create_post_type_provider( $provider_id, $name );
		}
		if ( 'taxonomy' === ProviderIdentity::kind( $provider_id ) ) {
			return $this->create_taxonomy_provider( $provider_id, $name );
		}
		return null;
	}

	private function create_post_type_provider( string $provider_id, string $name ): ?ProviderInterface {
		if (
			\Cybermaps\Core\PublicationPostTypes::contains( $name )
			&& PriorityEngine::calculate( $provider_id ) > 0
		) {
			return new PostTypeProvider( $name );
		}
		return null;
	}

	private function create_taxonomy_provider( string $provider_id, string $name ): ?ProviderInterface {
		$taxonomy = \get_taxonomy( $name );
		if (
			\is_object( $taxonomy )
			&& ! empty( $taxonomy->public )
			&& PriorityEngine::calculate( $provider_id ) > 0
		) {
			return new TaxonomyProvider( $name );
		}
		return null;
	}

	/**
	 * Resolve an identity without allowing raw-slug collisions.
	 */
	public function resolve_provider_id( string $type ): string {
		if ( '' !== ProviderIdentity::kind( $type ) ) {
			return $type;
		}

		$system = ProviderIdentity::system( $type );
		if ( '' !== $system ) {
			return $system;
		}

		$name = \sanitize_key( $type );
		if ( '' === $name ) {
			return '';
		}

		return ProviderIdentity::unambiguous_public_object( $name );
	}

	/**
	 * Return every internal child sitemap referenced by the current index.
	 *
	 * Keeping this inventory in one place guarantees that full static mode writes
	 * the same child files that the dynamic index advertises.
	 *
	 * @return array<int, array{provider_id:string,provider_kind:string,provider_name:string,page:int,filename:string,loc:string,lastmod:string}>
	 */
	public function get_internal_sitemap_entries(): array {
		if ( null !== $this->internal_sitemap_entries ) {
			return $this->internal_sitemap_entries;
		}

		$manifest = PageOccupancyManifest::load();
		if ( PageOccupancyManifest::is_usable( $manifest ) ) {
			$this->internal_sitemap_entries = PageOccupancyManifest::entries_for_index( $manifest, $this );
			return $this->internal_sitemap_entries;
		}

		$weighted_providers = $this->collect_weighted_providers();
		$routes             = PublicationRouteSlugs::resolve( $this->settings );
		$entries            = array();
		foreach ( $weighted_providers as $weighted_provider ) {
			$provider_id = (string) $weighted_provider['provider_id'];
			$provider    = $this->get_provider( $provider_id );
			if ( ! $provider ) {
				continue;
			}

			$count = $provider->get_count();
			if ( $count < 1 && ProviderIdentity::NEWS !== $provider_id ) {
				continue;
			}

			$pages = ProviderIdentity::is_single_page( $provider_id )
				? 1
				: (int) \ceil( $count / $this->get_per_page() );
			for ( $page = 1; $page <= $pages; ++$page ) {
				$filename = ProviderIdentity::filename( $provider_id, $page, $routes );
				if ( '' === $filename ) {
					continue;
				}

				$entries[] = array(
					'provider_id'   => $provider_id,
					'provider_kind' => ProviderIdentity::kind( $provider_id ),
					'provider_name' => ProviderIdentity::name( $provider_id ),
					'page'          => $page,
					'filename'      => $filename,
					'loc'           => \Cybermaps\Core\URLManager::get_home_url( '/' . $filename ),
					'lastmod'       => $provider->get_lastmod(),
				);
			}
		}

		$this->internal_sitemap_entries = $entries;
		return $entries;
	}

	/**
	 * Return weighted provider identities in canonical sitemap index order.
	 *
	 * @return array<int, array{provider_id:string,weight:int}>
	 */
	public function collect_weighted_providers(): array {
		$provider_ids = ProviderIdentity::system_providers(
			! empty( $this->settings['enable_google_news'] )
		);

		foreach ( \Cybermaps\Core\PublicationPostTypes::names() as $post_type ) {
			$provider_id = ProviderIdentity::post_type( $post_type );
			if ( PriorityEngine::calculate( $provider_id ) > 0 ) {
				$provider_ids[] = $provider_id;
			}
		}
		foreach ( \get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$taxonomy_object = \get_taxonomy( (string) $taxonomy );
			if (
				\is_object( $taxonomy_object )
				&& ! empty( $taxonomy_object->public )
				&& PriorityEngine::calculate( ProviderIdentity::taxonomy( (string) $taxonomy ) ) > 0
			) {
				$provider_ids[] = ProviderIdentity::taxonomy( (string) $taxonomy );
			}
		}

		$weighted_providers = array();
		foreach ( $provider_ids as $provider_id ) {
			$provider = $this->get_provider( $provider_id );
			$weight   = PriorityEngine::calculate(
				ProviderIdentity::priority_key( $provider_id )
			);
			if ( $provider && $weight > 0 ) {
				$weighted_providers[] = array(
					'provider_id' => $provider_id,
					'weight'      => $weight,
				);
			}
		}
		\usort(
			$weighted_providers,
			static function ( array $left, array $right ): int {
				$weight_order = $right['weight'] <=> $left['weight'];
				return 0 !== $weight_order
					? $weight_order
					: \strcmp( (string) $left['provider_id'], (string) $right['provider_id'] );
			}
		);

		return $weighted_providers;
	}

	/**
	 * @return string[]
	 */
	public function collect_weighted_provider_ids(): array {
		return \array_map(
			static fn( array $weighted_provider ): string => (string) $weighted_provider['provider_id'],
			$this->collect_weighted_providers()
		);
	}

	/**
	 * Generate one materialized child only when its bounded raw page still
	 * contains at least one publishable URL.
	 *
	 * Dynamic requests deliberately retain WordPress Core's 404 contract for an
	 * empty provider page. A static file cannot carry that response status, so
	 * full-static reconciliation omits the file and removes it from the same-run
	 * index instead of publishing a schema-invalid empty urlset with HTTP 200.
	 */
	public function generate_static_child_xml( string $type, int $page ): ?string {
		$provider_id = $this->resolve_provider_id( $type );
		$provider    = $this->get_provider( $provider_id );
		if ( ! $provider ) {
			$this->omit_internal_sitemap_entry( $provider_id, $page );
			return null;
		}

		$urls = $provider->get_urls( $page );
		if ( empty( $urls ) && ProviderIdentity::NEWS !== $provider_id ) {
			$this->omit_internal_sitemap_entry( $provider_id, $page );
			return null;
		}

		$xml = $this->generate_xml( $provider_id, $page, $urls );
		if ( ! \str_contains( $xml, '<url>' ) && ProviderIdentity::NEWS !== $provider_id ) {
			$this->omit_internal_sitemap_entry( $provider_id, $page );
			return null;
		}

		return $xml;
	}

	/**
	 * Remove one empty raw page from the request-local materialized index.
	 */
	private function omit_internal_sitemap_entry( string $provider_id, int $page ): void {
		$entries                        = $this->get_internal_sitemap_entries();
		$this->internal_sitemap_entries = \array_values(
			\array_filter(
				$entries,
				static fn ( array $entry ): bool =>
					(string) ( $entry['provider_id'] ?? '' ) !== $provider_id
					|| (int) ( $entry['page'] ?? 0 ) !== $page
			)
		);
	}

	/**
	 * Apply validated empty-page observations restored by a resumed static sync.
	 *
	 * @param array<string,int[]> $omissions Provider IDs mapped to omitted raw pages.
	 */
	public function apply_internal_sitemap_omissions( array $omissions ): void {
		foreach ( $omissions as $provider_id => $pages ) {
			if ( ! \is_string( $provider_id ) || ! \is_array( $pages ) ) {
				continue;
			}
			foreach ( $pages as $page ) {
				if ( \is_int( $page ) && $page > 0 ) {
					$this->omit_internal_sitemap_entry( $provider_id, $page );
				}
			}
		}
	}

	/**
	 * Get items per page.
	 *
	 * @return int
	 */
	public function get_per_page() {
		return self::URLS_PER_PAGE;
	}

	/**
	 * Generate Sitemap XML.
	 *
	 * @param string $type Sitemap type.
	 * @param int    $page Page number.
	 * @param array|null $preloaded_urls Optional request-local child URLs.
	 * @return string
	 */
	public function generate_xml( string $type, int $page, ?array $preloaded_urls = null ) {
		if ( 'ai_sitemap' === $type ) {
			$ai = new \Cybermaps\Discovery\AISitemap();
			return $ai->get_content();
		}

		$writer = new XmlWriter();
		$writer->startDocument( '1.0', 'UTF-8' );
		$writer->writePI(
			'xml-stylesheet',
			'type="text/xsl" href="' . esc_url( CYBERMAPS_PLUGIN_URL . 'assets/xsl/sitemap.xsl' ) . '"'
		);

		if ( 'index' === $type ) {
			$renderer = new Renderer\IndexRenderer( $this );
			$renderer->render( $writer );
		} elseif ( 'network' === $type ) {
			$renderer = new Renderer\NetworkRenderer( $this );
			$renderer->render( $writer );
		} else {
				$provider_id = $this->resolve_provider_id( $type );
				$provider    = $this->get_provider( $provider_id );
			if ( $provider ) {
				$renderer = new Renderer\SitemapRenderer( $this );
				$renderer->render( $writer, $provider, $provider_id, $page, $preloaded_urls );
			} else {
				$renderer = new Renderer\SitemapRenderer( $this );
				$renderer->render_empty( $writer );
			}
		}

		$writer->endDocument();
		return $writer->outputMemory();
	}

	/**
	 * Get sitemap base URL slug.
	 *
	 * @return string
	 */
	public static function get_sitemap_base() {
		$routes = PublicationRouteSlugs::current();
		return $routes['sitemap_url_base'];
	}

	/**
	 * Get news sitemap base URL slug.
	 *
	 * @return string
	 */
	public static function get_news_sitemap_base() {
		$routes = PublicationRouteSlugs::current();
		return $routes['news_sitemap_url_base'];
	}

	/**
	 * Get RSS sitemap base URL slug.
	 *
	 * @return string
	 */
	public static function get_rss_sitemap_base() {
		$routes = PublicationRouteSlugs::current();
		return $routes['rss_sitemap_url_base'];
	}
}
