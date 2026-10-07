<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\EdgeOptimizationController;
use Cybermaps\Admin\CloudflareOAuthTransactionStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/cloudflare-database.php';

final class EdgeOperationLockTest extends TestCase {
	use \CybermapsCloudflareDatabaseFixture;

	protected function setUp(): void {
		$this->install_cloudflare_database();
		parent::setUp();
	}

	protected function tearDown(): void {
		$this->restore_cloudflare_database();
		parent::tearDown();
	}

	public function test_mutations_exclude_other_connections_until_owner_release(): void {
		$first = new EdgeOptimizationController();
		$second = new EdgeOptimizationController();
		$acquire = new \ReflectionMethod( EdgeOptimizationController::class, 'acquire_lock' );
		$release = new \ReflectionMethod( EdgeOptimizationController::class, 'release_lock' );
		$this->assertTrue( $acquire->invoke( $first ) );
		try {
			$GLOBALS['cybermaps_mock_options']['cybermaps_edge_operation_lock'] = array( 'time' => time() - 1000 );
			$GLOBALS['wpdb']->connection = 102;
			$this->assertFalse( $acquire->invoke( $second ) );
			$release->invoke( $second );
			$this->assertFalse( $acquire->invoke( $second ) );
		} finally { $GLOBALS['wpdb']->connection = 101; $release->invoke( $first ); }
		$GLOBALS['wpdb']->connection = 102;
		$this->assertTrue( $acquire->invoke( $second ) );
		$release->invoke( $second );
	}

	public function test_retained_legacy_rules_report_partial_removal_for_manual_review(): void {
		$result = ( new \ReflectionMethod( EdgeOptimizationController::class, 'oauth_result' ) )->invoke(
			new EdgeOptimizationController(), array( 'retained_legacy' => array( 'cybermaps_v1_cache' ) ), null, 'revoked'
		);
		$this->assertSame( 'partial', $result['status'] );
		$this->assertStringContainsString( 'manual review', $result['message'] );
	}
	public function test_locked_callback_reads_successor_and_start_rejects_active_transaction(): void {
		$controller = new EdgeOptimizationController();
		$acquire = new \ReflectionMethod( EdgeOptimizationController::class, 'acquire_lock' );
		$release = new \ReflectionMethod( EdgeOptimizationController::class, 'release_lock' );
		$callback = new \ReflectionMethod( EdgeOptimizationController::class, 'accept_oauth_callback' );
		$begin = new \ReflectionMethod( EdgeOptimizationController::class, 'begin_oauth_transaction' );
		$prior_get = $_GET;
		self::assertTrue( $acquire->invoke( $controller ) );
		try {
			$store = ( new \ReflectionMethod( EdgeOptimizationController::class, 'transaction_store' ) )->invoke( $controller );
			$transaction = array( 'transaction_id' => 'callback-a', 'consume_secret' => 'secret', 'state' => 'old-state', 'client_id' => 'client', 'redirect_uri' => 'https://example.com/callback' );
			$store->begin( $transaction, 'verifier', 'install', 'custom' );
			$store->fail( 'expired', 'callback-a' );
			$transaction['transaction_id'] = 'callback-b'; $transaction['state'] = 'new-state';
			$store->begin( $transaction, 'verifier', 'install', 'custom' );
			$_GET = array( 'state' => 'old-state', 'code' => 'old-code' );
			try { $callback->invoke( $controller ); self::fail( 'Stale callback accepted.' ); }
			catch ( \RuntimeException $error ) { self::assertStringContainsString( 'invalid or expired', $error->getMessage() ); }
			self::assertSame( 'callback-b', $store->current()['transaction_id'] );
			self::assertSame( 'pending', $store->current()['status'] );
			$_GET = array( 'state' => 'new-state', 'code' => 'new-code' );
			$callback->invoke( $controller );
			self::assertSame( 'new-code', $store->current()['code'] );
			try { $begin->invoke( $controller, 'install' ); self::fail( 'Active authorization replaced.' ); }
			catch ( \RuntimeException $error ) { self::assertStringContainsString( 'already in progress', $error->getMessage() ); }
			$store->clear( 'callback-b' );
		} finally { $_GET = $prior_get; $release->invoke( $controller ); }
	}

}
