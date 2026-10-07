<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareOAuthTransactionStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/cloudflare-database.php';

final class CloudflareOAuthTransactionStoreTest extends TestCase {
	use \CybermapsCloudflareDatabaseFixture;
	protected function tearDown(): void { $this->restore_cloudflare_database(); parent::tearDown(); }

	protected function setUp(): void {
		$this->install_cloudflare_database();
		parent::setUp();
		$GLOBALS['cybermaps_mock_transients']            = array();
		$GLOBALS['cybermaps_mock_transient_expirations'] = array();
	}

	public function test_transaction_is_user_scoped_and_cleared(): void {
		$store = new CloudflareOAuthTransactionStore( 42, null, $this->cloudflare_lock() );
		$store->begin(
			array(
				'transaction_id' => '11111111-1111-4111-8111-111111111111',
				'consume_secret' => str_repeat( 's', 43 ),
				'state' => str_repeat( 'x', 43 ),
				'client_id' => 'public-client-id',
				'redirect_uri' => 'https://connect.cybermaps.dev/cloudflare/callback',
			),
			str_repeat( 'v', 64 ),
			'install'
		);
		self::assertSame( 'pending', $store->current()['status'] );
		self::assertArrayHasKey( 'cybermaps_cf_oauth_pointer_42', $GLOBALS['cybermaps_mock_options'] );
		$store->clear( $store->current()['transaction_id'] );
		self::assertNull( $store->current() );
	}

	public function test_failure_record_contains_no_transaction_secret(): void {
		$store = new CloudflareOAuthTransactionStore( 7, null, $this->cloudflare_lock() );
		$store->begin( $this->transaction( 'failed' ), str_repeat( 'v', 64 ), 'install' );
		$store->fail( 'Relay unavailable', 'failed' );
		$record = $store->current();
		self::assertSame( 'failed', $record['status'] );
		self::assertArrayNotHasKey( 'consume_secret', $record );
		self::assertArrayNotHasKey( 'code_verifier', $record );
	}

	private function transaction( string $id ): array {
		return array( 'transaction_id' => $id, 'consume_secret' => 'secret', 'state' => 'state-' . $id, 'client_id' => 'client', 'redirect_uri' => 'https://example.com/callback' );
	}

	public function test_stale_poll_callback_and_clear_cannot_replace_successor(): void {
		$store = new CloudflareOAuthTransactionStore( 42, null, $this->cloudflare_lock() );
		$store->begin( $this->transaction( 'a' ), 'verifier', 'install', 'custom' );
		$store->finish( array( 'status' => 'complete' ), 'a' );
		$store->begin( $this->transaction( 'b' ), 'verifier', 'install', 'custom' );
		self::assertFalse( $store->authorize_direct( 'state-a', 'stale-code', 'a' ) );
		foreach ( array( 'fail', 'finish' ) as $method ) {
			try {
				$store->$method( 'fail' === $method ? 'stale failure' : array( 'status' => 'stale' ), 'a' );
				self::fail( 'Stale mutation was accepted.' );
			} catch ( \RuntimeException $error ) { self::assertStringContainsString( 'replaced or expired', $error->getMessage() ); }
		}
		$store->clear( 'a' );
		self::assertSame( 'b', $store->current()['transaction_id'] );
		self::assertSame( 'pending', $store->current()['status'] );
	}

	public function test_active_start_rejected_but_failed_and_expired_restart_allowed(): void {
		$store = new CloudflareOAuthTransactionStore( 42, null, $this->cloudflare_lock() );
		$store->begin( $this->transaction( 'a' ), 'verifier', 'install', 'custom' );
		foreach ( array( 'pending', 'authorized' ) as $status ) {
			try { $store->begin( $this->transaction( 'blocked' ), 'verifier', 'install' ); self::fail( 'Active start replaced.' ); }
			catch ( \RuntimeException $error ) { self::assertStringContainsString( 'already in progress', $error->getMessage() ); }
			$store->authorize_direct( 'state-a', 'code', 'a' );
		}
		$store->fail( 'declined', 'a' );
		$store->begin( $this->transaction( 'b' ), 'verifier', 'remove' );
		$GLOBALS['cybermaps_mock_transient_expirations']['cybermaps_cf_oauth_42_' . hash( 'sha256', 'b' )] = time() - 1;
		$store->begin( $this->transaction( 'c' ), 'verifier', 'remove' );
		self::assertSame( 'c', $store->current()['transaction_id'] );
	}

	public function test_guard_is_revalidated_before_pointer_change(): void {
		$writes = 0;
		$store = new CloudflareOAuthTransactionStore( 42, static function () use ( &$writes ): void {
			if ( ++$writes === 2 ) { throw new \RuntimeException( 'lost lock' ); }
		}, $this->cloudflare_lock() );
		try { $store->begin( $this->transaction( 'orphan' ), 'verifier', 'install' ); self::fail( 'Lost guard published pointer.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'lost lock', $error->getMessage() ); }
		self::assertNull( $store->current() );
		self::assertArrayNotHasKey( 'cybermaps_cf_oauth_pointer_42', $GLOBALS['cybermaps_mock_options'] );
	}
}
