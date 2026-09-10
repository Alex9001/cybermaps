<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\IndexNow;
use Cybermaps\Discovery\IndexNowQueue;
use Cybermaps\Discovery\IndexNowQueueRepository;

final class IndexNowTest extends \WP_UnitTestCase {
	private mixed $previous_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->previous_wpdb = $wpdb ?? null;
		$wpdb                = new IndexNowQueueTestWpdb();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_indexnow'   => '1',
				'frontend_base_url' => 'https://frontend.example',
			),
			'cybermaps_indexnow_key' => 'existing-key',
		);
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_safe_remote_post_calls'] = array();
		$GLOBALS['cybermaps_mock_scheduled']              = array();
		unset( $GLOBALS['cybermaps_mock_safe_remote_post_response'] );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_notification_debounces_delivery_without_immediate_submit(): void {
		( new IndexNow() )->notify_url( 'https://frontend.example/?p=42' );

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_safe_remote_post_calls'] );
		$this->assertSame( 1, ( new IndexNow() )->get_queue_health()['queued'] );
		$this->assertArrayHasKey( IndexNowQueue::CRON_HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_process_now_uses_publication_host_and_supplied_public_url(): void {
		$result = ( new IndexNow() )->submit_urls( array( 'https://frontend.example/?p=42' ), true );

		$this->assertSame( 'accepted', $result['report']['status'] );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_safe_remote_post_calls'] );
		$call    = $GLOBALS['cybermaps_mock_safe_remote_post_calls'][0];
		$payload = json_decode( (string) $call['args']['body'], true );
		$this->assertSame( 'https://api.indexnow.org/indexnow', $call['url'] );
		$this->assertSame( 'frontend.example', $payload['host'] );
		$this->assertSame( 'https://frontend.example/existing-key.txt', $payload['keyLocation'] );
		$this->assertSame( array( 'https://frontend.example/?p=42' ), $payload['urlList'] );
	}

	public function test_disabled_service_does_not_submit_a_url(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_indexnow'] = '0';

		( new IndexNow() )->notify_url( 'https://frontend.example/?p=43' );

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_safe_remote_post_calls'] );
	}

	public function test_notification_rejects_urls_outside_the_publication_origin(): void {
		$indexnow = new IndexNow();

		$indexnow->notify_url( 'https://other.example/post' );
		$indexnow->notify_url( 'http://frontend.example/post' );
		$indexnow->notify_url( 'https://user:secret@frontend.example/post' );

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_safe_remote_post_calls'] );
	}

	public function test_invalid_stored_key_is_replaced_before_submission(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_indexnow_key'] = array( 'invalid' );

		( new IndexNow() )->submit_urls( array( 'https://frontend.example/post' ), true );

		$this->assertCount( 1, $GLOBALS['cybermaps_mock_safe_remote_post_calls'] );
		$key = (string) $GLOBALS['cybermaps_mock_options']['cybermaps_indexnow_key'];
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9-]{32}$/', $key );
		$payload = json_decode(
			(string) $GLOBALS['cybermaps_mock_safe_remote_post_calls'][0]['args']['body'],
			true
		);
		$this->assertSame( $key, $payload['key'] );
	}

	public function test_batch_submission_deduplicates_urls_and_uses_a_bounded_payload(): void {
		$result = ( new IndexNow() )->submit_urls(
			array(
				'https://frontend.example/a',
				'https://frontend.example/a',
				'https://frontend.example/b',
				'https://other.example/nope',
			),
			true
		);

		$this->assertSame( 2, $result['accepted'] );
		$this->assertSame( 1, $result['rejected'] );
		$this->assertSame( 'accepted', $result['report']['status'] );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_safe_remote_post_calls'] );
		$payload = json_decode( (string) $GLOBALS['cybermaps_mock_safe_remote_post_calls'][0]['args']['body'], true );
		$this->assertSame( array( 'https://frontend.example/a', 'https://frontend.example/b' ), $payload['urlList'] );
		$this->assertTrue( $GLOBALS['cybermaps_mock_safe_remote_post_calls'][0]['args']['blocking'] );
	}

	public function test_retryable_response_stays_queued_and_honors_retry_after(): void {
		$GLOBALS['cybermaps_mock_safe_remote_post_response'] = array(
			'response' => array( 'code' => 429 ),
			'headers'  => array( 'Retry-After' => '120' ),
		);
		$indexnow = new IndexNow();
		$indexnow->submit_urls( array( 'https://frontend.example/retry' ) );
		$result = $indexnow->process_queue();
		$health = $indexnow->get_queue_health();

		$this->assertSame( 'retry_scheduled', $result['status'] );
		$this->assertSame( 1, $health['queued'] );
		$this->assertGreaterThanOrEqual( time() + 119, $health['next_attempt_at'] );
	}

	public function test_hard_client_error_is_not_retried(): void {
		$GLOBALS['cybermaps_mock_safe_remote_post_response'] = array( 'response' => array( 'code' => 400 ) );
		$indexnow = new IndexNow();
		$indexnow->submit_urls( array( 'https://frontend.example/invalid' ) );
		$result = $indexnow->process_queue();

		$this->assertSame( 'discarded', $result['status'] );
		$this->assertSame( 0, $indexnow->get_queue_health()['queued'] );
	}

	public function test_successful_maximum_batch_rearms_the_remaining_url(): void {
		$urls = array();
		for ( $index = 0; $index < 10001; ++$index ) {
			$urls[] = 'https://frontend.example/batch-' . $index;
		}

		$indexnow = new IndexNow();
		$indexnow->submit_urls( $urls );
		$first = $indexnow->process_queue();
		$health = $indexnow->get_queue_health();
		$second = $indexnow->process_queue();

		$this->assertSame( 10000, $first['count'] );
		$this->assertSame( 1, $health['queued'] );
		$this->assertSame( 1, $second['count'] );
		$this->assertCount( 2, $GLOBALS['cybermaps_mock_safe_remote_post_calls'] );
		$first_payload = json_decode( (string) $GLOBALS['cybermaps_mock_safe_remote_post_calls'][0]['args']['body'], true );
		$this->assertCount( 10000, $first_payload['urlList'] );
	}

	public function test_second_retry_uses_exponential_backoff(): void {
		$GLOBALS['cybermaps_mock_safe_remote_post_response'] = array( 'response' => array( 'code' => 503 ) );
		$indexnow = new IndexNow();
		$indexnow->submit_urls( array( 'https://frontend.example/retry-twice' ) );
		$indexnow->process_queue();

		$this->database()->make_url_due( 'https://frontend.example/retry-twice' );
		$indexnow->process_queue();
		$health = $indexnow->get_queue_health();

		$this->assertSame( 1, $health['queued'] );
		$this->assertGreaterThanOrEqual( time() + 119, $health['next_attempt_at'] );
	}

	public function test_two_workers_cannot_deliver_the_same_claim(): void {
		$queue = new IndexNowQueue();
		$queue->enqueue( array( 'https://frontend.example/race' ) );

		$first  = $queue->claim_due();
		$second = $queue->claim_due();

		$this->assertSame( array( 'https://frontend.example/race' ), $first['urls'] );
		$this->assertSame( array(), $second['urls'] );
	}

	public function test_stale_acknowledgement_token_does_not_delete_claim(): void {
		$queue = new IndexNowQueue();
		$queue->enqueue( array( 'https://frontend.example/stale-ack' ) );
		$claim = $queue->claim_due();

		$this->assertSame( 0, $queue->acknowledge( $claim['urls'], str_repeat( '0', 64 ), 200 ) );
		$this->assertSame( 1, $queue->health()['claimed'] );
		$this->assertSame( 1, $queue->acknowledge( $claim['urls'], $claim['token'], 200 ) );
		$this->assertSame( 0, $queue->health()['queued'] );
	}

	public function test_crashed_claim_is_recovered_after_lease_expiry(): void {
		$queue = new IndexNowQueue();
		$queue->enqueue( array( 'https://frontend.example/crash' ) );
		$first = $queue->claim_due();
		$this->database()->expire_claim( $first['token'] );
		$second = $queue->claim_due();

		$this->assertSame( array( 'https://frontend.example/crash' ), $second['urls'] );
		$this->assertNotSame( $first['token'], $second['token'] );
	}

	public function test_enqueue_during_delivery_survives_acknowledgement(): void {
		$queue = new IndexNowQueue();
		$queue->enqueue( array( 'https://frontend.example/redeliver' ) );
		$claim = $queue->claim_due();
		$queue->enqueue( array( 'https://frontend.example/redeliver' ) );

		$this->assertSame( 1, $queue->acknowledge( $claim['urls'], $claim['token'], 200 ) );
		$this->assertSame( 1, $queue->health()['queued'] );
		$this->assertSame( array( 'https://frontend.example/redeliver' ), $queue->claim_due()['urls'] );
	}

	public function test_queue_cap_rejects_without_loading_all_rows(): void {
		$this->database()->seed_count( 50000 );
		$result = ( new IndexNowQueue() )->enqueue( array( 'https://frontend.example/full' ) );

		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 1, $result['rejected'] );
		$this->assertSame( 50000, ( new IndexNowQueue() )->health()['queued'] );
	}

	public function test_legacy_option_queue_is_migrated_to_table(): void {
		update_option(
			IndexNowQueue::OPTION,
			array(
				'urls' => array(
					'https://frontend.example/legacy' => array(
						'queued_at'       => time() - 20,
						'attempts'        => 1,
						'next_attempt_at' => time() - 1,
					),
				),
			),
			false
		);

		$queue = new IndexNowQueue();
		$this->assertSame( array( 'https://frontend.example/legacy' ), $queue->claim_due()['urls'] );
		$this->assertSame( array(), get_option( IndexNowQueue::OPTION, array() ) );
	}

	public function test_full_table_legacy_migration_retains_legacy_option(): void {
		update_option(
			IndexNowQueue::OPTION,
			array(
				'urls' => array(
					'https://frontend.example/full-legacy' => array(
						'attempts'        => 0,
						'next_attempt_at' => time() - 1,
					),
				),
			),
			false
		);
		$this->database()->seed_count( 50000 );

		$this->assertFalse( IndexNowQueue::migrate_legacy_option() );
		$legacy = get_option( IndexNowQueue::OPTION, array() );
		$this->assertArrayHasKey( 'https://frontend.example/full-legacy', $legacy['urls'] );
	}

	public function test_insert_failure_legacy_migration_retains_legacy_option(): void {
		update_option(
			IndexNowQueue::OPTION,
			array(
				'urls' => array(
					'https://frontend.example/insert-failure-legacy' => array(
						'attempts'        => 0,
						'next_attempt_at' => time() - 1,
					),
				),
			),
			false
		);
		$this->database()->fail_next_insert = true;

		$this->assertFalse( IndexNowQueue::migrate_legacy_option() );
		$legacy = get_option( IndexNowQueue::OPTION, array() );
		$this->assertArrayHasKey( 'https://frontend.example/insert-failure-legacy', $legacy['urls'] );
		$this->assertSame( 'legacy_migration_retained', ( new IndexNowQueue() )->health()['last_result']['status'] );
	}

	public function test_concurrent_capacity_fill_rejects_admission_without_exceeding_cap(): void {
		$this->database()->seed_count( 49999 );
		$this->database()->fill_to_capacity_after_lock = true;

		$result = ( new IndexNowQueue() )->enqueue( array( 'https://frontend.example/concurrent-full' ) );

		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 1, $result['rejected'] );
		$this->assertSame( 50000, ( new IndexNowQueue() )->health()['queued'] );
	}

	public function test_claim_read_failure_releases_token_for_immediate_reclaim(): void {
		$queue = new IndexNowQueue();
		$queue->enqueue( array( 'https://frontend.example/read-failure' ) );
		$this->database()->fail_next_claim_read = true;

		$failed_claim = $queue->claim_due();

		$this->assertSame( array(), $failed_claim['urls'] );
		$this->assertSame( '', $failed_claim['token'] );
		$this->assertSame( 1, $queue->health()['queued'] );
		$this->assertSame( 0, $queue->health()['claimed'] );
		$this->assertSame( array( 'https://frontend.example/read-failure' ), $queue->claim_due()['urls'] );
	}

	public function test_health_reports_schema_unavailable_without_option_fallback(): void {
		$this->database()->table_exists = false;
		$queue                         = new IndexNowQueue();
		$result                        = $queue->enqueue( array( 'https://frontend.example/unavailable' ) );
		$health                        = $queue->health();

		$this->assertSame( 0, $result['accepted'] );
		$this->assertSame( 1, $result['rejected'] );
		$this->assertSame( 'schema_unavailable', $health['status'] );
		$this->assertFalse( $health['schema_available'] );
		$this->assertSame( false, get_option( IndexNowQueue::OPTION, false ) );
	}

	public function test_service_registers_only_the_key_delivery_hook(): void {
		$GLOBALS['wp_hooks'] = array();
		$indexnow = new IndexNow();
		$indexnow->register_hooks();

		$init = array_values(
			array_filter(
				$GLOBALS['wp_hooks'],
				static fn( array $record ): bool => 'init' === $record['hook']
			)
		);

		$this->assertCount( 1, $init );
		$this->assertSame( array( 'init', \Cybermaps\Discovery\IndexNowQueue::CRON_HOOK ), array_column( $GLOBALS['wp_hooks'], 'hook' ) );
	}

	public function test_legacy_gate_and_unsafe_method_behavior_are_absent_from_source(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Discovery/IndexNow.php' );

		$this->assertStringNotContainsString( "\$this->settings['post_types']", $source );
		$this->assertStringContainsString( 'URLManager::get_home_url', $source );
		$this->assertStringNotContainsString( 'transition_post_status', $source );
		$this->assertStringNotContainsString( 'before_delete_post', $source );
		$this->assertStringContainsString( "array( 'GET', 'HEAD' )", $source );
		$this->assertStringContainsString( 'status_header( 405 )', $source );
	}

	private function database(): IndexNowQueueTestWpdb {
		global $wpdb;
		return $wpdb;
	}
}

final class IndexNowQueueTestWpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';
	public bool $table_exists = true;
	public bool $admission_lock_available = true;
	public bool $admission_lock_held = false;
	public bool $fill_to_capacity_after_lock = false;
	public bool $fail_next_insert = false;
	public bool $fail_next_claim_read = false;
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	/** @var array<int,array{query:string,args:array<int,mixed>}> */
	public array $queries = array();
	private int $next_id = 1;
	private int $synthetic_count = 0;

	/** @return array{query:string,args:array<int,mixed>} */
	public function prepare( string $query, mixed ...$args ): array {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function esc_like( string $value ): string {
		return $value;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function get_var( array|string $prepared ): mixed {
		$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['args'] : array();
		if ( str_contains( $query, 'GET_LOCK' ) ) {
			if ( ! $this->admission_lock_available || $this->admission_lock_held ) {
				return 0;
			}
			$this->admission_lock_held = true;
			if ( $this->fill_to_capacity_after_lock ) {
				$this->synthetic_count = 50000;
			}
			return 1;
		}
		if ( str_contains( $query, 'RELEASE_LOCK' ) ) {
			$this->admission_lock_held = false;
			return 1;
		}
		if ( str_starts_with( $query, 'SHOW TABLES LIKE' ) ) {
			return $this->table_exists ? $this->prefix . 'cybermaps_indexnow_queue' : '';
		}
		if ( str_contains( $query, 'COUNT(*)' ) && str_contains( $query, 'state IN' ) ) {
			return $this->synthetic_count + count( $this->open_rows() );
		}
		if ( str_contains( $query, 'COUNT(*)' ) && str_contains( $query, 'next_attempt_at <=' ) ) {
			return count( $this->due_rows( (int) ( $args[2] ?? time() ) ) );
		}
		if ( str_contains( $query, 'COUNT(*)' ) && str_contains( $query, 'state =' ) ) {
			$count = count( $this->rows_by_state( (string) ( $args[1] ?? '' ) ) );
			return 'queued' === (string) ( $args[1] ?? '' ) ? $count + $this->synthetic_count : $count;
		}
		if ( str_contains( $query, 'MIN(next_attempt_at)' ) ) {
			$values = array_column( $this->rows_by_state( 'queued' ), 'next_attempt_at' );
			return array() === $values ? null : min( $values );
		}
		if ( str_contains( $query, 'MIN(lease_expires_at)' ) ) {
			$values = array_filter( array_column( $this->rows_by_state( 'claimed' ), 'lease_expires_at' ) );
			return array() === $values ? null : min( $values );
		}

		return null;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( array|string $prepared, string $output = ARRAY_A ): array|false {
		unset( $output );
		$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['args'] : array();
		if ( str_contains( $query, 'WHERE url_hash = ' ) ) {
			$row = $this->row_by_hash( (string) ( $args[1] ?? '' ) );
			return null === $row ? array() : array( $row );
		}
		if ( str_contains( $query, 'SELECT id FROM' ) ) {
			$limit = (int) end( $args );
			$rows  = str_contains( $query, 'state IN' )
				? $this->open_rows()
				: $this->due_rows( (int) ( $args[2] ?? time() ) );
			return array_map(
				static fn( array $row ): array => array( 'id' => $row['id'] ),
				array_slice( $this->sort_rows( $rows ), 0, $limit )
			);
		}
		if ( str_contains( $query, 'claim_token = ' ) && str_contains( $query, 'SELECT url' ) ) {
			if ( $this->fail_next_claim_read ) {
				$this->fail_next_claim_read = false;
				$this->last_error           = 'Claim read failed';
				return false;
			}
			$token = (string) ( $args[2] ?? '' );
			return array_map(
				static fn( array $row ): array => array( 'url' => $row['url'] ),
				$this->sort_rows(
					array_filter(
						$this->rows,
						static fn( array $row ): bool => 'claimed' === $row['state'] && $token === $row['claim_token']
					)
				)
			);
		}
		if ( str_contains( $query, 'id IN' ) && str_contains( $query, 'SELECT url' ) ) {
			$ids = $this->ids_from_query( $query );
			return array_map(
				static fn( array $row ): array => array( 'url' => $row['url'] ),
				$this->sort_rows(
					array_filter(
						$this->rows,
						static fn( array $row ): bool => in_array( (int) $row['id'], $ids, true )
					)
				)
			);
		}
		if ( str_contains( $query, 'SELECT * FROM' ) && str_contains( $query, 'claim_token' ) ) {
			$token  = (string) ( $args[2] ?? '' );
			$hashes = $this->hashes_from_query( $query );
			return array_values(
				array_filter(
					$this->rows,
					static fn( array $row ): bool => 'claimed' === $row['state'] && $token === $row['claim_token'] && in_array( $row['url_hash'], $hashes, true )
				)
			);
		}

		return array();
	}

	public function query( array|string $prepared ): int|false {
		$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['args'] : array();
		$this->queries[] = array(
			'query' => $query,
			'args'  => $args,
		);
		if ( str_starts_with( $query, 'INSERT IGNORE INTO' ) ) {
			if ( $this->fail_next_insert ) {
				$this->fail_next_insert = false;
				$this->last_error       = 'Insert failed';
				return false;
			}
			if ( null !== $this->row_by_hash( (string) $args[1] ) ) {
				return 0;
			}
			$this->rows[ $this->next_id ] = array(
				'id'               => $this->next_id,
				'url_hash'         => (string) $args[1],
				'url'              => (string) $args[2],
				'state'            => (string) $args[3],
				'attempts'         => (int) $args[4],
				'next_attempt_at'  => (int) $args[5],
				'claim_token'      => '',
				'lease_expires_at' => 0,
				'queued_again'     => 0,
				'last_status'      => 0,
				'last_error'       => '',
				'created_at'       => (int) $args[10],
				'updated_at'       => (int) $args[11],
			);
			++$this->next_id;
			return 1;
		}
		if ( str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'SET queued_again = 1' ) ) {
			$hash = (string) ( $args[2] ?? '' );
			foreach ( $this->rows as &$row ) {
				if ( $hash === $row['url_hash'] && 'claimed' === $row['state'] && 0 === (int) $row['queued_again'] ) {
					$row['queued_again'] = 1;
					return 1;
				}
			}
			return 0;
		}
		if ( str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'claim_token' ) && str_contains( $query, 'id IN' ) ) {
			$ids     = $this->ids_from_query( $query );
			$claimed = 0;
			foreach ( $this->rows as &$row ) {
				if ( in_array( (int) $row['id'], $ids, true ) && ( 'queued' === $row['state'] || ( 'claimed' === $row['state'] && (int) $row['lease_expires_at'] <= time() ) ) ) {
					$row['state']            = 'claimed';
					$row['claim_token']      = (string) ( $args[2] ?? '' );
					$row['lease_expires_at'] = (int) ( $args[3] ?? time() + 300 );
					$row['updated_at']       = (int) ( $args[4] ?? time() );
					++$claimed;
				}
			}
			return $claimed;
		}
		if ( str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'attempts = %d' ) ) {
			$hash  = (string) ( $args[8] ?? '' );
			$token = (string) ( $args[9] ?? '' );
			foreach ( $this->rows as &$row ) {
				if ( $hash === $row['url_hash'] && $token === $row['claim_token'] ) {
					$row['state']            = 'queued';
					$row['attempts']         = (int) $args[2];
					$row['next_attempt_at']  = (int) $args[3];
					$row['claim_token']      = '';
					$row['lease_expires_at'] = 0;
					$row['queued_again']     = 0;
					return 1;
				}
			}
			return 0;
		}
		if ( str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'WHERE state = %s AND claim_token = %s' ) ) {
			$token   = (string) ( $args[6] ?? '' );
			$updated = 0;
			foreach ( $this->rows as &$row ) {
				if ( 'claimed' === $row['state'] && $token === $row['claim_token'] ) {
					$row['state']            = 'queued';
					$row['next_attempt_at']  = (int) ( $args[2] ?? time() );
					$row['claim_token']      = '';
					$row['lease_expires_at'] = 0;
					$row['queued_again']     = 0;
					++$updated;
				}
			}
			return $updated;
		}
		if ( str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'queued_again = 0' ) && str_contains( $query, 'claim_token = ' ) ) {
			$updated = 0;
			foreach ( $this->rows as &$row ) {
				if ( 1 === (int) $row['queued_again'] ) {
					$row['state']            = 'queued';
					$row['attempts']         = 0;
					$row['next_attempt_at']  = (int) ( $args[2] ?? time() );
					$row['claim_token']      = '';
					$row['lease_expires_at'] = 0;
					$row['queued_again']     = 0;
					++$updated;
				}
			}
			return $updated;
		}
		if ( str_starts_with( $query, 'DELETE FROM' ) && str_contains( $query, 'claim_token' ) ) {
			$token            = (string) ( $args[1] ?? '' );
			$hashes           = $this->hashes_from_query( $query );
			$require_no_again = str_contains( $query, 'queued_again = 0' );
			$deleted          = 0;
			foreach ( $this->rows as $id => $row ) {
				if ( $token === $row['claim_token'] && in_array( $row['url_hash'], $hashes, true ) && ( ! $require_no_again || 0 === (int) $row['queued_again'] ) ) {
					unset( $this->rows[ $id ] );
					++$deleted;
				}
			}
			return $deleted;
		}
		if ( str_starts_with( $query, 'DROP TABLE' ) ) {
			$this->table_exists = false;
			$this->rows         = array();
			return 1;
		}
		return 0;
	}

	public function make_url_due( string $url ): void {
		$hash = hash( 'sha256', $url );
		foreach ( $this->rows as &$row ) {
			if ( $hash === $row['url_hash'] ) {
				$row['next_attempt_at'] = time() - 1;
			}
		}
	}

	public function expire_claim( string $token ): void {
		foreach ( $this->rows as &$row ) {
			if ( $token === $row['claim_token'] ) {
				$row['lease_expires_at'] = time() - 1;
			}
		}
	}

	public function seed_count( int $count ): void {
		$this->synthetic_count = $count;
	}

	private function row_by_hash( string $hash ): ?array {
		foreach ( $this->rows as $row ) {
			if ( $hash === $row['url_hash'] ) {
				return $row;
			}
		}
		return null;
	}

	/** @return array<int,array<string,mixed>> */
	private function open_rows(): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => in_array( $row['state'], array( 'queued', 'claimed' ), true )
			)
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function rows_by_state( string $state ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => $state === $row['state']
			)
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function due_rows( int $now ): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => ( 'queued' === $row['state'] && (int) $row['next_attempt_at'] <= $now ) || ( 'claimed' === $row['state'] && (int) $row['lease_expires_at'] > 0 && (int) $row['lease_expires_at'] <= $now )
			)
		);
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private function sort_rows( array $rows ): array {
		usort(
			$rows,
			static fn( array $left, array $right ): int => ( (int) $left['next_attempt_at'] <=> (int) $right['next_attempt_at'] )
				?: ( (int) $left['created_at'] <=> (int) $right['created_at'] )
				?: ( (int) $left['id'] <=> (int) $right['id'] )
		);
		return $rows;
	}

	/** @return int[] */
	private function ids_from_query( string $query ): array {
		if ( 1 !== preg_match( '/id IN \\(([^)]+)\\)/', $query, $matches ) ) {
			return array();
		}
		return array_map( 'intval', array_filter( explode( ',', $matches[1] ) ) );
	}

	/** @return string[] */
	private function hashes_from_query( string $query ): array {
		if ( 1 !== preg_match( '/url_hash IN \\(([^)]+)\\)/', $query, $matches ) ) {
			return array();
		}
		return array_map(
			static fn( string $hash ): string => trim( $hash, " '" ),
			array_filter( explode( ',', $matches[1] ) )
		);
	}
}
