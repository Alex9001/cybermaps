<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical Cybermaps AI Discovery Manifest handler.
 *
 * The historical ADP class name remains as a compatibility surface, but the
 * public document is a Cybermaps vendor extension rather than an external
 * protocol or independently standardized format.
 */
final class AIManifest extends ADP {}
