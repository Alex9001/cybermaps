<?php
/**
 * Persistent, bounded IndexNow delivery queue.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores normalized IndexNow URLs until a delivery worker consumes them.
 *
 * The queue intentionally contains one site's public origin only. URL
 * eligibility is established by IndexNow before enqueueing, which keeps this
 * persistence layer useful to REST, CLI, and MCP callers without accepting
 * arbitrary destinations itself.
 */
final class IndexNowQueue {
	public const OPTION    = 'cybermaps_indexnow_queue';
	public const CRON_HOOK = 'cybermaps_process_indexnow_queue';

	private const MAX_BATCH_SIZE  = 10000;
	private const MIN_RETRY_DELAY = 60;
	private const DEBOUNCE_DELAY  = 60;

	private IndexNowQueueRepository $repository;

	public function __construct( ?IndexNowQueueRepository $repository = null ) {
		$this->repository = $repository ?? new IndexNowQueueRepository();
	}

	/**
	 * Add canonical public URLs to the queue once.
	 *
	 * @param string[] $urls Eligible, canonical URLs.
	 * @return array{accepted: int, duplicate: int, rejected: int}
	 */
	public function enqueue( array $urls ): array {
		$result = $this->repository->enqueue( $urls );
		if ( $result['accepted'] > 0 ) {
			$this->schedule( time() + self::DEBOUNCE_DELAY );
		}

		return array(
			'accepted'  => $result['accepted'],
			'duplicate' => $result['duplicate'],
			'rejected'  => $result['rejected'],
		);
	}

	/**
	 * Return a due, deterministic batch without removing it from durable state.
	 * A worker acknowledges only after IndexNow accepts the payload.
	 *
	 * @return string[]
	 */
	public function due_urls( int $limit = self::MAX_BATCH_SIZE, bool $force = false ): array {
		return $this->repository->peek_due( $limit, $force );
	}

	/**
	 * Claim due URLs for an exclusive worker lease.
	 *
	 * @return array{token:string,urls:string[],schema_available:bool}
	 */
	public function claim_due( int $limit = self::MAX_BATCH_SIZE, bool $force = false ): array {
		return $this->repository->claim_due( $limit, 300, $force );
	}

	/**
	 * Remove accepted URLs and retain a concise delivery diagnostic.
	 *
	 * @param string[] $urls URLs accepted by IndexNow.
	 */
	public function acknowledge( array $urls, string $token, int $status_code = 200 ): int {
		$count = $this->repository->acknowledge( $urls, $token, $status_code );
		$this->schedule_next();
		return $count;
	}

	/**
	 * Apply a bounded retry or discard a terminally failed batch.
	 *
	 * @param string[] $urls URLs in the rejected batch.
	 */
	public function retry_or_fail( array $urls, string $token, int $status_code, int $delay, string $reason, bool $retryable ): int {
		$count = $this->repository->retry_or_fail( $urls, $token, $status_code, $delay, $reason, $retryable );
		$this->schedule_next();
		return $count;
	}

	/**
	 * Queue health for status, REST, and MCP read-only reporting.
	 *
	 * @return array<string, mixed>
	 */
	public function health(): array {
		return array_merge(
			$this->repository->health(),
			array(
				'cron_hook' => self::CRON_HOOK,
			)
		);
	}

	public static function create_table(): bool {
		return IndexNowQueueSchema::create_table();
	}

	public static function table_name(): string {
		return IndexNowQueueSchema::table_name();
	}

	public static function migrate_legacy_option(): bool {
		return ( new IndexNowQueueRepository() )->migrate_legacy_option();
	}

	public static function cleanup_options(): void {
		IndexNowQueueSchema::cleanup_options();
	}

	public static function drop_table(): bool {
		return IndexNowQueueSchema::drop_table();
	}

	/**
	 * Ensure a single cron worker will revisit the durable queue.
	 */
	public function schedule( ?int $timestamp = null ): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		$timestamp = max( time() + 1, (int) ( $timestamp ?? ( time() + self::MIN_RETRY_DELAY ) ) );
		$existing  = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $existing ) {
			if ( (int) $existing === $timestamp ) {
				return;
			}
			if ( function_exists( 'wp_unschedule_event' ) ) {
				wp_unschedule_event( (int) $existing, self::CRON_HOOK );
			} else {
				return;
			}
		}

		wp_schedule_single_event( $timestamp, self::CRON_HOOK );
	}

	/**
	 * Re-arm the worker for the exact earliest queued item. This is required
	 * after an early cron invocation observes no due URLs.
	 */
	public function rearm(): void {
		$this->schedule_next();
	}

	private function schedule_next(): void {
		$next_attempt_at = $this->repository->next_due_timestamp();
		if ( $next_attempt_at > 0 ) {
			$this->schedule( max( time() + 1, $next_attempt_at ) );
		}
	}
}
