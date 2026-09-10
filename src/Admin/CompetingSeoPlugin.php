<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects common SEO plugins that may publish competing XML sitemaps.
 */
final class CompetingSeoPlugin {

	/**
	 * @return string Human-readable plugin name, or empty when none detected.
	 */
	public static function detect(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'All in One SEO';
		}
		return '';
	}
}
