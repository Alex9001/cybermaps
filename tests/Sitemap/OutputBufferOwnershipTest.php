<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

final class OutputBufferOwnershipTest extends \PHPUnit\Framework\TestCase {
	public function test_sitemap_exits_preserve_caller_buffers(): void {
		require_once dirname( __DIR__ ) . '/integration/output-buffer-cases.php';
		$cases = \cybermaps_buffer_cases( dirname( __DIR__ ) . '/bootstrap.php', 'https://example.com' );
		self::assertCount( 48, $cases );
		foreach ( $cases as $case ) { self::assertTrue( $case['passed'] ); }
	}
}
