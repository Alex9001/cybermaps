<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only audit surface for REST consumers and future companion plugins.
 */
final class AuditReadAPI {
	/**
	 * Maximum combined resource/current-finding/baseline-finding rows that may
	 * be hydrated into one synchronous JSON response.
	 */
	public const MAX_JSON_SNAPSHOT_ROWS = 25000;

	public function __construct(
		private readonly AuditRunRepository $repository = new AuditRunRepository(),
		private readonly ContentAuditService $service = new ContentAuditService()
	) {}

	public function latest_run_id(): int {
		return $this->repository->latest_completed_run_id();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_run( int $run_id ): ?array {
		return $run_id > 0 ? $this->service->get_run_with_diff( $run_id ) : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function latest_run(): ?array {
		return $this->get_run( $this->latest_run_id() );
	}

	/**
	 * Return a report only when its complete JSON snapshot fits the synchronous
	 * response budget shared with the administrator JSON export.
	 *
	 * @throws \OverflowException When the immutable snapshot exceeds the bound.
	 * @return array<string,mixed>|null
	 */
	public function get_json_run( int $run_id ): ?array {
		if ( $run_id < 1 ) {
			return null;
		}

		$profile = $this->repository->get_run_export_profile( $run_id );
		if ( null === $profile ) {
			return null;
		}

		$snapshot_rows = (int) $profile['resource_count']
			+ (int) $profile['finding_count']
			+ (int) $profile['baseline_finding_count'];
		if ( $snapshot_rows > self::MAX_JSON_SNAPSHOT_ROWS ) {
			throw new \OverflowException(
				esc_html__( 'The saved content report is too large for a synchronous JSON response.', 'cybermaps' )
			);
		}

		return $this->service->get_run_with_diff( $run_id );
	}

	/**
	 * Return the latest report through the bounded JSON projection.
	 *
	 * @throws \OverflowException When the immutable snapshot exceeds the bound.
	 * @return array<string,mixed>|null
	 */
	public function latest_json_run(): ?array {
		return $this->get_json_run( $this->latest_run_id() );
	}

	/**
	 * Return defaults only; no site settings are mutated.
	 *
	 * @return array<string,mixed>
	 */
	public function default_policy(): array {
		return AuditPolicy::from_settings( array() )->to_array();
	}
}
