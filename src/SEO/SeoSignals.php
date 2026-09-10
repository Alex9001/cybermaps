<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO directives reported by one compatibility adapter.
 */
final class SeoSignals {
	/**
	 * @param string $source      Stable adapter/source ID.
	 * @param bool   $noindex     Whether the resource declares noindex.
	 * @param bool   $nofollow    Whether the resource declares nofollow.
	 * @param bool   $noarchive   Whether the resource declares noarchive.
	 * @param string $canonical   Declared canonical URL.
	 * @param string $redirect    Declared redirect URL.
	 * @param string $title       Provider title, for diagnostics.
	 * @param string $description Provider description, for diagnostics.
	 * @param array  $details     Source-specific evidence.
	 */
	public function __construct(
		public readonly string $source,
		public readonly bool $noindex = false,
		public readonly bool $nofollow = false,
		public readonly bool $noarchive = false,
		public readonly string $canonical = '',
		public readonly string $redirect = '',
		public readonly string $title = '',
		public readonly string $description = '',
		public readonly array $details = array()
	) {}
}
