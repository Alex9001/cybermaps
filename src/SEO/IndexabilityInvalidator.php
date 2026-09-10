<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

use Cybermaps\Core\CacheManager;
use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PublicationTaxonomies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciles publications when an upstream SEO decision changes.
 */
final class IndexabilityInvalidator {
	private bool $content_invalidated = false;
	private bool $global_invalidated  = false;

	private const POST_META_KEYS = array(
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
		'_cybermaps_intent_override',
		'_cybermaps_sitemap_priority',
		'_cybermaps_sitemap_changefreq',
		'_cybermaps_media_audit',
	);

	private const TERM_META_KEYS = array( 'noindex', 'nofollow', 'noarchive' );
	private const USER_META_KEYS = array( 'noindex', 'nofollow', 'noarchive' );

	public function register_hooks(): void {
		add_action( 'updated_option', array( $this, 'on_updated_option' ), 10, 3 );
		add_action( 'added_option', array( $this, 'on_added_option' ), 10, 2 );
		add_action( 'deleted_option', array( $this, 'on_deleted_option' ), 10, 1 );

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_post_meta' ), 10, 4 );
		}
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_term_meta' ), 10, 4 );
		}
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_user_meta' ), 10, 4 );
		}

		// AIOSEO 4 stores singular directives in its own table and announces a
		// completed write through this public action.
		add_action( 'aioseo_insert_post', array( $this, 'invalidate_content' ), 10, 0 );
		add_action( 'after_switch_theme', array( $this, 'invalidate' ) );
	}

	public function on_updated_option( string $option, mixed $old_value, mixed $value ): void {
		unset( $old_value, $value );
		if ( $this->is_relevant_option( $option ) ) {
			$this->invalidate();
		}
	}

	public function on_added_option( string $option, mixed $value ): void {
		unset( $value );
		if ( $this->is_relevant_option( $option ) ) {
			$this->invalidate();
		}
	}

	public function on_deleted_option( string $option ): void {
		if ( $this->is_relevant_option( $option ) ) {
			$this->invalidate();
		}
	}

	public function on_post_meta( mixed $meta_id, mixed $object_id, mixed $meta_key, mixed $meta_value = null ): void {
		unset( $meta_id, $meta_value );
		if ( ! in_array( (string) $meta_key, self::POST_META_KEYS, true ) ) {
			return;
		}

		$post = get_post( (int) $object_id );
		if ( ! $this->is_published_publication_post( $post ) ) {
			return;
		}

		$this->invalidate_content();
	}

	public function on_term_meta( mixed $meta_id, mixed $object_id, mixed $meta_key, mixed $meta_value = null ): void {
		unset( $meta_id, $meta_value );
		if ( ! in_array( (string) $meta_key, self::TERM_META_KEYS, true ) ) {
			return;
		}

		$term = get_term( (int) $object_id );
		if ( is_object( $term ) && ! empty( $term->taxonomy ) ) {
			if ( ! PublicationTaxonomies::is_relevant_taxonomy_change( (string) $term->taxonomy ) ) {
				return;
			}
		}

		// Missing term context is treated conservatively because deleted terms
		// cannot be resolved after WordPress removes their row.
		$this->invalidate_content();
	}

	public function on_user_meta( mixed $meta_id, mixed $object_id, mixed $meta_key, mixed $meta_value = null ): void {
		unset( $meta_id, $object_id, $meta_value );
		if (
			in_array( (string) $meta_key, self::USER_META_KEYS, true )
			&& ! empty( ConfigurationStore::settings()['include_authors'] )
		) {
			$this->invalidate_content();
		}
	}

	public function invalidate(): void {
		if ( $this->global_invalidated ) {
			return;
		}
		$this->global_invalidated  = true;
		$this->content_invalidated = true;

		CacheManager::clear_family( 'sitemap' );
		CacheManager::clear_family( 'discovery' );
		CacheManager::clear_family( 'chunks' );

		$orchestrator = new Orchestrator();
		$orchestrator->invalidate_occupancy();

		$bridge = StaticBridge::get_instance();
		$bridge->invalidate();
		$bridge->request_sync();
	}

	/**
	 * Invalidate post/term/user-derived output without rebuilding the well-known
	 * site-policy bucket, which contains no content inventory.
	 */
	public function invalidate_content(): void {
		if ( $this->global_invalidated || $this->content_invalidated ) {
			return;
		}
		$this->content_invalidated = true;

		CacheManager::clear_family( 'sitemap' );
		CacheManager::clear_family( 'discovery' );
		CacheManager::clear_family( 'chunks' );

		( new Orchestrator() )->invalidate_occupancy();

		if ( 'all' !== StaticBridge::get_mode() ) {
			return;
		}

		$bridge = StaticBridge::get_instance();
		$bridge->invalidate();
		$bridge->request_sync();
	}

	private function is_relevant_option( string $option ): bool {
		return in_array(
			$option,
			array(
				'genesis-seo-settings',
				'blog_public',
				'active_plugins',
				'template',
				'stylesheet',
				'wpseo_titles',
				'rank-math-options-titles',
				'aioseo_options',
				'aioseo_options_dynamic',
			),
			true
		) || str_starts_with( $option, 'genesis-cpt-archive-settings-' );
	}

	private function is_published_publication_post( mixed $post ): bool {
		if (
			! is_object( $post )
			|| 'publish' !== (string) ( $post->post_status ?? '' )
			|| 'revision' === (string) ( $post->post_type ?? '' )
		) {
			return false;
		}

		return \Cybermaps\Core\PublicationPostTypes::contains(
			(string) ( $post->post_type ?? '' )
		);
	}
}
