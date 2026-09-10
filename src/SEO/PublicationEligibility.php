<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies Cybermaps channel scope after provider-level indexability.
 */
final class PublicationEligibility {
	public const SITEMAP = 'sitemap';
	public const AI      = 'ai';
	public const SCHEMA  = 'schema';
	public const REPORT  = 'report';

	private IndexabilityResolver $resolver;
	private array $settings;

	/**
	 * @var array<string,float>
	 */
	private array $priority_cache = array();

	public function __construct( ?IndexabilityResolver $resolver = null, ?array $settings = null ) {
		$this->resolver = $resolver ?? new IndexabilityResolver();
		$this->settings = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
	}

	public function decide( SeoContext $context, string $channel = self::SITEMAP ): IndexabilityDecision {
		$decision = $this->resolver->resolve( $context );
		$reasons  = $this->scope_reasons( $context, $channel );
		$result   = $decision->with_reasons( $reasons );

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'cybermaps_publication_eligibility', $result, $context, $channel );
			if ( $filtered instanceof IndexabilityDecision ) {
				$result = $filtered;
			}
		}

		return $result;
	}

	public function post( object|int $post, string $channel = self::SITEMAP ): IndexabilityDecision {
		return $this->decide( SeoContext::post( $post ), $channel );
	}

	public function term( object|int $term, string $taxonomy = '', string $channel = self::SITEMAP ): IndexabilityDecision {
		return $this->decide( SeoContext::term( $term, $taxonomy ), $channel );
	}

	/**
	 * @return string[]
	 */
	private function scope_reasons( SeoContext $context, string $channel ): array {
		$reasons = array();

		if ( SeoContext::POST === $context->type ) {
			$reasons = array_merge( $reasons, $this->post_scope_reasons( $context, $channel ) );
		} elseif ( SeoContext::TERM === $context->type ) {
			$reasons = array_merge( $reasons, $this->term_scope_reasons( $context, $channel ) );
		} elseif ( SeoContext::HOME === $context->type && self::SITEMAP === $channel && empty( $this->settings['include_homepage'] ) ) {
			$reasons[] = 'homepage_disabled';
		} elseif ( SeoContext::AUTHOR === $context->type && self::SITEMAP === $channel && empty( $this->settings['include_authors'] ) ) {
			$reasons[] = 'authors_disabled';
		} elseif ( SeoContext::DATE_ARCHIVE === $context->type && self::SITEMAP === $channel && empty( $this->settings['include_archives'] ) ) {
			$reasons[] = 'archives_disabled';
		}

		return $reasons;
	}

	/** @return string[] */
	private function post_scope_reasons( SeoContext $context, string $channel ): array {
		$id      = $context->object_id;
		$reasons = empty( \Cybermaps\Core\PublicationPostTypes::filter_names( array( $context->subtype ) ) ) ? array( 'publication_post_type_excluded' ) : array();
		if ( self::SITEMAP === $channel ) {
			$reasons = array_merge( $reasons, $this->sitemap_post_reasons( $context, $id ) );
		} elseif ( self::AI === $channel ) {
			$reasons = array_merge( $reasons, $this->ai_post_reasons( $id ) );
		}
		if ( ! in_array( $channel, array( self::SCHEMA, self::REPORT ), true ) && $this->priority( \Cybermaps\Sitemap\ProviderIdentity::post_type( $context->subtype ) ) <= 0 ) {
			$reasons[] = 'matrix_type_disabled';
		}
		return $reasons;
	}

	/** @return string[] */
	private function sitemap_post_reasons( SeoContext $context, int $id ): array {
		$reasons = array();
		if ( '1' === (string) get_post_meta( $id, '_cybermaps_exclude_sitemap', true ) ) {
			$reasons[] = 'cybermaps_sitemap_exclusion'; }
		if ( $this->id_in_setting( $id, 'exclude_post_ids' ) ) {
			$reasons[] = 'cybermaps_global_post_exclusion'; }
		if ( $this->post_has_excluded_terms( $id, 'exclude_categories', 'category' ) ) {
			$reasons[] = 'cybermaps_category_exclusion'; }
		if ( 'page' === $context->subtype && 'page' === (string) get_option( 'show_on_front', 'posts' ) && (int) get_option( 'page_on_front', 0 ) === $id ) {
			$reasons[] = 'homepage_owned_by_misc'; }
		return $reasons;
	}

	/** @return string[] */
	private function ai_post_reasons( int $id ): array {
		$reasons = array();
		if ( '1' === (string) get_post_meta( $id, '_cybermaps_exclude_ai', true ) ) {
			$reasons[] = 'cybermaps_ai_exclusion'; }
		if ( $this->id_in_setting( $id, 'llms_exclude_ids' ) ) {
			$reasons[] = 'cybermaps_global_ai_exclusion'; }
		if ( $this->post_has_any_excluded_term( $id, 'ai_sitemap_exclude_terms' ) ) {
			$reasons[] = 'cybermaps_global_ai_term_exclusion'; }
		return $reasons;
	}

	/** @return string[] */
	private function term_scope_reasons( SeoContext $context, string $channel ): array {
		$reasons = array();
		if ( ! in_array( $channel, array( self::SCHEMA, self::REPORT ), true ) && $this->priority( \Cybermaps\Sitemap\ProviderIdentity::taxonomy( $context->subtype ) ) <= 0 ) {
			$reasons[] = 'matrix_type_disabled'; }
		if ( self::SITEMAP === $channel && 'category' === $context->subtype && $this->term_in_setting( $context, 'exclude_categories' ) ) {
			$reasons[] = 'cybermaps_global_term_exclusion'; }
		if ( self::AI === $channel && $this->term_in_setting( $context, 'ai_sitemap_exclude_terms' ) ) {
			$reasons[] = 'cybermaps_global_ai_term_exclusion'; }
		return $reasons;
	}

	private function priority( string $type ): float {
		if ( ! array_key_exists( $type, $this->priority_cache ) ) {
			$this->priority_cache[ $type ] = (float) \Cybermaps\Sitemap\PriorityEngine::calculate( $type );
		}
		return $this->priority_cache[ $type ];
	}

	private function id_in_setting( int $id, string $key ): bool {
		return in_array( $id, \Cybermaps\Core\PositiveIdList::parse( $this->settings[ $key ] ?? '' ), true );
	}

	private function post_has_excluded_terms( int $post_id, string $key, string $taxonomy ): bool {
		if ( empty( $this->settings[ $key ] ) || ! function_exists( 'wp_get_post_terms' ) ) {
			return false;
		}

		$terms = wp_get_post_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return false;
		}

		$excluded = \Cybermaps\Core\TermExclusionList::parse( $this->settings[ $key ] ?? '' );
		foreach ( $terms as $term ) {
			if ( \Cybermaps\Core\TermExclusionList::term_matches( $term, $excluded ) ) {
				return true;
			}
		}

		return false;
	}

	private function post_has_any_excluded_term( int $post_id, string $key ): bool {
		if ( empty( $this->settings[ $key ] ) || ! function_exists( 'wp_get_object_terms' ) ) {
			return false;
		}

		$taxonomies = get_object_taxonomies( get_post_type( $post_id ) );
		if ( empty( $taxonomies ) ) {
			return false;
		}

		$terms = wp_get_object_terms( $post_id, $taxonomies );
		if ( ! is_array( $terms ) ) {
			return false;
		}

		$excluded = \Cybermaps\Core\TermExclusionList::parse( $this->settings[ $key ] ?? '' );

		foreach ( $terms as $term ) {
			if ( \Cybermaps\Core\TermExclusionList::term_matches( $term, $excluded ) ) {
				return true;
			}
		}

		return false;
	}

	private function term_in_setting( SeoContext $context, string $key ): bool {
		if ( empty( $this->settings[ $key ] ) ) {
			return false;
		}

		$excluded = \Cybermaps\Core\TermExclusionList::parse( $this->settings[ $key ] ?? '' );
		if ( \Cybermaps\Core\TermExclusionList::identifiers_match( $context->object_id, '', $excluded ) ) {
			return true;
		}

		$term = $context->object;
		if ( ! is_object( $term ) && function_exists( 'get_term' ) ) {
			$term = get_term( $context->object_id, $context->subtype );
		}

		return \Cybermaps\Core\TermExclusionList::term_matches( $term, $excluded );
	}
}
