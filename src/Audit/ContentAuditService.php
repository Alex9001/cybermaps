<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates saved audit runs and compares their exact finding identities.
 */
final class ContentAuditService {
	public const RUN_LOCKED_ERROR_CODE = 40901;
	private const RUN_LOCK_TTL_SECONDS = 10 * MINUTE_IN_SECONDS;

	public function __construct(
		private readonly AuditRunRepository $repository = new AuditRunRepository(),
		private readonly ContentAuditEvaluator $evaluator = new ContentAuditEvaluator(),
		private readonly PublishedPostSource $post_source = new PublishedPostSource()
	) {}

	public function run( ?AuditPolicy $policy = null ): int {
		$lock_token = $this->repository->acquire_run_lock( self::RUN_LOCK_TTL_SECONDS );
		if ( null === $lock_token ) {
			throw new \RuntimeException(
				esc_html__( 'Another Content Intelligence Report is already running. Wait for it to finish before starting another.', 'cybermaps' ),
				self::RUN_LOCKED_ERROR_CODE // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed integer exception code, never rendered as message text.
			);
		}

		try {
			$policy = $policy ?? AuditPolicy::from_settings();
			$this->repository->cleanup_incomplete_runs(
				DAY_IN_SECONDS,
				25,
				$lock_token
			);

			$baseline_id = $this->repository->latest_completed_run_id();
			$run_id      = $this->repository->begin( $policy, $baseline_id );
			try {
				$post_types = \Cybermaps\Core\PublicationPostTypes::names();
				if ( empty( $post_types ) ) {
					$post_types = array( 'post', 'page' );
				}
				$resource_count = 0;
				$finding_count  = 0;
				$snapshot       = hash_init( 'sha256' );
				$this->update_snapshot_hash( $snapshot, array( 'policy' => $policy->to_array() ) );

				foreach ( $this->post_source->batches( $post_types ) as $posts ) {
					$this->renew_run_lock( $lock_token );
					try {
						$attached_image_parents = $this->post_source->attached_image_parent_ids( $posts );
						foreach ( $posts as $post ) {
							if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
								continue;
							}
							$result = $this->evaluator->evaluate(
								$post,
								$policy,
								null,
								isset( $attached_image_parents[ (int) $post->ID ] )
							);
							$this->repository->add_resource( $run_id, $result['resource'], $result['findings'] );
							++$resource_count;
							$finding_count += count( $result['findings'] );
							$this->update_snapshot_hash(
								$snapshot,
								array(
									'resource' => $result['resource'],
									'findings' => $result['findings'],
								)
							);
						}
					} finally {
						$this->flush_runtime_cache();
					}
				}

				$this->renew_run_lock( $lock_token );
				$this->repository->complete(
					$run_id,
					$resource_count,
					$finding_count,
					hash_final( $snapshot )
				);
			} catch ( \Throwable $error ) {
				$this->repository->fail( $run_id );
				throw $error;
			}
		} finally {
			$this->repository->release_run_lock( $lock_token );
		}

		return $run_id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_run_with_diff( int $run_id, bool $include_resources = true ): ?array {
		$run = $this->repository->get_run( $run_id, $include_resources );
		if ( null === $run ) {
			return null;
		}

		$baseline_id = (int) ( $run['baseline_run_id'] ?? 0 );
		$baseline    = $baseline_id > 0 ? $this->repository->get_run( $baseline_id, false ) : null;
		$run['diff'] = $this->compare( $run, $baseline );
		return $run;
	}

	/**
	 * Return the bounded data needed by the Reports admin page.
	 *
	 * Full resource measurements remain available to exact exports through
	 * get_run_with_diff(); this path intentionally loads only a findings preview,
	 * grouped finding counts, and database-computed comparison counts.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_run_for_display( int $run_id, int $preview_limit = 25 ): ?array {
		$run = $this->repository->get_run_for_display( $run_id, $preview_limit );
		if ( null === $run ) {
			return null;
		}

		$run['diff'] = $this->repository->finding_diff_counts(
			$run_id,
			(int) ( $run['baseline_run_id'] ?? 0 ),
			(int) ( $run['finding_count'] ?? 0 )
		);

		return $run;
	}

	/**
	 * @param array<string,mixed>      $run Current run.
	 * @param array<string,mixed>|null $baseline Baseline run.
	 * @return array<string,mixed>
	 */
	public function compare( array $run, ?array $baseline ): array {
		$current  = $this->finding_map( (array) ( $run['findings'] ?? array() ) );
		$previous = $this->finding_map( (array) ( $baseline['findings'] ?? array() ) );

		return array(
			'baseline_run_id' => (int) ( $baseline['id'] ?? 0 ),
			'added'           => array_values( array_diff_key( $current, $previous ) ),
			'resolved'        => array_values( array_diff_key( $previous, $current ) ),
			'persisting'      => array_values( array_intersect_key( $current, $previous ) ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @return array<string,array<string,mixed>>
	 */
	private function finding_map( array $findings ): array {
		$map = array();
		foreach ( $findings as $finding ) {
			$key = (string) ( $finding['resource_key'] ?? '' ) . ':' . (string) ( $finding['finding_key'] ?? $finding['key'] ?? '' );
			if ( ':' !== $key ) {
				$map[ $key ] = $finding;
			}
		}
		return $map;
	}

	/**
	 * Add one length-delimited canonical value to the report snapshot hash.
	 *
	 * The hash covers every saved resource measurement and finding field, not
	 * merely content hashes and finding names. It is a stable fingerprint of
	 * the payload at completion, not authentication or tamper-proofing.
	 *
	 * @param \HashContext         $context Incremental SHA-256 context.
	 * @param array<string,mixed> $value   Snapshot fragment.
	 */
	private function update_snapshot_hash( \HashContext $context, array $value ): void {
		$encoded = wp_json_encode(
			$this->canonicalize_snapshot_value( $value ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not encode the content report snapshot.', 'cybermaps' ) );
		}

		hash_update( $context, pack( 'N', strlen( $encoded ) ) . $encoded );
	}

	/**
	 * @return mixed Canonicalized value.
	 */
	private function canonicalize_snapshot_value( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( $this, 'canonicalize_snapshot_value' ), $value );
		}

		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize_snapshot_value( $item );
		}

		return $value;
	}

	/**
	 * Release post, meta, and term objects accumulated by the completed batch.
	 *
	 * WordPress 7 exposes a runtime-only cache flush. Object-cache drop-ins that
	 * do not implement that contract are left untouched rather than flushing
	 * their persistent backend.
	 */
	private function flush_runtime_cache(): void {
		if (
			function_exists( 'wp_cache_flush_runtime' )
			&& (
				! function_exists( 'wp_cache_supports' )
				|| wp_cache_supports( 'flush_runtime' )
			)
		) {
			wp_cache_flush_runtime();
		}
	}

	private function renew_run_lock( string $token ): void {
		if ( ! $this->repository->refresh_run_lock( $token, self::RUN_LOCK_TTL_SECONDS ) ) {
			throw new \RuntimeException(
				esc_html__( 'The Content Intelligence Report lock expired or changed ownership, so this run stopped safely.', 'cybermaps' ),
				self::RUN_LOCKED_ERROR_CODE // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed integer exception code, never rendered as message text.
			);
		}
	}
}
