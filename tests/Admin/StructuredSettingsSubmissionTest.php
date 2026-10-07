<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\SettingsSubmission;
use PHPUnit\Framework\TestCase;

final class StructuredSettingsSubmissionTest extends TestCase {
	protected function setUp(): void {
		$_POST = array( 'option_page' => 'cybermaps_options_group', 'cybermaps_form_complete' => '1' );
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_discovery_center' => '{"archetype":"blog","disabled":{"post":true}}',
			'cybermaps_robots_manager' => array( 'takeover_enabled' => true, 'manual_directives' => 'Disallow: /private/' ),
		);
		$GLOBALS['cybermaps_mock_settings_errors'] = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	public function test_invalid_roots_preserve_both_options_and_report_errors(): void {
		foreach ( array( '{broken', '[]', '{}', 'null', '42', '"string"', '', '{"unexpected":{"nested":"value"}}', array(), array( 'unexpected' => 'value' ) ) as $input ) {
			$this->assert_rejected( 'cybermaps_discovery_center', $input );
			$this->assert_rejected( 'cybermaps_robots_manager', $input );
		}
	}

	public function test_strategy_rejects_wrong_scalar_nested_and_oversized_maps(): void {
		$cases = array(
			array( 'archetype' => array( 'blog' ) ),
			array( 'overrides' => array( 'post' => array( 0.5 ) ) ),
			array( 'overrides' => array( 'post' => 'not numeric' ) ),
			array( 'type_intents' => array( 'post' => true ) ),
			array( 'disabled' => array( 'post' => array( true ) ) ),
			array( 'disabled' => array( 'post' => 'invented' ) ),
			array( 'overrides' => array( 0.5 ) ),
			array( 'overrides' => array_fill_keys( array_map( static fn( int $n ): string => 'type_' . $n, range( 0, 400 ) ), 0.5 ) ),
		);
		foreach ( $cases as $input ) {
			$this->assert_rejected( 'cybermaps_discovery_center', $input );
			$this->assert_rejected( 'cybermaps_discovery_center', wp_json_encode( $input ) );
		}
	}

	public function test_crawler_policy_rejects_unknown_nested_fields_wrong_types_and_bounds(): void {
		$cases = array(
			array( 'takeover_enabled' => array( '1' ) ),
			array( 'takeover_enabled' => 'false' ),
			array( 'manual_directives' => false ),
			array( 'overrides' => array( 'gptbot' => array( 'unknown' => true ) ) ),
			array( 'overrides' => array( 'gptbot' => array( 'tpm' => array( 10 ) ) ) ),
			array( 'content_signals' => array( 'unknown' => 'no' ) ),
			array( 'content_signals' => array( 'search' => array( 'no' ) ) ),
			array( 'content_usage_overrides' => array( '/docs/' => array( 'signals' => array( 'unknown' => 'no' ) ) ) ),
			array( 'content_usage_overrides' => array( '/docs/' => array( 'path' => false ) ) ),
			array( 'content_usage_overrides' => array_fill( 0, 101, array( 'path' => '/docs/', 'search' => 'no' ) ) ),
		);
		foreach ( $cases as $input ) {
			$this->assert_rejected( 'cybermaps_robots_manager', $input );
			$this->assert_rejected( 'cybermaps_robots_manager', wp_json_encode( $input ) );
		}
	}

	public function test_byte_limit_and_json_depth_are_checked_before_normalization(): void {
		$oversized = '{"manual_directives":"' . str_repeat( 'x', SettingsSubmission::MAX_BYTES ) . '"}';
		foreach ( array( $oversized, '{"overrides":{"post":{"a":{"b":{"c":{"d":{"e":{"f":1}}}}}}}}' ) as $input ) {
			$this->assert_rejected( 'cybermaps_discovery_center', $input );
			$this->assert_rejected( 'cybermaps_robots_manager', $input );
		}
	}

	public function test_valid_compact_and_nested_array_submissions_have_identical_results(): void {
		$strategy = array( 'archetype' => 'blog', 'overrides' => array( 'post' => '0.8' ), 'disabled' => array( 'page' => '1' ) );
		self::assertSame( DiscoveryCenterSanitizer::sanitize( $strategy ), DiscoveryCenterSanitizer::sanitize( wp_json_encode( $strategy ) ) );
		$robots = array(
			'reset_overrides' => '0',
			'overrides' => array( 'gptbot' => array( 'llm' => '1', 'tpm' => '10' ) ),
			'content_usage_enabled' => '1',
			'content_usage_overrides' => array( array( 'path' => '/docs/', 'search' => 'no' ) ),
		);
		$result = RobotsManagerSanitizer::sanitize( $robots );
		self::assertSame( $result, RobotsManagerSanitizer::sanitize( wp_json_encode( $robots ) ) );
		self::assertFalse( $result['takeover_enabled'] );
		self::assertFalse( $result['overrides']['gptbot']['robots'] );
		self::assertSame( array( '/docs/' => array( 'search' => 'no' ) ), $result['content_usage_overrides'] );
		self::assertSame( array(), $GLOBALS['cybermaps_mock_settings_errors'] );
		self::assertSame( 0, RobotsManagerSanitizer::sanitize( array( 'overrides' => array( 'gptbot' => array( 'llm' => '1', 'tpm' => '' ) ) ) )['overrides']['gptbot']['tpm'] );
	}

	public function test_inactive_and_incomplete_submissions_preserve_options_while_explicit_resets_work(): void {
		foreach ( array( 'cybermaps_discovery_center', 'cybermaps_robots_manager' ) as $option ) {
			self::assertSame( get_option( $option ), $this->sanitize( $option, null ) );
		}
		unset( $_POST['cybermaps_form_complete'] );
		self::assertSame( get_option( 'cybermaps_discovery_center' ), DiscoveryCenterSanitizer::sanitize( '{"archetype":"blog","disabled":[]}' ) );
		self::assertSame( get_option( 'cybermaps_robots_manager' ), RobotsManagerSanitizer::sanitize( array( 'reset_overrides' => '1' ) ) );
		$_POST['cybermaps_form_complete'] = '1';
		self::assertSame( array(), json_decode( DiscoveryCenterSanitizer::sanitize( '{"archetype":"blog","disabled":[]}' ), true )['disabled'] );
		self::assertFalse( RobotsManagerSanitizer::sanitize( array( 'reset_overrides' => '1' ) )['takeover_enabled'] );
	}

	private function assert_rejected( string $option, mixed $input ): void {
		$GLOBALS['cybermaps_mock_settings_errors'] = array();
		self::assertSame( get_option( $option ), $this->sanitize( $option, $input ) );
		self::assertCount( 1, $GLOBALS['cybermaps_mock_settings_errors'] );
		self::assertSame( $option, $GLOBALS['cybermaps_mock_settings_errors'][0]['setting'] );
	}

	private function sanitize( string $option, mixed $input ): mixed {
		return 'cybermaps_discovery_center' === $option ? DiscoveryCenterSanitizer::sanitize( $input ) : RobotsManagerSanitizer::sanitize( $input );
	}
}
