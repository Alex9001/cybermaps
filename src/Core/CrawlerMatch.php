<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A crawler identity claimed by a matching User-Agent product token.
 */
readonly class CrawlerMatch {
	/**
	 * @param string      $id        Stable crawler registry ID.
	 * @param BotMetadata $metadata Registered crawler metadata.
	 * @param string      $signature Explicit User-Agent token that matched.
	 * @param string      $basis     Evidence used for the classification.
	 */
	public function __construct(
		public string $id,
		public BotMetadata $metadata,
		public string $signature,
		public string $basis = 'ua_signature'
	) {}
}
