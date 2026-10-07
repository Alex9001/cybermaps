<?php
declare(strict_types=1);

/** Small semantic option/session adapter; never connects to a database. */
final class CybermapsCloudflareDatabase {
	public string $options = 'wp_options';
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $last_result = array();
	public array $queries = array();
	public int $connection = 101;
	public array $locks = array();
	public mixed $before_query = null;
	private array $prepared = array();

	public function prepare( string $sql, ...$args ): string {
		$key = 'cf-prepared-' . count( $this->prepared );
		$this->prepared[ $key ] = array( $sql, $args );
		return $key;
	}

	public function get_var( string $key ): mixed {
		$result = $this->query( $key );
		$value = $this->last_result[0]->value ?? $this->last_result[0]->option_value ?? null;
		return false === $result || '' === $value ? null : $value;
	}

	public function query( string $key ): int|false {
		[$sql, $args] = $this->prepared[ $key ] ?? array( $key, array() );
		$this->queries[] = array( $sql, $args );
		$this->last_error = '';
		$this->last_result = array();
		if ( is_callable( $this->before_query ) && false === ( $this->before_query )( $sql, $args, $this ) ) {
			$this->last_error = 'Injected write failure';
			return false;
		}
		if ( str_contains( $sql, 'GET_LOCK(' ) ) {
			$available = ! isset( $this->locks[ $args[0] ] ) || $this->locks[ $args[0] ] === $this->connection;
			if ( $available ) { $this->locks[ $args[0] ] = $this->connection; }
			return $this->scalar( $available ? '1' : '0' );
		}
		if ( str_contains( $sql, 'RELEASE_LOCK(' ) ) {
			$owned = ( $this->locks[ $args[0] ] ?? null ) === $this->connection;
			if ( $owned ) { unset( $this->locks[ $args[0] ] ); }
			return $this->scalar( $owned ? '1' : '0' );
		}
		if ( 'SELECT CONNECTION_ID()' === $sql ) { return $this->scalar( (string) $this->connection ); }
		if ( str_starts_with( $sql, 'SELECT (IS_USED_LOCK(' ) ) { return $this->scalar( $this->owns( $args ) ? '1' : '0' ); }
		if ( str_starts_with( $sql, 'SELECT option_value ' ) ) {
			$raw = $this->raw( $args[1] );
			$this->last_result = null === $raw ? array() : array( (object) array( 'option_value' => $raw ) );
			return count( $this->last_result );
		}
		if ( str_contains( $sql, 'IS_USED_LOCK' ) && ! $this->owns( array_slice( $args, -3 ) ) ) { return 0; }
		$update = str_starts_with( $sql, 'UPDATE ' );
		$delete = str_starts_with( $sql, 'DELETE ' );
		$option = $args[ $update ? 2 : 1 ];
		$next = $delete ? null : $args[ $update ? 1 : 2 ];
		$expected = $update ? $args[3] : ( $delete ? $args[2] : null );
		if ( $this->raw( $option ) !== $expected ) { return 0; }
		if ( $next === $expected ) { return 0; }
		if ( $delete ) { unset( $GLOBALS['cybermaps_mock_options'][ $option ] ); }
		else { $GLOBALS['cybermaps_mock_options'][ $option ] = maybe_unserialize( $next ); }
		return 1;
	}

	public function reconnect(): void {
		$this->locks = array_filter( $this->locks, fn( int $owner ): bool => $owner !== $this->connection );
		++$this->connection;
	}

	private function raw( string $option ): ?string {
		return array_key_exists( $option, $GLOBALS['cybermaps_mock_options'] ) ? (string) maybe_serialize( $GLOBALS['cybermaps_mock_options'][ $option ] ) : null;
	}

	private function scalar( string $value ): int {
		$this->last_result = array( (object) array( 'value' => $value ) );
		return 1;
	}

	private function owns( array $fence ): bool {
		return ( $this->locks[ $fence[0] ] ?? null ) === $fence[1] && $this->connection === $fence[2];
	}
}

trait CybermapsCloudflareDatabaseFixture {
	private mixed $cf_previous_database;
	private mixed $cf_previous_sql_switch;
	private ?\Cybermaps\Core\DatabaseSessionLock $cf_lock = null;

	private function install_cloudflare_database(): void {
		$this->cf_previous_database = $GLOBALS['wpdb'] ?? null;
		$this->cf_previous_sql_switch = $GLOBALS['cybermaps_test_database_session_lock_use_sql'] ?? null;
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$GLOBALS['wpdb'] = new CybermapsCloudflareDatabase();
		foreach ( array_keys( $GLOBALS['cybermaps_mock_options'] ?? array() ) as $key ) {
			if ( str_starts_with( $key, 'cybermaps_cf_oauth_pointer_' ) ) { unset( $GLOBALS['cybermaps_mock_options'][ $key ] ); }
		}
	}

	private function cloudflare_lock(): \Cybermaps\Core\DatabaseSessionLock {
		if ( null === $this->cf_lock ) {
			$this->cf_lock = new \Cybermaps\Core\DatabaseSessionLock( 'edge-operation', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|wp_options' );
			self::assertTrue( $this->cf_lock->acquire() );
		}
		return $this->cf_lock;
	}

	private function restore_cloudflare_database(): void {
		$this->cf_lock?->release();
		$this->cf_lock = null;
		$GLOBALS['wpdb'] = $this->cf_previous_database;
		if ( null === $this->cf_previous_sql_switch ) { unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] ); }
		else { $GLOBALS['cybermaps_test_database_session_lock_use_sql'] = $this->cf_previous_sql_switch; }
	}
}
