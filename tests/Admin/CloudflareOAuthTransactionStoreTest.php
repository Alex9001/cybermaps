<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareOAuthTransactionStore;
use PHPUnit\Framework\TestCase;

final class CloudflareOAuthTransactionStoreTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_transients']            = array();
		$GLOBALS['cybermaps_mock_transient_expirations'] = array();
	}

	public function test_transaction_is_user_scoped_and_cleared(): void {
		$store = new CloudflareOAuthTransactionStore( 42 );
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
		self::assertArrayHasKey( 'cybermaps_cf_oauth_42', $GLOBALS['cybermaps_mock_transients'] );
		$store->clear();
		self::assertNull( $store->current() );
	}

	public function test_failure_record_contains_no_transaction_secret(): void {
		$store = new CloudflareOAuthTransactionStore( 7 );
		$store->fail( 'Relay unavailable' );
		$record = $store->current();
		self::assertSame( 'failed', $record['status'] );
		self::assertArrayNotHasKey( 'consume_secret', $record );
		self::assertArrayNotHasKey( 'code_verifier', $record );
	}
}
