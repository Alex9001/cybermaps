<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts an SEO provider's stored directives into normalized signals.
 */
interface SeoCompatibilityAdapter {
	public function get_id(): string;

	public function get_signals( SeoContext $context ): ?SeoSignals;
}
