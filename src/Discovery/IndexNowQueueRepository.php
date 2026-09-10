<?php
/**
 * Durable IndexNow queue repository.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database-authoritative queue operations for IndexNow delivery.
 */
final class IndexNowQueueRepository {
	public const LAST_RESULT_OPTION = 'cybermaps_indexnow_queue_last_result';

	private const STATE_QUEUED      = 'queued';
	private const STATE_CLAIMED     = 'claimed';
	private const MAX_QUEUE_SIZE    = 50000;
	private const MAX_BATCH_SIZE    = 10000;
	private const MAX_RETRY_COUNT   = 5;
	private const MIN_RETRY_DELAY   = 60;
	private const MAX_RETRY_DELAY   = 3600;
	private const DEFAULT_LEASE_TTL = 300;
	private const MAX_URL_BYTES     = 2048;
	private const MAX_ERROR_BYTES   = 255;
	private const LOCK_TIMEOUT      = 0;

	private bool $migrated_legacy = false;

	/**
	 * @return array{accepted:int,duplicate:int,rejected:int,schema_available:bool}
	 */
	public function enqueue( array $urls ): array {
		if ( ! $this->schema_available() ) {
			return array(
				'accepted'         => 0,
				'duplicate'        => 0,
				'rejected'         => \count( $urls ),
				'schema_available' => false,
			);
		}
		$this->migrate_legacy_option();

		$result = $this->with_admission_lock(
			function () use ( $urls ): array {
				return $this->enqueue_locked( $urls );
			}
		);
		if ( ! \is_array( $result ) ) {
			return array(
				'accepted'         => 0,
				'duplicate'        => 0,
				'rejected'         => \count( $urls ),
				'schema_available' => true,
			);
		}

		return $result;
	}

	/**
	 * Atomically claim one due batch.
	 *
	 * @return array{token:string,urls:string[],schema_available:bool}
	 */
	public function claim_due( int $limit = self::MAX_BATCH_SIZE, int $lease_ttl = self::DEFAULT_LEASE_TTL, bool $force = false ): array {
		if ( ! $this->schema_available() ) {
			return array(
				'token'            => '',
				'urls'             => array(),
				'schema_available' => false,
			);
		}
		$this->migrate_legacy_option();

		$limit = max( 1, min( self::MAX_BATCH_SIZE, $limit ) );
		$now   = time();
		$ids   = $this->candidate_ids( $limit, $now, $force );
		if ( array() === $ids ) {
			return array(
				'token'            => '',
				'urls'             => array(),
				'schema_available' => true,
			);
		}

		$token   = $this->new_token();
		$claimed = $this->claim_ids( $ids, $token, $now, max( 30, $lease_ttl ), $force );
		if ( $claimed < 1 ) {
			return array(
				'token'            => '',
				'urls'             => array(),
				'schema_available' => true,
			);
		}

		$urls = $this->urls_for_token( $token, $limit );
		if ( null === $urls ) {
			$this->release_claim_token( $token, $now );
			return array(
				'token'            => '',
				'urls'             => array(),
				'schema_available' => true,
			);
		}

		return array(
			'token'            => $token,
			'urls'             => $urls,
			'schema_available' => true,
		);
	}

	/**
	 * Non-mutating due URL peek for diagnostics/backward compatibility.
	 *
	 * @return string[]
	 */
	public function peek_due( int $limit = self::MAX_BATCH_SIZE, bool $force = false ): array {
		if ( ! $this->schema_available() ) {
			return array();
		}
		$this->migrate_legacy_option();

		$ids = $this->candidate_ids( max( 1, min( self::MAX_BATCH_SIZE, $limit ) ), time(), $force );
		return $this->urls_for_ids( $ids );
	}

	/**
	 * Acknowledge only rows still owned by this worker token.
	 */
	public function acknowledge( array $urls, string $token, int $status_code = 200 ): int {
		if ( '' === $token || ! $this->schema_available() ) {
			return 0;
		}

		$hashes = $this->hashes_for_urls( $urls );
		if ( array() === $hashes ) {
			return 0;
		}

		$now      = time();
		$requeued = $this->requeue_repeated_claims( $hashes, $token, $status_code, $now );
		$deleted  = $this->delete_claims( $hashes, $token, false );
		$count    = $requeued + $deleted;
		$this->record_result(
			array(
				'status'     => 'accepted',
				'code'       => $status_code,
				'count'      => $count,
				'requeued'   => $requeued,
				'updated_at' => $now,
			)
		);

		return $count;
	}

	/**
	 * Retry or discard only rows still owned by this worker token.
	 */
	public function retry_or_fail( array $urls, string $token, int $status_code, int $delay, string $reason, bool $retryable ): int {
		if ( '' === $token || ! $this->schema_available() ) {
			return 0;
		}

		$hashes = $this->hashes_for_urls( $urls );
		if ( array() === $hashes ) {
			return 0;
		}

		$rows      = $this->claimed_rows( $hashes, $token );
		$now       = time();
		$retried   = 0;
		$discarded = 0;
		$delay     = max( self::MIN_RETRY_DELAY, min( self::MAX_RETRY_DELAY, $delay ) );
		$reason    = $this->truncate( $reason, self::MAX_ERROR_BYTES );

		foreach ( $rows as $row ) {
			$hash     = (string) ( $row['url_hash'] ?? '' );
			$attempts = (int) ( $row['attempts'] ?? 0 ) + 1;
			if ( '' === $hash || ! $retryable || $attempts >= self::MAX_RETRY_COUNT ) {
				if ( '' !== $hash && $this->delete_claims( array( $hash ), $token, true ) > 0 ) {
					++$discarded;
				}
				continue;
			}

			$next_delay = $this->retry_delay_for_attempt( $attempts, $delay );
			if ( $this->release_for_retry( $hash, $token, $attempts, $now + $next_delay, $status_code, $reason, $now ) ) {
				++$retried;
			}
		}

		$this->record_result(
			array(
				'status'     => $retried > 0 ? 'retry_scheduled' : 'discarded',
				'code'       => $status_code,
				'count'      => \count( $urls ),
				'retried'    => $retried,
				'discarded'  => $discarded,
				'message'    => $reason,
				'updated_at' => $now,
			)
		);

		return $retried + $discarded;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function health(): array {
		$last_result = $this->last_result();
		if ( ! $this->schema_available() ) {
			return array(
				'status'           => 'schema_unavailable',
				'schema_available' => false,
				'queued'           => 0,
				'due'              => 0,
				'claimed'          => 0,
				'next_attempt_at'  => 0,
				'last_result'      => $last_result,
				'max_batch_size'   => self::MAX_BATCH_SIZE,
				'max_queue_size'   => self::MAX_QUEUE_SIZE,
			);
		}
		$this->migrate_legacy_option();

		return array(
			'status'           => 'ok',
			'schema_available' => true,
			'queued'           => $this->state_count( self::STATE_QUEUED ),
			'due'              => $this->due_count(),
			'claimed'          => $this->state_count( self::STATE_CLAIMED ),
			'next_attempt_at'  => $this->next_due_timestamp(),
			'last_result'      => $last_result,
			'max_batch_size'   => self::MAX_BATCH_SIZE,
			'max_queue_size'   => self::MAX_QUEUE_SIZE,
		);
	}

	public function schema_available(): bool {
		return IndexNowQueueSchema::table_exists();
	}

	public function next_due_timestamp(): int {
		if ( ! $this->schema_available() ) {
			return 0;
		}

		global $wpdb;
		$table = IndexNowQueueSchema::table_name();
		$now   = time();
		$next  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT MIN(next_attempt_at) FROM %i WHERE state = %s AND next_attempt_at > 0',
				$table,
				self::STATE_QUEUED
			)
		);
		$lease = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT MIN(lease_expires_at) FROM %i WHERE state = %s AND lease_expires_at > 0',
				$table,
				self::STATE_CLAIMED
			)
		);

		$candidates = array();
		foreach ( array( $next, $lease ) as $value ) {
			$value = (int) $value;
			if ( $value > 0 ) {
				$candidates[] = $value;
			}
		}
		if ( array() === $candidates ) {
			return 0;
		}

		$earliest = min( $candidates );
		return $earliest <= $now ? $now : $earliest;
	}

	public function migrate_legacy_option(): bool {
		if ( $this->migrated_legacy || ! $this->schema_available() ) {
			return false;
		}
		$this->migrated_legacy = true;

		$legacy = \get_option( IndexNowQueue::OPTION, array() );
		if ( ! \is_array( $legacy ) || ! isset( $legacy['urls'] ) || ! \is_array( $legacy['urls'] ) ) {
			return false;
		}

		$result = $this->with_admission_lock(
			function () use ( $legacy ): array {
				return $this->migrate_legacy_option_locked( $legacy );
			}
		);

		return \is_array( $result ) && ! empty( $result['migrated'] );
	}

	/**
	 * @param string[] $urls
	 * @return array{accepted:int,duplicate:int,rejected:int,schema_available:bool}
	 */
	private function enqueue_locked( array $urls ): array {
		$accepted   = 0;
		$duplicate  = 0;
		$rejected   = 0;
		$seen       = array();
		$open_count = $this->open_count();
		if ( null === $open_count ) {
			return array(
				'accepted'         => 0,
				'duplicate'        => 0,
				'rejected'         => \count( $urls ),
				'schema_available' => true,
			);
		}
		$capacity = max( 0, self::MAX_QUEUE_SIZE - $open_count );

		foreach ( $urls as $url ) {
			$url = $this->bounded_url( $url );
			if ( '' === $url ) {
				++$rejected;
				continue;
			}
			$hash = $this->url_hash( $url );
			if ( isset( $seen[ $hash ] ) ) {
				++$duplicate;
				continue;
			}
			$seen[ $hash ] = true;

			$result = $this->upsert_url( $url, time(), $capacity > 0 );
			if ( 'inserted' === $result ) {
				--$capacity;
				++$accepted;
			} elseif ( 'requeued_claim' === $result ) {
				++$accepted;
			} elseif ( 'duplicate' === $result ) {
				++$duplicate;
			} else {
				++$rejected;
			}
		}

		return array(
			'accepted'         => $accepted,
			'duplicate'        => $duplicate,
			'rejected'         => $rejected,
			'schema_available' => true,
		);
	}

	/**
	 * @param array<string,mixed> $legacy Legacy option payload.
	 * @return array{migrated:bool,valid:int,inserted:int,duplicate:int,requeued_claim:int,rejected:int,db_failed:int,durable:int}
	 */
	private function migrate_legacy_option_locked( array $legacy ): array {
		$stats = array(
			'migrated'       => false,
			'valid'          => 0,
			'inserted'       => 0,
			'duplicate'      => 0,
			'requeued_claim' => 0,
			'rejected'       => 0,
			'db_failed'      => 0,
			'durable'        => 0,
		);

		foreach ( $legacy['urls'] as $url => $entry ) {
			$outcome = $this->migrate_entry( $url, $entry );
			if ( ! $outcome['valid'] ) {
				++$stats['rejected'];
				continue;
			}
			++$stats['valid'];
			$result = $outcome['result'];
			if ( isset( $stats[ $result ] ) ) {
				++$stats[ $result ];
			} else {
				++$stats['db_failed'];
			}
			if ( $outcome['durable'] ) {
				++$stats['durable'];
			}
		}

		if ( $stats['valid'] === $stats['durable'] ) {
			\delete_option( IndexNowQueue::OPTION );
			$this->invalidate_option_cache( IndexNowQueue::OPTION );
			$stats['migrated'] = true;
		}

		$this->record_result(
			array(
				'status'         => $stats['migrated'] ? 'legacy_migration_completed' : 'legacy_migration_retained',
				'valid'          => $stats['valid'],
				'inserted'       => $stats['inserted'],
				'duplicate'      => $stats['duplicate'],
				'requeued_claim' => $stats['requeued_claim'],
				'rejected'       => $stats['rejected'],
				'db_failed'      => $stats['db_failed'],
				'durable'        => $stats['durable'],
				'updated_at'     => time(),
			)
		);

		return $stats;
	}

	/**
	 * @return array{valid:bool,result:string,durable:bool}
	 */
	private function migrate_entry( mixed $url, mixed $entry ): array {
		$url = $this->bounded_url( $url );
		if ( '' === $url ) {
			return array(
				'valid'   => false,
				'result'  => 'rejected',
				'durable' => false,
			);
		}
		$open_count = $this->open_count();
		if ( null === $open_count ) {
			return array(
				'valid'   => true,
				'result'  => 'db_failed',
				'durable' => false,
			);
		}
		$attempts        = \is_array( $entry ) ? max( 0, (int) ( $entry['attempts'] ?? 0 ) ) : 0;
		$next_attempt_at = \is_array( $entry ) ? max( 0, (int) ( $entry['next_attempt_at'] ?? time() ) ) : time();
		$result          = $this->upsert_url(
			$url,
			max( 1, $next_attempt_at ),
			$open_count < self::MAX_QUEUE_SIZE,
			$attempts
		);
		$durable         = \in_array( $result, array( 'inserted', 'duplicate', 'requeued_claim' ), true )
			&& null !== $this->row_by_hash( $this->url_hash( $url ) );
		return array(
			'valid'   => true,
			'result'  => $result,
			'durable' => $durable,
		);
	}

	private function upsert_url( string $url, int $next_attempt_at, bool $capacity_available, int $attempts = 0 ): string {
		$hash = $this->url_hash( $url );
		$row  = $this->row_by_hash( $hash );
		if ( null !== $row ) {
			return $this->existing_result( $row, $hash );
		}
		if ( ! $capacity_available ) {
			return 'rejected';
		}

		global $wpdb;
		$now      = time();
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (url_hash, url, state, attempts, next_attempt_at, claim_token, lease_expires_at, queued_again, last_status, last_error, created_at, updated_at) VALUES (%s, %s, %s, %d, %d, %s, %d, %d, %d, %s, %d, %d)',
				IndexNowQueueSchema::table_name(),
				$hash,
				$url,
				self::STATE_QUEUED,
				$attempts,
				$next_attempt_at,
				'',
				0,
				0,
				0,
				'',
				$now,
				$now
			)
		);

		if ( false === $inserted ) {
			return 'db_failed';
		}
		if ( 1 === (int) $inserted ) {
			return 'inserted';
		}

		return $this->collision_result( $hash );
	}

	/**
	 * @param array<string,mixed> $row Existing row.
	 */
	private function existing_result( array $row, string $hash ): string {
		if ( self::STATE_CLAIMED !== (string) ( $row['state'] ?? '' ) ) {
			return 'duplicate';
		}
		if ( ! empty( $row['queued_again'] ) ) {
			return 'duplicate';
		}
		return $this->mark_claim_for_redelivery( $hash ) ? 'requeued_claim' : 'rejected';
	}

	private function collision_result( string $hash ): string {
		$row = $this->row_by_hash( $hash );
		if ( null !== $row && self::STATE_CLAIMED === (string) ( $row['state'] ?? '' ) ) {
			return $this->mark_claim_for_redelivery( $hash ) ? 'requeued_claim' : 'duplicate';
		}
		return null === $row ? 'rejected' : 'duplicate';
	}

	private function mark_claim_for_redelivery( string $hash ): bool {
		global $wpdb;
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE %i SET queued_again = 1, updated_at = %d WHERE url_hash = %s AND state = %s AND queued_again = 0',
				IndexNowQueueSchema::table_name(),
				time(),
				$hash,
				self::STATE_CLAIMED
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * @return int[]
	 */
	private function candidate_ids( int $limit, int $now, bool $force ): array {
		global $wpdb;
		$table = IndexNowQueueSchema::table_name();
		if ( $force ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains a sanitized integer ID list; scalar values remain placeholders.
					'SELECT id FROM %i WHERE state IN (%s, %s) ORDER BY next_attempt_at ASC, created_at ASC, id ASC LIMIT %d',
					$table,
					self::STATE_QUEUED,
					self::STATE_CLAIMED,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT id FROM %i WHERE (state = %s AND next_attempt_at <= %d) OR (state = %s AND lease_expires_at > 0 AND lease_expires_at <= %d) ORDER BY next_attempt_at ASC, created_at ASC, id ASC LIMIT %d',
					$table,
					self::STATE_QUEUED,
					$now,
					self::STATE_CLAIMED,
					$now,
					$limit
				),
				ARRAY_A
			);
		}

		$ids = array();
		foreach ( \is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param int[] $ids
	 */
	private function claim_ids( array $ids, string $token, int $now, int $lease_ttl, bool $force ): int {
		if ( array() === $ids ) {
			return 0;
		}

		global $wpdb;
		$id_list = implode( ',', array_map( 'intval', $ids ) );
		if ( $force ) {
			$sql = "UPDATE %i SET state = %s, claim_token = %s, lease_expires_at = %d, updated_at = %d WHERE id IN ({$id_list}) AND state IN (%s, %s)";
			return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains a sanitized integer ID list; scalar values remain placeholders.
					IndexNowQueueSchema::table_name(),
					self::STATE_CLAIMED,
					$token,
					$now + $lease_ttl,
					$now,
					self::STATE_QUEUED,
					self::STATE_CLAIMED
				)
			);
		}

		$sql = "UPDATE %i SET state = %s, claim_token = %s, lease_expires_at = %d, updated_at = %d WHERE id IN ({$id_list}) AND ((state = %s AND next_attempt_at <= %d) OR (state = %s AND lease_expires_at > 0 AND lease_expires_at <= %d))";
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains a sanitized integer ID list; scalar values remain placeholders.
				IndexNowQueueSchema::table_name(),
				self::STATE_CLAIMED,
				$token,
				$now + $lease_ttl,
				$now,
				self::STATE_QUEUED,
				$now,
				self::STATE_CLAIMED,
				$now
			)
		);
	}

	/**
	 * @return string[]|null Null means the token read failed.
	 */
	private function urls_for_token( string $token, int $limit ): ?array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT url FROM %i WHERE state = %s AND claim_token = %s ORDER BY next_attempt_at ASC, created_at ASC, id ASC LIMIT %d',
				IndexNowQueueSchema::table_name(),
				self::STATE_CLAIMED,
				$token,
				$limit
			),
			ARRAY_A
		);
		if ( ! \is_array( $rows ) ) {
			return null;
		}

		$urls = array();
		foreach ( $rows as $row ) {
			if ( \is_string( $row['url'] ?? null ) ) {
				$urls[] = $row['url'];
			}
		}

		return $urls;
	}

	private function release_claim_token( string $token, int $now ): void {
		if ( '' === $token ) {
			return;
		}

		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE %i SET state = %s, next_attempt_at = %d, claim_token = %s, lease_expires_at = 0, queued_again = 0, updated_at = %d WHERE state = %s AND claim_token = %s',
				IndexNowQueueSchema::table_name(),
				self::STATE_QUEUED,
				$now,
				'',
				$now,
				self::STATE_CLAIMED,
				$token
			)
		);
	}

	/**
	 * @param int[] $ids
	 * @return string[]
	 */
	private function urls_for_ids( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		global $wpdb;
		$id_list = implode( ',', array_map( 'intval', $ids ) );
		$sql     = "SELECT url FROM %i WHERE id IN ({$id_list}) ORDER BY next_attempt_at ASC, created_at ASC, id ASC";
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains a sanitized integer ID list.
				IndexNowQueueSchema::table_name()
			),
			ARRAY_A
		);

		$urls = array();
		foreach ( \is_array( $rows ) ? $rows : array() as $row ) {
			if ( \is_string( $row['url'] ?? null ) ) {
				$urls[] = $row['url'];
			}
		}

		return $urls;
	}

	/**
	 * @param string[] $hashes
	 */
	private function requeue_repeated_claims( array $hashes, string $token, int $status_code, int $now ): int {
		global $wpdb;
		$hash_list = $this->quoted_hash_list( $hashes );
		if ( '' === $hash_list ) {
			return 0;
		}

		$sql = "UPDATE %i SET state = %s, attempts = 0, next_attempt_at = %d, claim_token = %s, lease_expires_at = 0, queued_again = 0, last_status = %d, last_error = %s, updated_at = %d WHERE claim_token = %s AND url_hash IN ({$hash_list}) AND queued_again = 1";
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains a sanitized URL-hash list; scalar values remain placeholders.
				IndexNowQueueSchema::table_name(),
				self::STATE_QUEUED,
				$now,
				'',
				$status_code,
				'',
				$now,
				$token
			)
		);
	}

	/**
	 * @param string[] $hashes
	 */
	private function delete_claims( array $hashes, string $token, bool $include_requeued ): int {
		global $wpdb;
		$hash_list = $this->quoted_hash_list( $hashes );
		if ( '' === $hash_list ) {
			return 0;
		}

		$queued_again_sql = $include_requeued ? '' : ' AND queued_again = 0';
		$sql              = "DELETE FROM %i WHERE claim_token = %s AND url_hash IN ({$hash_list}){$queued_again_sql}";

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( $sql, IndexNowQueueSchema::table_name(), $token ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains a sanitized URL-hash list.
		);
	}

	private function release_for_retry( string $hash, string $token, int $attempts, int $next_attempt_at, int $status_code, string $reason, int $now ): bool {
		global $wpdb;
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE %i SET state = %s, attempts = %d, next_attempt_at = %d, claim_token = %s, lease_expires_at = 0, queued_again = 0, last_status = %d, last_error = %s, updated_at = %d WHERE url_hash = %s AND claim_token = %s',
				IndexNowQueueSchema::table_name(),
				self::STATE_QUEUED,
				$attempts,
				$next_attempt_at,
				'',
				$status_code,
				$reason,
				$now,
				$hash,
				$token
			)
		);

		return (int) $updated > 0;
	}

	/**
	 * @param string[] $hashes
	 * @return array<int,array<string,mixed>>
	 */
	private function claimed_rows( array $hashes, string $token ): array {
		global $wpdb;
		$hash_list = $this->quoted_hash_list( $hashes );
		if ( '' === $hash_list ) {
			return array();
		}

		$sql  = "SELECT * FROM %i WHERE state = %s AND claim_token = %s AND url_hash IN ({$hash_list})";
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( $sql, IndexNowQueueSchema::table_name(), self::STATE_CLAIMED, $token ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL contains a sanitized URL-hash list.
			ARRAY_A
		);

		return \is_array( $rows ) ? $rows : array();
	}

	private function row_by_hash( string $hash ): ?array {
		global $wpdb;
		$row = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE url_hash = %s LIMIT 1',
				IndexNowQueueSchema::table_name(),
				$hash
			),
			ARRAY_A
		);
		if ( ! \is_array( $row ) || ! isset( $row[0] ) || ! \is_array( $row[0] ) ) {
			return null;
		}

		return $row[0];
	}

	private function open_count(): ?int {
		global $wpdb;
		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE state IN (%s, %s)',
				IndexNowQueueSchema::table_name(),
				self::STATE_QUEUED,
				self::STATE_CLAIMED
			)
		);
		if ( false === $count || ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) || ! \is_numeric( $count ) ) {
			return null;
		}

		return max( 0, (int) $count );
	}

	private function state_count( string $state ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE state = %s',
				IndexNowQueueSchema::table_name(),
				$state
			)
		);
	}

	private function due_count(): int {
		global $wpdb;
		$now = time();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE (state = %s AND next_attempt_at <= %d) OR (state = %s AND lease_expires_at > 0 AND lease_expires_at <= %d)',
				IndexNowQueueSchema::table_name(),
				self::STATE_QUEUED,
				$now,
				self::STATE_CLAIMED,
				$now
			)
		);
	}

	/**
	 * @param string[] $urls
	 * @return string[]
	 */
	private function hashes_for_urls( array $urls ): array {
		$hashes = array();
		foreach ( $urls as $url ) {
			$url = $this->bounded_url( $url );
			if ( '' !== $url ) {
				$hashes[ $this->url_hash( $url ) ] = true;
			}
		}

		return array_keys( $hashes );
	}

	private function bounded_url( mixed $url ): string {
		if ( ! \is_string( $url ) || '' === $url || \strlen( $url ) > self::MAX_URL_BYTES ) {
			return '';
		}
		$parts = \wp_parse_url( $url );
		if ( ! \is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = \strtolower( (string) $parts['scheme'] );
		if ( ! \in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}

	private function url_hash( string $url ): string {
		return \hash( 'sha256', $url );
	}

	/**
	 * @param string[] $hashes
	 */
	private function quoted_hash_list( array $hashes ): string {
		$quoted = array();
		foreach ( $hashes as $hash ) {
			if ( 1 === \preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				$quoted[] = "'" . $hash . "'";
			}
		}

		return implode( ',', $quoted );
	}

	private function retry_delay_for_attempt( int $attempts, int $base_delay ): int {
		$exponential = min( self::MAX_RETRY_DELAY, self::MIN_RETRY_DELAY * ( 2 ** max( 0, $attempts - 1 ) ) );
		$jitter      = random_int( 0, min( 30, (int) floor( max( $base_delay, $exponential ) / 4 ) ) );

		return min( self::MAX_RETRY_DELAY, max( $base_delay, $exponential ) + $jitter );
	}

	private function new_token(): string {
		try {
			return \bin2hex( \random_bytes( 32 ) );
		} catch ( \Throwable ) {
			return \hash( 'sha256', \wp_generate_password( 64, false, false ) . '|' . \microtime( true ) );
		}
	}

	private function admission_lock_name(): string {
		return 'cybermaps_indexnow_queue_' . \hash( 'sha256', IndexNowQueueSchema::table_name() );
	}

	private function acquire_admission_lock(): string {
		global $wpdb;
		if ( ! \method_exists( $wpdb, 'get_var' ) ) {
			return '';
		}

		$name     = $this->admission_lock_name();
		$acquired = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT )
		);

		return 1 === (int) $acquired ? $name : '';
	}

	private function release_admission_lock( string $name ): void {
		global $wpdb;
		if ( '' === $name || ! \method_exists( $wpdb, 'get_var' ) ) {
			return;
		}

		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name )
		);
	}

	private function with_admission_lock( callable $callback ): mixed {
		$lock = $this->acquire_admission_lock();
		if ( '' === $lock ) {
			return null;
		}

		try {
			return $callback();
		} finally {
			$this->release_admission_lock( $lock );
		}
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function record_result( array $result ): void {
		\update_option( self::LAST_RESULT_OPTION, $result, false );
		$this->invalidate_option_cache( self::LAST_RESULT_OPTION );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function last_result(): array {
		$result = \get_option( self::LAST_RESULT_OPTION, array() );
		return \is_array( $result ) ? $result : array();
	}

	private function truncate( string $value, int $max_bytes ): string {
		if ( \strlen( $value ) <= $max_bytes ) {
			return $value;
		}

		return \substr( $value, 0, $max_bytes );
	}

	private function invalidate_option_cache( string $option_name ): void {
		if ( ! \function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		\wp_cache_delete( $option_name, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
		\wp_cache_delete( 'alloptions', 'options' );
	}
}
