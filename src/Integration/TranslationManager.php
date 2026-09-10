<?php
declare(strict_types=1);
namespace Cybermaps\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Cybermaps\Core\TranslationRegistry;

class TranslationManager {
	public const SYNC_DISABLED_META = '_cybermaps_translation_sync_disabled';

	/**
	 * Post metadata that can change whether a translated URL is eligible for a
	 * sitemap or which canonical URL it represents.
	 */
	private const PUBLICATION_META_KEYS = array(
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
	);

	private const TERM_META_KEYS = array( 'noindex', 'nofollow', 'noarchive' );

	/**
	 * Options whose changes can alter translated URLs or sitemap eligibility.
	 */
	private const PUBLICATION_OPTIONS = array(
		'active_plugins',
		'aioseo_options',
		'aioseo_options_dynamic',
		'blog_public',
		'category_base',
		'cybermaps_discovery_center',
		'cybermaps_settings',
		'genesis-seo-settings',
		'home',
		'page_on_front',
		'permalink_structure',
		'rank-math-options-titles',
		'show_on_front',
		'siteurl',
		'stylesheet',
		'tag_base',
		'template',
		'wpseo_titles',
	);

	/**
	 * @var TranslationRegistry
	 */
	private $registry;

	/**
	 * TranslationManager constructor.
	 *
	 * @param TranslationRegistry $registry
	 */
	public function __construct( TranslationRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register hooks for translation syncing.
	 */
	public function register_hooks() {
		// Sync on post save.
		add_action( 'save_post', array( $this, 'sync_post_translations' ), 20, 2 );
		// Run after the automatic mapper so manual-only relationships and
		// resource changes also invalidate every connected site's sitemap.
		add_action( 'save_post', array( $this, 'invalidate_post_publications' ), 100, 2 );

		// Remove registry rows when WordPress permanently deletes their post.
		add_action( 'before_delete_post', array( $this, 'delete_post_relationship' ), 10, 2 );

		// Refresh after WPML creates a duplicate.
		add_action( 'icl_make_duplicate', array( $this, 'sync_wpml_duplicate' ), 10, 4 );

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_post_metadata' ), 10, 4 );
		}
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_term_metadata' ), 10, 4 );
		}

		add_action( 'set_object_terms', array( $this, 'invalidate_post_terms' ), 10, 6 );
		add_action( 'created_term', array( $this, 'invalidate_term_publications' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'invalidate_term_publications' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'delete_term_relationship' ), 10, 1 );

		add_action( 'updated_option', array( $this, 'invalidate_changed_option' ), 10, 3 );
		add_action( 'added_option', array( $this, 'invalidate_added_option' ), 10, 2 );
		add_action( 'deleted_option', array( $this, 'invalidate_deleted_option' ), 10, 1 );

		// AIOSEO stores singular indexability outside WordPress post meta.
		add_action( 'aioseo_insert_post', array( $this, 'invalidate_site_publications' ), 10, 0 );
	}

	/**
	 * Sync translations when a post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function sync_post_translations( $post_id, $post ) {
		if ( ! $this->can_sync_post( $post_id, $post ) ) {
			return;
		}

		$translations = $this->post_translations( $post_id, $post );
		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return;
		}

		$this->sync_relationships( $post_id, $post, $translations );
	}

	private function can_sync_post( $post_id, $post ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( '1' !== (string) ( $settings['enable_translation_integrations'] ?? '0' ) ) {
			return false;
		}
		if ( ! is_object( $post ) || ! \Cybermaps\Core\PublicationPostTypes::contains( (string) ( $post->post_type ?? '' ) ) ) {
			return false;
		}
		if ( 'publish' !== (string) ( $post->post_status ?? '' ) ) {
			return false;
		}
		return '1' !== (string) get_post_meta( (int) $post_id, self::SYNC_DISABLED_META, true );
	}

	private function post_translations( $post_id, $post ): array {
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			return is_array( $translations ) ? $translations : array();
		}
		return $this->wpml_post_translations( $post_id, $post );
	}

	private function wpml_post_translations( $post_id, $post ): array {
		if ( ! $this->wpml_is_available() ) {
			return array();
		}
		$element_type = 'post_' . $post->post_type;
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
		$raw  = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, $element_type ) : array();
		// phpcs:enable
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$translations = array();
		foreach ( $raw as $lang => $details ) {
			$translation = $this->wpml_translation( $lang, $details );
			if ( null !== $translation ) {
				$translations[ $translation[0] ] = $translation[1];
			}
		}
		return $translations;
	}

	/** @return array{0:string,1:int}|null */
	private function wpml_translation( $lang, $details ): ?array {
		if ( is_object( $details ) ) {
			$raw_id   = $details->element_id ?? 0;
			$raw_lang = $details->language_code ?? $lang;
		} elseif ( is_array( $details ) ) {
			$raw_id   = $details['element_id'] ?? 0;
			$raw_lang = $details['language_code'] ?? $lang;
		} else {
			return null;
		}
		if ( ! is_scalar( $raw_id ) || ! is_scalar( $raw_lang ) || (int) $raw_id < 1 ) {
			return null;
		}
		return array( (string) $raw_lang, (int) $raw_id );
	}

	private function sync_relationships( $post_id, $post, array $translations ): void {
		$site_id       = get_current_blog_id();
		$group_id      = 0;
		$seen_post_ids = array();
		foreach ( $translations as $lang => $translated_id ) {
			if ( ! is_scalar( $translated_id ) ) {
				continue;
			}
			$translated_id   = (int) $translated_id;
			$language        = \Cybermaps\Core\TranslationHelper::normalize_hreflang( $lang );
			$translated_post = $translated_id === (int) $post_id ? $post : get_post( $translated_id );
			if ( ! $this->valid_translation( $translated_id, $language, $translated_post, $post, $seen_post_ids ) ) {
				continue;
			}
			$seen_post_ids[ $translated_id ] = true;
			$group_id                        = $this->registry->update_relationship( $group_id, $site_id, $translated_id, $language, 'post' );
			if ( $group_id < 1 ) {
				break;
			}
		}
		if ( $group_id > 0 ) {
			$this->registry->prune_group_relationships( $group_id, $site_id, array_keys( $seen_post_ids ), 'post' );
		}
	}

	private function valid_translation( int $translated_id, string $language, $translated_post, $post, array $seen_post_ids ): bool {
		if ( $translated_id < 1 || '' === $language || isset( $seen_post_ids[ $translated_id ] ) || ! is_object( $translated_post ) ) {
			return false;
		}
		if ( 'publish' !== (string) ( $translated_post->post_status ?? '' ) || (string) ( $translated_post->post_type ?? '' ) !== (string) $post->post_type ) {
			return false;
		}
		if ( ! \Cybermaps\Core\PublicationPostTypes::contains( (string) ( $translated_post->post_type ?? '' ) ) ) {
			return false;
		}
		return '1' !== (string) get_post_meta( $translated_id, self::SYNC_DISABLED_META, true );
	}

	/**
	 * Propagate post URL, status, password, and manual-relationship changes to
	 * the sitemap caches of every site in the relationship.
	 */
	public function invalidate_post_publications( $post_id, $post = null ): void {
		if ( ! is_multisite() ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return;
		}

		$site_id = get_current_blog_id();
		$post    = is_object( $post ) ? $post : get_post( $post_id );
		if (
			! is_object( $post )
			|| ! \Cybermaps\Core\PublicationPostTypes::contains(
				(string) ( $post->post_type ?? '' )
			)
		) {
			return;
		}

		$this->registry->invalidate_relationship( $site_id, $post_id, 'post' );
	}

	/**
	 * Invalidate a translated post after a relevant metadata change.
	 */
	public function invalidate_post_metadata(
		$meta_id,
		$object_id,
		$meta_key,
		$meta_value = null
	): void {
		unset( $meta_id, $meta_value );
		if (
			! is_multisite()
			|| ! in_array( (string) $meta_key, self::PUBLICATION_META_KEYS, true )
		) {
			return;
		}

		$this->registry->invalidate_relationship(
			get_current_blog_id(),
			(int) $object_id,
			'post'
		);
	}

	/**
	 * Term assignments can change a translated post's sitemap eligibility.
	 */
	public function invalidate_post_terms(
		$object_id,
		$terms = array(),
		$tt_ids = array(),
		$taxonomy = '',
		$append = false,
		$old_tt_ids = array()
	): void {
		unset( $terms, $taxonomy, $append );
		if ( ! is_multisite() ) {
			return;
		}

		$current  = array_values( array_unique( array_map( 'intval', (array) $tt_ids ) ) );
		$previous = array_values( array_unique( array_map( 'intval', (array) $old_tt_ids ) ) );
		sort( $current, SORT_NUMERIC );
		sort( $previous, SORT_NUMERIC );
		if ( $current === $previous ) {
			return;
		}

		$this->registry->invalidate_relationship(
			get_current_blog_id(),
			(int) $object_id,
			'post'
		);
	}

	/**
	 * Invalidate a translated term after its public data changes.
	 */
	public function invalidate_term_publications(
		$term_id,
		$term_taxonomy_id = 0,
		$taxonomy = ''
	): void {
		unset( $term_taxonomy_id, $taxonomy );
		if ( ! is_multisite() ) {
			return;
		}

		$this->registry->invalidate_relationship(
			get_current_blog_id(),
			(int) $term_id,
			'term'
		);
	}

	/**
	 * Term metadata may supply noindex signals used by publication eligibility.
	 */
	public function invalidate_term_metadata(
		$meta_id,
		$object_id,
		$meta_key,
		$meta_value = null
	): void {
		unset( $meta_id, $meta_value );
		if (
			! is_multisite()
			|| ! in_array( (string) $meta_key, self::TERM_META_KEYS, true )
		) {
			return;
		}

		$this->invalidate_term_publications( $object_id );
	}

	/**
	 * Remove an obsolete term identity from the shared registry.
	 */
	public function delete_term_relationship( $term_id ): void {
		$this->registry->delete_relationship(
			get_current_blog_id(),
			(int) $term_id,
			'term'
		);
	}

	/**
	 * Adapt WordPress's three-argument updated_option action.
	 */
	public function invalidate_changed_option( $option, $old_value, $value ): void {
		unset( $old_value, $value );
		$this->invalidate_option( (string) $option );
	}

	/**
	 * Adapt WordPress's two-argument added_option action.
	 */
	public function invalidate_added_option( $option, $value ): void {
		unset( $value );
		$this->invalidate_option( (string) $option );
	}

	/**
	 * Adapt WordPress's deleted_option action.
	 */
	public function invalidate_deleted_option( $option ): void {
		$this->invalidate_option( (string) $option );
	}

	/**
	 * Reconcile relationships affected by a site-wide eligibility change.
	 */
	private function invalidate_option( string $option ): void {
		if (
			! is_multisite()
			|| (
				! in_array( $option, self::PUBLICATION_OPTIONS, true )
				&& ! str_starts_with( $option, 'genesis-cpt-archive-settings-' )
			)
		) {
			return;
		}

		$this->invalidate_site_publications();
	}

	/**
	 * Invalidate all relationships represented by the current site.
	 */
	public function invalidate_site_publications(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$this->registry->invalidate_site_relationships( get_current_blog_id() );
	}

	/**
	 * Remove a deleted post from the shared translation registry.
	 *
	 * @param int             $post_id Post ID.
	 * @param \WP_Post|null   $post    Post object supplied by WordPress.
	 */
	public function delete_post_relationship( $post_id, $post = null ): void {
		unset( $post );
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return;
		}

		$this->registry->delete_relationship(
			get_current_blog_id(),
			$post_id,
			'post'
		);
	}

	/**
	 * Handle WPML duplicate creation.
	 */
	public function sync_wpml_duplicate( $master_post_id, $lang, $post_array, $duplicate_id ) {
		$this->sync_post_translations( $duplicate_id, get_post( $duplicate_id ) );
	}

	/**
	 * Detect WPML without relying on a non-existent helper function.
	 */
	private function wpml_is_available(): bool {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return true;
		}

		return function_exists( 'has_filter' )
			&& false !== has_filter( 'wpml_element_trid' )
			&& false !== has_filter( 'wpml_get_element_translations' );
	}
}
