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

require_once dirname( __DIR__ ) . '/mocks/configuration-database.php';

final class DiagnosticLoggerTest extends TestCase {
	private mixed $previous_database;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \CybermapsConfigurationDatabase();
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_database;
		parent::tearDown();
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

	public function test_demonstrated_private_message_is_redacted_for_new_and_retained_events(): void {
		$message = 'Connection from 2001:db8::42 failed for password=short-secret at /srv/private/credential.json';
		DiagnosticLogger::enable();
		DiagnosticLogger::log( 'test.failure', array( 'error_summary' => $message ) );
		$stored = $GLOBALS['cybermaps_mock_options'][ DiagnosticLogger::ENTRIES_OPTION ];
		$this->assertSame( 'Connection from [ip] failed for password=[redacted] at [path]', end( $stored )['context']['error_summary'] );
		$GLOBALS['cybermaps_mock_options'][ DiagnosticLogger::ENTRIES_OPTION ] = array(
			array( 'time' => gmdate( 'c' ), 'level' => 'error', 'event' => 'legacy.failure', 'context' => array( 'error_summary' => $message, 'token' => 'legacy-private-value' ) ),
		);
		$bundle = DiagnosticLogger::support_bundle( array() );
		$this->assertSame( 'Connection from [ip] failed for password=[redacted] at [path]', $bundle['debugging']['entries'][0]['context']['error_summary'] );
		$this->assertStringNotContainsString( 'short-secret', wp_json_encode( $GLOBALS['cybermaps_mock_options'][ DiagnosticLogger::ENTRIES_OPTION ] ) );
		$this->assertArrayNotHasKey( 'token', $bundle['debugging']['entries'][0]['context'] );
		$this->assertArrayNotHasKey( 'omitted_data', $bundle );
		$this->assertSame( 'best_effort', $bundle['redaction']['mode'] );
		$this->assertTrue( $bundle['redaction']['review_before_sharing'] );
	}

	/** @dataProvider common_private_strings */
	public function test_common_private_values_are_masked_without_claiming_universal_redaction( string $message, string $private ): void {
		DiagnosticLogger::enable();
		DiagnosticLogger::log( 'test.failure', array( 'error_summary' => $message ) );
		$entries = DiagnosticLogger::support_bundle( array() )['debugging']['entries'];
		$this->assertStringNotContainsString( $private, end( $entries )['context']['error_summary'] );
	}

	public static function common_private_strings(): array {
		return array(
			'quoted password' => array( 'password="short secret with spaces" failed', 'short secret' ),
			'quoted key' => array( '{"api_key":"tiny-key"}', 'tiny-key' ),
			'single quoted secret' => array( "client_secret='tiny secret'", 'tiny secret' ),
			'IPv6 brackets' => array( 'Host [2001:db8::1] failed', '2001:db8' ),
			'IPv6 sentence' => array( 'Host 2001:db8::42.', '2001:db8' ),
			'IPv6 mapped' => array( 'Host ::ffff:192.0.2.5', 'ffff' ),
			'IPv6 scope' => array( 'Host fe80::1%eth0', 'eth0' ),
			'Windows drive' => array( 'Cannot open C:\\private\\secret.pem', 'secret.pem' ),
			'UNC path' => array( 'Cannot open \\\\server\\private\\secret.pem', 'server' ),
			'quoted path spaces' => array( 'Cannot open "/srv/private directory/secret.pem"', 'private directory' ),
		);
	}

	public function test_redaction_input_is_bounded_before_processing_and_retained_shape_is_revalidated(): void {
		DiagnosticLogger::enable();
		DiagnosticLogger::log( 'test.failure', array( 'error_summary' => str_repeat( ' ', 4096 ) . 'beyond-input-limit' ) );
		$entries = DiagnosticLogger::support_bundle( array() )['debugging']['entries'];
		$this->assertSame( '', end( $entries )['context']['error_summary'] );
		$GLOBALS['cybermaps_mock_options'][ DiagnosticLogger::ENTRIES_OPTION ] = array(
			array( 'time' => array( 'invalid' ), 'context' => array() ),
			array( 'time' => gmdate( 'c', time() - 8 * DAY_IN_SECONDS ), 'context' => array() ),
			array( 'time' => gmdate( 'c' ), 'level' => array(), 'event' => array(), 'context' => 'invalid' ),
		);
		$entries = DiagnosticLogger::support_bundle( array() )['debugging']['entries'];
		$this->assertCount( 1, $entries );
		$this->assertSame( 'info', $entries[0]['level'] );
		$this->assertSame( array(), $entries[0]['context'] );
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
