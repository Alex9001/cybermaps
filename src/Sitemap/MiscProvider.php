<?php
declare(strict_types=1);
namespace Cybermaps\Sitemap;

use Cybermaps\SEO\PublicationEligibility;
use Cybermaps\SEO\SeoContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MiscProvider extends BaseProvider {

	/**
	 * Get URLs for a specific page.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public function get_urls( int $page ): array {
		if ( $page > 1 ) {
			return array();
		}

		$settings = $this->get_settings();
		$urls     = array();
		$weight   = $this->get_calculated_priority( ProviderIdentity::MISC );
		$home     = new PublicationEligibility( null, $settings );

		if ( $home->decide( SeoContext::home(), PublicationEligibility::SITEMAP )->indexable ) {
			$urls[] = array(
				'loc'        => \Cybermaps\Core\URLManager::get_home_url( '/' ),
				'lastmod'    => $this->get_home_lastmod(),
				'priority'   => $weight,
				'images'     => array(),
				'changefreq' => 'daily',
			);
		}

		$external_pages = is_scalar( $settings['external_pages'] ?? null )
			? (string) $settings['external_pages']
			: '';
		if ( '' !== $external_pages ) {
			$external_urls = array_filter(
				array_map(
					'trim',
					explode(
						"\n",
						ExternalSitemapValidator::filter_page_textarea( $external_pages )
					)
				)
			);
			foreach ( $external_urls as $url ) {
				$urls[] = array(
					'loc'        => $url,
					'lastmod'    => '',
					'priority'   => $weight,
					'images'     => array(),
					'changefreq' => '',
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
		$settings = $this->get_settings();
		$count    = 0;
		$home     = new PublicationEligibility( null, $settings );
		if ( $home->decide( SeoContext::home(), PublicationEligibility::SITEMAP )->indexable ) {
			++$count;
		}
		$external_pages = is_scalar( $settings['external_pages'] ?? null )
			? (string) $settings['external_pages']
			: '';
		if ( '' !== $external_pages ) {
			$external_urls = array_filter(
				explode(
					"\n",
					ExternalSitemapValidator::filter_page_textarea( $external_pages )
				)
			);
			$count        += count( $external_urls );
		}
		return $count;
	}

	/**
	 * Get last modification date.
	 *
	 * @return string
	 */
	public function get_lastmod(): string {
		return $this->get_home_lastmod();
	}

	private function get_home_lastmod(): string {
		if ( 'page' === (string) get_option( 'show_on_front', 'posts' ) ) {
			$front_id = (int) get_option( 'page_on_front', 0 );
			$post     = $front_id > 0 ? get_post( $front_id ) : null;
			if ( is_object( $post ) && ! empty( $post->post_modified_gmt ) ) {
				return gmdate( 'c', strtotime( (string) $post->post_modified_gmt ) );
			}
		}

		return $this->get_eligible_content()->get_post_lastmod( 'post' );
	}
}
