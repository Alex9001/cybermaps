<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use PHPUnit\Framework\TestCase;

final class DiagnosticClearTest extends TestCase {
	public function test_clear_verifies_retained_data_and_preserves_authorization_boundaries(): void {
		$result = $this->run_fixture( array( PHP_BINARY, __DIR__ . '/fixtures/diagnostic-clear.php' ) );
		$cases = $result['cases'];
		foreach ( array( 'failed_delete', 'read_failure', 'unsupported_database', 'malformed_retained', 'concurrent_event', 'after_verification_event' ) as $case ) {
			self::assertSame( 500, $cases[$case]['status'], $case );
			self::assertFalse( $cases[$case]['reply']['success'], $case );
			self::assertSame( 'Diagnostic events could not be cleared. Reload and try again.', $cases[$case]['reply']['data']['message'] );
		}
		foreach ( array( 'successful_delete', 'already_empty', 'already_absent' ) as $case ) {
			self::assertSame( 200, $cases[$case]['status'], $case );
			self::assertTrue( $cases[$case]['reply']['success'], $case );
			self::assertSame( 0, $cases[$case]['reply']['data']['state']['entry_count'], $case );
		}
		self::assertSame( 1, $cases['failed_delete']['retained_entries'] );
		self::assertSame( 'retained-malformed-data', $cases['malformed_retained']['retained_value'] );
		foreach ( array( 'concurrent_event', 'after_verification_event' ) as $case ) {
			self::assertSame( 1, $cases[$case]['retained_entries'] );
			self::assertSame( 1, $cases[$case]['delete_calls'], 'Never retry a delete over a successor event.' );
		}
		foreach ( array( 'forbidden' => 403, 'invalid_nonce' => 403, 'wrong_method' => 405 ) as $case => $status ) {
			self::assertSame( $status, $cases[$case]['status'] );
			self::assertSame( 0, $cases[$case]['delete_calls'] );
		}
	}

	public function test_client_preserves_displayed_events_when_clear_returns_an_error(): void {
		$result = $this->run_fixture( array( 'node', __DIR__ . '/fixtures/diagnostic-clear-ui.cjs' ) );
		self::assertSame( 0, $result[0]['replacements'] );
		self::assertSame( 'Clear was not verified', $result[0]['message'] );
		self::assertSame( 1, $result[1]['replacements'] );
	}

	private function run_fixture( array $command ): array {
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}
}
