<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\AtomicOptionSequence;
use Cybermaps\Core\OptionLeaseLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sharded ownership inventory for generated static files.
 */
final class StaticOwnershipStore {
	public const LEGACY_OPTION     = 'cybermaps_static_hashes';
	public const SCHEMA_OPTION     = 'cybermaps_static_hashes_schema';
	public const SCHEMA_VERSION    = 2;
	public const SHARD_COUNT       = 64;
	public const MAX_PATH_LENGTH   = 4096;
	public const REPAIR_OPTION     = 'cybermaps_static_ownership_repair_pending';
	public const REPAIR_ACK_OPTION = 'cybermaps_static_ownership_repair_ack';

	private const HEARTBEAT_BATCH = 250;

	private const REVISION_OPTION   = 'cybermaps_static_ownership_revision';
	private const SYNC_EPOCH_OPTION = 'cybermaps_static_sync_epoch';

	/** @var array<string,string>|null Request-local flattened path => hash map. */
	private ?array $local_hashes = null;

	/** @var array<int,array<string,array{hash:string,generation:int}>> */
	private array $loaded_shards = array();

	/** @var array<int,array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool}> */
	private array $shard_observations = array();
	/** @var array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool}|null */
	private ?array $legacy_observation = null;
	/** @var array<int,true> Shards changed by another writer after this instance's CAS. */
	private array $conflicted_shards = array();
	/** @var array<int,true> Shards whose raw storage failed strict validation. */
	private array $invalid_shards = array();
	private bool $invalid_legacy  = false;
	private ?int $schema_cache    = null;

	/** @var array<int,true> */
	private array $dirty_shards = array();

	private bool $local_dirty = false;

	private static bool $hooks_registered = false;

	/** @var object|null Unique request-local marker for a failed direct option read. */
	private static ?object $read_failure = null;

	/**
	 * Lease whose database advisory-lock connection fences ownership mutations.
	 *
	 * A null lease keeps the store read-only in production. The PHPUnit harness
	 * deliberately permits no-argument instances so isolated storage tests do
	 * not need to manufacture a database connection.
	 */
	private ?OptionLeaseLock $lease_lock;

	public function __construct( ?OptionLeaseLock $lease_lock = null ) {
		$this->lease_lock = $lease_lock;
	}

	public static function register_hooks(): void {
		$read_hook     = 'pre_option_' . self::LEGACY_OPTION;
		$read_callback = array( self::class, 'filter_legacy_option_read' );
		if ( ! self::is_filter_registered( $read_hook, $read_callback ) ) {
			\add_filter( $read_hook, $read_callback, 10, 1 );
		}

		$write_hook     = 'pre_update_option_' . self::LEGACY_OPTION;
		$write_callback = array( self::class, 'filter_legacy_option_write' );
		if ( ! self::is_filter_registered( $write_hook, $write_callback ) ) {
			\add_filter( $write_hook, $write_callback, 10, 3 );
		}
		self::$hooks_registered = true;
	}

	/**
	 * @param array{0:class-string|object,1:string} $callback
	 */
	private static function is_filter_registered( string $hook, array $callback ): bool {
		if ( \function_exists( 'has_filter' ) ) {
			return false !== \has_filter( $hook, $callback );
		}

		foreach ( $GLOBALS['wp_hooks'] ?? array() as $registered ) {
			if (
				'filter' === ( $registered['type'] ?? '' )
				&& ( $registered['hook'] ?? '' ) === $hook
				&& ( $registered['callback'] ?? null ) === $callback
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Serve flattened path => hash reads when schema 2 shards are authoritative.
	 *
	 * @param mixed $pre_option Existing pre-option value.
	 * @return mixed
	 */
	public static function filter_legacy_option_read( $pre_option ) {
		if ( false !== $pre_option ) {
			return $pre_option;
		}

		if ( self::SCHEMA_VERSION !== self::current_schema() ) {
			return $pre_option;
		}

		$instance = new self();
		return $instance->read_flat_hashes();
	}

	/**
	 * Keep the schema-2 compatibility option read-only.
	 *
	 * Writes through the retired monolithic option cannot be made atomic with the
	 * sharded inventory. Returning the prior filtered value prevents creation of
	 * a hidden shadow row while Core owns schema 2.
	 *
	 * @param mixed $value New value.
	 * @param mixed $old_value Previous value.
	 * @param mixed $option Option name.
	 * @return mixed
	 */
	public static function filter_legacy_option_write( $value, $old_value, $option ) {
		unset( $option );
		return 0 === self::current_schema() ? $value : $old_value;
	}

	public static function current_schema(): int {
		$stored = self::read_uncached_option( self::SCHEMA_OPTION, 0 );
		if ( \is_int( $stored ) ) {
			return $stored >= 0 ? $stored : -1;
		}
		if ( \is_string( $stored ) && 1 === \preg_match( '/^(?:0|[1-9][0-9]*)$/D', $stored ) ) {
			return (int) $stored;
		}

		return -1;
	}

	public static function shard_option_name( int $shard ): string {
		return \sprintf( 'cybermaps_static_hashes_%02x', max( 0, min( self::SHARD_COUNT - 1, $shard ) ) );
	}

	public static function shard_for_path( string $path ): int {
		$normalized = self::normalize_path( $path );
		$digest     = \hash( 'sha256', $normalized, true );

		return \ord( $digest[0] ) % self::SHARD_COUNT;
	}

	public static function normalize_path( string $path ): string {
		return \str_replace( '\\', '/', $path );
	}

	/**
	 * @return array<string,string>
	 */
	public function read_flat_hashes(): array {
		if ( null !== $this->local_hashes ) {
			return $this->local_hashes;
		}

		$schema = $this->schema();
		if ( 0 === $schema ) {
			$this->legacy_observation = self::read_uncached_observation( self::LEGACY_OPTION );
			$legacy                   = $this->legacy_observation['exists']
				? $this->legacy_observation['value']
				: array();
			$this->invalid_legacy     = $this->legacy_observation['failed']
				|| ! self::validate_legacy_storage( $legacy );
			$this->local_hashes       = $this->invalid_legacy ? array() : $this->flatten_legacy_hashes( $legacy );
			return $this->local_hashes;
		}
		if ( self::SCHEMA_VERSION !== $schema ) {
			$this->local_hashes = array();
			return $this->local_hashes;
		}

		$flat = array();
		for ( $shard = 0; $shard < self::SHARD_COUNT; ++$shard ) {
			foreach ( $this->load_shard( $shard ) as $path => $record ) {
				$flat[ $path ] = (string) ( $record['hash'] ?? '' );
			}
		}
		if ( ! empty( $this->invalid_shards ) ) {
			$this->local_hashes = array();
			return $this->local_hashes;
		}
		$this->local_hashes = $flat;
		return $this->local_hashes;
	}

	public function get_hash( string $path ): ?string {
		$record = $this->get_record( $path );
		$hash   = $record['hash'] ?? null;

		return \is_string( $hash ) && 1 === \preg_match( '/^[a-f0-9]{32}$/i', $hash ) ? \strtolower( $hash ) : null;
	}

	/**
	 * Return the publication epoch in which a path was most recently verified.
	 */
	public function get_generation( string $path ): int {
		$record = $this->get_record( $path );
		return null === $record ? -1 : max( 0, (int) $record['generation'] );
	}

	/**
	 * Stage one ownership record without rebuilding unrelated shards.
	 */
	public function set_hash(
		string $path,
		string $hash,
		int $generation,
		bool $force_flush = false,
		bool $bump_revision = true
	): bool {
		$path = self::normalize_path( $path );
		$hash = \strtolower( $hash );
		if ( '' === $path || \strlen( $path ) > self::MAX_PATH_LENGTH || 1 !== \preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			return false;
		}

		$schema = $this->schema();
		if ( 0 === $schema ) {
			$hashes = $this->read_flat_hashes();
			if ( $this->invalid_legacy ) {
				return false;
			}
			if ( isset( $hashes[ $path ] ) && \hash_equals( $hash, (string) $hashes[ $path ] ) ) {
				return ! $force_flush || $this->flush( $generation, true, $bump_revision );
			}
			$hashes[ $path ]    = $hash;
			$this->local_hashes = $hashes;
			$this->local_dirty  = true;
			if ( $force_flush ) {
				return $this->flush( $generation, true, $bump_revision );
			}
			return true;
		}
		if ( self::SCHEMA_VERSION !== $schema ) {
			return false;
		}

		$shard   = self::shard_for_path( $path );
		$records = $this->load_shard( $shard );
		if ( isset( $this->invalid_shards[ $shard ] ) ) {
			return false;
		}
		$next = array(
			'hash'       => $hash,
			'generation' => max( 0, $generation ),
		);
		if ( isset( $records[ $path ] ) && $next === $records[ $path ] ) {
			return ! $force_flush || $this->flush( $generation, true, $bump_revision );
		}

		$records[ $path ]              = $next;
		$this->loaded_shards[ $shard ] = $records;
		$this->dirty_shards[ $shard ]  = true;
		$this->local_dirty             = true;
		if ( null !== $this->local_hashes ) {
			$this->local_hashes[ $path ] = $hash;
		}

		return ! $force_flush || $this->flush( $generation, true, $bump_revision );
	}

	/**
	 * Mark a verified path as visited without changing its ownership hash.
	 */
	public function mark_seen( string $path, int $generation, bool $force_flush = false ): bool {
		$record = $this->get_record( $path );
		if ( null === $record ) {
			return false;
		}

		return $this->set_hash( $path, $record['hash'], $generation, $force_flush );
	}

	/**
	 * Stage removal of one ownership record.
	 */
	public function delete_hash( string $path, bool $force_flush = false, bool $bump_revision = true ): bool {
		$path = self::normalize_path( $path );
		if ( '' === $path || \strlen( $path ) > self::MAX_PATH_LENGTH ) {
			return false;
		}
		$schema = $this->schema();
		if ( 0 === $schema ) {
			$hashes = $this->read_flat_hashes();
			if ( $this->invalid_legacy ) {
				return false;
			}
			if ( ! array_key_exists( $path, $hashes ) ) {
				return ! $force_flush || $this->flush( 0, true, $bump_revision );
			}
			unset( $hashes[ $path ] );
			$this->local_hashes = $hashes;
			$this->local_dirty  = true;
			return ! $force_flush || $this->flush( 0, true, $bump_revision );
		}
		if ( self::SCHEMA_VERSION !== $schema ) {
			return false;
		}

		$shard   = self::shard_for_path( $path );
		$records = $this->load_shard( $shard );
		if ( isset( $this->invalid_shards[ $shard ] ) ) {
			return false;
		}
		if ( ! array_key_exists( $path, $records ) ) {
			return ! $force_flush || $this->flush( 0, true, $bump_revision );
		}

		unset( $records[ $path ] );
		$this->loaded_shards[ $shard ] = $records;
		$this->dirty_shards[ $shard ]  = true;
		$this->local_dirty             = true;
		if ( null !== $this->local_hashes ) {
			unset( $this->local_hashes[ $path ] );
		}

		return ! $force_flush || $this->flush( 0, true, $bump_revision );
	}

	/**
	 * @param array<string,string> $hashes Flat path => hash inventory.
	 */
	public function stage_hashes( array $hashes, int $generation, bool $force_flush = false ): bool {
		$schema = $this->schema();
		if ( ! \in_array( $schema, array( 0, self::SCHEMA_VERSION ), true ) ) {
			return false;
		}
		$normalized = self::normalize_staged_hashes( $hashes );
		if ( null === $normalized ) {
			return false;
		}

		$current = $this->read_flat_hashes();
		if ( $this->invalid_legacy || ! empty( $this->invalid_shards ) ) {
			return false;
		}
		if ( ! $this->remove_absent_staged_hashes( $current, $normalized ) || ! $this->stage_normalized_hashes( $normalized, $generation ) ) {
			return false;
		}
		$this->local_hashes = $normalized;
		return ! $force_flush || $this->flush( $generation );
	}

	/** @return array<string,string>|null */
	private static function normalize_staged_hashes( array $hashes ): ?array {
		$normalized = array();
		foreach ( $hashes as $path => $hash ) {
			if ( ! \is_string( $path ) || ! \is_string( $hash ) || 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $hash ) ) {
				return null;
			}
			$path = self::normalize_path( $path );
			if ( '' === $path || \strlen( $path ) > self::MAX_PATH_LENGTH || \array_key_exists( $path, $normalized ) ) {
				return null;
			}
			$normalized[ $path ] = \strtolower( $hash );
		}
		return $normalized;
	}

	/** @param array<string,string> $current @param array<string,string> $normalized */
	private function remove_absent_staged_hashes( array $current, array $normalized ): bool {
		foreach ( $current as $path => $hash ) {
			if ( ! \array_key_exists( $path, $normalized ) && ! $this->delete_hash( $path ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,string> $hashes */
	private function stage_normalized_hashes( array $hashes, int $generation ): bool {
		foreach ( $hashes as $path => $hash ) {
			$record = $this->get_record( $path );
			$seen   = null !== $record && \hash_equals( $hash, $record['hash'] ) ? $record['generation'] : $generation;
			if ( ! $this->set_hash( $path, $hash, $seen ) ) {
				return false;
			}
		}
		return true;
	}

	public function flush( int $generation = 0, bool $standalone_write = false, bool $bump_revision = true ): bool {
		if ( ! $this->local_dirty ) {
			return true;
		}
		if ( false === $this->mutation_fence() ) {
			return false;
		}

		$flushed = 0 === $this->schema()
			? $this->flush_legacy_hashes( $bump_revision )
			: $this->flush_sharded_hashes( $bump_revision );
		if ( ! $flushed ) {
			return false;
		}
		if ( $standalone_write ) {
			$this->local_hashes  = null;
			$this->loaded_shards = array();
			$this->dirty_shards  = array();
		}

		return true;
	}

	private function flush_legacy_hashes( bool $bump_revision ): bool {
		if ( $this->invalid_legacy ) {
			return false;
		}
		$hashes      = $this->local_hashes ?? array();
		$observation = $this->legacy_observation ?? self::read_uncached_observation( self::LEGACY_OPTION );
		$fence       = $this->mutation_fence();
		if ( false === $fence || $observation['failed'] || ! self::compare_and_swap_option_value( self::LEGACY_OPTION, $observation, $hashes, empty( $hashes ), $fence ) ) {
			$this->bump_revision();
			return false;
		}
		$verified = self::read_uncached_observation( self::LEGACY_OPTION );
		$stored   = $verified['exists'] ? $verified['value'] : array();
		if ( $verified['failed'] || ! self::validate_legacy_storage( $stored ) || $this->flatten_legacy_hashes( $stored ) !== $hashes ) {
			$this->invalid_legacy = $verified['failed'] || ! self::validate_legacy_storage( $stored );
			$this->bump_revision();
			return false;
		}
		$this->legacy_observation = $verified;
		if ( $bump_revision && ! $this->bump_revision() ) {
			return false;
		}
		$this->local_dirty = false;
		return true;
	}

	private function flush_sharded_hashes( bool $bump_revision ): bool {
		if ( self::SCHEMA_VERSION !== $this->schema() ) {
			return false;
		}
		foreach ( \array_keys( $this->dirty_shards ) as $shard ) {
			if ( ! $this->write_shard( $shard, $this->loaded_shards[ $shard ] ?? array() ) ) {
				$this->bump_revision();
				return false;
			}
		}
		if ( $bump_revision && ! $this->bump_revision() ) {
			return false;
		}
		$this->local_dirty = false;
		return true;
	}

	public function clear_local_cache(): void {
		$this->local_hashes       = null;
		$this->loaded_shards      = array();
		$this->shard_observations = array();
		$this->legacy_observation = null;
		$this->conflicted_shards  = array();
		$this->invalid_shards     = array();
		$this->invalid_legacy     = false;
		$this->dirty_shards       = array();
		$this->local_dirty        = false;
		$this->schema_cache       = null;
	}

	/**
	 * Migrate legacy storage to schema 2 shards under an active static lease.
	 */
	public function migrate_if_needed( ?callable $heartbeat = null, bool $validate_current_schema = false ): bool {
		if ( false === $this->mutation_fence() ) {
			return false;
		}
		$schema_observation = self::read_uncached_observation( self::SCHEMA_OPTION );
		if ( $schema_observation['failed'] ) {
			return false;
		}
		$current_schema     = self::observed_schema( $schema_observation );
		$this->schema_cache = $current_schema;
		if ( self::SCHEMA_VERSION === $current_schema ) {
			return $this->finish_current_schema_migration( $heartbeat, $validate_current_schema );
		}
		// Never reinterpret a newer or unknown persisted contract as the legacy
		// monolithic map. A downgraded plugin must leave future ownership intact.
		if ( 0 !== $current_schema ) {
			return false;
		}
		return $this->migrate_legacy_schema( $schema_observation, $heartbeat );
	}

	private static function observed_schema( array $observation ): int {
		$value = $observation['exists'] ? $observation['value'] : 0;
		if ( \is_int( $value ) ) {
			return $value >= 0 ? $value : -1;
		}
		return \is_string( $value ) && 1 === \preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ? (int) $value : -1;
	}

	private static function heartbeat_is_healthy( ?callable $heartbeat ): bool {
		return null === $heartbeat || (bool) $heartbeat();
	}

	private function finish_current_schema_migration( ?callable $heartbeat, bool $validate ): bool {
		if ( $validate && ! $this->all_persisted_shards_valid( $heartbeat ) ) {
			return false;
		}
		$legacy = self::read_uncached_observation( self::LEGACY_OPTION );
		if ( $legacy['failed'] || ! $legacy['exists'] ) {
			return ! $legacy['failed'];
		}
		if ( ! self::heartbeat_is_healthy( $heartbeat ) || false === $this->mutation_fence() || ! $this->bump_revision() ) {
			return false;
		}
		$fence = $this->mutation_fence();
		return false !== $fence && self::compare_and_swap_option( self::LEGACY_OPTION, $legacy, array(), $fence ) && ! self::read_uncached_observation( self::LEGACY_OPTION )['exists'];
	}

	/** @param array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool} $schema_observation */
	private function migrate_legacy_schema( array $schema_observation, ?callable $heartbeat ): bool {
		if ( ! self::heartbeat_is_healthy( $heartbeat ) ) {
			return false;
		}
		$legacy_observation = self::read_uncached_observation( self::LEGACY_OPTION );
		$legacy             = $legacy_observation['exists'] ? $legacy_observation['value'] : array();
		if ( $legacy_observation['failed'] || ! \is_array( $legacy ) || ( empty( $legacy ) && $this->has_persisted_shard_data( $heartbeat ) ) ) {
			return false;
		}
		$generation = AtomicOptionSequence::current( self::SYNC_EPOCH_OPTION );
		$shards     = $generation < 0 ? null : $this->migration_shards( $legacy, $generation, $heartbeat );
		if ( null === $shards || ! $this->write_migration_shards( $shards, $heartbeat ) || ! self::verify_migration( $shards, $heartbeat ) ) {
			return false;
		}
		return $this->complete_legacy_migration( $schema_observation, $legacy, $heartbeat );
	}

	/** @return array<int,array<string,array{hash:string,generation:int}>>|null */
	private function migration_shards( array $legacy, int $generation, ?callable $heartbeat ): ?array {
		$shards    = array_fill( 0, self::SHARD_COUNT, array() );
		$processed = 0;
		foreach ( $legacy as $path => $hash ) {
			if ( 0 === $processed % self::HEARTBEAT_BATCH && ! self::heartbeat_is_healthy( $heartbeat ) ) {
				return null;
			}
			++$processed;
			$record = self::migration_record( $path, $hash, $generation );
			if ( null === $record || ! self::add_migration_record( $shards, $record ) ) {
				return null;
			}
		}
		return $shards;
	}

	/** @return array{path:string,hash:string,generation:int,shard:int}|null */
	private static function migration_record( mixed $path, mixed $hash, int $generation ): ?array {
		if ( ! \is_string( $path ) || '' === $path || \strlen( $path ) > self::MAX_PATH_LENGTH || ! \is_string( $hash ) || 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $hash ) ) {
			return null;
		}
		$path = self::normalize_path( $path );
		return array(
			'path'       => $path,
			'hash'       => \strtolower( $hash ),
			'generation' => $generation,
			'shard'      => self::shard_for_path( $path ),
		);
	}

	/** @param array<int,array<string,array{hash:string,generation:int}>> $shards @param array{path:string,hash:string,generation:int,shard:int} $record */
	private static function add_migration_record( array &$shards, array $record ): bool {
		$existing = $shards[ $record['shard'] ][ $record['path'] ]['hash'] ?? null;
		if ( \is_string( $existing ) && ! \hash_equals( $record['hash'], $existing ) ) {
			return false;
		}
		$shards[ $record['shard'] ][ $record['path'] ] = array(
			'hash'       => $record['hash'],
			'generation' => $record['generation'],
		);
		return true;
	}

	/** @param array<int,array<string,array{hash:string,generation:int}>> $shards */
	private function write_migration_shards( array $shards, ?callable $heartbeat ): bool {
		for ( $shard = 0; $shard < self::SHARD_COUNT; ++$shard ) {
			if ( ! self::heartbeat_is_healthy( $heartbeat ) || ! $this->write_migration_shard( $shard, $shards[ $shard ] ) ) {
				return false;
			}
		}
		return true;
	}

	private function write_migration_shard( int $shard, array $records ): bool {
		$fence = $this->mutation_fence();
		if ( false === $fence ) {
			return false;
		}
		$option      = self::shard_option_name( $shard );
		$observation = self::read_uncached_observation( $option );
		$stored      = $observation['exists'] ? $observation['value'] : array();
		if ( $observation['failed'] || ! self::validate_shard_storage( $stored, $shard ) ) {
			return false;
		}
		$current = self::normalize_records( $stored );
		return ( empty( $current ) || $current === $records ) && self::compare_and_swap_option( $option, $observation, $records, $fence );
	}

	/** @param array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool} $schema_observation */
	private function complete_legacy_migration( array $schema_observation, array $legacy, ?callable $heartbeat ): bool {
		$current = self::read_uncached_observation( self::LEGACY_OPTION );
		$value   = $current['exists'] ? $current['value'] : array();
		if ( $current['failed'] || ! \is_array( $value ) || $value !== $legacy || ! self::heartbeat_is_healthy( $heartbeat ) ) {
			return false;
		}
		$fence = $this->mutation_fence();
		if ( false === $fence || ! self::compare_and_swap_option_value( self::SCHEMA_OPTION, $schema_observation, self::SCHEMA_VERSION, false, $fence ) || self::SCHEMA_VERSION !== self::current_schema() ) {
			return false;
		}
		if ( ! self::heartbeat_is_healthy( $heartbeat ) || false === $this->mutation_fence() || ! $this->bump_revision() ) {
			return false;
		}
		$fence = $this->mutation_fence();
		if ( false === $fence || ( $current['exists'] && ! self::compare_and_swap_option( self::LEGACY_OPTION, $current, array(), $fence ) ) ) {
			return false;
		}
		if ( self::read_uncached_observation( self::LEGACY_OPTION )['exists'] ) {
			return false;
		}
		$this->clear_local_cache();
		return true;
	}

	private function has_persisted_shard_data( ?callable $heartbeat = null ): bool {
		for ( $shard = 0; $shard < self::SHARD_COUNT; ++$shard ) {
			if ( 0 === $shard % 8 && null !== $heartbeat && ! $heartbeat() ) {
				return true;
			}
			$stored = self::read_uncached_option( self::shard_option_name( $shard ), null );
			if ( null !== $stored && false !== $stored && array() !== $stored ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,array<string,array{hash:string,generation:int}>> $expected_shards
	 */
	private static function verify_migration( array $expected_shards, ?callable $heartbeat = null ): bool {
		for ( $shard = 0; $shard < self::SHARD_COUNT; ++$shard ) {
			if ( null !== $heartbeat && ! $heartbeat() ) {
				return false;
			}
			$stored = self::read_uncached_option( self::shard_option_name( $shard ), array() );
			if (
				! self::validate_shard_storage( $stored, $shard )
				|| self::normalize_records( $stored ) !== ( $expected_shards[ $shard ] ?? array() )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Persist only dirty shards after a standalone mutation reload.
	 *
	 * @param array<string,array{hash:string,generation:int}> $records
	 */
	public function write_shard_records( int $shard, array $records, bool $bump_revision = true ): bool {
		if (
			self::SCHEMA_VERSION !== $this->schema()
			|| $shard < 0
			|| $shard >= self::SHARD_COUNT
			|| ! self::validate_shard_storage( $records, $shard )
		) {
			return false;
		}
		$this->load_shard( $shard );
		if ( isset( $this->invalid_shards[ $shard ] ) ) {
			return false;
		}
		$written            = $this->write_shard( $shard, self::normalize_records( $records ) );
		$this->local_hashes = null;
		if ( $written && $bump_revision ) {
			return $this->bump_revision();
		}
		if ( ! $written ) {
			$this->bump_revision();
		}
		return $written;
	}

	/**
	 * @return array<string,array{hash:string,generation:int}>
	 */
	public function load_shard( int $shard ): array {
		if ( $shard < 0 || $shard >= self::SHARD_COUNT ) {
			return array();
		}
		if ( isset( $this->loaded_shards[ $shard ] ) ) {
			return $this->loaded_shards[ $shard ];
		}

		if ( self::SCHEMA_VERSION !== $this->schema() ) {
			$this->loaded_shards[ $shard ] = array();
			return $this->loaded_shards[ $shard ];
		}

		$observation                        = self::read_uncached_observation( self::shard_option_name( $shard ) );
		$stored                             = $observation['exists'] ? $observation['value'] : array();
		$this->shard_observations[ $shard ] = $observation;
		if ( $observation['failed'] ) {
			$this->invalid_shards[ $shard ] = true;
			$this->loaded_shards[ $shard ]  = array();
			return $this->loaded_shards[ $shard ];
		}
		if ( ! self::validate_shard_storage( $stored, $shard ) ) {
			$this->invalid_shards[ $shard ] = true;
			$this->loaded_shards[ $shard ]  = array();
			return $this->loaded_shards[ $shard ];
		}
		unset( $this->invalid_shards[ $shard ] );
		$normalized = self::normalize_records( $stored );

		$this->loaded_shards[ $shard ] = $normalized;
		return $this->loaded_shards[ $shard ];
	}

	public function is_shard_valid( int $shard ): bool {
		if ( self::SCHEMA_VERSION !== $this->schema() || $shard < 0 || $shard >= self::SHARD_COUNT ) {
			return false;
		}
		$this->load_shard( $shard );
		return ! isset( $this->invalid_shards[ $shard ] );
	}

	/**
	 * Validate every authoritative shard without normalizing corrupt evidence.
	 */
	private function all_persisted_shards_valid( ?callable $heartbeat = null ): bool {
		for ( $shard = 0; $shard < self::SHARD_COUNT; ++$shard ) {
			if ( null !== $heartbeat && ! $heartbeat() ) {
				return false;
			}
			if ( ! self::persisted_shard_valid( $shard ) ) {
				return false;
			}
		}

		return true;
	}

	private function schema(): int {
		if ( null === $this->schema_cache ) {
			$this->schema_cache = self::current_schema();
		}

		return $this->schema_cache;
	}

	/**
	 * Resolve the currently held mutation fence.
	 *
	 * @return array{name:string,connection_id:int}|false|null A database fence,
	 *     false when mutation authority is absent, or null for the explicit
	 *     PHPUnit/no-database fallback.
	 */
	private function mutation_fence(): array|false|null {
		if ( null === $this->lease_lock ) {
			return self::allows_unfenced_test_mutations() ? null : false;
		}
		if ( null === $this->lease_lock->get_token() || $this->lease_lock->is_lost() ) {
			return false;
		}

		$required = $this->lease_lock->requires_database_fence();
		$fence    = $this->lease_lock->get_database_fence();
		if ( $required && null === $fence ) {
			return false;
		}
		if (
			null !== $fence
			&& (
				! isset( $fence['name'], $fence['connection_id'] )
				|| ! \is_string( $fence['name'] )
				|| '' === $fence['name']
				|| ! \is_int( $fence['connection_id'] )
				|| $fence['connection_id'] <= 0
			)
		) {
			return false;
		}

		return $fence;
	}

	private static function allows_unfenced_test_mutations(): bool {
		return \defined( 'CYBERMAPS_PHPUNIT' ) && true === CYBERMAPS_PHPUNIT;
	}

	/**
	 * Read one option with exact row-presence and raw-value evidence for CAS.
	 *
	 * @return array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool}
	 */
	private static function read_uncached_observation( string $option_name ): array {
		if (
			\defined( 'CYBERMAPS_PHPUNIT' )
			&& CYBERMAPS_PHPUNIT
			&& empty( $GLOBALS['cybermaps_test_static_ownership_use_sql'] )
		) {
			return self::read_phpunit_observation( $option_name );
		}

		global $wpdb;
		if ( self::supports_direct_observation( $wpdb ) ) {
			return self::read_direct_observation( $wpdb, $option_name );
		}

		$missing = new \stdClass();
		$value   = \get_option( $option_name, $missing );
		return array(
			'exists' => $value !== $missing,
			'value'  => $value === $missing ? null : $value,
			'raw'    => null,
			'direct' => false,
			'failed' => false,
		);
	}

	private static function read_phpunit_observation( string $option_name ): array {
		$blog_id = (int) ( $GLOBALS['cybermaps_mock_current_blog_id'] ?? 1 );
		$by_blog = $GLOBALS['cybermaps_mock_options_by_blog'] ?? array();
		if ( isset( $by_blog[ $blog_id ] ) && \array_key_exists( $option_name, $by_blog[ $blog_id ] ) ) {
			return array(
				'exists' => true,
				'value'  => $by_blog[ $blog_id ][ $option_name ],
				'raw'    => null,
				'direct' => false,
				'failed' => false,
			);
		}
		$options = $GLOBALS['cybermaps_mock_options'] ?? array();
		$exists  = \array_key_exists( $option_name, $options );
		return array(
			'exists' => $exists,
			'value'  => $exists ? $options[ $option_name ] : null,
			'raw'    => null,
			'direct' => false,
			'failed' => false,
		);
	}

	private static function supports_direct_observation( mixed $database ): bool {
		return \is_object( $database ) && isset( $database->options ) && \is_string( $database->options ) && \method_exists( $database, 'prepare' ) && \method_exists( $database, 'get_var' ) && \method_exists( $database, 'query' );
	}

	private static function read_direct_observation( mixed $wpdb, string $option_name ): array {
		$raw    = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $option_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$failed = false === $raw || ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error );
		return array(
			'exists' => ! $failed && \is_string( $raw ),
			'value'  => $failed || ! \is_string( $raw ) ? null : \maybe_unserialize( $raw ),
			'raw'    => \is_string( $raw ) ? $raw : null,
			'direct' => true,
			'failed' => $failed,
		);
	}

	/**
	 * Replace or remove one option only if its exact observed row is unchanged.
	 *
	 * @param array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool} $observed Exact prior observation.
	 * @param array<string,array{hash:string,generation:int}> $records Desired shard records.
	 * @param array{name:string,connection_id:int}|null $database_fence Held advisory-lock connection.
	 */
	private static function compare_and_swap_option(
		string $option_name,
		array $observed,
		array $records,
		?array $database_fence = null
	): bool {
		return self::compare_and_swap_option_value(
			$option_name,
			$observed,
			$records,
			empty( $records ),
			$database_fence
		);
	}

	/**
	 * Compare-and-swap any ownership coordination option under the same database
	 * connection that owns the advisory fence.
	 *
	 * @param array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool} $observed Exact prior observation.
	 * @param array{name:string,connection_id:int}|null $database_fence Held advisory-lock connection.
	 */
	private static function compare_and_swap_option_value(
		string $option_name,
		array $observed,
		mixed $next_value,
		bool $delete,
		?array $database_fence = null
	): bool {
		if ( $observed['failed'] ) {
			return false;
		}
		return $observed['direct']
			? self::compare_direct_option( $option_name, $observed, $next_value, $delete, $database_fence )
			: self::compare_wordpress_option( $option_name, $observed, $next_value, $delete );
	}

	private static function compare_direct_option( string $option_name, array $observed, mixed $next_value, bool $delete, ?array $database_fence ): bool {
		if ( null === $database_fence && ! self::allows_unfenced_test_mutations() ) {
			return false;
		}
		global $wpdb;
		$changed = self::direct_option_mutation( $wpdb, $option_name, $observed, $next_value, $delete, $database_fence );
		if ( false === $changed || ( 0 === (int) $changed && ! self::direct_noop_matches( $wpdb, $option_name, $observed, $next_value, $delete, $database_fence ) ) ) {
			return false;
		}
		self::invalidate_option_cache( $option_name );
		return true;
	}

	private static function direct_option_mutation( mixed $wpdb, string $option_name, array $observed, mixed $next_value, bool $delete, ?array $fence ): int|false {
		if ( $delete && ! $observed['exists'] ) {
			return 1;
		}
		if ( ( $delete || $observed['exists'] ) && ! \is_string( $observed['raw'] ) ) {
			return false;
		}
		if ( $delete ) {
			return self::direct_option_query( $wpdb, 'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s', array( $wpdb->options, $option_name, $observed['raw'] ), $fence );
		}
		if ( $observed['exists'] ) {
			return self::direct_option_query( $wpdb, 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', array( $wpdb->options, self::serialize_option_value( $next_value ), 'off', $option_name, $observed['raw'] ), $fence );
		}
		$sql  = null === $fence ? 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)' : 'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s WHERE IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d';
		$args = array( $wpdb->options, $option_name, self::serialize_option_value( $next_value ), 'off' );
		return self::direct_option_query( $wpdb, $sql, $args, $fence );
	}

	private static function direct_option_query( mixed $wpdb, string $sql, array $args, ?array $fence ): int|false {
		if ( null !== $fence ) {
			if ( ! \str_contains( $sql, 'IS_USED_LOCK' ) ) {
				$sql .= ' AND IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d';
			}
			$args[] = $fence['name'];
			$args[] = $fence['connection_id'];
		}
		return $wpdb->query( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Built only from fixed SQL fragments; values remain placeholders.
	}

	private static function direct_noop_matches( mixed $wpdb, string $option_name, array $observed, mixed $next_value, bool $delete, ?array $fence ): bool {
		if ( $delete || ! $observed['exists'] || $observed['value'] !== $next_value ) {
			return false;
		}
		if ( null === $fence ) {
			$current = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $option_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$current = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s AND IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d LIMIT 1', $wpdb->options, $option_name, $fence['name'], $fence['connection_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return \is_string( $current ) && \hash_equals( self::serialize_option_value( $next_value ), $current );
	}

	private static function compare_wordpress_option( string $option_name, array $observed, mixed $next_value, bool $delete ): bool {
		$current = self::read_uncached_observation( $option_name );
		if ( $current['failed'] || $current['exists'] !== $observed['exists'] || ( $current['exists'] && $current['value'] !== $observed['value'] ) ) {
			return false;
		}
		if ( $delete ) {
			return ! $current['exists'] || (bool) \delete_option( $option_name );
		}
		if ( $current['exists'] && $current['value'] === $next_value ) {
			return true;
		}
		\update_option( $option_name, $next_value, false );
		$verified = self::read_uncached_observation( $option_name );
		return ! $verified['failed'] && $verified['exists'] && $verified['value'] === $next_value;
	}

	private static function serialize_option_value( mixed $value ): string {
		return \function_exists( 'maybe_serialize' )
			? (string) \maybe_serialize( $value )
			: \serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	private static function invalidate_option_cache( string $option_name ): void {
		if ( ! \function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		\wp_cache_delete( $option_name, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
		\wp_cache_delete( 'alloptions', 'options' );
	}

	private static function persisted_shard_valid( int $shard ): bool {
		$stored = self::read_uncached_option( self::shard_option_name( $shard ), array() );
		return self::validate_shard_storage( $stored, $shard );
	}

	/**
	 * Accept only the schema-2 record contract and its legacy MD5 shorthand.
	 */
	private static function validate_shard_storage( mixed $stored, ?int $expected_shard = null ): bool {
		if ( ! \is_array( $stored ) ) {
			return false;
		}
		if ( null !== $expected_shard && ( $expected_shard < 0 || $expected_shard >= self::SHARD_COUNT ) ) {
			return false;
		}

		foreach ( $stored as $path => $record ) {
			if (
				! \is_string( $path )
				|| '' === $path
				|| \strlen( $path ) > self::MAX_PATH_LENGTH
				|| self::normalize_path( $path ) !== $path
				|| ( null !== $expected_shard && self::shard_for_path( $path ) !== $expected_shard )
			) {
				return false;
			}
			if ( \is_string( $record ) ) {
				if ( 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $record ) ) {
					return false;
				}
				continue;
			}
			if (
				! \is_array( $record )
				|| array( 'generation', 'hash' ) !== ( static function ( array $keys ): array {
					\sort( $keys, SORT_STRING );
					return $keys;
				} )( \array_keys( $record ) )
				|| ! \is_string( $record['hash'] ?? null )
				|| 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $record['hash'] )
				|| ! \is_int( $record['generation'] ?? null )
				|| $record['generation'] < 0
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read coordination data without this request's potentially stale option cache.
	 */
	private static function read_uncached_option( string $option_name, mixed $fallback ): mixed {
		if (
			\defined( 'CYBERMAPS_PHPUNIT' )
			&& CYBERMAPS_PHPUNIT
			&& empty( $GLOBALS['cybermaps_test_static_ownership_use_sql'] )
		) {
			$blog_id = (int) ( $GLOBALS['cybermaps_mock_current_blog_id'] ?? 1 );
			$by_blog = $GLOBALS['cybermaps_mock_options_by_blog'] ?? array();
			if ( isset( $by_blog[ $blog_id ] ) && \array_key_exists( $option_name, $by_blog[ $blog_id ] ) ) {
				return $by_blog[ $blog_id ][ $option_name ];
			}
			$options = $GLOBALS['cybermaps_mock_options'] ?? array();
			return \array_key_exists( $option_name, $options ) ? $options[ $option_name ] : $fallback;
		}

		global $wpdb;
		if (
			\is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' )
			&& \method_exists( $wpdb, 'get_var' )
		) {
			$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
					$wpdb->options,
					$option_name
				)
			);
			if ( false === $raw || ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) ) {
				return self::read_failure_marker();
			}
			return null === $raw ? $fallback : \maybe_unserialize( $raw );
		}

		return \get_option( $option_name, $fallback );
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

	/**
	 * @param mixed $stored Persisted shard value.
	 * @return array<string,array{hash:string,generation:int}>
	 */
	private static function normalize_records( mixed $stored ): array {
		if ( ! \is_array( $stored ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $stored as $path => $record ) {
			if ( \is_string( $record ) && 1 === \preg_match( '/^[a-f0-9]{32}$/i', $record ) ) {
				$normalized[ (string) $path ] = array(
					'hash'       => \strtolower( $record ),
					'generation' => 0,
				);
				continue;
			}
			if ( ! \is_array( $record ) ) {
				continue;
			}
			$hash = $record['hash'] ?? '';
			if ( ! \is_string( $hash ) || 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $hash ) ) {
				continue;
			}
			$normalized[ (string) $path ] = array(
				'hash'       => \strtolower( $hash ),
				'generation' => max( 0, (int) ( $record['generation'] ?? 0 ) ),
			);
		}

		return $normalized;
	}

	/**
	 * @param array<string,array{hash:string,generation:int}> $records
	 */
	private function write_shard( int $shard, array $records ): bool {
		if ( ! $this->can_write_shard( $shard, $records ) ) {
			return false;
		}
		$fence = $this->mutation_fence();
		if ( false === $fence ) {
			return false;
		}
		$option = self::shard_option_name( $shard );
		if ( ! isset( $this->loaded_shards[ $shard ] ) ) {
			$this->load_shard( $shard );
		}
		$observation = $this->shard_observations[ $shard ] ?? self::read_uncached_observation( $option );
		if ( $observation['failed'] || ! self::compare_and_swap_option( $option, $observation, $records, $fence ) ) {
			return false;
		}
		return $this->verify_shard_write( $shard, $option, $records );
	}

	private function can_write_shard( int $shard, array $records ): bool {
		return self::SCHEMA_VERSION === $this->schema() && $shard >= 0 && $shard < self::SHARD_COUNT && ! isset( $this->invalid_shards[ $shard ] ) && ! isset( $this->conflicted_shards[ $shard ] ) && self::validate_shard_storage( $records, $shard );
	}

	private function verify_shard_write( int $shard, string $option, array $records ): bool {
		$verified = self::read_uncached_observation( $option );
		$stored   = $verified['exists'] ? $verified['value'] : array();
		if ( $verified['failed'] || ! self::validate_shard_storage( $stored, $shard ) ) {
			$this->invalid_shards[ $shard ] = true;
			return false;
		}
		$stored = self::normalize_records( $stored );
		if ( $stored !== $records ) {
			$this->conflicted_shards[ $shard ] = true;
			return false;
		}
		$this->shard_observations[ $shard ] = $verified;
		$this->loaded_shards[ $shard ]      = $stored;
		unset( $this->conflicted_shards[ $shard ], $this->invalid_shards[ $shard ], $this->dirty_shards[ $shard ] );
		return true;
	}

	/**
	 * @return array{hash:string,generation:int}|null
	 */
	private function get_record( string $path ): ?array {
		$path   = self::normalize_path( $path );
		$schema = $this->schema();
		if ( 0 === $schema ) {
			$hash = $this->read_flat_hashes()[ $path ] ?? null;
			return \is_string( $hash )
				? array(
					'hash'       => $hash,
					'generation' => 0,
				)
				: null;
		}
		if ( self::SCHEMA_VERSION !== $schema ) {
			return null;
		}

		$records = $this->load_shard( self::shard_for_path( $path ) );
		return $records[ $path ] ?? null;
	}

	public static function delete_all(): void {
		foreach ( self::all_option_names() as $option_name ) {
			\delete_option( $option_name );
		}
	}

	/**
	 * @return string[]
	 */
	public static function all_option_names(): array {
		$options = array(
			self::LEGACY_OPTION,
			self::SCHEMA_OPTION,
			self::REPAIR_ACK_OPTION,
			self::REPAIR_OPTION,
			self::REVISION_OPTION,
			self::SYNC_EPOCH_OPTION,
		);
		for ( $shard = 0; $shard < self::SHARD_COUNT; ++$shard ) {
			$options[] = self::shard_option_name( $shard );
		}
		return $options;
	}

	private function bump_revision(): bool {
		return AtomicOptionSequence::increment( self::REVISION_OPTION ) > 0;
	}

	/**
	 * Invalidate ownership-derived caches once after a verified mutation batch.
	 */
	public function commit_revision(): bool {
		return false !== $this->mutation_fence() && $this->bump_revision();
	}

	/**
	 * @param array<string,mixed> $legacy
	 * @return array<string,string>
	 */
	private function flatten_legacy_hashes( array $legacy ): array {
		$flat = array();
		foreach ( $legacy as $path => $hash ) {
			if ( \is_string( $path ) && \is_string( $hash ) && 1 === \preg_match( '/^[a-f0-9]{32}$/i', $hash ) ) {
				$flat[ $path ] = \strtolower( $hash );
			}
		}
		return $flat;
	}

	private static function validate_legacy_storage( mixed $legacy ): bool {
		if ( ! \is_array( $legacy ) ) {
			return false;
		}
		foreach ( $legacy as $path => $hash ) {
			if (
				! \is_string( $path )
				|| '' === $path
				|| \strlen( $path ) > self::MAX_PATH_LENGTH
				|| ! \is_string( $hash )
				|| 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $hash )
			) {
				return false;
			}
		}

		return true;
	}
}
