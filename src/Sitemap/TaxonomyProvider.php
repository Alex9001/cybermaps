<?php
/**
 * Taxonomy Sitemap Provider
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TaxonomyProvider extends BaseProvider {

	/**
	 * Taxonomy slug.
	 *
	 * @var string
	 */
	private $taxonomy;

	/**
	 * TaxonomyProvider constructor.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function __construct( string $taxonomy ) {
		$this->taxonomy = $taxonomy;
	}

	/**
	 * Get URLs for a specific page.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public function get_urls( int $page ): array {
		$per_page = $this->get_per_page();
		$terms    = $this->get_eligible_content()->get_term_page( $this->taxonomy, $page, $per_page );

		if ( empty( $terms ) ) {
			return array();
		}

		$urls     = array();
		$priority = $this->get_calculated_priority(
			ProviderIdentity::taxonomy( $this->taxonomy )
		);
		$lastmods = $this->get_eligible_content()->get_term_lastmods(
			array_map(
				static fn ( object $term ): int => (int) ( $term->term_id ?? 0 ),
				$terms
			),
			$this->taxonomy
		);

		foreach ( $terms as $term ) {
			$term_link = get_term_link( (int) $term->term_id, $this->taxonomy );
			if ( ! is_wp_error( $term_link ) ) {
				$urls[] = array(
					'loc'        => \Cybermaps\Core\URLManager::rewrite_url( $term_link ),
					'lastmod'    => $lastmods[ (int) $term->term_id ] ?? '',
					'priority'   => $priority,
					'images'     => array(),
					'alternates' => $this->get_alternates( (int) $term->term_id, 'term' ),
					'changefreq' => 'weekly',
				);
			}
		}

		return $urls;
	}

	/**
	 * Get total count of items.
	 *
	 * @return int
	 */
	public function get_count(): int {
		$count = $this->get_eligible_content()->get_term_count( $this->taxonomy );
		if ( $count > 0 && $count <= $this->get_per_page() ) {
			return count(
				$this->get_eligible_content()->get_term_page(
					$this->taxonomy,
					1,
					$this->get_per_page()
				)
			);
		}
		return $count;
	}

	/**
	 * Get last modification date.
	 *
	 * @return string
	 */
	public function get_lastmod(): string {
		return $this->get_eligible_content()->get_taxonomy_lastmod( $this->taxonomy );
	}
}
