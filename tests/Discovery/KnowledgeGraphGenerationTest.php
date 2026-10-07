<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

/** Bounded real-generator revocations; no database or remote service is used. */
final class KnowledgeGraphGenerationTest extends \PHPUnit\Framework\TestCase {
	/** @dataProvider revocation_stages */
	public function test_known_revocation_never_returns_derived_private_offer( string $stage ): void {
		$result = $this->run_fixture( $stage );
		self::assertTrue( $result['fired'] );
		self::assertSame( 0, $result['before'] );
		self::assertSame( 1, $result['after'] );
		self::assertSame( 'private', $result['post_status'] );
		self::assertTrue( $result['blocked'] );
		self::assertFalse( $result['returned_private_offer'] );
	}

	public static function revocation_stages(): array {
		return array( array( 'website' ), array( 'settings' ), array( 'discovery' ), array( 'filter' ), array( 'serialization' ) );
	}

	public function test_stable_public_offer_is_preserved(): void {
		$result = $this->run_fixture( 'stable' );
		self::assertFalse( $result['fired'] );
		self::assertSame( 0, $result['after'] );
		self::assertSame( 'publish', $result['post_status'] );
		self::assertFalse( $result['blocked'] );
		self::assertTrue( $result['returned_private_offer'] );
	}

	private function run_fixture( string $stage ): array {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/knowledge-graph-return-race.php', $stage ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}
}
