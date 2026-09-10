<?php
/**
 * News Sitemap Provider
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NewsProvider extends BaseProvider {
	private ?int $reference_timestamp = null;

	/**
	 * Get URLs for a specific page.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public function get_urls( int $page ): array {
		$settings = $this->get_settings();
		// News has one canonical route rather than numbered child routes. Generate
		// up to Google's protocol maximum so the index and static inventory cannot
		// advertise a partial page set.
		$per_page = 1000;
		$offset   = ( $page - 1 ) * $per_page;
		$posts    = array_slice( $this->get_recent_rows(), $offset, $per_page );

		if ( empty( $posts ) ) {
			return array();
		}

		$urls             = array();
		$publication_name = is_scalar( $settings['news_publication_name'] ?? null )
			? trim( (string) $settings['news_publication_name'] )
			: '';
		if ( '' === $publication_name ) {
			$publication_name = (string) get_bloginfo( 'name' );
		}
		$language = $this->google_news_language( (string) get_bloginfo( 'language' ) );

		foreach ( $posts as $p ) {
			$urls[] = array(
				'loc'     => \Cybermaps\Core\URLManager::rewrite_url( get_permalink( $p->ID ) ),
				'lastmod' => gmdate( 'c', strtotime( $p->post_modified_gmt ) ),
				'news'    => array(
					'publication'      => array(
						'name'     => $publication_name,
						'language' => $language,
					),
					'publication_date' => gmdate( 'c', strtotime( $p->post_date_gmt ) ),
					'title'            => $p->post_title,
				),
			);
		}

		return $urls;
	}

	/**
	 * Get total count of items.
	 *
	 * @return int
	 */
	public function get_count(): int {
		return min( 1000, count( $this->get_recent_rows() ) );
	}

	/**
	 * Get last modification date.
	 *
	 * @return string
	 */
	public function get_lastmod(): string {
		$lastmod = '';
		foreach ( $this->get_recent_rows() as $row ) {
			$candidate = (string) ( $row->post_modified_gmt ?? '' );
			if ( $candidate > $lastmod ) {
				$lastmod = $candidate;
			}
		}
		return '' !== $lastmod ? gmdate( 'c', strtotime( $lastmod ) ) : '';
	}

	/**
	 * Return the next time an eligible entry will leave the 48-hour window.
	 */
	public function get_next_expiration_timestamp( ?int $after = null ): ?int {
		$after = max( 0, $after ?? time() );
		$next  = null;

		foreach ( $this->get_recent_rows( $after ) as $row ) {
			$published = self::publication_timestamp( $row );
			if ( $published < 1 ) {
				continue;
			}

			// The current predicate includes the exact 48-hour boundary.
			$expires = $published + ( 2 * DAY_IN_SECONDS ) + 1;
			if ( $expires > $after && ( null === $next || $expires < $next ) ) {
				$next = $expires;
			}
		}

		return $next;
	}

	/**
	 * Whether an eligible entry expired after one checkpoint and by another.
	 */
	public function has_expiration_between( int $start, int $end ): bool {
		if ( $end <= $start ) {
			return false;
		}

		return $this->get_eligible_content()->has_eligible_post_in_publication_window(
			'post',
			$start - ( 2 * DAY_IN_SECONDS ) - 1,
			$end - ( 2 * DAY_IN_SECONDS ) - 1
		);
	}

	/**
	 * Return eligible posts still inside the Google News publication window.
	 *
	 * @return object[]
	 */
	private function get_recent_rows( ?int $timestamp = null ): array {
		if ( null === $timestamp ) {
			$this->reference_timestamp ??= time();
			$timestamp                   = $this->reference_timestamp;
		}

		return $this->get_eligible_content()->get_recent_post_rows(
			'post',
			max( 0, $timestamp ),
			2 * DAY_IN_SECONDS,
			1000
		);
	}

	private static function publication_timestamp( object $row ): int {
		$date      = trim( (string) ( $row->post_date_gmt ?? '' ) );
		$timestamp = '' === $date ? false : strtotime( $date . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Google News accepts base languages and specifically recognizes zh-cn and
	 * zh-tw. Other regional WordPress locales reduce to their base language.
	 */
	private function google_news_language( string $language ): string {
		$normalized = \strtolower( \str_replace( '_', '-', \trim( $language ) ) );
		if ( \in_array( $normalized, array( 'zh-cn', 'zh-tw' ), true ) ) {
			return $normalized;
		}

		$base = \explode( '-', $normalized )[0] ?? '';
		return 1 === \preg_match( '/^[a-z]{2,8}$/', $base ) ? $base : 'en';
	}
}
