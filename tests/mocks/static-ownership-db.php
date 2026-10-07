<?php
declare(strict_types=1);

/**
 * Opt-in, in-memory executor for the ownership code's fixed prepared SQL.
 * It is deliberately not a general SQL engine or evidence of real MySQL behavior.
 * Transactions model one session's rollback only, not isolation or deadlocks.
 */
final class CybermapsMockStaticOwnershipDatabase {

	public string $base_prefix = 'wp_';
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';
	public array $last_result = array();
	public int $rows_affected = 0;
	public int $num_rows = 0;
	public int $connection_id = 41;
	public array $tables = array();
	public array $columns = array();
	public array $indexes = array();
	public array $queries = array();
	public array $locks = array();
	public mixed $before_query = null;
	public mixed $after_query = null;
	public mixed $failure = null;
	public bool $fail_next = false;
	public array $engines = array();
	public int $lock_wait = 50;
	private array $lock_counts = array();
	private array $raw_options = array();
	private ?array $transaction = null;

	public function __construct() {
		$this->set_blog_id( max( 1, (int) ( $GLOBALS['cybermaps_mock_current_blog_id'] ?? 1 ) ) );
		$this->install_ownership_table( $this->prefix . 'cybermaps_static_ownership' );
	}

	public function set_blog_id( int $blog_id ): void {
		if ( $blog_id < 1 ) {
			throw new UnexpectedValueException( 'Ownership fixture blog ID must be positive.' );
		}
		$this->prefix = $this->base_prefix . ( $blog_id > 1 ? $blog_id . '_' : '' );
		$this->options = $this->prefix . 'options';
		$this->option_values_reference( $this->options );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/** Closing a session rolls back its transaction and releases its advisory locks. */
	public function reconnect( int $connection_id ): void {
		if ( $connection_id < 1 || $connection_id === $this->connection_id ) {
			throw new UnexpectedValueException( 'A reconnect needs a different positive connection ID.' );
		}
		$this->transaction_statement( 'ROLLBACK' );
		foreach ( $this->locks as $name => $owner ) {
			if ( $owner === $this->connection_id ) {
				unset( $this->locks[ $name ], $this->lock_counts[ $name ] );
			}
		}
		$this->connection_id = $connection_id;
	}

	/** Keep the template and typed arguments observable; never interpolate them. */
	public function prepare( string $query, mixed ...$args ): array {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		preg_match_all( '/%[idsf]/', $query, $placeholders );
		if ( count( $placeholders[0] ) !== count( $args ) ) {
			throw new UnexpectedValueException( 'Ownership mock placeholder count mismatch: ' . $query );
		}
		foreach ( $placeholders[0] as $index => $placeholder ) {
			if ( '%d' === $placeholder && ! is_int( $args[ $index ] ) ) {
				throw new UnexpectedValueException( 'Ownership mock expects an integer placeholder value.' );
			}
			if ( in_array( $placeholder, array( '%s', '%i' ), true ) && ! is_string( $args[ $index ] ) ) {
				throw new UnexpectedValueException( 'Ownership mock expects a string placeholder value.' );
			}
		}
		return array( 'query' => $query, 'args' => array_values( $args ) );
	}

	public function install_ownership_table( string $table ): void {
		$this->ownership_identifier( $table );
		$this->tables[ $table ] ??= array();
		$this->columns[ $table ] = array();
		foreach ( array( 'path_key' => 'char(64)', 'path' => 'varbinary(4096)', 'body_hash' => 'char(32)', 'generation' => 'bigint(20) unsigned', 'shard' => 'tinyint(3) unsigned' ) as $name => $type ) {
			$this->columns[ $table ][] = array( 'Field' => $name, 'Type' => $type, 'Null' => 'NO' );
		}
		$this->indexes[ $table ] = array();
		foreach ( array( 'PRIMARY' => array( 'path_key' ), 'shard_cursor' => array( 'shard', 'path_key' ), 'generation_cursor' => array( 'generation', 'path_key' ) ) as $name => $columns ) {
			foreach ( $columns as $index => $column ) {
				$this->indexes[ $table ][] = array( 'Key_name' => $name, 'Seq_in_index' => (string) ( $index + 1 ), 'Column_name' => $column, 'Non_unique' => 'PRIMARY' === $name ? '0' : '1' );
			}
		}
	}

	public function dbdelta( string $query ): array {
		if ( 1 !== preg_match( '/^CREATE TABLE ([a-zA-Z0-9_]+) \(/', $query, $match ) ) {
			throw new UnexpectedValueException( 'Unsupported ownership mock schema statement.' );
		}
		$this->install_ownership_table( $match[1] );
		return array( $match[1] => 'Ownership fixture table installed' );
	}

	/** Native option values remain shared with the existing WordPress mocks. */
	public function read_option( string $table, string $name, mixed $default = false ): mixed {
		$options = $this->option_values( $table );
		return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
	}

	public function get_var( array|string $prepared ): string|int|null|false {
		if ( false === $this->query( $prepared ) ) {
			return false;
		}
		return isset( $this->last_result[0] ) ? array_values( get_object_vars( $this->last_result[0] ) )[0] : null;
	}

	public function query( array|string $prepared ): int|false {
		$prepared = is_array( $prepared ) ? $prepared : array( 'query' => $prepared, 'args' => array() );
		$this->last_error = '';
		$this->last_result = array();
		$this->rows_affected = 0;
		$this->num_rows = 0;
		$this->queries[] = $prepared;
		if ( is_callable( $this->before_query ) ) {
			( $this->before_query )( $this, $prepared );
		}
		if ( $this->fail_next || ( is_callable( $this->failure ) && ( $this->failure )( $this, $prepared ) ) ) {
			$this->fail_next = false;
			$this->last_error = 'Injected ownership database failure';
			return false;
		}
		$result = $this->execute( trim( $prepared['query'] ), $prepared['args'] );
		if ( is_array( $result ) ) {
			$this->last_result = array_map( static fn( array $row ): object => (object) $row, $result );
			$this->num_rows = count( $result );
			$result = $this->num_rows;
		} elseif ( false !== $result ) {
			$this->rows_affected = $result;
		}
		if ( is_callable( $this->after_query ) ) {
			( $this->after_query )( $this, $prepared, $result );
		}
		return $result;
	}

	private function execute( string $sql, array $args ): array|int|false {
		if ( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' === $sql ) {
			if ( null !== $this->transaction ) {
				$this->last_error = '1568 Transaction characteristics cannot change during a transaction';
				return false;
			}
			return 0;
		}
		if ( 'SELECT @@SESSION.innodb_lock_wait_timeout AS lock_wait' === $sql ) {
			return array( array( 'lock_wait' => (string) $this->lock_wait ) );
		}
		if ( 'SET SESSION innodb_lock_wait_timeout = %d' === $sql ) {
			$this->lock_wait = $args[0];
			return 0;
		}
		if ( 'SELECT CONNECTION_ID() AS connection_id' === $sql ) {
			return array( array( 'connection_id' => (string) $this->connection_id ) );
		}
		if ( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s) LIMIT 2' === $sql ) {
			return array_map( fn( string $name ): array => array( 'TABLE_NAME' => $name, 'ENGINE' => $this->engines[ $name ] ?? 'InnoDB' ), $args );
		}

		if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			return $this->transaction_statement( $sql );
		}
		$lock = $this->lock_statement( $sql, $args );
		if ( null !== $lock ) {
			return $lock;
		}
		list( $sql, $args, $allowed ) = $this->remove_fence( $sql, $args );
		if ( str_ends_with( $sql, ' FOR UPDATE' ) ) {
			$sql = substr( $sql, 0, -11 );
		}
		if ( ! $allowed ) {
			$this->validate_blocked_statement( $sql, $args );
			return str_starts_with( $sql, 'SELECT' ) ? array() : 0;
		}
		if ( in_array( $sql, array( 'SHOW COLUMNS FROM %i', 'SHOW INDEX FROM %i' ), true ) ) {
			$table = $args[0];
			$this->ownership_identifier( $table );
			return $this->table_exists( $table ) ? ( 'SHOW COLUMNS FROM %i' === $sql ? $this->columns[ $table ] : $this->indexes[ $table ] ) : false;
		}
		if ( str_contains( $sql, 'path_key' ) ) {
			return $this->ownership_statement( $sql, $args );
		}
		return $this->option_statement( $sql, $args );
	}

	/** A failed fence must not turn an unsupported template into mock success. */
	private function validate_blocked_statement( string $sql, array $args ): void {
		$select = 'SELECT path_key, path, body_hash, generation, shard FROM %i';
		$row_sql = array(
			$select . ' WHERE path_key = %s LIMIT 1',
			$select . ' WHERE path_key > %s ORDER BY path_key LIMIT %d',
			$select . ' WHERE shard = %d AND path_key > %s ORDER BY path_key LIMIT %d',
			'INSERT IGNORE INTO %i (path_key,path,body_hash,generation,shard) SELECT %s,%s,%s,%d,%d WHERE 1=1',
			'UPDATE %i SET body_hash = %s, generation = %d WHERE path_key = %s AND path = %s AND body_hash = %s AND generation = %d',
			'DELETE FROM %i WHERE path_key = %s AND path = %s AND body_hash = %s AND generation = %d',
		);
		if ( in_array( $sql, $row_sql, true ) ) {
			$this->ownership_identifier( $args[0] );
			return;
		}
		$options_sql = array(
			'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
			'SELECT LENGTH(option_value) AS bytes, SHA2(option_value,256) AS digest FROM %i WHERE option_name = %s LIMIT 1',
			'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s WHERE 1=1',
			'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE 1=1',
			'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
			'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
			'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s',
			'DELETE FROM %i WHERE option_name = %s AND LENGTH(option_value) = %d AND SHA2(option_value,256) = %s',
		);
		if ( in_array( $sql, $options_sql, true ) ) {
			$this->option_values_reference( $args[0] );
			return;
		}
		if ( 'SELECT SUBSTRING(BINARY option_value,%d,%d) AS chunk FROM %i WHERE option_name = %s AND LENGTH(option_value) = %d AND SHA2(option_value,256) = %s LIMIT 1' === $sql ) {
			$this->option_values_reference( $args[2] );
			return;
		}
		throw new UnexpectedValueException( 'Unsupported ownership SQL behind a failed fence: ' . $sql );
	}

	/** Strip only the two audited same-statement session-fence predicates. */
	private function remove_fence( string $sql, array $args ): array {
		$optional = '/(?:AND|WHERE) \(%d = 0 OR \(IS_USED_LOCK\(%s\) = %d AND CONNECTION_ID\(\) = %d\)\)$/';
		if ( 1 === preg_match( $optional, $sql, $match, PREG_OFFSET_CAPTURE ) ) {
			$offset = $match[0][1];
			$count = preg_match_all( '/%[ids]/', substr( $sql, 0, $offset ) );
			$fence = array_splice( $args, $count, 4 );
			if ( ! in_array( $fence[0], array( 0, 1 ), true ) ) { throw new UnexpectedValueException( 'Optional fence must be explicitly zero or one.' ); }
			$allowed = 0 === $fence[0] || ( ( $this->locks[ $fence[1] ] ?? null ) === $fence[2] && $this->connection_id === $fence[3] );
			$replacement = str_starts_with( $match[0][0], 'WHERE' ) ? 'WHERE 1=1' : '';
			return array( trim( substr_replace( $sql, $replacement, $offset ) ), $args, $allowed );
		}
		$pattern = '/(?:AND|WHERE) IS_USED_LOCK\(%s\) = (CONNECTION_ID\(\)|%d) AND CONNECTION_ID\(\) = %d/';
		if ( 1 !== preg_match( $pattern, $sql, $match, PREG_OFFSET_CAPTURE ) ) {
			return array( $sql, $args, true );
		}
		$offset = $match[0][1];
		$count = preg_match_all( '/%[ids]/', substr( $sql, 0, $offset ) );
		$explicit_owner = '%d' === $match[1][0];
		$fence = array_splice( $args, $count, $explicit_owner ? 3 : 2 );
		$owner = $explicit_owner ? $fence[1] : $this->connection_id;
		$connection = $fence[ $explicit_owner ? 2 : 1 ];
		$allowed = isset( $this->locks[ $fence[0] ] ) && $this->locks[ $fence[0] ] === $owner && $this->connection_id === $connection;
		$replacement = str_starts_with( $match[0][0], 'WHERE' ) ? 'WHERE 1=1' : '';
		$sql = trim( preg_replace( '/\s+/', ' ', substr_replace( $sql, $replacement, $offset, strlen( $match[0][0] ) ) ) );
		return array( $sql, $args, $allowed );
	}

	private function ownership_statement( string $sql, array $args ): array|int|false {
		$table = $args[0];
		$this->ownership_identifier( $table );
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}
		$select = 'SELECT path_key, path, body_hash, generation, shard FROM %i';
		if ( $sql === $select . ' WHERE path_key = %s LIMIT 1' ) {
			return isset( $this->tables[ $table ][ $args[1] ] ) ? array( $this->tables[ $table ][ $args[1] ] ) : array();
		}
		if ( in_array( $sql, array( $select . ' WHERE path_key > %s ORDER BY path_key LIMIT %d', $select . ' WHERE shard = %d AND path_key > %s ORDER BY path_key LIMIT %d' ), true ) ) {
			$sharded = str_contains( $sql, 'WHERE shard' );
			$after = $args[ $sharded ? 2 : 1 ];
			$limit = $args[ $sharded ? 3 : 2 ];
			if ( $limit < 1 || $limit > 100 ) {
				throw new UnexpectedValueException( 'Ownership mock page must be bounded to 100 rows.' );
			}
			$rows = $this->tables[ $table ];
			ksort( $rows, SORT_STRING );
			$rows = array_filter( $rows, static fn( array $row ): bool => strcmp( $row['path_key'], $after ) > 0 && ( ! $sharded || (int) $row['shard'] === $args[1] ) );
			return array_slice( array_values( $rows ), 0, $limit );
		}
		if ( 'INSERT IGNORE INTO %i (path_key,path,body_hash,generation,shard) SELECT %s,%s,%s,%d,%d WHERE 1=1' === $sql ) {
			if ( isset( $this->tables[ $table ][ $args[1] ] ) ) {
				return 0;
			}
			$this->tables[ $table ][ $args[1] ] = array( 'path_key' => $args[1], 'path' => $args[2], 'body_hash' => $args[3], 'generation' => (string) $args[4], 'shard' => (string) $args[5] );
			return 1;
		}
		if ( 'UPDATE %i SET body_hash = %s, generation = %d WHERE path_key = %s AND path = %s AND body_hash = %s AND generation = %d' === $sql ) {
			$row = $this->tables[ $table ][ $args[3] ] ?? null;
			if ( null === $row || $row['path'] !== $args[4] || $row['body_hash'] !== $args[5] || (string) $row['generation'] !== (string) $args[6] ) {
				return 0;
			}
			$changed = $row['body_hash'] !== $args[1] || (string) $row['generation'] !== (string) $args[2];
			$this->tables[ $table ][ $args[3] ]['body_hash'] = $args[1];
			$this->tables[ $table ][ $args[3] ]['generation'] = (string) $args[2];
			return $changed ? 1 : 0;
		}
		if ( 'DELETE FROM %i WHERE path_key = %s AND path = %s AND body_hash = %s AND generation = %d' === $sql ) {
			$row = $this->tables[ $table ][ $args[1] ] ?? null;
			if ( null === $row || $row['path'] !== $args[2] || $row['body_hash'] !== $args[3] || (string) $row['generation'] !== (string) $args[4] ) {
				return 0;
			}
			unset( $this->tables[ $table ][ $args[1] ] );
			return 1;
		}
		throw new UnexpectedValueException( 'Unsupported ownership row SQL: ' . $sql );
	}

	private function option_statement( string $sql, array $args ): array|int|false {
		if ( 'SELECT SUBSTRING(BINARY option_value,%d,%d) AS chunk FROM %i WHERE option_name = %s AND LENGTH(option_value) = %d AND SHA2(option_value,256) = %s LIMIT 1' === $sql ) {
			$raw = $this->raw_option( $args[2], $args[3] );
			if ( null !== $raw && ( strlen( $raw ) !== $args[4] || hash( 'sha256', $raw ) !== $args[5] ) ) { return array(); }
			return null === $raw ? array() : array( array( 'chunk' => substr( $raw, $args[0] - 1, $args[1] ) ) );
		}
		$table = $args[0] ?? '';
		if ( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1' === $sql ) {
			$raw = $this->raw_option( $table, $args[1] );
			return null === $raw ? array() : array( array( 'option_value' => $raw ) );
		}
		if ( 'SELECT LENGTH(option_value) AS bytes, SHA2(option_value,256) AS digest FROM %i WHERE option_name = %s LIMIT 1' === $sql ) {
			$raw = $this->raw_option( $table, $args[1] );
			return null === $raw ? array() : array( array( 'bytes' => (string) strlen( $raw ), 'digest' => hash( 'sha256', $raw ) ) );
		}
		$insert = array( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', 'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s WHERE 1=1', 'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE 1=1' );
		if ( in_array( $sql, $insert, true ) ) {
			$this->option_behavior( $args[1], $args[2], 'before' );
			if ( null !== $this->raw_option( $table, $args[1] ) ) {
				return 0;
			}
			$this->write_option( $table, $args[1], $args[2] );
			$this->option_behavior( $args[1], $args[2], 'after' );
			return 1;
		}
		$updates = array( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = BINARY %s' );
		if ( in_array( $sql, $updates, true ) ) {
			$index = str_contains( $sql, 'autoload' ) ? 3 : 2;
			$this->option_behavior( $args[ $index ], $args[1], 'before' );
			if ( $this->raw_option( $table, $args[ $index ] ) !== $args[ $index + 1 ] ) {
				return 0;
			}
			$changed = $args[1] !== $args[ $index + 1 ];
			$this->write_option( $table, $args[ $index ], $args[1] );
			$this->option_behavior( $args[ $index ], $args[1], 'after' );
			return $changed ? 1 : 0;
		}
		if ( in_array( $sql, array( 'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s', 'DELETE FROM %i WHERE option_name = %s AND LENGTH(option_value) = %d AND SHA2(option_value,256) = %s' ), true ) ) {
			$raw = $this->raw_option( $table, $args[1] );
			$matches = str_contains( $sql, 'LENGTH' ) ? null !== $raw && strlen( $raw ) === $args[2] && hash( 'sha256', $raw ) === $args[3] : $raw === $args[2];
			if ( ! $matches ) {
				return 0;
			}
			$values = &$this->option_values_reference( $table );
			unset( $values[ $args[1] ], $this->raw_options[ $table ][ $args[1] ] );
			return 1;
		}
		$sequence_prefix = "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = IF(option_value REGEXP '^(0|[1-9][0-9]*)$', ";
		$increment = $sequence_prefix . 'CAST(option_value AS UNSIGNED) + 1, option_value)';
		$advance = $sequence_prefix . 'GREATEST(CAST(option_value AS UNSIGNED), CAST(%s AS UNSIGNED)), option_value)';
		if ( in_array( $sql, array( $increment, $advance ), true ) ) {
			$raw = $this->raw_option( $table, $args[1] );
			if ( null !== $raw && 1 !== preg_match( '/^(0|[1-9][0-9]*)$/D', $raw ) ) {
				return 0;
			}
			if ( null !== $raw && ( strlen( $raw ) > 20 || ( 20 === strlen( $raw ) && strcmp( $raw, '18446744073709551615' ) > 0 ) ) ) {
				$this->last_error = 'Ownership fixture unsigned sequence overflow';
				return false;
			}
			if ( $sql === $increment && '18446744073709551615' === $raw ) {
				$this->last_error = 'Ownership fixture unsigned sequence overflow';
				return false;
			}
			$next = null === $raw ? $args[2] : ( $sql === $increment ? $this->increment_decimal( $raw ) : $this->maximum_decimal( $raw, $args[4] ) );
			$this->write_option( $table, $args[1], $next );
			return null === $raw ? 1 : ( $raw === $next ? 0 : 2 );
		}
		throw new UnexpectedValueException( 'Unsupported ownership option SQL: ' . $sql );
	}

	private function increment_decimal( string $value ): string {
		for ( $i = strlen( $value ) - 1; $i >= 0; --$i ) {
			if ( '9' !== $value[ $i ] ) {
				$value[ $i ] = (string) ( (int) $value[ $i ] + 1 );
				return $value;
			}
			$value[ $i ] = '0';
		}
		return '1' . $value;
	}

	private function maximum_decimal( string $left, string $right ): string {
		return strlen( $left ) > strlen( $right ) || ( strlen( $left ) === strlen( $right ) && strcmp( $left, $right ) >= 0 ) ? $left : $right;
	}

	private function option_behavior( string $name, string $raw, string $phase ): void {
		$callback = $GLOBALS['cybermaps_mock_update_option_behavior'] ?? null;
		if ( is_callable( $callback ) ) {
			$callback( $name, maybe_unserialize( $raw ), $phase );
		}
	}

	private function raw_option( string $table, string $name ): ?string {
		$values = $this->option_values( $table );
		if ( ! array_key_exists( $name, $values ) ) {
			return null;
		}
		$value = $values[ $name ];
		$snapshot = $this->raw_options[ $table ][ $name ] ?? null;
		if ( null !== $snapshot && $snapshot['value'] === $value ) {
			return $snapshot['raw'];
		}
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	}

	private function write_option( string $table, string $name, string $raw ): void {
		$value = maybe_unserialize( $raw );
		$values = &$this->option_values_reference( $table );
		$values[ $name ] = $value;
		$this->raw_options[ $table ][ $name ] = array( 'raw' => $raw, 'value' => $value );
	}

	private function option_values( string $table ): array {
		$values = &$this->option_values_reference( $table );
		return $values;
	}

	private function &option_values_reference( string $table ): array {
		$pattern = '/^' . preg_quote( $this->base_prefix, '/' ) . '(?:([1-9][0-9]*)_)?options$/D';
		if ( 1 !== preg_match( $pattern, $table, $match ) ) {
			throw new UnexpectedValueException( 'Unexpected ownership mock options table: ' . $table );
		}
		$blog = isset( $match[1] ) ? (int) $match[1] : 1;
		if ( $blog > 1 || isset( $GLOBALS['cybermaps_mock_options_by_blog'][1] ) ) {
			$GLOBALS['cybermaps_mock_options_by_blog'][ $blog ] ??= array();
			return $GLOBALS['cybermaps_mock_options_by_blog'][ $blog ];
		}
		$GLOBALS['cybermaps_mock_options'] ??= array();
		return $GLOBALS['cybermaps_mock_options'];
	}

	private function ownership_identifier( string $table ): void {
		if ( 1 !== preg_match( '/^' . preg_quote( $this->base_prefix, '/' ) . '(?:[1-9][0-9]*_)?cybermaps_static_ownership$/D', $table ) ) {
			throw new UnexpectedValueException( 'Unexpected ownership mock table: ' . $table );
		}
	}

	private function table_exists( string $table ): bool {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->last_error = 'Ownership fixture table does not exist';
			return false;
		}
		return true;
	}

	private function lock_statement( string $sql, array $args ): ?array {
		if ( 'SELECT CONNECTION_ID()' === $sql ) {
			return array( array( 'connection' => (string) $this->connection_id ) );
		}
		if ( 'SELECT GET_LOCK(%s, 0)' === $sql ) {
			$owner = $this->locks[ $args[0] ] ?? null;
			$acquired = null === $owner || $owner === $this->connection_id;
			if ( $acquired ) {
				$this->locks[ $args[0] ] = $this->connection_id;
				$this->lock_counts[ $args[0] ] = ( $this->lock_counts[ $args[0] ] ?? 0 ) + 1;
			}
			return array( array( 'acquired' => $acquired ? '1' : '0' ) );
		}
		if ( 'SELECT (IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d)' === $sql ) {
			return array( array( 'owned' => isset( $this->locks[ $args[0] ] ) && $this->locks[ $args[0] ] === $args[1] && $this->connection_id === $args[2] ? '1' : '0' ) );
		}
		if ( 'SELECT IS_USED_LOCK(%s)' === $sql ) {
			return isset( $this->locks[ $args[0] ] ) ? array( array( 'owner' => (string) $this->locks[ $args[0] ] ) ) : array();
		}
		if ( 'SELECT RELEASE_LOCK(%s)' === $sql ) {
			$owner = $this->locks[ $args[0] ] ?? null;
			if ( $owner === $this->connection_id ) {
				$this->lock_counts[ $args[0] ] = ( $this->lock_counts[ $args[0] ] ?? 1 ) - 1;
				if ( $this->lock_counts[ $args[0] ] <= 0 ) {
					unset( $this->locks[ $args[0] ], $this->lock_counts[ $args[0] ] );
				}
			}
			return array( array( 'released' => null === $owner ? null : ( $owner === $this->connection_id ? '1' : '0' ) ) );
		}
		return null;
	}

	private function transaction_statement( string $sql ): int {
		if ( 'START TRANSACTION' === $sql ) {
			if ( null !== $this->transaction ) {
				throw new UnexpectedValueException( 'Nested fixture transactions are unsupported.' );
			}
			$this->transaction = array( $this->tables, $this->raw_options, $GLOBALS['cybermaps_mock_options'] ?? array(), $GLOBALS['cybermaps_mock_options_by_blog'] ?? array() );
		} elseif ( 'ROLLBACK' === $sql && null !== $this->transaction ) {
			list( $this->tables, $this->raw_options, $GLOBALS['cybermaps_mock_options'], $GLOBALS['cybermaps_mock_options_by_blog'] ) = $this->transaction;
			$this->transaction = null;
		} else {
			$this->transaction = null;
		}
		return 0;
	}
}

/** Preserve every pre-existing database and SQL-path flag on opt-in. */
function cybermaps_mock_enable_static_ownership_database( bool $sql_fences = false ): CybermapsMockStaticOwnershipDatabase {
	if ( isset( $GLOBALS['cybermaps_mock_ownership_database_restore'] ) ) {
		throw new LogicException( 'Disable the ownership database adapter before enabling another.' );
	}
	$restore = array();
	foreach ( array( 'wpdb', 'cybermaps_test_static_ownership_use_sql', 'cybermaps_test_database_session_lock_use_sql' ) as $name ) {
		$restore[ $name ] = array( 'exists' => array_key_exists( $name, $GLOBALS ), 'value' => $GLOBALS[ $name ] ?? null );
	}
	$GLOBALS['cybermaps_mock_ownership_database_restore'] = $restore;
	$GLOBALS['cybermaps_test_static_ownership_use_sql'] = true;
	$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = $sql_fences;
	$GLOBALS['wpdb'] = new CybermapsMockStaticOwnershipDatabase();
	return $GLOBALS['wpdb'];
}

function cybermaps_mock_disable_static_ownership_database(): void {
	foreach ( $GLOBALS['cybermaps_mock_ownership_database_restore'] ?? array() as $name => $record ) {
		if ( $record['exists'] ) {
			$GLOBALS[ $name ] = $record['value'];
		} else {
			unset( $GLOBALS[ $name ] );
		}
	}
	unset( $GLOBALS['cybermaps_mock_ownership_database_restore'] );
}
