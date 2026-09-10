<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists completed audit snapshots in three normalized tables.
 */
final class AuditRunRepository {
	public const SCHEMA_VERSION                = '2';
	public const RUN_LOCK_OPTION               = 'cybermaps_content_audit_run_lock';
	private const SCHEMA_OPTION                = 'cybermaps_audit_schema_version';
	private const MAINTENANCE_LOCK_TTL_SECONDS = 5 * MINUTE_IN_SECONDS;

	/** @var array<int,true> Runs opened by this repository instance. */
	private array $running_runs = array();

	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			self::create_tables();
		}
	}

	public static function create_tables(): void {
		global $wpdb;

		$charset   = $wpdb->get_charset_collate();
		$runs      = $wpdb->prefix . 'cybermaps_audit_runs';
		$resources = $wpdb->prefix . 'cybermaps_audit_resources';
		$findings  = $wpdb->prefix . 'cybermaps_audit_findings';

		$sql_runs = "CREATE TABLE $runs (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
run_uuid char(36) NOT NULL,
created_gmt datetime NOT NULL,
completed_gmt datetime DEFAULT NULL,
status varchar(20) NOT NULL,
policy_json longtext NOT NULL,
policy_hash char(64) NOT NULL,
baseline_run_id bigint(20) unsigned DEFAULT NULL,
resource_count bigint(20) unsigned DEFAULT 0 NOT NULL,
finding_count bigint(20) unsigned DEFAULT 0 NOT NULL,
snapshot_hash char(64) DEFAULT '' NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY run_uuid (run_uuid),
KEY completed_gmt (completed_gmt),
KEY baseline_run_id (baseline_run_id),
KEY status (status)
) $charset;";

		$sql_resources = "CREATE TABLE $resources (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
run_id bigint(20) unsigned NOT NULL,
resource_key varchar(191) NOT NULL,
object_type varchar(32) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
post_type varchar(32) NOT NULL,
title text NOT NULL,
url text NOT NULL,
modified_gmt datetime DEFAULT NULL,
word_count int(10) unsigned DEFAULT 0 NOT NULL,
age_days int(10) unsigned DEFAULT NULL,
has_media tinyint(1) unsigned DEFAULT 0 NOT NULL,
indexable tinyint(1) unsigned DEFAULT 0 NOT NULL,
indexability_json longtext NOT NULL,
measurement_json longtext NOT NULL,
content_hash char(64) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY run_resource (run_id,resource_key),
KEY run_id (run_id),
KEY object_lookup (object_type,object_id),
KEY post_type (post_type)
) $charset;";

		$sql_findings = "CREATE TABLE $findings (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
run_id bigint(20) unsigned NOT NULL,
resource_id bigint(20) unsigned NOT NULL,
finding_key varchar(64) NOT NULL,
severity varchar(20) NOT NULL,
summary text NOT NULL,
evidence_json longtext NOT NULL,
recommendation text NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY resource_finding (run_id,resource_id,finding_key),
KEY run_id (run_id),
KEY finding_key (finding_key),
KEY severity (severity)
) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$schema_failed = false;
		foreach ( array( $sql_runs, $sql_resources, $sql_findings ) as $statement ) {
			$wpdb->last_error = '';
			dbDelta( $statement );
			if ( ! empty( $wpdb->last_error ) ) {
				$schema_failed = true;
			}
		}
		if ( ! $schema_failed ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		}
	}

	public function latest_completed_run_id(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_audit_runs';
		$this->reset_database_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Reports must reflect the latest persisted completed run.
		$run_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE status = 'complete' ORDER BY id DESC LIMIT 1",
				$table
			)
		);
		$this->assert_database_read_succeeded();
		return $run_id;
	}

	/**
	 * Acquire the single site-local report-generation lease.
	 *
	 * add_option() provides the normal atomic insert. An expired or malformed
	 * lease is replaced with an exact-value compare-and-swap so one contender
	 * cannot delete or overwrite a newer owner's lease.
	 */
	public function acquire_run_lock( int $ttl_seconds ): ?string {
		global $wpdb;

		$ttl_seconds = max( MINUTE_IN_SECONDS, min( HOUR_IN_SECONDS, $ttl_seconds ) );
		$token       = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : $this->fallback_uuid();
		$replacement = $this->encode_run_lock( $token, time() + $ttl_seconds );

		$this->reset_database_error();
		if ( add_option( self::RUN_LOCK_OPTION, $replacement, '', false ) ) {
			return $token;
		}
		$this->assert_database_write_succeeded();

		$observed = $this->read_run_lock_raw();
		if ( null === $observed ) {
			$this->reset_database_error();
			if ( add_option( self::RUN_LOCK_OPTION, $replacement, '', false ) ) {
				return $token;
			}
			$this->assert_database_write_succeeded();
			return null;
		}

		$lease = $this->decode_run_lock( $observed );
		if ( (int) ( $lease['expires'] ?? 0 ) > time() ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Exact-value CAS is required for ownership-safe expiry takeover.
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $replacement ),
			array(
				'option_name'  => self::RUN_LOCK_OPTION,
				'option_value' => $observed,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( false === $updated ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not acquire the content report lock.', 'cybermaps' ) );
		}
		if ( 1 !== $updated ) {
			return null;
		}

		$this->invalidate_run_lock_cache();
		return $token;
	}

	/**
	 * Extend a lease only while its exact owner still holds it.
	 */
	public function refresh_run_lock( string $token, int $ttl_seconds ): bool {
		if ( '' === $token ) {
			return false;
		}

		global $wpdb;
		$observed = $this->read_run_lock_raw();
		if ( null === $observed ) {
			return false;
		}
		$lease = $this->decode_run_lock( $observed );
		if ( ! hash_equals( (string) ( $lease['token'] ?? '' ), $token ) ) {
			return false;
		}

		$ttl_seconds = max( MINUTE_IN_SECONDS, min( HOUR_IN_SECONDS, $ttl_seconds ) );
		$expires     = max(
			(int) ( $lease['expires'] ?? 0 ),
			time() + $ttl_seconds
		);
		$replacement = $this->encode_run_lock( $token, $expires );
		if ( $replacement === $observed ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Exact-value CAS prevents an expired former owner from refreshing a successor's lease.
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $replacement ),
			array(
				'option_name'  => self::RUN_LOCK_OPTION,
				'option_value' => $observed,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( false === $updated ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not renew the content report lock.', 'cybermaps' ) );
		}
		if ( 1 !== $updated ) {
			return false;
		}

		$this->invalidate_run_lock_cache();
		return true;
	}

	/**
	 * Release a lease without ever deleting a successor's value.
	 *
	 * Release is deliberately best-effort so a transient database problem in
	 * this final cleanup step cannot turn an otherwise completed report into an
	 * apparent failure. The lease remains self-expiring.
	 */
	public function release_run_lock( string $token ): void {
		if ( '' === $token ) {
			return;
		}

		try {
			global $wpdb;
			$observed = $this->read_run_lock_raw();
			if ( null === $observed ) {
				return;
			}
			$lease = $this->decode_run_lock( $observed );
			if ( ! hash_equals( (string) ( $lease['token'] ?? '' ), $token ) ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Exact token-bearing value makes release ownership-safe.
			$deleted = $wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => self::RUN_LOCK_OPTION,
					'option_value' => $observed,
				),
				array( '%s', '%s' )
			);
			if ( 1 === $deleted ) {
				$this->invalidate_run_lock_cache();
			}
		} catch ( \Throwable $error ) {
			unset( $error );
			// The bounded lease is the recovery path after a release failure.
		}
	}

	/**
	 * Return small row-count metadata before an exact export is hydrated.
	 *
	 * @return array{resource_count:int,finding_count:int,baseline_run_id:int,baseline_finding_count:int}|null
	 */
	public function get_run_export_profile( int $run_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_audit_runs';
		$this->reset_database_error();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Export sizing must read the exact persisted report metadata.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT report.resource_count, report.finding_count, report.baseline_run_id,
					COALESCE(baseline.finding_count, 0) AS baseline_finding_count
				FROM %i report
				LEFT JOIN %i baseline
					ON baseline.id = report.baseline_run_id
					AND baseline.status = 'complete'
				WHERE report.id = %d
				AND report.status = 'complete'",
				$table,
				$table,
				$run_id
			),
			ARRAY_A
		);
		// phpcs:enable
		$this->assert_database_read_succeeded();
		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'resource_count'         => max( 0, (int) ( $row['resource_count'] ?? 0 ) ),
			'finding_count'          => max( 0, (int) ( $row['finding_count'] ?? 0 ) ),
			'baseline_run_id'        => max( 0, (int) ( $row['baseline_run_id'] ?? 0 ) ),
			'baseline_finding_count' => max( 0, (int) ( $row['baseline_finding_count'] ?? 0 ) ),
		);
	}

	/**
	 * Return bounded report-run metadata without hydrating every resource.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function completed_runs( int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_audit_runs';
		$limit = max( 1, min( 50, $limit ) );
		$this->reset_database_error();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The reports page needs exact bounded metadata for completed runs.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT report.id, report.completed_gmt, report.baseline_run_id,
					report.resource_count, report.finding_count, report.snapshot_hash,
					EXISTS (
						SELECT 1 FROM %i dependent
						WHERE dependent.baseline_run_id = report.id
						AND dependent.status IN ('running', 'complete')
					) AS is_baseline
				FROM %i report
				WHERE report.status = 'complete'
				ORDER BY report.id DESC
				LIMIT %d",
				$table,
				$table,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		$this->assert_database_read_succeeded();
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove failed runs and abandoned runs older than the supplied threshold.
	 *
	 * Completed client reports are deliberately excluded. The multi-table
	 * statement removes an incomplete run and its child rows atomically.
	 */
	public function cleanup_incomplete_runs(
		int $stale_after_seconds = DAY_IN_SECONDS,
		int $run_limit = 25,
		string $owned_lock_token = ''
	): void {
		$lock_token   = $owned_lock_token;
		$release_lock = false;
		if ( '' === $lock_token ) {
			$lock_token = (string) ( $this->acquire_run_lock( self::MAINTENANCE_LOCK_TTL_SECONDS ) ?? '' );
			if ( '' === $lock_token ) {
				return;
			}
			$release_lock = true;
		} elseif ( ! $this->refresh_run_lock( $lock_token, self::MAINTENANCE_LOCK_TTL_SECONDS ) ) {
			throw new \RuntimeException(
				esc_html__( 'Cybermaps no longer owns the content report lock required for cleanup.', 'cybermaps' )
			);
		}

		try {
			$this->cleanup_incomplete_runs_unlocked( $stale_after_seconds, $run_limit );
		} finally {
			if ( $release_lock ) {
				$this->release_run_lock( $lock_token );
			}
		}
	}

	/**
	 * Remove one bounded batch while the caller owns the report mutation lease.
	 */
	private function cleanup_incomplete_runs_unlocked(
		int $stale_after_seconds,
		int $run_limit
	): void {
		global $wpdb;
		$runs      = $wpdb->prefix . 'cybermaps_audit_runs';
		$resources = $wpdb->prefix . 'cybermaps_audit_resources';
		$findings  = $wpdb->prefix . 'cybermaps_audit_findings';
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - max( HOUR_IN_SECONDS, $stale_after_seconds ) );
		$run_limit = max( 1, min( 100, $run_limit ) );

		if ( ! $this->tables_exist( array( $runs, $resources, $findings ) ) ) {
			return;
		}

		// Select a small run batch first because MySQL does not support LIMIT on
		// a multi-table DELETE. The final DELETE repeats the status predicate so
		// a run completed between the SELECT and DELETE is retained.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Cleanup must inspect exact persisted run state while holding the mutation lease.
		$this->reset_database_error();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id
				FROM %i
				WHERE status = 'failed'
				OR (status = 'running' AND created_gmt < %s)
				ORDER BY id ASC
				LIMIT %d",
				$runs,
				$cutoff,
				$run_limit
			),
			ARRAY_A
		);
		// phpcs:enable
		$this->assert_database_read_succeeded();
		if ( ! is_array( $rows ) ) {
			throw new \RuntimeException(
				esc_html__( 'Cybermaps could not read incomplete content reports for cleanup.', 'cybermaps' )
			);
		}
		$run_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( array $row ): int => max( 0, (int) ( $row['id'] ?? 0 ) ),
						$rows
					)
				)
			)
		);
		if ( empty( $run_ids ) ) {
			return;
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $run_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The bounded multi-table delete is atomic and rechecks incomplete status at mutation time.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The variadic list supplies three identifiers, one integer placeholder per bounded run ID, and the cutoff.
		$query = $wpdb->prepare(
			"DELETE finding, resource, report
			FROM %i report
			LEFT JOIN %i resource ON resource.run_id = report.id
			LEFT JOIN %i finding
				ON finding.resource_id = resource.id
				AND finding.run_id = report.id
			WHERE report.id IN ({$id_placeholders})
			AND (
				report.status = 'failed'
				OR (report.status = 'running' AND report.created_gmt < %s)
			)",
			...array_merge( array( $runs, $resources, $findings ), $run_ids, array( $cutoff ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$this->reset_database_error();
		$deleted = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The complete bounded statement is prepared immediately above.
		// phpcs:enable
		if ( false === $deleted || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException(
				esc_html__( 'Cybermaps could not remove incomplete content reports.', 'cybermaps' )
			);
		}
	}

	/**
	 * Avoid noisy cleanup queries before activation or after manual table loss.
	 *
	 * @param string[] $tables Required table names.
	 */
	private function tables_exist( array $tables ): bool {
		global $wpdb;
		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'get_var' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			return false;
		}

		foreach ( $tables as $table ) {
			$pattern = method_exists( $wpdb, 'esc_like' )
				? $wpdb->esc_like( $table )
				: addcslashes( $table, '_%\\' );
			$this->reset_database_error();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern )
			);
			$this->assert_database_read_succeeded();
			if ( $table !== (string) $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete one completed report only when no later completed report uses it
	 * as a comparison baseline.
	 */
	public function delete_completed_run( int $run_id ): bool {
		if ( $run_id < 1 ) {
			return false;
		}

		$lock_token = $this->acquire_run_lock( self::MAINTENANCE_LOCK_TTL_SECONDS );
		if ( null === $lock_token ) {
			return false;
		}

		try {
			global $wpdb;
			$runs      = $wpdb->prefix . 'cybermaps_audit_runs';
			$resources = $wpdb->prefix . 'cybermaps_audit_resources';
			$findings  = $wpdb->prefix . 'cybermaps_audit_findings';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The atomic multi-table delete enforces baseline ownership at mutation time.
			$query   = $wpdb->prepare(
				"DELETE finding, resource, report
				FROM %i report
				LEFT JOIN %i resource ON resource.run_id = report.id
				LEFT JOIN %i finding
					ON finding.resource_id = resource.id
					AND finding.run_id = report.id
				LEFT JOIN %i dependent
					ON dependent.baseline_run_id = report.id
					AND dependent.status IN ('running', 'complete')
				WHERE report.id = %d
				AND report.status = 'complete'
				AND dependent.id IS NULL",
				$runs,
				$resources,
				$findings,
				$runs,
				$run_id
			);
			$deleted = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The complete bounded statement is prepared immediately above.
			// phpcs:enable
			return false !== $deleted && $deleted > 0;
		} finally {
			$this->release_run_lock( $lock_token );
		}
	}

	public function begin( AuditPolicy $policy, int $baseline_run_id = 0 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_audit_runs';
		$uuid  = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : $this->fallback_uuid();
		$data  = array(
			'run_uuid'        => $uuid,
			'created_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			'status'          => 'running',
			'policy_json'     => (string) wp_json_encode( $policy->to_array() ),
			'policy_hash'     => $policy->hash(),
			'baseline_run_id' => $baseline_run_id > 0 ? $baseline_run_id : null,
			'resource_count'  => 0,
			'finding_count'   => 0,
			'snapshot_hash'   => '',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching
		$inserted = $wpdb->insert( $table, $data );
		if ( false === $inserted ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not create the audit run.', 'cybermaps' ) );
		}

		$run_id                        = (int) $wpdb->insert_id;
		$this->running_runs[ $run_id ] = true;
		return $run_id;
	}

	/**
	 * @param array<string,mixed> $resource Resource snapshot.
	 * @param array<int,array<string,mixed>> $findings Findings for the snapshot.
	 */
	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- Preserve the established public named-argument contract.
	public function add_resource( int $run_id, array $resource, array $findings ): void {
		// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound
		if ( ! isset( $this->running_runs[ $run_id ] ) ) {
			$this->assert_running( $run_id );
			$this->running_runs[ $run_id ] = true;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_audit_resources';
		$data  = array(
			'run_id'            => $run_id,
			'resource_key'      => (string) $resource['resource_key'],
			'object_type'       => (string) $resource['object_type'],
			'object_id'         => (int) $resource['object_id'],
			'post_type'         => (string) $resource['post_type'],
			'title'             => (string) $resource['title'],
			'url'               => (string) $resource['url'],
			'modified_gmt'      => '' !== (string) $resource['modified_gmt'] ? (string) $resource['modified_gmt'] : null,
			'word_count'        => (int) $resource['word_count'],
			'age_days'          => null === $resource['age_days'] ? null : (int) $resource['age_days'],
			'has_media'         => ! empty( $resource['has_media'] ) ? 1 : 0,
			'indexable'         => ! empty( $resource['indexable'] ) ? 1 : 0,
			'indexability_json' => (string) wp_json_encode( $resource['indexability'] ),
			'measurement_json'  => (string) wp_json_encode( $resource['measurement'] ),
			'content_hash'      => (string) $resource['content_hash'],
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching
		if ( false === $wpdb->insert( $table, $data ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not store an audit resource.', 'cybermaps' ) );
		}
		$resource_id = (int) $wpdb->insert_id;

		foreach ( $findings as $finding ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'cybermaps_audit_findings',
				array(
					'run_id'         => $run_id,
					'resource_id'    => $resource_id,
					'finding_key'    => (string) $finding['key'],
					'severity'       => (string) $finding['severity'],
					'summary'        => (string) $finding['summary'],
					'evidence_json'  => (string) wp_json_encode( $finding['evidence'] ),
					'recommendation' => (string) $finding['recommendation'],
				)
			);
			if ( false === $inserted ) {
				throw new \RuntimeException( esc_html__( 'Cybermaps could not store an audit finding.', 'cybermaps' ) );
			}
		}
	}

	public function complete( int $run_id, int $resource_count, int $finding_count, string $snapshot_hash ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_audit_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'completed_gmt'  => gmdate( 'Y-m-d H:i:s' ),
				'status'         => 'complete',
				'resource_count' => $resource_count,
				'finding_count'  => $finding_count,
				'snapshot_hash'  => $snapshot_hash,
			),
			array(
				'id'     => $run_id,
				'status' => 'running',
			)
		);
		if ( 1 !== $updated ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not finalize the content report.', 'cybermaps' ) );
		}
		unset( $this->running_runs[ $run_id ] );
	}

	/**
	 * Close an incomplete run so a storage/evaluation error cannot leave a
	 * record that appears to be perpetually running.
	 */
	public function fail( int $run_id ): void {
		unset( $this->running_runs[ $run_id ] );
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_audit_runs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching
		$wpdb->update(
			$table,
			array(
				'completed_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'status'        => 'failed',
			),
			array(
				'id'     => $run_id,
				'status' => 'running',
			)
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_run( int $run_id, bool $include_resources = true ): ?array {
		global $wpdb;
		$runs      = $wpdb->prefix . 'cybermaps_audit_runs';
		$resources = $wpdb->prefix . 'cybermaps_audit_resources';
		$findings  = $wpdb->prefix . 'cybermaps_audit_findings';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- A report export must hydrate its exact immutable persisted snapshot.
		$this->reset_database_error();
		$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND status = 'complete'", $runs, $run_id ), ARRAY_A );
		$this->assert_database_read_succeeded();
		if ( ! is_array( $run ) ) {
			return null;
		}
		$resource_rows = array();
		if ( $include_resources ) {
			$this->reset_database_error();
			$resource_rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY post_type, object_id', $resources, $run_id ),
				ARRAY_A
			);
			$this->assert_database_read_succeeded();
		}
		$this->reset_database_error();
		$finding_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.*, r.resource_key, r.title, r.url, r.post_type, r.object_id
				FROM %i f
				INNER JOIN %i r ON r.id = f.resource_id
				WHERE f.run_id = %d
				ORDER BY f.finding_key, r.post_type, r.object_id',
				$findings,
				$resources,
				$run_id
			),
			ARRAY_A
		);
		$this->assert_database_read_succeeded();
		// phpcs:enable

		$run['policy']   = self::decode_json_array( (string) $run['policy_json'] );
		$run['findings'] = array_map( array( $this, 'hydrate_finding' ), is_array( $finding_rows ) ? $finding_rows : array() );
		if ( $include_resources ) {
			$run['resources'] = array_map( array( $this, 'hydrate_resource' ), is_array( $resource_rows ) ? $resource_rows : array() );
		}

		return $run;
	}

	/**
	 * Return a bounded Reports-page projection without loading resource rows.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_run_for_display( int $run_id, int $preview_limit = 25 ): ?array {
		global $wpdb;
		$runs      = $wpdb->prefix . 'cybermaps_audit_runs';
		$resources = $wpdb->prefix . 'cybermaps_audit_resources';
		$findings  = $wpdb->prefix . 'cybermaps_audit_findings';
		$limit     = max( 1, min( 100, $preview_limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The reports screen needs an exact bounded projection of persisted report evidence.
		$this->reset_database_error();
		$run = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT report.*,
					EXISTS (
						SELECT 1 FROM %i dependent
						WHERE dependent.baseline_run_id = report.id
						AND dependent.status IN ('running', 'complete')
					) AS is_baseline
				FROM %i report
				WHERE report.id = %d
				AND report.status = 'complete'",
				$runs,
				$runs,
				$run_id
			),
			ARRAY_A
		);
		$this->assert_database_read_succeeded();
		if ( ! is_array( $run ) ) {
			return null;
		}

		$this->reset_database_error();
		$finding_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT finding.*, resource.resource_key, resource.title, resource.url,
					resource.post_type, resource.object_id
				FROM %i finding
				INNER JOIN %i resource ON resource.id = finding.resource_id
				WHERE finding.run_id = %d
				ORDER BY finding.finding_key, resource.post_type, resource.object_id
				LIMIT %d',
				$findings,
				$resources,
				$run_id,
				$limit
			),
			ARRAY_A
		);
		$this->assert_database_read_succeeded();
		$this->reset_database_error();
		$count_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT finding_key, COUNT(*) AS finding_count
				FROM %i
				WHERE run_id = %d
				GROUP BY finding_key',
				$findings,
				$run_id
			),
			ARRAY_A
		);
		$this->assert_database_read_succeeded();
		// phpcs:enable

		$finding_counts = array();
		foreach ( is_array( $count_rows ) ? $count_rows : array() as $row ) {
			$key = sanitize_key( (string) ( $row['finding_key'] ?? '' ) );
			if ( '' !== $key ) {
				$finding_counts[ $key ] = max( 0, (int) ( $row['finding_count'] ?? 0 ) );
			}
		}

		$run['policy']         = self::decode_json_array( (string) $run['policy_json'] );
		$run['findings']       = array_map( array( $this, 'hydrate_finding' ), is_array( $finding_rows ) ? $finding_rows : array() );
		$run['finding_counts'] = $finding_counts;

		return $run;
	}

	/**
	 * Count exact finding-identity changes without hydrating either report.
	 *
	 * @return array{baseline_run_id:int,added_count:int,resolved_count:int,persisting_count:int}
	 */
	public function finding_diff_counts( int $run_id, int $baseline_run_id, int $current_finding_count ): array {
		global $wpdb;
		$runs      = $wpdb->prefix . 'cybermaps_audit_runs';
		$resources = $wpdb->prefix . 'cybermaps_audit_resources';
		$findings  = $wpdb->prefix . 'cybermaps_audit_findings';

		if ( $baseline_run_id < 1 ) {
			return array(
				'baseline_run_id'  => 0,
				'added_count'      => max( 0, $current_finding_count ),
				'resolved_count'   => 0,
				'persisting_count' => 0,
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Comparison counts must reflect the exact immutable report snapshots.
		$this->reset_database_error();
		$baseline_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE id = %d AND status = 'complete'",
				$runs,
				$baseline_run_id
			)
		);
		$this->assert_database_read_succeeded();
		if ( $baseline_exists < 1 ) {
			return array(
				'baseline_run_id'  => 0,
				'added_count'      => max( 0, $current_finding_count ),
				'resolved_count'   => 0,
				'persisting_count' => 0,
			);
		}

		$added_query      = $wpdb->prepare(
			'SELECT COUNT(*)
			FROM %i current_finding
			INNER JOIN %i current_resource
				ON current_resource.id = current_finding.resource_id
				AND current_resource.run_id = current_finding.run_id
			LEFT JOIN %i baseline_resource
				ON baseline_resource.run_id = %d
				AND baseline_resource.resource_key = current_resource.resource_key
			LEFT JOIN %i baseline_finding
				ON baseline_finding.run_id = %d
				AND baseline_finding.resource_id = baseline_resource.id
				AND baseline_finding.finding_key = current_finding.finding_key
			WHERE current_finding.run_id = %d
			AND baseline_finding.id IS NULL',
			$findings,
			$resources,
			$resources,
			$baseline_run_id,
			$findings,
			$baseline_run_id,
			$run_id
		);
		$resolved_query   = $wpdb->prepare(
			'SELECT COUNT(*)
			FROM %i baseline_finding
			INNER JOIN %i baseline_resource
				ON baseline_resource.id = baseline_finding.resource_id
				AND baseline_resource.run_id = baseline_finding.run_id
			LEFT JOIN %i current_resource
				ON current_resource.run_id = %d
				AND current_resource.resource_key = baseline_resource.resource_key
			LEFT JOIN %i current_finding
				ON current_finding.run_id = %d
				AND current_finding.resource_id = current_resource.id
				AND current_finding.finding_key = baseline_finding.finding_key
			WHERE baseline_finding.run_id = %d
			AND current_finding.id IS NULL',
			$findings,
			$resources,
			$resources,
			$run_id,
			$findings,
			$run_id,
			$baseline_run_id
		);
		$persisting_query = $wpdb->prepare(
			'SELECT COUNT(*)
			FROM %i current_finding
			INNER JOIN %i current_resource
				ON current_resource.id = current_finding.resource_id
				AND current_resource.run_id = current_finding.run_id
			INNER JOIN %i baseline_resource
				ON baseline_resource.run_id = %d
				AND baseline_resource.resource_key = current_resource.resource_key
			INNER JOIN %i baseline_finding
				ON baseline_finding.run_id = %d
				AND baseline_finding.resource_id = baseline_resource.id
				AND baseline_finding.finding_key = current_finding.finding_key
			WHERE current_finding.run_id = %d',
			$findings,
			$resources,
			$resources,
			$baseline_run_id,
			$findings,
			$baseline_run_id,
			$run_id
		);

		$this->reset_database_error();
		$added = (int) $wpdb->get_var( $added_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The complete identifier-and-value statement is prepared above.
		$this->assert_database_read_succeeded();
		$this->reset_database_error();
		$resolved = (int) $wpdb->get_var( $resolved_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The complete identifier-and-value statement is prepared above.
		$this->assert_database_read_succeeded();
		$this->reset_database_error();
		$persisting = (int) $wpdb->get_var( $persisting_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The complete identifier-and-value statement is prepared above.
		$this->assert_database_read_succeeded();
		// phpcs:enable

		return array(
			'baseline_run_id'  => $baseline_run_id,
			'added_count'      => max( 0, $added ),
			'resolved_count'   => max( 0, $resolved ),
			'persisting_count' => max( 0, $persisting ),
		);
	}

	private function assert_running( int $run_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_audit_runs';
		$this->reset_database_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Mutation validation must observe the exact persisted run state.
		$status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $table, $run_id ) );
		$this->assert_database_read_succeeded();
		if ( 'running' !== $status ) {
			throw new \LogicException( esc_html__( 'Only a running Cybermaps content report can be modified.', 'cybermaps' ) );
		}
	}

	private function reset_database_error(): void {
		global $wpdb;
		$wpdb->last_error = '';
	}

	private function assert_database_read_succeeded(): void {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not read the saved content report data.', 'cybermaps' ) );
		}
	}

	private function assert_database_write_succeeded(): void {
		global $wpdb;
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not update the content report lock.', 'cybermaps' ) );
		}
	}

	private function read_run_lock_raw(): ?string {
		global $wpdb;
		$this->reset_database_error();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Lock coordination must observe the database value rather than an object-cache copy.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
				$wpdb->options,
				self::RUN_LOCK_OPTION
			)
		);
		$this->assert_database_read_succeeded();
		return null === $value ? null : (string) $value;
	}

	private function encode_run_lock( string $token, int $expires ): string {
		$encoded = wp_json_encode(
			array(
				'token'   => $token,
				'expires' => $expires,
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not encode the content report lock.', 'cybermaps' ) );
		}
		return $encoded;
	}

	/**
	 * @return array{token?:mixed,expires?:mixed}
	 */
	private function decode_run_lock( string $value ): array {
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function invalidate_run_lock_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::RUN_LOCK_OPTION, 'options' );
		}
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function hydrate_resource( array $row ): array {
		$row['indexability'] = self::decode_json_array( (string) $row['indexability_json'] );
		$row['measurement']  = self::decode_json_array( (string) $row['measurement_json'] );
		return $row;
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function hydrate_finding( array $row ): array {
		$row['evidence'] = self::decode_json_array( (string) $row['evidence_json'] );
		return $row;
	}

	/**
	 * Decode one persisted JSON object without relying on short-ternary truthiness.
	 *
	 * @return array<string,mixed>
	 */
	private static function decode_json_array( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function fallback_uuid(): string {
		$hex = bin2hex( random_bytes( 16 ) );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 )
			. '-a' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
	}
}
