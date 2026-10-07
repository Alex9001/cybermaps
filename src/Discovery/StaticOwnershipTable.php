<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exact per-path ownership rows behind the store's prepared, fenced SQL sink.
 *
 * @internal All query templates are defined here; callers supply typed values.
 */
final class StaticOwnershipTable {
	public const SUFFIX    = 'cybermaps_static_ownership';
	public const PAGE_SIZE = 100;

	/** @var \Closure(string,array,bool,bool):(array|int|false) */
	private \Closure $query;
	private string $table;

	/** @param callable(string,array,bool,bool):(array|int|false) $query Prepared query executor. */
	public function __construct( string $table, callable $query ) {
		$this->table = $table;
		$this->query = \Closure::fromCallable( $query );
	}

	/** Provision only while migrating, never on settled publication reads. */
	public function install(): bool {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		\dbDelta(
			"CREATE TABLE {$this->table} (
			path_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			path varbinary(4096) NOT NULL,
			body_hash char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			generation bigint unsigned NOT NULL,
			shard tinyint unsigned NOT NULL,
			PRIMARY KEY  (path_key),
			KEY shard_cursor (shard,path_key),
			KEY generation_cursor (generation,path_key)
			) " . $wpdb->get_charset_collate()
		);
		$columns = ( $this->query )( 'SHOW COLUMNS FROM %i', array( $this->table ), true, false );
		$indexes = ( $this->query )( 'SHOW INDEX FROM %i', array( $this->table ), true, false );
		if ( ! is_array( $columns ) || ! is_array( $indexes ) ) {
			return false;
		}
		return $this->valid_columns( $columns ) && $this->valid_indexes( $indexes );
	}

	private function valid_columns( array $columns ): bool {
		$expected = array(
			'path_key'   => '/^char\(64\)$/',
			'path'       => '/^varbinary\(4096\)$/',
			'body_hash'  => '/^char\(32\)$/',
			'generation' => '/^bigint(?:\([0-9]+\))? unsigned$/',
			'shard'      => '/^tinyint(?:\([0-9]+\))? unsigned$/',
		);
		if ( array_keys( $expected ) !== array_column( $columns, 'Field' ) ) {
			return false;
		}
		foreach ( $columns as $column ) {
			if ( 'NO' !== ( $column['Null'] ?? null ) || 1 !== preg_match( $expected[ $column['Field'] ], strtolower( (string) ( $column['Type'] ?? '' ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	private function valid_indexes( array $indexes ): bool {
		$actual = array();
		foreach ( $indexes as $index ) {
			$actual[ (string) ( $index['Key_name'] ?? '' ) ][ (int) ( $index['Seq_in_index'] ?? 0 ) ] = (string) ( $index['Column_name'] ?? '' );
		}
		foreach ( array(
			'PRIMARY'           => array( 1 => 'path_key' ),
			'shard_cursor'      => array(
				1 => 'shard',
				2 => 'path_key',
			),
			'generation_cursor' => array(
				1 => 'generation',
				2 => 'path_key',
			),
		) as $key => $columns ) {
			if ( ( $actual[ $key ] ?? null ) !== $columns ) {
				return false;
			}
		}
		return true;
	}

	/** @return array{hash:string,generation:int}|null|false */
	public function read( string $path, bool $fenced = false ): array|null|false {
		$rows = ( $this->query )( 'SELECT path_key, path, body_hash, generation, shard FROM %i WHERE path_key = %s LIMIT 1', array( $this->table, hash( 'sha256', $path ) ), true, $fenced );
		if ( false === $rows ) {
			return false;
		}
		if ( array() === $rows ) {
			return null;
		}
		$row = $rows[0];
		if ( ! self::valid_row( $row ) || $path !== $row['path'] ) {
			return false;
		}
		return array(
			'hash'       => $row['body_hash'],
			'generation' => (int) $row['generation'],
		);
	}

	/** @return array<int,array{key:string,path:string,hash:string,generation:int}>|false */
	public function page( int $shard, string $after = '', int $limit = self::PAGE_SIZE ): array|false {
		$limit = max( 1, min( self::PAGE_SIZE, $limit ) );
		$rows  = $shard < 0
			? ( $this->query )( 'SELECT path_key, path, body_hash, generation, shard FROM %i WHERE path_key > %s ORDER BY path_key LIMIT %d', array( $this->table, $after, $limit ), true, false )
			: ( $this->query )( 'SELECT path_key, path, body_hash, generation, shard FROM %i WHERE shard = %d AND path_key > %s ORDER BY path_key LIMIT %d', array( $this->table, $shard, $after, $limit ), true, false );
		if ( false === $rows ) {
			return false;
		}
		$records = array();
		foreach ( $rows as $row ) {
			if ( ! self::valid_row( $row ) || ( $shard >= 0 && (int) $row['shard'] !== $shard ) ) {
				return false;
			}
			$records[] = array(
				'key'        => $row['path_key'],
				'path'       => $row['path'],
				'hash'       => $row['body_hash'],
				'generation' => (int) $row['generation'],
			);
		}
		return $records;
	}

	/** @param array{hash:string,generation:int}|null $prior @param array{hash:string,generation:int}|null $next */
	public function replace( string $path, ?array $prior, ?array $next ): bool {
		if ( null === $prior && null === $next ) {
			return false; // An absent expected row is not a deletion CAS.
		}
		$changed = null === $prior ? $this->insert( $path, $next ) : $this->change( $path, $prior, $next );
		if ( null === $next && 1 !== $changed ) {
			return false;
		}
		return false !== $changed && $this->read( $path, true ) === $next;
	}

	/** @param array{hash:string,generation:int} $next */
	private function insert( string $path, array $next ): int|false {
		return ( $this->query )(
			'INSERT IGNORE INTO %i (path_key,path,body_hash,generation,shard) SELECT %s,%s,%s,%d,%d WHERE 1=1',
			array( $this->table, hash( 'sha256', $path ), $path, $next['hash'], $next['generation'], StaticOwnershipStore::shard_for_path( $path ) ),
			false,
			true
		);
	}

	/** @param array{hash:string,generation:int} $prior @param array{hash:string,generation:int}|null $next */
	private function change( string $path, array $prior, ?array $next ): int|false {
		$identity = array( hash( 'sha256', $path ), $path, $prior['hash'], $prior['generation'] );
		if ( null === $next ) {
			return ( $this->query )( 'DELETE FROM %i WHERE path_key = %s AND path = %s AND body_hash = %s AND generation = %d', array_merge( array( $this->table ), $identity ), false, true );
		}
		return ( $this->query )( 'UPDATE %i SET body_hash = %s, generation = %d WHERE path_key = %s AND path = %s AND body_hash = %s AND generation = %d', array_merge( array( $this->table, $next['hash'], $next['generation'] ), $identity ), false, true );
	}

	private static function valid_row( array $row ): bool {
		$path = $row['path'] ?? null;
		if ( ! is_string( $path ) || '' === $path || strlen( $path ) > StaticOwnershipStore::MAX_PATH_LENGTH || StaticOwnershipStore::normalize_path( $path ) !== $path ) {
			return false;
		}
		return hash( 'sha256', $path ) === ( $row['path_key'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{32}$/D', (string) ( $row['body_hash'] ?? '' ) )
			&& self::valid_generation( $row['generation'] ?? null )
			&& StaticOwnershipStore::shard_for_path( $path ) === (int) ( $row['shard'] ?? -1 );
	}

	private static function valid_generation( mixed $generation ): bool {
		return ( is_int( $generation ) || is_string( $generation ) ) && (string) (int) $generation === (string) $generation && (int) $generation >= 0;
	}
}
