<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\OptionLeaseLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable single-writer journal for the file-move/ownership-checkpoint gap.
 *
 * Typed write intents describe an atomic move; typed delete intents are
 * tombstones created before filesystem deletion. A valid journal supplies
 * recovery evidence, but stale-lease takeover remains operator-confirmed
 * because WP_Filesystem cannot enforce a database fencing token.
 */
final class StaticWriteIntentStore {
	public const OPTION               = 'cybermaps_static_write_intent';
	public const SCHEMA               = 2;
	private const LEGACY_WRITE_SCHEMA = 1;

	/** @var object|null Unique marker for a failed direct database read. */
	private static ?object $read_failure = null;
	private ?OptionLeaseLock $lease_lock;
	private bool $allow_unfenced_test_mutations;

	public function __construct(
		?OptionLeaseLock $lease_lock = null,
		bool $allow_unfenced_test_mutations = false
	) {
		$this->lease_lock                    = $lease_lock;
		$this->allow_unfenced_test_mutations = $allow_unfenced_test_mutations
			&& \defined( 'CYBERMAPS_PHPUNIT' )
			&& true === CYBERMAPS_PHPUNIT;
	}

	/**
	 * Persist a new intent only while no unresolved intent exists.
	 *
	 * @return array<string,mixed>|null Exact persisted record, or null on failure.
	 */
	public function create(
		string $filename,
		bool $old_exists,
		string $old_hash,
		int $old_generation,
		string $new_hash,
		int $generation,
		int $epoch,
		string $lease_token
	): ?array {
		$fence = $this->mutation_fence( $lease_token );
		if ( ! $this->allow_unfenced_test_mutations && null === $fence ) {
			return null;
		}

		$record = array(
			'schema'         => self::SCHEMA,
			'operation'      => 'write',
			'intent_id'      => \wp_generate_password( 32, false, false ),
			'filename'       => StaticOwnershipStore::normalize_path( $filename ),
			'old_exists'     => $old_exists,
			'old_hash'       => \strtolower( $old_hash ),
			'old_generation' => max( -1, $old_generation ),
			'new_hash'       => \strtolower( $new_hash ),
			'generation'     => max( 0, $generation ),
			'epoch'          => max( 0, $epoch ),
			'lease_digest'   => \hash( 'sha256', $lease_token ),
			'created_at'     => \time(),
		);
		if ( ! self::is_valid_record( $record ) || ! $this->insert_if_absent( $record, $fence ) ) {
			return null;
		}

		$stored = $this->read();
		return \is_array( $stored ) && $stored === $record ? $record : null;
	}

	/**
	 * Persist a deletion tombstone before removing an owned publication.
	 *
	 * @return array<string,mixed>|null Exact persisted record, or null on failure.
	 */
	public function create_delete(
		string $filename,
		string $old_hash,
		int $old_generation,
		int $generation,
		int $epoch,
		string $lease_token
	): ?array {
		$fence = $this->mutation_fence( $lease_token );
		if ( ! $this->allow_unfenced_test_mutations && null === $fence ) {
			return null;
		}

		$record = array(
			'schema'         => self::SCHEMA,
			'operation'      => 'delete',
			'intent_id'      => \wp_generate_password( 32, false, false ),
			'filename'       => StaticOwnershipStore::normalize_path( $filename ),
			'old_exists'     => true,
			'old_hash'       => \strtolower( $old_hash ),
			'old_generation' => max( -1, $old_generation ),
			'new_hash'       => '',
			'generation'     => max( 0, $generation ),
			'epoch'          => max( 0, $epoch ),
			'lease_digest'   => \hash( 'sha256', $lease_token ),
			'created_at'     => \time(),
		);
		if ( ! self::is_valid_record( $record ) || ! $this->insert_if_absent( $record, $fence ) ) {
			return null;
		}

		$stored = $this->read();
		return \is_array( $stored ) && $stored === $record ? $record : null;
	}

	/**
	 * Read and strictly validate the current intent.
	 *
	 * @return array<string,mixed>|null|false Record, null when absent, false on
	 *                                           malformed storage/read failure.
	 */
	public function read(): array|null|false {
		$stored = self::read_uncached();
		if ( self::is_read_failure( $stored ) ) {
			return false;
		}
		if ( null === $stored ) {
			return null;
		}

		return self::is_valid_record( $stored ) ? $stored : false;
	}

	/**
	 * Return a bounded, body-free status suitable for an admin or CLI adapter.
	 *
	 * @return array{status:string,schema:int,type:string,intent_id:string,path:string,created_at:int}
	 */
	public function describe(): array {
		$record = $this->read();
		if ( null === $record ) {
			return array(
				'status'     => 'none',
				'schema'     => 0,
				'type'       => '',
				'intent_id'  => '',
				'path'       => '',
				'created_at' => 0,
			);
		}
		if ( false === $record ) {
			return array(
				'status'     => 'malformed',
				'schema'     => 0,
				'type'       => '',
				'intent_id'  => '',
				'path'       => '',
				'created_at' => 0,
			);
		}

		return array(
			'status'     => 'pending',
			'schema'     => (int) $record['schema'],
			'type'       => self::operation( $record ),
			'intent_id'  => (string) $record['intent_id'],
			'path'       => (string) $record['filename'],
			'created_at' => (int) $record['created_at'],
		);
	}

	/**
	 * Prove the exact intent is current while this database-fenced lease is live.
	 *
	 * A successor recovering a prior lease may skip only the lease-digest match;
	 * it must still hold its own database fence and match the exact journal row.
	 *
	 * @param array<string,mixed> $expected Exact record returned by read/create.
	 */
	public function is_current_exact( array $expected, bool $recover_prior_lease = false ): bool {
		if ( ! self::is_valid_record( $expected ) ) {
			return false;
		}

		$active_token = null === $this->lease_lock ? null : $this->lease_lock->get_token();
		$fence        = $this->mutation_fence();
		if ( ! $this->can_mutate_record( $expected, $recover_prior_lease, $active_token, $fence ) ) {
			return false;
		}

		return $this->read() === $expected;
	}

	/**
	 * Remove only the exact observed intent so a stale owner cannot erase a
	 * successor's journal entry.
	 *
	 * @param array<string,mixed> $expected Exact record returned by read/create.
	 */
	public function delete_exact( array $expected, bool $recover_prior_lease = false ): bool {
		if ( ! self::is_valid_record( $expected ) ) {
			return false;
		}

		$active_token = null === $this->lease_lock ? null : $this->lease_lock->get_token();
		$fence        = $this->mutation_fence();
		if ( ! $this->allow_unfenced_test_mutations ) {
			if ( null === $fence || null === $active_token ) {
				return false;
			}
			if ( ! $recover_prior_lease && ! self::matches_lease_token( $expected, $active_token ) ) {
				return false;
			}
		}

		$observation = $this->read_observation();
		if ( $observation['value'] !== $expected ) {
			return false;
		}
		if ( $observation['direct'] ) {
			return $this->delete_direct_observation( $observation['raw'], $fence );
		}

		if ( ! \delete_option( self::OPTION ) ) {
			return false;
		}
		return null === self::read_uncached();
	}

	/**
	 * @param array<string,mixed> $expected Exact record.
	 * @param array{name:string,connection_id:int}|null $fence Database fence.
	 */
	private function can_mutate_record(
		array $expected,
		bool $recover_prior_lease,
		?string $active_token,
		?array $fence
	): bool {
		if ( $this->allow_unfenced_test_mutations ) {
			return true;
		}
		if ( null === $fence || null === $active_token ) {
			return false;
		}
		return $recover_prior_lease || self::matches_lease_token( $expected, $active_token );
	}

	/**
	 * @param array{name:string,connection_id:int}|null $fence Database fence.
	 */
	private function delete_direct_observation( mixed $raw, ?array $fence ): bool {
		if ( ! \is_string( $raw ) ) {
			return false;
		}
		global $wpdb;
		if ( null !== $fence ) {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s AND IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d',
					$wpdb->options,
					self::OPTION,
					$raw,
					$fence['name'],
					$fence['connection_id']
				)
			);
		} else {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s',
					$wpdb->options,
					self::OPTION,
					$raw
				)
			);
		}
		if ( 1 !== (int) $deleted ) {
			return false;
		}
		self::invalidate_cache();
		return null === self::read_uncached();
	}

	/**
	 * Confirm that an intent was prepared by the supplied option-lease token.
	 *
	 * @param array<string,mixed> $record Candidate intent record.
	 */
	public static function matches_lease_token( array $record, string $lease_token ): bool {
		return '' !== $lease_token
			&& self::is_valid_record( $record )
			&& \hash_equals( (string) $record['lease_digest'], \hash( 'sha256', $lease_token ) );
	}

	/**
	 * Return the typed operation represented by a validated intent.
	 *
	 * Schema 1 existed before typed deletion tombstones and always means write.
	 *
	 * @param array<string,mixed> $record Candidate intent record.
	 */
	public static function operation( array $record ): string {
		if ( ! self::is_valid_record( $record ) ) {
			return '';
		}

		return self::LEGACY_WRITE_SCHEMA === (int) $record['schema']
			? 'write'
			: (string) $record['operation'];
	}

	/**
	 * @param mixed $record Candidate record.
	 */
	private static function is_valid_record( mixed $record ): bool {
		if ( ! \is_array( $record ) ) {
			return false;
		}
		$schema   = $record['schema'] ?? null;
		$expected = array(
			'created_at',
			'epoch',
			'filename',
			'generation',
			'intent_id',
			'lease_digest',
			'new_hash',
			'old_exists',
			'old_generation',
			'old_hash',
			'schema',
		);
		if ( self::SCHEMA === $schema ) {
			$expected[] = 'operation';
		} elseif ( self::LEGACY_WRITE_SCHEMA !== $schema ) {
			return false;
		}
		$keys = \array_keys( $record );
		\sort( $keys, SORT_STRING );
		\sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			return false;
		}

		$filename  = $record['filename'];
		$old_hash  = $record['old_hash'];
		$operation = self::LEGACY_WRITE_SCHEMA === $schema ? 'write' : $record['operation'];
		return \in_array( $operation, array( 'write', 'delete' ), true )
			&& \is_string( $record['intent_id'] )
			&& '' !== $record['intent_id']
			&& \strlen( $record['intent_id'] ) <= 128
			&& \is_string( $filename )
			&& '' !== $filename
			&& \strlen( $filename ) <= StaticOwnershipStore::MAX_PATH_LENGTH
			&& StaticOwnershipStore::normalize_path( $filename ) === $filename
			&& \is_bool( $record['old_exists'] )
			&& \is_string( $old_hash )
			&& ( $record['old_exists'] ? 1 === \preg_match( '/^[a-f0-9]{32}$/', $old_hash ) : '' === $old_hash )
			&& \is_int( $record['old_generation'] )
			&& $record['old_generation'] >= -1
			&& \is_string( $record['new_hash'] )
			&& (
				( 'write' === $operation && 1 === \preg_match( '/^[a-f0-9]{32}$/', $record['new_hash'] ) )
				|| ( 'delete' === $operation && '' === $record['new_hash'] && true === $record['old_exists'] )
			)
			&& \is_int( $record['generation'] )
			&& $record['generation'] >= 0
			&& \is_int( $record['epoch'] )
			&& $record['epoch'] >= 0
			&& \is_string( $record['lease_digest'] )
			&& 1 === \preg_match( '/^[a-f0-9]{64}$/', $record['lease_digest'] )
			&& \is_int( $record['created_at'] )
			&& $record['created_at'] > 0
			&& $record['created_at'] <= \time() + 300;
	}

	/**
	 * @param array<string,mixed> $record Validated record.
	 */
	private function insert_if_absent( array $record, ?array $fence ): bool {
		global $wpdb;
		if (
			\is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' )
			&& \method_exists( $wpdb, 'query' )
			&& \method_exists( $wpdb, 'get_var' )
		) {
			if ( null !== $fence ) {
				$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d',
						$wpdb->options,
						self::OPTION,
						self::serialize_value( $record ),
						'off',
						$fence['name'],
						$fence['connection_id']
					)
				);
			} else {
				$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
						$wpdb->options,
						self::OPTION,
						self::serialize_value( $record ),
						'off'
					)
				);
			}
			if ( 1 !== (int) $inserted ) {
				return false;
			}
			self::invalidate_cache();
			return true;
		}

		return (bool) \add_option( self::OPTION, $record, '', false );
	}

	/**
	 * Return a live database-session fence for the active lease.
	 *
	 * @return array{name:string,connection_id:int}|null
	 */
	private function mutation_fence( ?string $expected_token = null ): ?array {
		if ( null === $this->lease_lock || $this->lease_lock->is_lost() ) {
			return null;
		}

		$active_token = $this->lease_lock->get_token();
		if (
			null === $active_token
			|| ( null !== $expected_token && ! \hash_equals( $active_token, $expected_token ) )
		) {
			return null;
		}
		if ( ! $this->lease_lock->requires_database_fence() ) {
			return null;
		}

		$fence = $this->lease_lock->get_database_fence();
		if (
			! \is_array( $fence )
			|| ! \is_string( $fence['name'] ?? null )
			|| '' === $fence['name']
			|| ! \is_int( $fence['connection_id'] ?? null )
			|| $fence['connection_id'] < 1
		) {
			return null;
		}

		return array(
			'name'          => $fence['name'],
			'connection_id' => $fence['connection_id'],
		);
	}

	/**
	 * @return array{value:mixed,raw:string|null,direct:bool}
	 */
	private function read_observation(): array {
		global $wpdb;
		if (
			\is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' )
			&& \method_exists( $wpdb, 'get_var' )
			&& \method_exists( $wpdb, 'query' )
		) {
			$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
					$wpdb->options,
					self::OPTION
				)
			);
			if ( false === $raw || ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) ) {
				return array(
					'value'  => self::read_failure_marker(),
					'raw'    => null,
					'direct' => true,
				);
			}
			return array(
				'value'  => null === $raw ? null : \maybe_unserialize( $raw ),
				'raw'    => \is_string( $raw ) ? $raw : null,
				'direct' => true,
			);
		}

		return array(
			'value'  => \get_option( self::OPTION, null ),
			'raw'    => null,
			'direct' => false,
		);
	}

	private static function read_uncached(): mixed {
		$store = new self();
		return $store->read_observation()['value'];
	}

	private static function read_failure_marker(): object {
		if ( null === self::$read_failure ) {
			self::$read_failure = new \stdClass();
		}
		return self::$read_failure;
	}

	private static function is_read_failure( mixed $value ): bool {
		return null !== self::$read_failure && $value === self::$read_failure;
	}

	private static function serialize_value( mixed $value ): string {
		return \function_exists( 'maybe_serialize' )
			? (string) \maybe_serialize( $value )
			: \serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	private static function invalidate_cache(): void {
		if ( ! \function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		\wp_cache_delete( self::OPTION, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
		\wp_cache_delete( 'alloptions', 'options' );
	}
}
