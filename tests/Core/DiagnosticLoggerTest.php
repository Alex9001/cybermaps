<?php
/**
 * Diagnostic logger tests.
 *
 * @package Cybermaps\Tests\Core
 */

declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\DiagnosticLogger;
use PHPUnit\Framework\TestCase;

final class DiagnosticLoggerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	public function test_logging_is_off_by_default(): void {
		DiagnosticLogger::log( 'test.event', array( 'status' => 'ignored' ) );

		$this->assertArrayNotHasKey( DiagnosticLogger::ENTRIES_OPTION, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_enable_records_only_redacted_allowlisted_context(): void {
		DiagnosticLogger::enable( HOUR_IN_SECONDS );
		DiagnosticLogger::log(
			'test.failure',
			array(
				'status'        => 'failed at https://example.com/path from 192.0.2.5',
				'error_summary' => 'Bearer abcdefghijklmnopqrstuvwxyz1234567890',
				'token'         => 'must-not-be-stored',
			),
			'error'
		);

		$bundle = DiagnosticLogger::support_bundle( array( 'summary' => array() ) );
		$json   = wp_json_encode( $bundle );

		$this->assertTrue( $bundle['debugging']['state']['enabled'] );
		$this->assertStringNotContainsString( 'must-not-be-stored', $json );
		$this->assertStringNotContainsString( 'example.com', $json );
		$this->assertStringNotContainsString( '192.0.2.5', $json );
		$this->assertStringNotContainsString( 'abcdefghijklmnopqrstuvwxyz1234567890', $json );
	}

	public function test_expired_session_stops_collection_and_clear_removes_events(): void {
		$GLOBALS['cybermaps_mock_options'][ DiagnosticLogger::STATE_OPTION ] = array(
			'enabled'    => true,
			'expires_at' => time() - 1,
		);
		$GLOBALS['cybermaps_mock_options'][ DiagnosticLogger::ENTRIES_OPTION ] = array(
			array( 'time' => gmdate( 'c' ), 'level' => 'info', 'event' => 'old', 'context' => array() ),
		);

		$this->assertFalse( DiagnosticLogger::is_enabled() );
		$this->assertSame( 1, DiagnosticLogger::state_summary()['entry_count'] );

		$state = DiagnosticLogger::clear();
		$this->assertSame( 0, $state['entry_count'] );
	}

	public function test_event_ring_is_limited_to_two_hundred_entries(): void {
		DiagnosticLogger::enable( HOUR_IN_SECONDS );
		for ( $index = 0; $index < 225; ++$index ) {
			DiagnosticLogger::log( 'test.event', array( 'attempt' => $index ) );
		}

		$entries = DiagnosticLogger::support_bundle( array() )['debugging']['entries'];
		$this->assertCount( 200, $entries );
		$this->assertSame( 25, $entries[0]['context']['attempt'] );
	}
}
