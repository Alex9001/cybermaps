<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Audit\AuditExporter;
use Cybermaps\Audit\AuditPolicy;
use Cybermaps\Audit\AuditReadAPI;
use Cybermaps\Audit\AuditRunRepository;
use Cybermaps\Audit\ContentAuditService;
use Cybermaps\Audit\DiscoveryReportExporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns nonce-protected report runs and exports.
 */
final class ContentAuditManager {
	private const MAX_CSV_EXPORT_FINDINGS       = 50000;
	private const MAX_HTML_EXPORT_FINDING_ROWS  = 25000;
	private const MAX_JSON_EXPORT_SNAPSHOT_ROWS = AuditReadAPI::MAX_JSON_SNAPSHOT_ROWS;

	public function register_hooks(): void {
		add_action( 'admin_init', array( AuditRunRepository::class, 'maybe_upgrade' ) );
		add_action( 'cybermaps_cleanup_logs_event', array( $this, 'cleanup_incomplete_runs' ) );
		add_action( 'admin_post_cybermaps_run_content_audit', array( $this, 'handle_run' ) );
		add_action( 'admin_post_cybermaps_delete_content_audit', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_cybermaps_export_content_audit', array( $this, 'handle_export' ) );
		add_action( 'admin_post_cybermaps_export_discovery_report', array( $this, 'handle_discovery_export' ) );
	}

	/**
	 * Reuse Core's daily cleanup event for abandoned report transactions.
	 */
	public function cleanup_incomplete_runs(): void {
		( new AuditRunRepository() )->cleanup_incomplete_runs();
	}

	public function handle_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'cybermaps' ) );
		}
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_die(
				esc_html__( 'Invalid request method.', 'cybermaps' ),
				'',
				array( 'response' => 405 )
			);
		}
		check_admin_referer( 'cybermaps_content_audit', 'cybermaps_content_audit_nonce' );

		try {
			$run_id = ( new ContentAuditService() )->run( AuditPolicy::from_settings() );
		} catch ( \Throwable $error ) {
			if ( ContentAuditService::RUN_LOCKED_ERROR_CODE === $error->getCode() ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'                  => 'cybermaps-settings',
							'tab'                   => 'review',
							'cybermaps_report_busy' => '1',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: audit error */
						__( 'The content report could not be completed: %s', 'cybermaps' ),
						$error->getMessage()
					)
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'cybermaps-settings',
					'tab'              => 'review',
					'cybermaps_run_id' => $run_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'cybermaps' ) );
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_die(
				esc_html__( 'Invalid request method.', 'cybermaps' ),
				'',
				array( 'response' => 405 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The report-specific nonce is verified immediately below.
		$run_id = isset( $_POST['run_id'] ) && is_scalar( $_POST['run_id'] )
			? absint( wp_unslash( (string) $_POST['run_id'] ) )
			: 0;
		check_admin_referer( 'cybermaps_delete_content_audit_' . $run_id, 'cybermaps_delete_nonce' );
		if ( $run_id < 1 || ! ( new AuditRunRepository() )->delete_completed_run( $run_id ) ) {
			wp_die(
				esc_html__( 'This report could not be deleted. It may be missing or still required as the baseline for a later report.', 'cybermaps' ),
				'',
				array( 'response' => 409 )
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => 'cybermaps-settings',
					'tab'                      => 'review',
					'cybermaps_report_deleted' => $run_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_export(): void {
		check_admin_referer( 'cybermaps_content_audit' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'cybermaps' ) );
		}

		$request    = self::export_request();
		$repository = new AuditRunRepository();
		$run_id     = self::resolve_export_run_id( $repository, $request['run_id'] );
		$profile    = self::load_export_profile( $repository, $run_id );
		$this->enforce_export_bounds( $profile, $request['format'] );
		$run = self::load_export_run( $repository, $run_id, $request['format'] );
		if ( '' !== $request['filter'] ) {
			$run = $this->filter_run( $run, $request['filter'] );
		}

		$this->stream_export( $run, $run_id, $request['format'], $request['filter'] );
	}

	/**
	 * Read and validate the nonce-protected export query.
	 *
	 * @return array{run_id:int,format:string,filter:string}
	 */
	private static function export_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- handle_export() verifies the content-audit nonce before calling this helper.
		$run_id = isset( $_GET['run_id'] ) && is_scalar( $_GET['run_id'] ) ? absint( wp_unslash( (string) $_GET['run_id'] ) ) : 0;
		$format = isset( $_GET['format'] ) && is_scalar( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'html';
		$filter = isset( $_GET['finding'] ) && is_scalar( $_GET['finding'] ) ? sanitize_key( wp_unslash( (string) $_GET['finding'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $format, array( 'html', 'csv', 'json' ), true ) ) {
			wp_die( esc_html__( 'Invalid export format.', 'cybermaps' ), '', array( 'response' => 400 ) );
		}
		if ( '' !== $filter && ! array_key_exists( $filter, AuditExporter::finding_catalog() ) ) {
			wp_die( esc_html__( 'Invalid report filter.', 'cybermaps' ), '', array( 'response' => 400 ) );
		}

		return compact( 'run_id', 'format', 'filter' );
	}

	/**
	 * Resolve an omitted run to the latest completed report.
	 */
	private static function resolve_export_run_id( AuditRunRepository $repository, int $run_id ): int {
		try {
			return $run_id > 0 ? $run_id : $repository->latest_completed_run_id();
		} catch ( \Throwable ) {
			self::export_load_error();
		}
	}

	/**
	 * Load the lightweight size profile used before report hydration.
	 *
	 * @return array<string,mixed>
	 */
	private static function load_export_profile( AuditRunRepository $repository, int $run_id ): array {
		try {
			$profile = $run_id > 0 ? $repository->get_run_export_profile( $run_id ) : null;
		} catch ( \Throwable ) {
			self::export_load_error();
		}
		if ( null === $profile ) {
			self::export_not_found();
		}

		return $profile;
	}

	/**
	 * Reject synchronous exports whose bounded profile exceeds its format limit.
	 *
	 * @param array<string,mixed> $profile Run export profile.
	 */
	private function enforce_export_bounds( array $profile, string $format ): void {
		$current  = (int) $profile['finding_count'];
		$baseline = (int) $profile['baseline_finding_count'];
		$rows     = (int) $profile['resource_count'] + $current + $baseline;
		if ( 'csv' === $format && $current > self::MAX_CSV_EXPORT_FINDINGS ) {
			$this->export_too_large( __( 'This report contains too many findings for a safe synchronous CSV export.', 'cybermaps' ) );
		}
		if ( 'html' === $format && ( $current + $baseline ) > self::MAX_HTML_EXPORT_FINDING_ROWS ) {
			$this->export_too_large( __( 'This report and its comparison baseline are too large for a safe synchronous printable export. Use CSV for the current finding inventory.', 'cybermaps' ) );
		}
		if ( 'json' === $format && $rows > self::MAX_JSON_EXPORT_SNAPSHOT_ROWS ) {
			$this->export_too_large( __( 'This full report snapshot is too large for a safe synchronous JSON export. Use CSV for the current finding inventory.', 'cybermaps' ) );
		}
	}

	/**
	 * Hydrate the run shape required by the selected exporter.
	 *
	 * @return array<string,mixed>
	 */
	private static function load_export_run( AuditRunRepository $repository, int $run_id, string $format ): array {
		try {
			$run = 'csv' === $format
				? $repository->get_run( $run_id, false )
				: ( new ContentAuditService() )->get_run_with_diff( $run_id, 'json' === $format );
		} catch ( \Throwable ) {
			self::export_load_error();
		}
		if ( null === $run ) {
			self::export_not_found();
		}

		return $run;
	}

	/**
	 * Emit the selected attachment response.
	 *
	 * @param array<string,mixed> $run Hydrated run.
	 */
	private function stream_export( array $run, int $run_id, string $format, string $filter ): never {
		$exporter      = new AuditExporter();
		$filename_base = 'cybermaps-content-report-' . $run_id . ( '' !== $filter ? '-' . $filter : '' );
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		if ( 'csv' === $format ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename_base . '.csv"' );
			foreach ( $exporter->csv_chunks( $run ) as $chunk ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate, spreadsheet-safe CSV response.
				echo $chunk;
			}
		} elseif ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename_base . '.json"' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate JSON response.
			echo $exporter->json( $run );
		} else {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="' . $filename_base . '.html"' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exporter escapes every dynamic HTML field.
			echo $exporter->html( $run );
		}
		exit;
	}

	/**
	 * Terminate a failed report read without exposing database details.
	 */
	private static function export_load_error(): never {
		wp_die( esc_html__( 'The requested report could not be loaded from the database.', 'cybermaps' ), '', array( 'response' => 500 ) );
		exit;
	}

	/**
	 * Terminate a missing report request.
	 */
	private static function export_not_found(): never {
		wp_die( esc_html__( 'The requested report was not found.', 'cybermaps' ), '', array( 'response' => 404 ) );
		exit;
	}

	public function handle_discovery_export(): void {
		check_admin_referer( 'cybermaps_reports' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'cybermaps' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
		$format = isset( $_GET['format'] ) && is_scalar( $_GET['format'] )
			? sanitize_key( wp_unslash( (string) $_GET['format'] ) )
			: 'html';
		if ( ! in_array( $format, array( 'html', 'json' ), true ) ) {
			wp_die(
				esc_html__( 'Invalid export format.', 'cybermaps' ),
				'',
				array( 'response' => 400 )
			);
		}

		$status   = ( new DiscoveryStatus() )->get_status_data( true );
		$exporter = new DiscoveryReportExporter();
		$filename = 'cybermaps-discovery-report-' . current_time( 'Y-m-d-His' );
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '.json"' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate JSON response.
			echo $exporter->json( $status );
		} else {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="' . $filename . '.html"' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exporter escapes every dynamic HTML field.
			echo $exporter->html( $status );
		}
		exit;
	}

	/**
	 * @param array<string,mixed> $run Hydrated report run.
	 * @return array<string,mixed>
	 */
	private function filter_run( array $run, string $finding_key ): array {
		$matches = static fn( array $finding ): bool => (string) ( $finding['finding_key'] ?? $finding['key'] ?? '' ) === $finding_key;

		$run['findings']      = array_values( array_filter( (array) ( $run['findings'] ?? array() ), $matches ) );
		$run['finding_count'] = count( $run['findings'] );
		$run['report_filter'] = $finding_key;
		$diff                 = (array) ( $run['diff'] ?? array() );
		foreach ( array( 'added', 'resolved', 'persisting' ) as $bucket ) {
			$diff[ $bucket ] = array_values( array_filter( (array) ( $diff[ $bucket ] ?? array() ), $matches ) );
		}
		$run['diff'] = $diff;

		return $run;
	}

	private function export_too_large( string $message ): never {
		wp_die(
			esc_html( $message ),
			'',
			array( 'response' => 413 )
		);
		exit;
	}
}
