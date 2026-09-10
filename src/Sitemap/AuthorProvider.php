<?php
/**
 * Author Sitemap Provider
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuthorProvider extends BaseProvider {

	/**
	 * Get URLs for a specific page.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public function get_urls( int $page ): array {
		$per_page = $this->get_per_page();
		$users    = $this->get_eligible_content()->get_author_page( $page, $per_page );

		if ( empty( $users ) ) {
			return array();
		}

		$urls   = array();
		$weight = $this->get_calculated_priority( ProviderIdentity::AUTHORS );
		foreach ( $users as $user ) {
			$author_url = get_author_posts_url( $user['user_id'] );
			if ( $author_url ) {
				$urls[] = array(
					'loc'        => \Cybermaps\Core\URLManager::rewrite_url( $author_url ),
					'lastmod'    => $user['lastmod'],
					'priority'   => $weight,
					'images'     => array(),
					'changefreq' => 'monthly',
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
		$count = $this->get_eligible_content()->get_author_count();
		if ( $count > 0 && $count <= $this->get_per_page() ) {
			return count(
				$this->get_eligible_content()->get_author_page(
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
		return $this->get_eligible_content()->get_author_lastmod();
	}
}
