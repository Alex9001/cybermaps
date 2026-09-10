<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Core\CacheFill;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Counts candidates before SEO evaluation and bounds synchronous build time. */
final class PublicationScanBudget {
	public const MAX_SECONDS = 20;
	private int $scanned     = 0;
	private bool $truncated  = false;
	private float $started;

	public function __construct( private int $limit = PHP_INT_MAX ) {
		$this->limit   = max( 0, $limit );
		$this->started = ( hrtime( true ) / 1e9 );
	}

	/** Claim one candidate before metadata filters or content extraction run. */
	public function claim(): bool {
		$this->checkpoint();
		if ( 0 === $this->remaining() ) {
			$this->truncated = true;
			return false;
		}
		++$this->scanned;
		return true;
	}

	public function remaining(): int {
		return max( 0, $this->limit - $this->scanned );
	}

	public function scanned(): int {
		return $this->scanned;
	}

	public function truncated(): bool {
		return $this->truncated;
	}

	/** A deadline failure is never a successful partial publication. */
	public function checkpoint(): void {
		if ( ( hrtime( true ) / 1e9 ) - $this->started >= self::MAX_SECONDS ) {
			throw new BuildUnavailableException( esc_html__( 'Cybermaps stopped publication at its generation time limit. No partial publication was produced.', 'cybermaps' ) );
		}
		CacheFill::heartbeat();
	}
}
