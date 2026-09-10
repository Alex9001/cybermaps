<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\PositiveIdList;
use PHPUnit\Framework\TestCase;

final class PositiveIdListTest extends TestCase {
	public function test_parser_keeps_unique_positive_ids_in_input_order(): void {
		self::assertSame(
			array( 12, 45, 102 ),
			PositiveIdList::parse( '12, invalid, 45, 12, 0, -3, 102' )
		);
	}

	public function test_parser_accepts_arrays_and_applies_item_and_byte_limits(): void {
		self::assertSame( array( 1, 22 ), PositiveIdList::parse( array( 1, '22', 1, null ) ) );
		self::assertSame( array( 1, 22 ), PositiveIdList::parse( '1, 22, 333', 2 ) );
		self::assertSame( '1, 22', PositiveIdList::to_csv( '1, 22, 333', PHP_INT_MAX, 5 ) );
	}
}
