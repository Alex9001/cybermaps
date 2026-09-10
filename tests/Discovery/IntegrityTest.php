<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Integrity;
use PHPUnit\Framework\TestCase;

class IntegrityTest extends TestCase {
	protected function setUp(): void {
		update_option(
			'cybermaps_settings',
			array(
				'enable_discovery_hub' => '1',
			)
		);
	}

	public function test_is_hub_enabled_when_on(): void {
		$this->assertTrue( Integrity::is_hub_enabled() );
	}

	public function test_is_hub_enabled_when_off(): void {
		update_option( 'cybermaps_settings', array() );
		$this->assertFalse( Integrity::is_hub_enabled() );
	}

	public function test_weak_if_none_match_matches_current_etag(): void {
		$this->assertTrue(
			Integrity::is_not_modified(
				'"abc123"',
				1700000000,
				array( 'HTTP_IF_NONE_MATCH' => 'W/"abc123"' )
			)
		);
	}

	public function test_comma_separated_and_wildcard_if_none_match_values_are_supported(): void {
		$this->assertTrue(
			Integrity::is_not_modified(
				'"current"',
				null,
				array( 'HTTP_IF_NONE_MATCH' => '"stale", W/"current", "other"' )
			)
		);
		$this->assertTrue(
			Integrity::is_not_modified(
				'"current"',
				null,
				array( 'HTTP_IF_NONE_MATCH' => '*' )
			)
		);
	}

	public function test_nonmatching_etag_takes_precedence_over_matching_date(): void {
		$this->assertFalse(
			Integrity::is_not_modified(
				'"current"',
				1700000000,
				array(
					'HTTP_IF_NONE_MATCH'     => '"stale"',
					'HTTP_IF_MODIFIED_SINCE' => 'Wed, 01 Jan 2031 00:00:00 GMT',
				)
			)
		);
	}

	public function test_if_modified_since_is_used_without_an_etag_condition(): void {
		$this->assertTrue(
			Integrity::is_not_modified(
				'"current"',
				1700000000,
				array( 'HTTP_IF_MODIFIED_SINCE' => 'Wed, 01 Jan 2031 00:00:00 GMT' )
			)
		);
	}

	public function test_if_modified_since_is_ignored_without_an_authoritative_timestamp(): void {
		$this->assertFalse(
			Integrity::is_not_modified(
				'"current"',
				null,
				array( 'HTTP_IF_MODIFIED_SINCE' => 'Wed, 01 Jan 2031 00:00:00 GMT' )
			)
		);
	}

	public function test_malformed_conditional_header_values_are_ignored_without_warnings(): void {
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$not_modified = Integrity::is_not_modified(
				'"current"',
				1700000000,
				array(
					'HTTP_IF_NONE_MATCH'     => array( '"current"' ),
					'HTTP_IF_MODIFIED_SINCE' => new \stdClass(),
				)
			);
		} finally {
			restore_error_handler();
		}

		$this->assertFalse( $not_modified );
	}

	public function test_training_header_policy_uses_the_current_setting_key_and_value(): void {
		$method = new \ReflectionMethod( Integrity::class, 'should_forbid_ai_training' );

		$this->assertTrue( $method->invoke( null, array( 'ai_usage_training' => 'forbid' ) ) );
		$this->assertFalse( $method->invoke( null, array( 'ai_usage_training' => 'allow' ) ) );
		$this->assertTrue( $method->invoke( null, array() ) );
		$this->assertTrue( $method->invoke( null, array( 'ai_usage_training' => 'invalid' ) ) );
		$this->assertTrue( $method->invoke( null, array( 'disallow_ai_training' => '1' ) ) );
	}
}
