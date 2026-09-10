<?php
/**
 * Sitemap Provider Interface
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ProviderInterface
 */
interface ProviderInterface {
	/**
	 * Get URLs for a specific page.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public function get_urls( int $page ): array;

	/**
	 * Get total count of items.
	 *
	 * @return int
	 */
	public function get_count(): int;

	/**
	 * Get last modification date.
	 *
	 * @return string
	 */
	public function get_lastmod(): string;
}
