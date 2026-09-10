<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Support;

/**
 * Deterministic counters for remediation performance evidence (test-only).
 */
final class RemediationInstrumentation {
	private static int $queries = 0;
	private static int $candidate_evaluations = 0;
	private static int $static_target_visits = 0;
	private static int $ownership_reads = 0;
	private static int $file_content_verifications = 0;
	private static int $continuation_runs = 0;

	public static function reset(): void {
		self::$queries                      = 0;
		self::$candidate_evaluations        = 0;
		self::$static_target_visits         = 0;
		self::$ownership_reads              = 0;
		self::$file_content_verifications   = 0;
		self::$continuation_runs            = 0;
	}

	public static function record_query(): void {
		++self::$queries;
	}

	public static function record_candidate_evaluation(): void {
		++self::$candidate_evaluations;
	}

	public static function record_static_target_visit(): void {
		++self::$static_target_visits;
	}

	public static function record_ownership_read(): void {
		++self::$ownership_reads;
	}

	public static function record_file_content_verification(): void {
		++self::$file_content_verifications;
	}

	public static function record_continuation_run(): void {
		++self::$continuation_runs;
	}

	/**
	 * @return array<string, int>
	 */
	public static function snapshot(): array {
		return array(
			'queries'                    => self::$queries,
			'candidate_evaluations'      => self::$candidate_evaluations,
			'static_target_visits'       => self::$static_target_visits,
			'ownership_reads'            => self::$ownership_reads,
			'file_content_verifications' => self::$file_content_verifications,
			'continuation_runs'          => self::$continuation_runs,
		);
	}
}
