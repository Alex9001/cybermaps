<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\AtomicMinuteCounter;
use PHPUnit\Framework\TestCase;

final class AtomicMinuteCounterTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_transient_expirations'] = array();
		$GLOBALS['cybermaps_mock_object_cache'] = array();
		$GLOBALS['cybermaps_mock_object_cache_expirations'] = array();
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	public function test_increment_uses_transient_fallback_without_object_cache(): void {
		$key = AtomicMinuteCounter::requester_bucket( 'test', 'scope', '203.0.113.1' );

		$this->assertSame( 1, AtomicMinuteCounter::increment( $key ) );
		$this->assertSame( 2, AtomicMinuteCounter::increment( $key ) );
	}

	public function test_unresolved_client_bucket_is_stable(): void {
		$key = AtomicMinuteCounter::requester_bucket(
			'test',
			'scope',
			AtomicMinuteCounter::UNRESOLVED_CLIENT_BUCKET
		);

		$this->assertNotSame(
			'',
			$key
		);
		$this->assertSame( 1, AtomicMinuteCounter::increment( $key ) );
	}

	public function test_expired_transient_bucket_restarts_at_one(): void {
		$key = AtomicMinuteCounter::requester_bucket( 'test', 'expired', '203.0.113.2' );
		set_transient( $key, 17, 70 );
		$GLOBALS['cybermaps_mock_transient_expirations'][ $key ] = time() - 1;

		$this->assertSame( 1, AtomicMinuteCounter::increment( $key ) );
	}

	public function test_expired_external_cache_bucket_restarts_at_one(): void {
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		$key = AtomicMinuteCounter::requester_bucket( 'test', 'expired-external', '203.0.113.3' );
		$this->assertSame( 1, AtomicMinuteCounter::increment( $key ) );
		$cache_key = array_key_first( $GLOBALS['cybermaps_mock_object_cache'] );
		$GLOBALS['cybermaps_mock_object_cache_expirations'][ $cache_key ] = time() - 1;

		$this->assertSame( 1, AtomicMinuteCounter::increment( $key ) );
	}
}
