<?php
declare(strict_types=1);

function wp_cache_flush_runtime(): bool {
	++$GLOBALS['audit_budget_flushes'];
	$GLOBALS['cybermaps_mock_object_cache'] = array();
	return true;
}
require dirname( __DIR__, 2 ) . '/bootstrap.php';
require dirname( __DIR__ ) . '/AuditRunRepositoryTest.php';
require dirname( __DIR__ ) . '/PublishedPostSourceTest.php';

/** Compose existing SQL fixtures while recording actual repository run writes. */
final class AuditBudgetFixtureDatabase {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $posts = 'wp_posts';
	public string $last_error = '';
	public array $last_result = array();
	public int $insert_id = 8;
	public array $statuses = array();
	public int $resources = 0;
	private object $audit;
	private object $source;
	public function __construct() {
		$this->audit = new \Cybermaps\Tests\Audit\AuditRunRepositoryWpdbStub();
		$this->source = new \Cybermaps\Tests\Audit\PublishedPostSourceWpdbStub( 101 );
	}
	public function prepare( string $sql, mixed ...$args ): string { return $this->audit->prepare( $sql, ...$args ); }
	public function get_var( string $sql ): mixed {
		if ( str_contains( $sql, 'SELECT MAX(ID)' ) ) { return $this->source->get_var( $sql ); }
		$result = $this->audit->get_var( $sql );
		$this->last_error = $this->audit->last_error;
		$this->last_result = $this->audit->last_result;
		return $result;
	}
	public function get_col( string $sql ): array { return $this->source->get_col( $sql ); }
	public function get_results( string $sql, string $format ): array { return $this->audit->get_results( $sql, $format ); }
	public function query( string $sql ): int|false { return $this->audit->query( $sql ); }
	public function insert( string $table, array $data ): int {
		if ( 'wp_cybermaps_audit_runs' === $table ) { $this->statuses[] = $data['status']; }
		if ( 'wp_cybermaps_audit_resources' === $table ) { ++$this->resources; }
		return 1;
	}
	public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): int|false {
		if ( 'wp_cybermaps_audit_runs' === $table ) { $this->statuses[] = $data['status']; return 1; }
		return $this->audit->update( $table, $data, $where, $format, $where_format );
	}
	public function delete( string $table, array $where, array $format = array() ): int|false { return $this->audit->delete( $table, $where, $format ); }
}

$audit_budget_results = array();
foreach ( array( 'unsupported', 'supported', 'sql-failure' ) as $audit_budget_scenario ) {
	$GLOBALS['wpdb'] = new AuditBudgetFixtureDatabase();
	$GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'static_engine_mode' => 'off' ), 'cybermaps_audit_schema_version' => '3', 'blog_public' => '1' );
	$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
	$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
	$GLOBALS['cybermaps_mock_posts'] = array();
	$GLOBALS['cybermaps_mock_cache_capabilities'] = 'unsupported' !== $audit_budget_scenario ? array( 'flush_runtime' ) : array();
	$GLOBALS['cybermaps_mock_object_cache'] = array();
	$GLOBALS['audit_budget_persistent_sentinel'] = 'retained backend entry';
	$GLOBALS['audit_budget_flushes'] = 0;
	$GLOBALS['audit_budget_loaded_batches'] = array();
	for ( $id = 1; $id <= 101; ++$id ) {
		$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Literal fixture', 'post_content' => 'Small ordinary content.', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ) );
	}
	$GLOBALS['cybermaps_mock_get_posts_callback'] = static function ( array $args ): mixed {
		if ( ! isset( $args['post__in'] ) ) { return null; }
		$GLOBALS['audit_budget_loaded_batches'][] = count( $args['post__in'] );
		foreach ( $args['post__in'] as $id ) {
			wp_cache_set( $id, (object) array( 'ID' => $id ), 'posts' );
			wp_cache_set( $id, array( 'literal' => 'meta' ), 'post_meta' );
			wp_cache_set( $id, array( $id ), 'category_relationships' );
		}
		return null;
	};
	unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
	if ( 'sql-failure' === $audit_budget_scenario ) {
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ): array {
			if ( $args['cache_results'] ) { throw new RuntimeException( 'Failed query must bypass native result cache.' ); }
			$GLOBALS['wpdb']->last_error = 'Injected native audit posts SELECT failure';
			return array();
		};
	}
	$service = new \Cybermaps\Audit\ContentAuditService( runtime_budget: new \Cybermaps\Audit\AuditRuntimeBudget( 'supported' === $audit_budget_scenario ? 0 : 201 ) );
	$error = '';
	try { $service->run(); } catch ( RuntimeException $exception ) { $error = $exception->getMessage(); }
	$audit_budget_results[ $audit_budget_scenario ] = array( 'error' => $error, 'statuses' => $GLOBALS['wpdb']->statuses, 'resources' => $GLOBALS['wpdb']->resources, 'batches' => $GLOBALS['audit_budget_loaded_batches'], 'flushes' => $GLOBALS['audit_budget_flushes'], 'lock' => get_option( \Cybermaps\Audit\AuditRunRepository::RUN_LOCK_OPTION ), 'persistent_sentinel' => $GLOBALS['audit_budget_persistent_sentinel'] );
}
echo json_encode( $audit_budget_results );
