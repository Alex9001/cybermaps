<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Audit;

use Cybermaps\Audit\AuditRunRepository;
use Cybermaps\Audit\ContentAuditService;
use PHPUnit\Framework\TestCase;

final class AuditRunRepositoryTest extends TestCase {
	private mixed $previous_wpdb;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new AuditRunRepositoryWpdbStub();
		delete_option( AuditRunRepository::RUN_LOCK_OPTION );
		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );
	}

	protected function tearDown(): void {
		delete_option( AuditRunRepository::RUN_LOCK_OPTION );
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );
	}

	public function test_display_projection_is_bounded_and_does_not_hydrate_resources(): void {
		$run = ( new ContentAuditService( new AuditRunRepository() ) )->get_run_for_display( 8, 25 );

		$this->assertIsArray( $run );
		$this->assertArrayNotHasKey( 'resources', $run );
		$this->assertCount( 2, $run['findings'] );
		$this->assertSame( array( 'actual_words' => 100 ), $run['findings'][0]['evidence'] );
		$this->assertSame( array( 'thin_content' => 12, 'stale_content' => 5 ), $run['finding_counts'] );
		$this->assertSame(
			array(
				'baseline_run_id' => 7,
				'added_count'     => 3,
				'resolved_count'  => 2,
				'persisting_count' => 4,
			),
			$run['diff']
		);

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'LIMIT 25', $sql );
		$this->assertStringNotContainsString( 'SELECT * FROM wp_cybermaps_audit_resources', $sql );
	}

	public function test_full_run_path_still_hydrates_complete_export_resources(): void {
		$run = ( new AuditRunRepository() )->get_run( 8 );

		$this->assertIsArray( $run );
		$this->assertCount( 1, $run['resources'] );
		$this->assertSame( array( 'words' => 100 ), $run['resources'][0]['measurement'] );
		$this->assertCount( 2, $run['findings'] );

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'SELECT * FROM wp_cybermaps_audit_resources', $sql );
	}

	public function test_finding_only_export_projection_skips_resource_hydration(): void {
		$run = ( new AuditRunRepository() )->get_run( 8, false );

		$this->assertIsArray( $run );
		$this->assertArrayNotHasKey( 'resources', $run );
		$this->assertCount( 2, $run['findings'] );

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertStringNotContainsString( 'SELECT * FROM wp_cybermaps_audit_resources', $sql );
		$this->assertStringContainsString( 'cybermaps_audit_findings', $sql );
	}

	public function test_export_profile_returns_counts_without_hydrating_report_rows(): void {
		$profile = ( new AuditRunRepository() )->get_run_export_profile( 8 );

		$this->assertSame(
			array(
				'resource_count'         => 40,
				'finding_count'          => 17,
				'baseline_run_id'        => 7,
				'baseline_finding_count' => 14,
			),
			$profile
		);
		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'COALESCE(baseline.finding_count, 0)', $sql );
		$this->assertStringNotContainsString( 'cybermaps_audit_resources', $sql );
		$this->assertStringNotContainsString( 'cybermaps_audit_findings', $sql );
	}

	public function test_run_lock_rejects_a_second_owner_and_releases_only_for_its_owner(): void {
		$repository = new AuditRunRepository();
		$owner      = $repository->acquire_run_lock( 10 * MINUTE_IN_SECONDS );

		$this->assertIsString( $owner );
		$this->assertNotSame( '', $owner );
		$this->assertNull( ( new AuditRunRepository() )->acquire_run_lock( 10 * MINUTE_IN_SECONDS ) );

		$repository->release_run_lock( 'not-the-owner' );
		$this->assertIsString( get_option( AuditRunRepository::RUN_LOCK_OPTION, false ) );

		$repository->release_run_lock( $owner );
		$this->assertFalse( get_option( AuditRunRepository::RUN_LOCK_OPTION, false ) );
	}

	public function test_expired_run_lock_is_replaced_without_allowing_the_old_owner_to_delete_it(): void {
		$old_owner = 'old-owner';
		add_option(
			AuditRunRepository::RUN_LOCK_OPTION,
			(string) wp_json_encode(
				array(
					'token'   => $old_owner,
					'expires' => time() - 1,
				)
			),
			'',
			false
		);

		$repository = new AuditRunRepository();
		$new_owner  = $repository->acquire_run_lock( 10 * MINUTE_IN_SECONDS );
		$this->assertIsString( $new_owner );
		$this->assertNotSame( $old_owner, $new_owner );

		$repository->release_run_lock( $old_owner );
		$stored = json_decode( (string) get_option( AuditRunRepository::RUN_LOCK_OPTION ), true );
		$this->assertSame( $new_owner, $stored['token'] ?? null );
		$this->assertTrue( $repository->refresh_run_lock( $new_owner, 10 * MINUTE_IN_SECONDS ) );

		$repository->release_run_lock( $new_owner );
		$this->assertFalse( get_option( AuditRunRepository::RUN_LOCK_OPTION, false ) );
	}

	public function test_refresh_never_shortens_an_owned_run_lease(): void {
		$repository = new AuditRunRepository();
		$owner      = $repository->acquire_run_lock( 10 * MINUTE_IN_SECONDS );
		$this->assertIsString( $owner );
		$before = json_decode( (string) get_option( AuditRunRepository::RUN_LOCK_OPTION ), true );

		$this->assertTrue( $repository->refresh_run_lock( $owner, MINUTE_IN_SECONDS ) );

		$after = json_decode( (string) get_option( AuditRunRepository::RUN_LOCK_OPTION ), true );
		$this->assertGreaterThanOrEqual( (int) ( $before['expires'] ?? 0 ), (int) ( $after['expires'] ?? 0 ) );
		$repository->release_run_lock( $owner );
	}

	public function test_expired_lock_takeover_cannot_overwrite_a_competing_new_owner(): void {
		add_option(
			AuditRunRepository::RUN_LOCK_OPTION,
			(string) wp_json_encode(
				array(
					'token'   => 'expired-owner',
					'expires' => time() - 1,
				)
			),
			'',
			false
		);
		$competitor = (string) wp_json_encode(
			array(
				'token'   => 'competing-owner',
				'expires' => time() + 600,
			)
		);
		$GLOBALS['wpdb']->replace_lock_before_next_update = $competitor;

		$this->assertNull( ( new AuditRunRepository() )->acquire_run_lock( 10 * MINUTE_IN_SECONDS ) );
		$this->assertSame( $competitor, get_option( AuditRunRepository::RUN_LOCK_OPTION ) );
	}

	public function test_report_read_errors_do_not_look_like_a_missing_report(): void {
		$GLOBALS['wpdb']->fail_next_get_row = true;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'could not read the saved content report data' );
		( new AuditRunRepository() )->get_run( 8 );
	}

	public function test_cleanup_targets_only_failed_or_abandoned_incomplete_runs(): void {
		( new AuditRunRepository() )->cleanup_incomplete_runs( DAY_IN_SECONDS );

		$query = $this->last_report_delete_query();
		$this->assertIsString( $query );
		$this->assertStringContainsString( 'report.id IN (31,32)', $query );
		$this->assertStringContainsString( "report.status = 'failed'", $query );
		$this->assertStringContainsString( "report.status = 'running'", $query );
		$this->assertStringNotContainsString( "report.status = 'complete'", $query );
		$this->assertStringContainsString( 'report.created_gmt <', $query );
		$this->assertStringContainsString( 'finding.resource_id = resource.id', $query );
		$this->assertStringContainsString( 'finding.run_id = report.id', $query );
	}

	public function test_cleanup_is_a_noop_when_an_audit_table_is_missing(): void {
		$GLOBALS['wpdb']->missing_table = 'wp_cybermaps_audit_resources';

		( new AuditRunRepository() )->cleanup_incomplete_runs();

		$this->assertNotEmpty( $GLOBALS['wpdb']->queries );
		$this->assertStringNotContainsString(
			'DELETE finding, resource, report',
			implode( "\n", $GLOBALS['wpdb']->queries )
		);
	}

	public function test_cleanup_read_failure_is_not_treated_as_an_empty_batch(): void {
		$GLOBALS['wpdb']->fail_cleanup_read = true;

		try {
			( new AuditRunRepository() )->cleanup_incomplete_runs();
			$this->fail( 'Expected cleanup read failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'could not read', $error->getMessage() );
		}

		$this->assertFalse( get_option( AuditRunRepository::RUN_LOCK_OPTION, false ) );
	}

	public function test_cleanup_delete_failure_is_reported_and_releases_its_lease(): void {
		$GLOBALS['wpdb']->fail_cleanup_delete = true;

		try {
			( new AuditRunRepository() )->cleanup_incomplete_runs();
			$this->fail( 'Expected cleanup delete failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'could not remove', $error->getMessage() );
		}

		$this->assertFalse( get_option( AuditRunRepository::RUN_LOCK_OPTION, false ) );
	}

	public function test_cron_cleanup_refuses_to_mutate_while_report_generation_owns_the_lease(): void {
		$repository = new AuditRunRepository();
		$owner      = $repository->acquire_run_lock( 10 * MINUTE_IN_SECONDS );
		$this->assertIsString( $owner );

		( new AuditRunRepository() )->cleanup_incomplete_runs();

		$this->assertSame( array(), $this->report_delete_queries() );
		$repository->release_run_lock( $owner );
	}

	public function test_service_owned_cleanup_reuses_and_preserves_its_existing_lease(): void {
		$repository = new AuditRunRepository();
		$owner      = $repository->acquire_run_lock( 10 * MINUTE_IN_SECONDS );
		$this->assertIsString( $owner );

		$repository->cleanup_incomplete_runs( DAY_IN_SECONDS, 25, $owner );

		$this->assertCount( 1, $this->report_delete_queries() );
		$stored = json_decode( (string) get_option( AuditRunRepository::RUN_LOCK_OPTION ), true );
		$this->assertSame( $owner, $stored['token'] ?? null );
		$repository->release_run_lock( $owner );
	}

	public function test_completed_report_deletion_is_atomic_and_baseline_guarded(): void {
		$deleted = ( new AuditRunRepository() )->delete_completed_run( 8 );

		$this->assertTrue( $deleted );
		$query = $this->last_report_delete_query();
		$this->assertIsString( $query );
		$this->assertStringContainsString( 'DELETE finding, resource, report', $query );
		$this->assertStringContainsString( 'finding.resource_id = resource.id', $query );
		$this->assertStringContainsString( 'finding.run_id = report.id', $query );
		$this->assertStringContainsString( 'dependent.baseline_run_id = report.id', $query );
		$this->assertStringContainsString( 'dependent.id IS NULL', $query );
		$this->assertStringContainsString( "report.status = 'complete'", $query );
		$this->assertFalse( get_option( AuditRunRepository::RUN_LOCK_OPTION, false ) );
	}

	public function test_completed_report_deletion_refuses_to_race_an_active_report_run(): void {
		$repository = new AuditRunRepository();
		$owner      = $repository->acquire_run_lock( 10 * MINUTE_IN_SECONDS );
		$this->assertIsString( $owner );

		$this->assertFalse( ( new AuditRunRepository() )->delete_completed_run( 8 ) );
		$this->assertSame( array(), $this->report_delete_queries() );

		$repository->release_run_lock( $owner );
	}

	public function test_schema_upgrade_ignores_an_unrelated_stale_database_error(): void {
		$GLOBALS['wpdb']->last_error = 'Earlier unrelated query failed';
		delete_option( 'cybermaps_audit_schema_version' );

		AuditRunRepository::create_tables();

		$this->assertSame(
			AuditRunRepository::SCHEMA_VERSION,
			get_option( 'cybermaps_audit_schema_version' )
		);
	}

	public function test_schema_version_is_not_saved_when_any_table_update_fails(): void {
		delete_option( 'cybermaps_audit_schema_version' );
		$statement = 0;
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function () use ( &$statement ): array {
			++$statement;
			$GLOBALS['wpdb']->last_error = 1 === $statement ? 'Could not create runs table' : '';
			return array();
		};

		AuditRunRepository::create_tables();

		$this->assertSame( '', get_option( 'cybermaps_audit_schema_version', '' ) );
		$this->assertSame( 3, $statement );
	}

	/**
	 * @return string[]
	 */
	private function report_delete_queries(): array {
		return array_values(
			array_filter(
				$GLOBALS['wpdb']->queries,
				static fn( string $query ): bool => str_contains( $query, 'DELETE finding, resource, report' )
			)
		);
	}

	private function last_report_delete_query(): string|false {
		$queries = $this->report_delete_queries();
		return end( $queries );
	}
}

/**
 * Minimal query-aware database surface for report repository projections.
 */
final class AuditRunRepositoryWpdbStub {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';
	public string $missing_table = '';
	public bool $fail_next_get_row = false;
	public bool $fail_cleanup_read = false;
	public bool $fail_cleanup_delete = false;
	public ?string $replace_lock_before_next_update = null;
	/** @var array<int,string> */
	public array $queries = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			preg_match( '/%[dis]/', $query, $placeholder );
			$replacement = '%i' === ( $placeholder[0] ?? '' )
				? (string) $arg
				: ( is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'" );
			$query       = (string) preg_replace( '/%[dis]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $output );
		$this->queries[] = $query;
		if ( $this->fail_next_get_row ) {
			$this->fail_next_get_row = false;
			$this->last_error        = 'Database unavailable';
			return null;
		}
		if ( ! str_contains( $query, 'id = 8' ) ) {
			return null;
		}
		if ( str_contains( $query, 'COALESCE(baseline.finding_count, 0)' ) ) {
			return array(
				'resource_count'         => 40,
				'finding_count'          => 17,
				'baseline_run_id'        => 7,
				'baseline_finding_count' => 14,
			);
		}
		return array(
			'id'              => 8,
			'status'          => 'complete',
			'policy_json'     => '{"post":{"min_words":300}}',
			'baseline_run_id' => 7,
			'resource_count'  => 40,
			'finding_count'   => 17,
			'is_baseline'     => 0,
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );
		$this->queries[] = $query;

		if ( str_contains( $query, 'SELECT id' ) && str_contains( $query, "status = 'failed'" ) ) {
			if ( $this->fail_cleanup_read ) {
				$this->last_error = 'Cleanup read failed';
				return array();
			}
			return array(
				array( 'id' => 31 ),
				array( 'id' => 32 ),
			);
		}
		if ( str_contains( $query, 'GROUP BY finding_key' ) ) {
			return array(
				array( 'finding_key' => 'thin_content', 'finding_count' => 12 ),
				array( 'finding_key' => 'stale_content', 'finding_count' => 5 ),
			);
		}
		if ( str_contains( $query, 'SELECT * FROM wp_cybermaps_audit_resources' ) ) {
			return array(
				array(
					'id'                => 80,
					'run_id'            => 8,
					'resource_key'      => 'post:1:10',
					'indexability_json' => '{"indexable":true}',
					'measurement_json'  => '{"words":100}',
				),
			);
		}
		if ( str_contains( $query, 'cybermaps_audit_findings' ) ) {
			return array(
				array(
					'id'              => 1,
					'resource_key'    => 'post:1:10',
					'finding_key'     => 'thin_content',
					'evidence_json'   => '{"actual_words":100}',
				),
				array(
					'id'              => 2,
					'resource_key'    => 'post:1:11',
					'finding_key'     => 'stale_content',
					'evidence_json'   => '{"age_days":500}',
				),
			);
		}

		return array();
	}

	public function get_var( string $query ): mixed {
		$this->queries[] = $query;
		if ( str_contains( $query, 'SELECT option_value FROM wp_options' ) ) {
			return get_option( AuditRunRepository::RUN_LOCK_OPTION, null );
		}
		if ( str_starts_with( $query, 'SHOW TABLES LIKE ' ) ) {
			$quoted  = trim( substr( $query, strlen( 'SHOW TABLES LIKE ' ) ), "'" );
			$table   = stripslashes( $quoted );
			return $table === $this->missing_table ? '' : $table;
		}
		if ( str_contains( $query, 'baseline_finding.id IS NULL' ) ) {
			return 3;
		}
		if ( str_contains( $query, 'current_finding.id IS NULL' ) ) {
			return 2;
		}
		if (
			str_contains( $query, 'INNER JOIN' )
			&& str_contains( $query, 'baseline_finding' )
			&& ! str_contains( $query, 'baseline_finding.id IS NULL' )
		) {
			return 4;
		}
		return 1;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if (
			$this->fail_cleanup_delete
			&& str_contains( $query, 'DELETE finding, resource, report' )
		) {
			$this->last_error = 'Cleanup delete failed';
			return false;
		}
		return 3;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $where
	 */
	public function update(
		string $table,
		array $data,
		array $where,
		array $format = array(),
		array $where_format = array()
	): int|false {
		unset( $format, $where_format );
		$this->queries[] = 'UPDATE ' . $table;
		if ( 'wp_options' !== $table ) {
			return 1;
		}
		if ( null !== $this->replace_lock_before_next_update ) {
			update_option(
				AuditRunRepository::RUN_LOCK_OPTION,
				$this->replace_lock_before_next_update
			);
			$this->replace_lock_before_next_update = null;
		}
		$current = get_option( (string) ( $where['option_name'] ?? '' ), null );
		if ( $current !== ( $where['option_value'] ?? null ) ) {
			return 0;
		}
		update_option( (string) $where['option_name'], $data['option_value'] ?? '' );
		return 1;
	}

	/**
	 * @param array<string,mixed> $where
	 */
	public function delete(
		string $table,
		array $where,
		array $where_format = array()
	): int|false {
		unset( $where_format );
		$this->queries[] = 'DELETE FROM ' . $table;
		if ( 'wp_options' !== $table ) {
			return 0;
		}
		$current = get_option( (string) ( $where['option_name'] ?? '' ), null );
		if ( $current !== ( $where['option_value'] ?? null ) ) {
			return 0;
		}
		delete_option( (string) $where['option_name'] );
		return 1;
	}
}
