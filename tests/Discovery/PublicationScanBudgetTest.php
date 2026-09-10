<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Discovery\PublicationScanBudget;

final class PublicationScanBudgetTest extends \PHPUnit\Framework\TestCase {
	public function test_exhaustion_is_reported_only_when_another_candidate_exists(): void {
		$scan = new PublicationScanBudget( 2 );
		self::assertTrue( $scan->claim() );
		self::assertTrue( $scan->claim() );
		self::assertSame( 0, $scan->remaining() );
		self::assertFalse( $scan->truncated() );
		self::assertFalse( $scan->claim() );
		self::assertTrue( $scan->truncated() );
		self::assertSame( 2, $scan->scanned() );
	}

	public function test_deadline_fails_instead_of_reporting_successful_truncation(): void {
		$scan = new PublicationScanBudget();
		( new \ReflectionProperty( $scan, 'started' ) )->setValue( $scan, ( hrtime( true ) / 1e9 ) - PublicationScanBudget::MAX_SECONDS - 1 );
		$this->expectException( BuildUnavailableException::class );
		$scan->claim();
	}
}
