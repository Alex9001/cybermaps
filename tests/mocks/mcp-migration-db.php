<?php
declare(strict_types=1);
require_once __DIR__ . '/static-ownership-db.php';

/** Strict existing option/session executor plus the fixed legacy-table cleanup seam. */
final class CybermapsMCPMigrationDatabase {
	public string $options = 'wp_options';
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public string $last_error = '';
	public array $last_result = array();
	public array $queries = array();
	public array $existing_tables = array( 'wp_cybermaps_mcp_tasks', 'wp_cybermaps_audit_runs' );
	public string $fail_drop_table = '';
	private array $prepared = array();
	public function __construct( public CybermapsMockStaticOwnershipDatabase $sql ) {}
	public function prepare( string $template, mixed ...$args ): string {
		$key = 'mcp-prepared-' . count( $this->prepared );
		$this->prepared[ $key ] = $this->sql->prepare( $template, ...$args );
		return $key;
	}
	public function query( string $key ): int|false {
		$prepared = $this->prepared[ $key ] ?? array( 'query' => $key, 'args' => array() );
		$this->queries[] = $prepared;
		$this->last_error = '';
		$this->last_result = array();
		if ( 'DROP TABLE IF EXISTS %i' === $prepared['query'] ) {
			$table = $prepared['args'][0];
			if ( $table === $this->fail_drop_table ) { $this->last_error = 'Injected DROP failure'; return false; }
			$this->existing_tables = array_values( array_diff( $this->existing_tables, array( $table ) ) );
			return 0;
		}
		if ( 'SHOW TABLES LIKE %s' === $prepared['query'] ) {
			$table = stripslashes( $prepared['args'][0] );
			$this->last_result = in_array( $table, $this->existing_tables, true ) ? array( (object) array( 'name' => $table ) ) : array();
			return count( $this->last_result );
		}
		$result = $this->sql->query( $prepared );
		$this->last_error = $this->sql->last_error;
		$this->last_result = $this->sql->last_result;
		return $result;
	}
	public function get_var( string $key ): mixed {
		if ( false === $this->query( $key ) ) { return false; }
		return isset( $this->last_result[0] ) ? current( (array) $this->last_result[0] ) : null;
	}
	public function get_results( string $key, string $output = 'OBJECT' ): array {
		$this->query( $key );
		return 'ARRAY_A' === $output ? array_map( 'get_object_vars', $this->last_result ) : $this->last_result;
	}
	public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	public function get_charset_collate(): string { return $this->sql->get_charset_collate(); }
	public function dbdelta( string $query ): array { return $this->sql->dbdelta( $query ); }
}

function cybermaps_mock_enable_mcp_migration_database(): CybermapsMCPMigrationDatabase {
	$database = new CybermapsMCPMigrationDatabase( cybermaps_mock_enable_static_ownership_database( true ) );
	$GLOBALS['wpdb'] = $database;
	return $database;
}
