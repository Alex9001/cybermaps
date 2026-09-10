<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\PublicationPostTypes;
use Cybermaps\Core\URLManager;
use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends publication notifications only after WordPress finishes the request's
 * post, term, and editor-metadata writes.
 *
 * transition_post_status runs before classic metabox persistence and before
 * REST additional fields. Capturing the pre-write inventory here and comparing
 * it with the shutdown state prevents stale exclusion decisions.
 */
final class PublicationNotifier {
	/**
	 * Metadata capable of moving a post into or out of a public inventory.
	 *
	 * @var string[]
	 */
	private const ELIGIBILITY_META_KEYS = array(
		'_genesis_noindex',
		'_genesis_nofollow',
		'_genesis_noarchive',
		'_genesis_canonical_uri',
		'redirect',
		'_yoast_wpseo_meta-robots-noindex',
		'_yoast_wpseo_canonical',
		'rank_math_robots',
		'rank_math_canonical_url',
		'_cybermaps_exclude_sitemap',
		'_cybermaps_exclude_ai',
	);

	/**
	 * First observed inventory state per post.
	 *
	 * @var array<int, array{url:string,sitemap:bool,feed:bool}>
	 */
	private array $pending = array();

	public function __construct(
		private readonly IndexNow $indexnow,
		private readonly WebSub $websub
	) {}

	/**
	 * Register capture and post-commit delivery hooks.
	 */
	public function register_hooks(): void {
		add_action( 'transition_post_status', array( $this, 'queue_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'queue_deletion' ), 10, 2 );
		foreach ( array( 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata' ) as $hook ) {
			add_filter( $hook, array( $this, 'capture_before_meta_write' ), 10, 5 );
		}
		// These actions run before WordPress adds or deletes a relationship.
		// Capturing the first mutation preserves the pre-change eligibility even
		// when wp_set_object_terms() performs several relationship writes.
		add_action( 'add_term_relationship', array( $this, 'capture_before_term_write' ), 10, 3 );
		add_action( 'delete_term_relationships', array( $this, 'capture_before_term_write' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'flush' ), PHP_INT_MAX );
	}

	/**
	 * Capture the inventory that existed before a status transition.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Previous status.
	 * @param mixed $post       Transitioned post.
	 */
	public function queue_transition( $new_status, $old_status, $post ): void {
		if (
			( 'publish' !== (string) $new_status && 'publish' !== (string) $old_status )
			|| ! is_object( $post )
		) {
			return;
		}

		$before              = clone $post;
		$before->post_status = (string) $old_status;
		$this->capture( $before );
	}

	/**
	 * Capture an eligible force-deleted row before WordPress removes it.
	 *
	 * @param mixed $post_id Post identifier.
	 * @param mixed $post    Post object before deletion.
	 */
	public function queue_deletion( $post_id, $post = null ): void {
		$post = is_object( $post ) ? $post : get_post( (int) $post_id );
		$this->capture( $post );
	}

	/**
	 * Capture the inventory before WordPress mutates relevant post metadata.
	 *
	 * Metadata filters are short-circuit filters; returning the incoming value
	 * unchanged preserves WordPress's normal write behavior.
	 *
	 * @param mixed $check      Existing short-circuit result.
	 * @param mixed $object_id Post identifier.
	 * @param mixed $meta_key   Metadata key.
	 * @param mixed $meta_value New or matched value.
	 * @param mixed $constraint Unique/previous/delete-all argument.
	 * @return mixed
	 */
	public function capture_before_meta_write(
		$check,
		$object_id,
		$meta_key,
		$meta_value = null,
		$constraint = null
	) {
		unset( $meta_value, $constraint );
		if ( in_array( (string) $meta_key, self::ELIGIBILITY_META_KEYS, true ) ) {
			$this->capture( get_post( (int) $object_id ) );
		}

		return $check;
	}

	/**
	 * Capture inventory before a taxonomy relationship changes.
	 *
	 * @param mixed $object_id Post identifier.
	 * @param mixed $term_ids  Term-taxonomy identifier(s).
	 * @param mixed $taxonomy  Taxonomy slug.
	 */
	public function capture_before_term_write( $object_id, $term_ids = array(), $taxonomy = '' ): void {
		unset( $term_ids, $taxonomy );
		$this->capture( get_post( (int) $object_id ) );
	}

	/**
	 * Compare the final persisted state and deliver each required notification.
	 */
	public function flush(): void {
		if ( empty( $this->pending ) ) {
			return;
		}

		$pending       = $this->pending;
		$this->pending = array();
		$notify_feed   = false;

		foreach ( $pending as $post_id => $before ) {
			if ( $this->flush_post( $post_id, $before ) ) {
				$notify_feed = true;
			}
		}

		if ( $notify_feed ) {
			$this->websub->notify_change();
		}
	}

	/**
	 * @param array{url:string,sitemap:bool,feed:bool} $before Captured state.
	 */
	private function flush_post( int $post_id, array $before ): bool {
		$post = get_post( $post_id );
		if ( ! is_object( $post ) ) {
			if ( $before['sitemap'] ) {
				$this->indexnow->notify_url( $before['url'] );
			}
			return $before['feed'];
		}
		if ( ! PublicationPostTypes::contains( (string) ( $post->post_type ?? '' ) ) ) {
			return false;
		}
		$after_sitemap = $this->is_eligible( $post, PublicationEligibility::SITEMAP );
		$after_feed    = $this->is_feed_eligible( $post );
		if ( $before['sitemap'] || $after_sitemap ) {
			$url = URLManager::rewrite_url( (string) get_permalink( $post_id ) );
			$this->indexnow->notify_url( '' !== $url ? $url : $before['url'] );
		}
		return $before['feed'] || $after_feed;
	}

	/**
	 * Store the first state observed in this request.
	 */
	private function capture( mixed $post ): void {
		if (
			! is_object( $post )
			|| ! isset( $post->ID )
			|| ! PublicationPostTypes::contains( (string) ( $post->post_type ?? '' ) )
		) {
			return;
		}

		$post_id = (int) $post->ID;
		if ( $post_id < 1 || isset( $this->pending[ $post_id ] ) ) {
			return;
		}

		$this->pending[ $post_id ] = array(
			'url'     => URLManager::rewrite_url( (string) get_permalink( $post_id ) ),
			'sitemap' => $this->is_eligible( $post, PublicationEligibility::SITEMAP ),
			'feed'    => $this->is_feed_eligible( $post ),
		);
	}

	private function is_eligible( object $post, string $channel ): bool {
		if ( 'publish' !== (string) ( $post->post_status ?? '' ) ) {
			return false;
		}

		return ( new PublicationEligibility() )->post( $post, $channel )->indexable;
	}

	private function is_feed_eligible( object $post ): bool {
		return 'post' === (string) ( $post->post_type ?? '' )
			&& $this->is_eligible( $post, PublicationEligibility::AI );
	}
}
