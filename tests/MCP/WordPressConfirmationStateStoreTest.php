<?php
declare(strict_types=1);

namespace Cybermaps\Tests\MCP;

use Cybermaps\MCP\WordPressConfirmationStateStore;
use PHPUnit\Framework\TestCase;

final class WordPressConfirmationStateStoreTest extends TestCase {
	private mixed $previous_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->previous_wpdb = $wpdb ?? null;
		$wpdb                = new ConfirmationOneTimeStateWpdb();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_confirmation_state_is_consumed_once(): void {
		$store = new WordPressConfirmationStateStore();
		$now   = time();

		$this->assertTrue( $store->put( 'nonce-key', $now + 60 ) );
		$this->assertTrue( $store->take( 'nonce-key', $now ) );
		$this->assertFalse( $store->take( 'nonce-key', $now ) );
	}

	public function test_expired_confirmation_is_removed_without_success(): void {
		$store = new WordPressConfirmationStateStore();
		$now   = time();

		$this->assertTrue( $store->put( 'expired-key', $now + 1 ) );
		$this->assertFalse( $store->take( 'expired-key', $now + 2 ) );
		$this->assertFalse( $store->take( 'expired-key', $now + 2 ) );
	}
}

final class ConfirmationOneTimeStateWpdb {
	public string $options = 'wp_options';
	public string $last_error = '';
	/** @var array<string,string> */
	public array $rows = array();

	/** @return array{query:string,args:array<int,mixed>} */
	public function prepare( string $query, mixed ...$args ): array {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function query( array|string $prepared ): int|false {
		$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['args'] : array();
		if ( str_starts_with( $query, 'INSERT INTO' ) ) {
			$this->rows[ (string) $args[1] ] = (string) $args[2];
			return 1;
		}
		if ( str_starts_with( $query, 'DELETE FROM' ) ) {
			$option = (string) ( $args[1] ?? '' );
			if ( str_contains( $query, 'BINARY option_value' ) && ( $this->rows[ $option ] ?? null ) !== ( $args[2] ?? null ) ) {
				return 0;
			}
			if ( ! array_key_exists( $option, $this->rows ) ) {
				return 0;
			}
			unset( $this->rows[ $option ] );
			return 1;
		}
		return 0;
	}

	public function get_var( array|string $prepared ): ?string {
		$args = is_array( $prepared ) ? $prepared['args'] : array();
		return $this->rows[ (string) ( $args[1] ?? '' ) ] ?? null;
	}
}
