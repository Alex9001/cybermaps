<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded, site-local persistence for asynchronous MCP operation handles.
 *
 * The unique active_slot column makes one pending or running task of a given
 * type possible per site without relying on mutable WordPress options.
 */
final class TaskRepository {
	private const MAX_TTL = 86400;
	private const MIN_TTL = 60;

	public static function create_tables(): void {
		global $wpdb;
		$table     = $wpdb->prefix . 'cybermaps_mcp_tasks';
		$charset   = $wpdb->get_charset_collate();
		$statement = "CREATE TABLE $table (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
task_id char(64) NOT NULL,
task_type varchar(64) NOT NULL,
active_slot varchar(64) NOT NULL,
status varchar(20) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
client_id varchar(191) NOT NULL,
request_json longtext NOT NULL,
result_json longtext NOT NULL,
error_code varchar(64) NOT NULL,
created_gmt datetime NOT NULL,
updated_gmt datetime NOT NULL,
expires_gmt datetime NOT NULL,
cancelled_gmt datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY task_id (task_id),
UNIQUE KEY active_slot (active_slot),
KEY status_expiry (status,expires_gmt),
KEY user_id (user_id)
) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$wpdb->last_error = '';
		\dbDelta( $statement );
		if ( ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Unable to create Cybermaps MCP task storage.' );
		}
	}

	public static function drop_tables(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_tasks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	/**
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>
	 */
	public function create( string $task_id, string $task_type, int $user_id, string $client_id, array $request, int $ttl = 3600 ): array {
		$this->assert_task_id( $task_id );
		$this->assert_task_type( $task_type );
		if ( $user_id < 1 || '' === $client_id || strlen( $client_id ) > 191 ) {
			throw new \InvalidArgumentException( 'A valid user and client are required for an MCP task.' );
		}
		$payload = wp_json_encode( $request );
		if ( ! is_string( $payload ) || strlen( $payload ) > 65535 ) {
			throw new \InvalidArgumentException( 'The MCP task request is too large.' );
		}
		$ttl = max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) );
		$now = time();

		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_tasks';
		$data  = array(
			'task_id'      => $task_id,
			'task_type'    => $task_type,
			'active_slot'  => $task_type,
			'status'       => 'pending',
			'user_id'      => $user_id,
			'client_id'    => $client_id,
			'request_json' => $payload,
			'result_json'  => '{}',
			'error_code'   => '',
			'created_gmt'  => $this->gmt( $now ),
			'updated_gmt'  => $this->gmt( $now ),
			'expires_gmt'  => $this->gmt( $now + $ttl ),
		);
		if ( false === $wpdb->insert( $table, $data ) ) {
			if ( null !== $this->find_active( $task_type ) ) {
				throw new \LogicException( 'An MCP task of this type is already active.' );
			}
			throw new \RuntimeException( 'Unable to create the MCP task.' );
		}

		$created = $this->get( $task_id );
		if ( null === $created ) {
			throw new \RuntimeException( 'Unable to read the newly created MCP task.' );
		}
		return $created;
	}

	/** @return array<string, mixed>|null */
	public function get( string $task_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_tasks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE task_id = %s', $table, $task_id ), ARRAY_A );
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/**
	 * Update a task state. Terminal states clear the single-active-task slot.
	 *
	 * @param array<string, mixed> $result
	 */
	public function update( string $task_id, string $status, array $result = array(), string $error_code = '' ): bool {
		if ( ! in_array( $status, array( 'pending', 'running', 'complete', 'failed', 'cancelled' ), true ) ) {
			throw new \InvalidArgumentException( 'The MCP task status is invalid.' );
		}
		$result_json = wp_json_encode( $result );
		if ( ! is_string( $result_json ) || strlen( $result_json ) > 65535 || strlen( $error_code ) > 64 ) {
			throw new \InvalidArgumentException( 'The MCP task result is too large.' );
		}
		$terminal = in_array( $status, array( 'complete', 'failed', 'cancelled' ), true );
		$now      = time();

		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_tasks';
		$data  = array(
			'status'      => $status,
			'result_json' => $result_json,
			'error_code'  => $error_code,
			'updated_gmt' => $this->gmt( $now ),
		);
		if ( $terminal ) {
			$data['active_slot'] = '';
		}
		if ( 'cancelled' === $status ) {
			$data['cancelled_gmt'] = $this->gmt( $now );
		}
		return false !== $wpdb->update( $table, $data, array( 'task_id' => $task_id ) );
	}

	/** Cancel a pending or running task and release its active task-type slot. */
	public function cancel( string $task_id ): bool {
		$task = $this->get( $task_id );
		if ( null === $task ) {
			return false;
		}
		if ( in_array( $task['status'], array( 'complete', 'failed', 'cancelled' ), true ) ) {
			return 'cancelled' === $task['status'];
		}
		return $this->update( $task_id, 'cancelled' );
	}

	/** Delete terminal or expired task records in a bounded batch. */
	public function cleanup( int $limit = 100 ): int {
		$limit = max( 1, min( 500, $limit ) );
		$now   = $this->gmt( time() );

		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_tasks';
		$query = $wpdb->prepare(
			"DELETE FROM %i WHERE expires_gmt <= %s OR (active_slot = '' AND updated_gmt <= %s) ORDER BY id ASC LIMIT %d",
			$table,
			$now,
			$now,
			$limit
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The complete bounded statement is prepared immediately above.
		$deleted = $wpdb->query( $query );
		return false === $deleted ? 0 : (int) $deleted;
	}

	/** @return array<string, mixed>|null */
	private function find_active( string $task_type ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_tasks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE active_slot = %s', $table, $task_type ), ARRAY_A );
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	private function assert_task_id( string $task_id ): void {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/D', $task_id ) ) {
			throw new \InvalidArgumentException( 'The MCP task ID is invalid.' );
		}
	}

	private function assert_task_type( string $task_type ): void {
		if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/D', $task_type ) ) {
			throw new \InvalidArgumentException( 'The MCP task type is invalid.' );
		}
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function decode( array $row ): array {
		$row['user_id'] = (int) $row['user_id'];
		$request        = json_decode( (string) $row['request_json'], true );
		$result         = json_decode( (string) $row['result_json'], true );
		$row['request'] = is_array( $request ) ? $request : array();
		$row['result']  = is_array( $result ) ? $result : array();
		return $row;
	}

	private function gmt( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
