<?php
/**
 * Base Sitemap Provider
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseProvider implements ProviderInterface {
	private ?EligibleContentRepository $eligible_content = null;

	/**
	 * Get plugin settings.
	 *
	 * @return array
	 */
	protected function get_settings() {
		return \Cybermaps\Core\ConfigurationStore::settings();
	}

	/**
	 * Get items per page.
	 *
	 * @return int
	 */
	protected function get_per_page() {
		return Orchestrator::URLS_PER_PAGE;
	}

	/**
	 * Shared request-local bounded eligible-resource access.
	 */
	protected function get_eligible_content(): EligibleContentRepository {
		if ( ! $this->eligible_content instanceof EligibleContentRepository ) {
			$this->eligible_content = new EligibleContentRepository( $this->get_settings() );
		}

		return $this->eligible_content;
	}

	/**
	 * Get alternates (hreflang) for an item.
	 *
	 * @param int    $item_id   Item ID.
	 * @param string $item_type Item type.
	 * @return array
	 */
	protected function get_alternates( $item_id, $item_type = 'post' ) {
		$settings = $this->get_settings();
		if ( empty( $settings['enable_translation_integrations'] ) || '1' !== (string) $settings['enable_translation_integrations'] ) {
			return array();
		}

		$reference = $this->normalize_item_reference( $item_id, $item_type );
		if ( null === $reference ) {
			return array();
		}
		$item_id   = $reference['item_id'];
		$item_type = $reference['item_type'];

		$registry   = $this->get_translation_registry();
		$site_id    = get_current_blog_id();
		$results    = $registry->get_translations( $site_id, $item_id, $item_type );
		$candidates = $this->get_translation_candidates( $results, $site_id, $item_id, $item_type );

		// When malformed legacy data assigns one language more than once, prefer
		// the requested resource as its own reciprocal hreflang.
		\usort(
			$candidates,
			static fn ( array $left, array $right ): int =>
				(int) $right['source'] <=> (int) $left['source']
		);

		return $this->build_alternates( $candidates, $site_id );
	}

	/**
	 * Normalize a requested translation resource.
	 *
	 * @return array{item_id:int,item_type:string}|null
	 */
	private function normalize_item_reference( mixed $item_id, mixed $item_type ): ?array {
		$item_id   = $this->normalize_positive_id( $item_id );
		$item_type = $this->normalize_item_type( $item_type );
		if ( $item_id < 1 || ! \in_array( $item_type, array( 'post', 'term' ), true ) ) {
			return null;
		}

		return array(
			'item_id'   => $item_id,
			'item_type' => $item_type,
		);
	}

	/**
	 * Normalize candidate rows returned by the translation registry.
	 *
	 * @return array<int, array{site_id:int,item_id:int,item_type:string,language:string,source:bool}>
	 */
	private function get_translation_candidates( mixed $results, int $site_id, int $item_id, string $item_type ): array {
		if ( ! \is_array( $results ) ) {
			return array();
		}

		$candidates = array();
		foreach ( $results as $row ) {
			$candidate = $this->normalize_translation_candidate( $row, $site_id, $item_id, $item_type );
			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}

	/**
	 * Normalize one translation row.
	 *
	 * @return array{site_id:int,item_id:int,item_type:string,language:string,source:bool}|null
	 */
	private function normalize_translation_candidate( mixed $row, int $site_id, int $item_id, string $item_type ): ?array {
		$row = \is_object( $row ) ? \get_object_vars( $row ) : $row;
		if ( ! \is_array( $row ) ) {
			return null;
		}

		$row_site_id = $this->normalize_positive_id( $row['site_id'] ?? 0 );
		$row_item_id = $this->normalize_positive_id( $row['item_id'] ?? 0 );
		$row_type    = $this->normalize_item_type( $row['item_type'] ?? '' );
		$language    = \Cybermaps\Core\TranslationHelper::normalize_hreflang( $row['lang_code'] ?? '' );
		if ( ! $this->is_valid_translation_candidate( $row_site_id, $row_item_id, $row_type, $language, $site_id, $item_type ) ) {
			return null;
		}

		return array(
			'site_id'   => $row_site_id,
			'item_id'   => $row_item_id,
			'item_type' => $row_type,
			'language'  => $language,
			'source'    => $row_site_id === $site_id && $row_item_id === $item_id,
		);
	}

	/**
	 * Whether a normalized translation row is usable in this request.
	 */
	private function is_valid_translation_candidate(
		int $row_site_id,
		int $row_item_id,
		string $row_type,
		string $language,
		int $site_id,
		string $item_type
	): bool {
		return $row_site_id > 0
			&& $row_item_id > 0
			&& $item_type === $row_type
			&& '' !== $language
			&& ( \is_multisite() || $row_site_id === $site_id );
	}

	/**
	 * Resolve deduplicated alternate URLs.
	 *
	 * @param array<int, array{site_id:int,item_id:int,item_type:string,language:string,source:bool}> $candidates Candidates.
	 * @return array<string, string>
	 */
	private function build_alternates( array $candidates, int $site_id ): array {
		$alternates = array();
		foreach ( $candidates as $candidate ) {
			$language = (string) $candidate['language'];
			if ( isset( $alternates[ $language ] ) ) {
				continue;
			}

			$url = $this->get_candidate_url( $candidate, $site_id );
			if ( '' !== $url ) {
				$alternates[ $language ] = $url;
			}
		}

		return $alternates;
	}

	/**
	 * Resolve one candidate URL in its site context.
	 *
	 * @param array{site_id:int,item_id:int,item_type:string,language:string,source:bool} $candidate Candidate.
	 */
	private function get_candidate_url( array $candidate, int $site_id ): string {
		$switched = $this->switch_to_candidate_site( (int) $candidate['site_id'], $site_id );
		if ( null === $switched ) {
			return '';
		}

		try {
			$url = $this->get_eligible_alternate_url(
				(int) $candidate['item_id'],
				(string) $candidate['item_type']
			);
			// Headless URL settings are site-local, so rewrite and validate
			// while the translated site's WordPress context is still active.
			return \Cybermaps\Core\URLManager::sanitize_http_url(
				\Cybermaps\Core\URLManager::rewrite_url( $url )
			);
		} finally {
			if ( $switched ) {
				\restore_current_blog();
			}
		}
	}

	/**
	 * Enter a candidate's site context.
	 *
	 * @return bool|null False when no switch is needed, null when unavailable.
	 */
	private function switch_to_candidate_site( int $row_site_id, int $site_id ): ?bool {
		if ( $row_site_id === $site_id ) {
			return false;
		}
		if ( \function_exists( 'get_site' ) && ! $this->is_public_site( \get_site( $row_site_id ) ) ) {
			return null;
		}

		$switched = (bool) \switch_to_blog( $row_site_id );
		return $switched ? true : null;
	}

	/**
	 * Normalize a positive identifier.
	 */
	private function normalize_positive_id( mixed $value ): int {
		return \is_scalar( $value ) ? \max( 0, (int) $value ) : 0;
	}

	/**
	 * Normalize a supported item type.
	 */
	private function normalize_item_type( mixed $value ): string {
		return \is_scalar( $value )
			? \substr( \sanitize_key( (string) $value ), 0, 20 )
			: '';
	}

	/**
	 * Isolate the registry factory so provider behavior can be tested without a
	 * database and extensions can subclass without replacing the container.
	 */
	protected function get_translation_registry(): \Cybermaps\Core\TranslationRegistry {
		return new \Cybermaps\Core\TranslationRegistry();
	}

	/**
	 * Return a URL only when the translated object can independently appear in
	 * a sitemap on its own site.
	 */
	private function get_eligible_alternate_url( int $item_id, string $item_type ): string {
		$eligibility = new \Cybermaps\SEO\PublicationEligibility(
			null,
			\Cybermaps\Core\ConfigurationStore::settings()
		);

		if ( 'post' === $item_type ) {
			return $this->get_eligible_post_url( $item_id, $eligibility );
		}
		return $this->get_eligible_term_url( $item_id, $eligibility );
	}

	/**
	 * Resolve an eligible post URL.
	 */
	private function get_eligible_post_url( int $item_id, \Cybermaps\SEO\PublicationEligibility $eligibility ): string {
		$post = \get_post( $item_id );
		if (
			! \is_object( $post )
			|| ! \Cybermaps\Core\PublicationPostTypes::contains( (string) ( $post->post_type ?? '' ) )
			|| ! $eligibility->post(
				$post,
				\Cybermaps\SEO\PublicationEligibility::SITEMAP
			)->indexable
		) {
			return '';
		}

		$url = \get_permalink( $item_id );
		return \is_string( $url ) ? $url : '';
	}

	/**
	 * Resolve an eligible term URL.
	 */
	private function get_eligible_term_url( int $item_id, \Cybermaps\SEO\PublicationEligibility $eligibility ): string {

		if ( ! \function_exists( 'get_term' ) ) {
			return '';
		}
		$term = \get_term( $item_id );
		if ( ! \is_object( $term ) || \is_wp_error( $term ) ) {
			return '';
		}

		$taxonomy        = \substr(
			\sanitize_key( (string) ( $term->taxonomy ?? '' ) ),
			0,
			32
		);
		$taxonomy_object = '' !== $taxonomy ? \get_taxonomy( $taxonomy ) : null;
		$settings        = \Cybermaps\Core\ConfigurationStore::settings();
		if ( ! $this->is_eligible_term( $term, $taxonomy, $taxonomy_object, $settings, $eligibility ) ) {
			return '';
		}

		$url = \get_term_link( $term, $taxonomy );
		return \is_string( $url ) && ! \is_wp_error( $url ) ? $url : '';
	}

	/**
	 * Whether a translated term independently qualifies for publication.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	private function is_eligible_term(
		object $term,
		string $taxonomy,
		mixed $taxonomy_object,
		array $settings,
		\Cybermaps\SEO\PublicationEligibility $eligibility
	): bool {
		return '' !== $taxonomy
			&& \is_object( $taxonomy_object )
			&& ! empty( $taxonomy_object->public )
			&& (
				! empty( $settings['include_empty_terms'] )
				|| ! isset( $term->count )
				|| (int) $term->count >= 1
			)
			&& $eligibility->term(
				$term,
				$taxonomy,
				\Cybermaps\SEO\PublicationEligibility::SITEMAP
			)->indexable;
	}

	/**
	 * Exclude deleted, archived, spammed, and non-public multisite members.
	 */
	private function is_public_site( mixed $site ): bool {
		if ( ! \is_object( $site ) ) {
			return false;
		}

		foreach ( array( 'archived', 'spam', 'deleted' ) as $flag ) {
			if ( ! empty( $site->{$flag} ) ) {
				return false;
			}
		}

		return ! isset( $site->public ) || '0' !== (string) $site->public;
	}

	/**
	 * Get calculated priority for a given type.
	 *
	 * @param string $type The object type (post type or taxonomy).
	 * @return float
	 */
	protected function get_calculated_priority( $type ) {
		return PriorityEngine::calculate( $type );
	}
}
