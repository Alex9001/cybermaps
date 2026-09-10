<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manager {
	/**
	 * @var ADP
	 */
	private $adp;

	/**
	 * @var Robots
	 */
	private $robots;

	/**
	 * Constructor
	 *
	 * @param ADP    $adp    Backward-compatible Cybermaps manifest service.
	 * @param Robots $robots Robots.txt service.
	 */
	public function __construct( ADP $adp, Robots $robots ) {
		$this->adp    = $adp;
		$this->robots = $robots;
	}

	/**
	 * Register discovery-related hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$throttler = new Throttler();
		$throttler->register_hooks();

		// One registry-backed router owns every fixed discovery publication.
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		$registry->register_extension_endpoints();
		add_action(
			'parse_request',
			array( new PublicationRouter( $this->adp, $registry ), 'handle' ),
			1,
			1
		);

		$llms = new LLMS();
		// LLMS also owns localized parameterized paths (for example
		// /es/llms.txt), which are intentionally outside the fixed registry.
		add_action( 'parse_request', array( $llms, 'handle' ), 1, 1 );

		$llms_tldr = new LLMSTLDR();
		// Localized TL;DR variants are parameterized for the same reason.
		add_action( 'parse_request', array( $llms_tldr, 'handle' ), 1, 1 );

		// Parameterized chunk paths intentionally remain outside the fixed-path
		// endpoint registry.
		add_action( 'parse_request', array( new RAGChunk(), 'handle' ), 1, 1 );

		// Per-resource Markdown alternates are derived from canonical WordPress
		// permalinks and therefore remain parameterized as well.
		add_action( 'parse_request', array( new MarkdownAlternate(), 'handle' ), 1, 1 );
		( new MarkdownNegotiation() )->register_hooks();

		$not_found_suggestions = new NotFoundSuggestions();
		$not_found_suggestions->register_hooks();

		$headers = new HeaderDiscovery();
		$headers->register_hooks();

		// Own the virtual robots.txt method, media type, and content contract.
		$this->robots->register_hooks();

		add_action( 'cybermaps_bg_sync_static_files', array( \Cybermaps\Discovery\StaticBridge::get_instance(), 'sync_all' ) );
		add_action(
			\Cybermaps\Discovery\StaticBridge::TIME_SENSITIVE_REFRESH_HOOK,
			array( \Cybermaps\Discovery\StaticBridge::get_instance(), 'refresh_time_sensitive_publications' )
		);
		add_action( 'save_post', array( $this, 'on_post_saved' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_post_status_transition' ), 10, 3 );
		add_action( 'delete_post', array( $this, 'on_post_deleted' ), 10, 2 );
		add_action( 'set_object_terms', array( $this, 'on_object_terms_set' ), 10, 6 );
		add_action( 'edited_term', array( $this, 'on_term_changed' ), 10, 3 );
		add_action( 'create_term', array( $this, 'on_term_changed' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_changed' ), 10, 3 );
	}

	/**
	 * Invalidate discovery publications after a published post is stored.
	 *
	 * Revision/autosave rows and non-public statuses cannot appear in any Core
	 * publication and must not enqueue a whole-site static reconciliation.
	 *
	 * @param mixed $post_id Post identifier.
	 * @param mixed $post    Saved post object.
	 */
	public function on_post_saved( $post_id, $post = null ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) {
			return;
		}

		$post = \is_object( $post ) ? $post : \get_post( (int) $post_id );
		if ( ! $this->is_published_post( $post ) ) {
			return;
		}

		$this->invalidate_content_publications();
	}

	/**
	 * Remove stale publications when content leaves the published inventory.
	 *
	 * Entering publish is handled by save_post after WordPress has completed the
	 * write. A publish-to-publish update is likewise handled there.
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status.
	 * @param mixed $post       Transitioned post object.
	 */
	public function on_post_status_transition( $new_status, $old_status, $post ): void {
		if ( 'publish' !== (string) $old_status || 'publish' === (string) $new_status ) {
			return;
		}
		if ( ! $this->is_publication_post_type( $post ) ) {
			return;
		}

		$this->invalidate_content_publications();
	}

	/**
	 * Invalidate only when a directly deleted row was publicly visible.
	 *
	 * @param mixed $post_id Deleted post identifier.
	 * @param mixed $post    Deleted post object.
	 */
	public function on_post_deleted( $post_id, $post = null ): void {
		$post = \is_object( $post ) ? $post : \get_post( (int) $post_id );
		if ( ! $this->is_published_post( $post ) ) {
			return;
		}

		$this->invalidate_content_publications();
	}

	/**
	 * Taxonomy assignments can change sitemap term counts and AI eligibility
	 * without firing save_post (for example, direct wp_set_object_terms calls).
	 *
	 * @param mixed $object_id  Object identifier.
	 * @param mixed $terms      Submitted terms.
	 * @param mixed $tt_ids     New term-taxonomy IDs.
	 * @param mixed $taxonomy   Taxonomy name.
	 * @param mixed $append     Whether terms were appended.
	 * @param mixed $old_tt_ids Previous term-taxonomy IDs.
	 */
	public function on_object_terms_set(
		$object_id,
		$terms = array(),
		$tt_ids = array(),
		$taxonomy = '',
		$append = false,
		$old_tt_ids = array()
	): void {
		unset( $terms, $append );
		$taxonomy = \is_scalar( $taxonomy ) ? (string) $taxonomy : '';
		if ( ! \Cybermaps\Sitemap\PublicationTaxonomies::is_relevant_taxonomy_change( $taxonomy ) ) {
			return;
		}
		if ( ! $this->term_relationships_changed( $tt_ids, $old_tt_ids ) ) {
			return;
		}

		$post = \get_post( (int) $object_id );
		if ( ! $this->is_published_post( $post ) ) {
			return;
		}

		$this->invalidate_content_publications();
	}

	/**
	 * Term creation, editing, and deletion can affect filtered post inventory.
	 *
	 * @param mixed $term_id          Term identifier.
	 * @param mixed $term_taxonomy_id Term-taxonomy identifier.
	 * @param mixed $taxonomy         Taxonomy name.
	 */
	public function on_term_changed( $term_id = 0, $term_taxonomy_id = 0, $taxonomy = '' ): void {
		unset( $term_id, $term_taxonomy_id );
		$taxonomy = \is_scalar( $taxonomy ) ? (string) $taxonomy : '';
		if ( ! \Cybermaps\Sitemap\PublicationTaxonomies::is_relevant_taxonomy_change( $taxonomy ) ) {
			return;
		}
		$this->invalidate_content_publications();
	}

	/**
	 * Clear dynamic content caches and reconcile disk only when disk contains
	 * content-derived publications. The well-known bucket contains site policy
	 * and endpoint metadata, not post or term inventory.
	 */
	private function invalidate_content_publications(): void {
		// Content order and eligibility also drive the cached AI publication
		// inventory used to authorize chunk requests.
		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );

		if ( 'all' === StaticBridge::get_mode() ) {
			$bridge = StaticBridge::get_instance();
			// Fence any generation that captured the inventory before this
			// content or relationship mutation, then coalesce a replacement.
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
}
