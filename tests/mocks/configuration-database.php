<?php
declare(strict_types=1);

require_once __DIR__ . '/static-ownership-db.php';

/** Executes the exact SQL operation semantics against the ordinary option mock. */
final class CybermapsConfigurationDatabase {
	public string $options = 'wp_options';
	public string $last_error = '';
	public string $prefix = 'wp_';
	public array $last_result = array();
	public array $queries = array();
	public mixed $before_query = null;
	private array $prepared = array();
	private ?CybermapsMockStaticOwnershipDatabase $ownership = null;

	public function __construct( bool $include_ownership = false ) {
		if ( $include_ownership ) {
			$this->ownership = new CybermapsMockStaticOwnershipDatabase();
			$this->ownership->set_blog_id( 1 );
		}
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare( string $sql, ...$args ): string {
		$key = 'prepared-' . count( $this->prepared );
		$this->prepared[ $key ] = array( $sql, $args );
		return $key;
	}

	public function get_var( string $key ): mixed {
		list( $sql, $args ) = $this->prepared[ $key ] ?? array( $key, array() );
		if ( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1' !== $sql ) {
			return false === $this->ownership_query( $sql, $args ) ? null : ( isset( $this->last_result[0] ) ? current( (array) $this->last_result[0] ) : null );
		}
		$this->queries[] = array( $sql, $args );
		$this->last_error = '';
		$option = $args[1];
		$observer = $GLOBALS['cybermaps_mock_get_option_observer'] ?? null;
		if ( is_callable( $observer ) ) {
			$observer( $option );
		}
		$raw = $this->raw( $option );
		$this->last_result = null === $raw ? array() : array( (object) array( 'option_value' => $raw ) );
		// Match native wpdb: get_var collapses '', but last_result preserves it.
		return '' === $raw ? null : $raw;
	}

	public function raw( string $option ): ?string {
		return array_key_exists( $option, $GLOBALS['cybermaps_mock_options'] ) ? (string) maybe_serialize( $GLOBALS['cybermaps_mock_options'][ $option ] ) : null;
	}

	public function query( string $key ): int|false {
		list( $sql, $args ) = $this->prepared[ $key ] ?? array( $key, array() );
		$this->queries[] = array( $sql, $args );
		$this->last_error = '';
		if ( ! preg_match( '/^(UPDATE %i SET option_value|DELETE FROM %i WHERE option_name|INSERT (?:IGNORE )?INTO %i \\(option_name)/', $sql ) || str_contains( $sql, 'IS_USED_LOCK' ) ) {
			return $this->ownership_query( $sql, $args );
		}
		$update = str_starts_with( $sql, 'UPDATE ' );
		$delete = str_starts_with( $sql, 'DELETE ' );
		$option = $args[ $update ? 2 : 1 ];
		$next = $delete ? null : $args[ $update ? 1 : 2 ];
		$expected = $update ? $args[3] : ( $delete ? $args[2] : null );
		$add_behavior = $GLOBALS['cybermaps_mock_add_option_behavior'] ?? null;
		if ( ! $update && ! $delete && is_callable( $add_behavior ) ) { $add_behavior( $option, maybe_unserialize( $next ), 'before' ); }
		if ( str_contains( $sql, 'ON DUPLICATE KEY UPDATE' ) ) {
			$current = $GLOBALS['cybermaps_mock_options'][ $option ] ?? 0;
			$GLOBALS['cybermaps_mock_options'][ $option ] = isset( $args[4] ) ? max( (int) $current, (int) $args[4] ) : (int) $current + 1;
			return 1;
		}
		if ( is_callable( $this->before_query ) ) {
			$result = ( $this->before_query )( $sql, $option, $next );
			if ( false === $result ) {
				$this->last_error = 'Simulated database error';
				return false;
			}
		}
		$behavior = $GLOBALS['cybermaps_mock_update_option_behavior'] ?? null;
		if ( is_callable( $behavior ) && ! $delete ) {
			$behavior( $option, maybe_unserialize( $next ), 'before' );
		}
		if ( $this->raw( $option ) !== $expected ) {
			return 0;
		}
		if ( $delete ) {
			unset( $GLOBALS['cybermaps_mock_options'][ $option ] );
		} else {
			$GLOBALS['cybermaps_mock_options'][ $option ] = maybe_unserialize( $next );
		}
		if ( ! $update && ! $delete && is_callable( $add_behavior ) ) { $add_behavior( $option, maybe_unserialize( $next ), 'after' ); }
		return 1;
	}

	private function ownership_query( string $sql, array $args ): int|false {
		if ( null === $this->ownership ) {
			$this->last_error = 'Statement is outside this configuration-only fixture.';
			$this->last_result = array();
			return false;
		}
		$result = $this->ownership->query( $this->ownership->prepare( $sql, ...$args ) );
		$this->last_error = $this->ownership->last_error;
		$this->last_result = $this->ownership->last_result;
		return $result;
	}
}

trait CybermapsConfigurationDatabaseFixture {
	private mixed $configuration_previous_database;
	private array $configuration_previous_actions;
	private array $configuration_previous_flags;

	private function install_configuration_database(): void {
		$this->configuration_previous_database = $GLOBALS['wpdb'] ?? null;
		$this->configuration_previous_actions = $GLOBALS['cybermaps_mock_action_callbacks'] ?? array();
		$this->configuration_previous_flags = array();
		foreach ( array( 'cybermaps_test_static_ownership_use_sql', 'cybermaps_test_database_session_lock_use_sql' ) as $flag ) {
			$this->configuration_previous_flags[ $flag ] = $GLOBALS[ $flag ] ?? null;
			$GLOBALS[ $flag ] = true;
		}
		$GLOBALS['wpdb'] = new CybermapsConfigurationDatabase( true );
		$GLOBALS['cybermaps_mock_action_callbacks']['updated_option'][] = static function ( $option, $old, $value ): void {
			$behavior = $GLOBALS['cybermaps_mock_update_option_behavior'] ?? null;
			if ( is_callable( $behavior ) ) {
				$behavior( $option, $value, 'after' );
			}
		};
		$GLOBALS['cybermaps_mock_action_callbacks']['added_option'][] = static function ( $option, $value ): void {
			$behavior = $GLOBALS['cybermaps_mock_update_option_behavior'] ?? null;
			if ( is_callable( $behavior ) ) {
				$behavior( $option, $value, 'after' );
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->configuration_previous_database;
		$GLOBALS['cybermaps_mock_action_callbacks'] = $this->configuration_previous_actions;
		foreach ( $this->configuration_previous_flags as $flag => $previous ) {
			if ( null === $previous ) {
				unset( $GLOBALS[ $flag ] );
			} else {
				$GLOBALS[ $flag ] = $previous;
			}
		}
		unset( $GLOBALS['cybermaps_mock_update_option_behavior'], $GLOBALS['cybermaps_mock_get_option_observer'] );
		parent::tearDown();
	}
}
