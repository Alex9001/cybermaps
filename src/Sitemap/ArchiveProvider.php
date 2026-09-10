<?php
/**
 * Archive Sitemap Provider
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArchiveProvider extends BaseProvider {

	/**
	 * Get URLs for a specific page.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public function get_urls( int $page ): array {
		$per_page = $this->get_per_page();
		$archives = $this->get_eligible_content()->get_archive_page( $page, $per_page );

		if ( empty( $archives ) ) {
			return array();
		}

		$urls   = array();
		$weight = $this->get_calculated_priority( ProviderIdentity::ARCHIVES );
		foreach ( $archives as $archive ) {
			$archive_url = get_month_link( $archive['year'], $archive['month'] );
			if ( $archive_url ) {
				$urls[] = array(
					'loc'        => \Cybermaps\Core\URLManager::rewrite_url( $archive_url ),
					'lastmod'    => $archive['lastmod'],
					'priority'   => $weight,
					'images'     => array(),
					'changefreq' => 'daily',
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
		$count = $this->get_eligible_content()->get_archive_count();
		if ( $count > 0 && $count <= $this->get_per_page() ) {
			return count(
				$this->get_eligible_content()->get_archive_page(
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
		return $this->get_eligible_content()->get_archive_lastmod();
	}
}
