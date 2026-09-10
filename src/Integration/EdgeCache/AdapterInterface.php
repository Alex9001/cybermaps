<?php
/**
 * Edge cache invalidation adapter contract.
 *
 * @package Cybermaps\Integration\EdgeCache
 */

declare(strict_types=1);

namespace Cybermaps\Integration\EdgeCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AdapterInterface {
	/**
	 * @param array<string,mixed> $event Bounded canonical invalidation event.
	 * @return array<string,mixed> Delivery result suitable for status reporting.
	 */
	public function purge( array $event ): array;
}
