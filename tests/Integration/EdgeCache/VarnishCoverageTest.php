<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Integration\EdgeCache;

use PHPUnit\Framework\TestCase;

final class VarnishCoverageTest extends TestCase {
	public function test_bounded_transport_reports_scope_gaps_without_permanent_retries(): void {
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__, 2 ) . '/fixtures/edge-cache-coverage.php' ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$cases = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );

		foreach ( array( 'empty' => 'unsupported_scope', 'overflow' => 'incomplete_scope', 'partial' => 'incomplete_scope' ) as $name => $status ) {
			$this->assertSame( 'incomplete', $cases[ $name ]['status'] );
			$this->assertSame( $status, $cases[ $name ]['adapters'][1]['status'] );
			$this->assertSame( 0, $cases[ $name ]['pending_count'] );
			$this->assertFalse( $cases[ $name ]['retry_scheduled'] );
			$this->assertSame( $status, $cases[ $name ]['rest']['adapters'][1]['status'] );
		}
		$this->assertSame( 0, $cases['empty']['http_request_count'] );
		$this->assertSame( 50, $cases['overflow']['http_request_count'] );
		$this->assertSame( 60, $cases['overflow']['requested_url_count'] );
		$this->assertSame( 10, $cases['overflow']['truncated_url_count'] );
		$this->assertSame( 60, $cases['overflow']['rest']['requested_url_count'] );
		$this->assertSame( 10, $cases['overflow']['rest']['adapters'][1]['truncated_url_count'] );
		$this->assertFalse( $cases['partial']['rest']['url_scope_complete'] );
		$this->assertSame( 'sent', $cases['complete']['adapters'][1]['status'] );
		$this->assertTrue( $cases['complete']['url_scope_complete'] );
		$this->assertSame( 'retry_pending', $cases['transient']['status'] );
		$this->assertSame( 1, $cases['transient']['pending_count'] );
		$this->assertTrue( $cases['transient']['retry_scheduled'] );
		$this->assertSame( 'incomplete_scope', $cases['direct_overflow']['status'] );
		$this->assertSame( 60, $cases['direct_overflow']['requested_url_count'] );
		$this->assertSame( 10, $cases['direct_overflow']['truncated_url_count'] );
		$this->assertSame( 50, $cases['direct_overflow']['http_request_count'] );
	}
}
