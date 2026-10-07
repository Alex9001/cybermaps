<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Discovery;

use PHPUnit\Framework\TestCase;

final class OptionalMetadataBoundaryTest extends TestCase {
	private function fixture( string $mode ): array {
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/optional-metadata-contract.php', $mode ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $errors );
		self::assertSame( '', $errors );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}

	public function test_size_rejection_preserves_save_and_search_without_optional_hint(): void {
		$result = $this->fixture( 'size-rejection' );
		self::assertSame( 'x', $result['save']['content'] );
		self::assertEmpty( $result['save']['stale_meta'] );
		self::assertIsInt( $result['save']['transition'] );
		self::assertSame( 'Public hit', $result['search']['title'] );
		self::assertNotSame( '', $result['search']['url'] );
		self::assertSame( '', $result['search']['snippet'] );
		self::assertContains( $result['search']['intent'], array( 'informational', 'transactional' ) );
	}

	public function test_ordinary_errors_are_not_silently_swallowed(): void {
		$result = $this->fixture( 'ordinary-error' );
		self::assertSame( 'An ordinary dependency error.', $result['save']['error'] );
		self::assertSame( 'An ordinary dependency error.', $result['search']['error'] );
	}
}
