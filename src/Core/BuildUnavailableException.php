<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A publication build could not finish safely; callers must not publish a prefix. */
final class BuildUnavailableException extends \RuntimeException {
	public const RETRY_AFTER = 5;
}
