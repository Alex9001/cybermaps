<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded, restartable copy of schema-zero/two options into ownership rows. */
final class StaticOwnershipMigration {
	private const RECORD_BUDGET = 250;
	private const TIME_BUDGET   = 2.0;
	private StaticOwnershipTable $table;
	/** @var \Closure(string,array,bool,bool):(array|int|false) */
	private \Closure $query;
	/** @var \Closure(array):bool */
	private \Closure $save;
	/** @var \Closure():bool */
	private \Closure $heartbeat;
	private string $options;
	private array $observations                         = array();
	private ?StaticOwnershipLegacyReader $active_reader = null;
	private int $active_source                          = -1;

	private bool $pending = false;

	/** @param callable $query Fixed-template prepared executor. @param callable $save Fenced progress checkpoint. @param callable $heartbeat Active publication lease. */
	public function __construct( StaticOwnershipTable $table, string $options, callable $query, callable $save, callable $heartbeat ) {
		$this->table     = $table;
		$this->options   = $options;
		$this->query     = \Closure::fromCallable( $query );
		$this->save      = \Closure::fromCallable( $save );
		$this->heartbeat = \Closure::fromCallable( $heartbeat );
	}

	/** A small checkpoint contains cursors and at most 65 source fingerprints. */
	public static function initial_state( int $schema, int $generation ): array {
		return array(
			'format'     => 1,
			'schema'     => $schema,
			'generation' => $generation,
			'phase'      => 'reset',
			'source'     => 0,
			'offset'     => 0,
			'remaining'  => -1,
			'pins'       => array(),
			'after'      => '',
		);
	}

	/** Return true only after every source and staged row was verified. */
	public function advance( array &$state ): bool {
		$this->pending      = false;
		$this->observations = array();
		$deadline           = microtime( true ) + self::TIME_BUDGET;
		for ( $work = 0; $work < self::RECORD_BUDGET; ++$work ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			if ( ! ( $this->heartbeat )() || ! $this->step( $state ) ) {
				return false;
			}
			if ( 'ready' === $state['phase'] ) {
				return ( $this->save )( $state );
			}
		}
		$this->pending = ( $this->save )( $state );
		return false;
	}

	public function has_pending_work(): bool {
		return $this->pending;
	}

	private function step( array &$state ): bool {
		return match ( $state['phase'] ) {
			'reset' => $this->reset_rows( $state ),
			'copy' => $this->copy_entry( $state ),
			'validate' => $this->validate_rows( $state ),
			'verify' => $this->verify_source( $state ),
			'ready' => true,
			default => false,
		};
	}

	private function reset_rows( array &$state ): bool {
		$rows = $this->table->page( -1, '', 1 );
		if ( false === $rows ) {
			return false;
		}
		if ( array() === $rows ) {
			$state['phase'] = 'copy';
			return ( $this->save )( $state );
		}
		$row = $rows[0];
		return $this->table->replace(
			$row['path'],
			array(
				'hash'       => $row['hash'],
				'generation' => $row['generation'],
			),
			null
		);
	}

	private function copy_entry( array &$state ): bool {
		$index = (int) $state['source'];
		if ( $index > StaticOwnershipStore::SHARD_COUNT ) {
			$state['phase'] = 'validate';
			$state['after'] = '';
			return ( $this->save )( $state );
		}
		$metadata                     = array_key_exists( $index, $this->observations ) ? $this->observations[ $index ] : $this->metadata( $index );
		$this->observations[ $index ] = $metadata;
		if ( false === $metadata || ! $this->pin_source( $state, $index, $metadata ) ) {
			return false;
		}
		if ( null === $metadata || ( 2 === $state['schema'] && 0 === $index ) ) {
			return $this->next_source( $state );
		}
		$reader = $this->active_reader;
		if ( null === $reader || $this->active_source !== $index || $reader->position() !== $state['offset'] ) {
			$reader              = $this->reader( $index, $metadata, $state['offset'] );
			$this->active_reader = $reader;
			$this->active_source = $index;
		}
		if ( -1 === $state['remaining'] ) {
			$count = $reader->begin();
			if ( null === $count ) {
				return false;
			}
			$state['remaining'] = $count;
		}
		return $this->copy_reader_entry( $state, $reader );
	}

	private function pin_source( array &$state, int $index, ?array $metadata ): bool {
		if ( array_key_exists( $index, $state['pins'] ) && $state['pins'][ $index ] !== $metadata ) {
			$state         = self::initial_state( $state['schema'], $state['generation'] );
			$this->pending = ( $this->save )( $state );
			return false;
		}
		if ( ! array_key_exists( $index, $state['pins'] ) ) {
			$state['pins'][ $index ] = $metadata;
			return ( $this->save )( $state );
		}
		return true;
	}

	private function copy_reader_entry( array &$state, StaticOwnershipLegacyReader $reader ): bool {
		if ( 0 === $state['remaining'] ) {
			return $reader->finish() && $this->next_source( $state );
		}
		$source_schema = 0 === $state['source'] ? 0 : 2;
		$entry         = $reader->next( $source_schema, $state['generation'] );
		if ( null === $entry || ! $this->copy_record( $entry, $state ) ) {
			return false;
		}
		$state['offset'] = $reader->position();
		--$state['remaining'];
		return true;
	}

	private function copy_record( array $entry, array $state ): bool {
		$path = StaticOwnershipStore::normalize_path( $entry['path'] );
		if ( $state['source'] > 0 && ( $path !== $entry['path'] || StaticOwnershipStore::shard_for_path( $path ) !== $state['source'] - 1 ) ) {
			return false;
		}
		$prior = $this->table->read( $path );
		$next  = array(
			'hash'       => $entry['hash'],
			'generation' => $entry['generation'],
		);
		if ( false === $prior ) {
			return false;
		}
		// Schema-zero shards can only be remnants of its older interrupted copy.
		if ( 0 === $state['schema'] && $state['source'] > 0 ) {
			return is_array( $prior ) && $prior === $next;
		}
		// A leftover monolith under schema two is cleanup evidence, not authority.
		if ( 2 === $state['schema'] && 0 === $state['source'] ) {
			return true;
		}
		return null === $prior ? $this->table->replace( $path, null, $next ) : $prior === $next;
	}

	private function next_source( array &$state ): bool {
		++$state['source'];
		$state['offset']    = 0;
		$state['remaining'] = -1;
		return ( $this->save )( $state );
	}

	private function validate_rows( array &$state ): bool {
		$rows = $this->table->page( -1, $state['after'] );
		if ( false === $rows ) {
			return false;
		}
		if ( array() === $rows ) {
			$state['phase']  = 'verify';
			$state['source'] = 0;
			return ( $this->save )( $state );
		}
		$state['after'] = $rows[ array_key_last( $rows ) ]['key'];
		return true;
	}

	private function verify_source( array &$state ): bool {
		$index = (int) $state['source'];
		if ( $index > StaticOwnershipStore::SHARD_COUNT ) {
			$state['phase'] = 'ready';
			return true;
		}
		$metadata = $this->metadata( $index );
		if ( false === $metadata || ! array_key_exists( $index, $state['pins'] ) || ! $this->pin_source( $state, $index, $metadata ) ) {
			return false;
		}
		++$state['source'];
		return true;
	}

	/** Recheck every source in one serializable transaction, locking absent-name gaps too. */
	public function verify_cutover_sources( array $state ): int {
		for ( $index = 0; $index <= StaticOwnershipStore::SHARD_COUNT; ++$index ) {
			if ( ! ( $this->heartbeat )() || ! array_key_exists( $index, $state['pins'] ) ) {
				return -1;
			}
			$metadata = $this->metadata( $index, true );
			if ( false === $metadata ) {
				return -1;
			}
			if ( $metadata !== $state['pins'][ $index ] ) {
				return 0;
			}
		}
		return 1;
	}

	/** @return array{bytes:int,digest:string}|null|false */
	public function metadata( int $source, bool $lock = false ): array|null|false {
		$sql  = $lock
			? 'SELECT LENGTH(option_value) AS bytes, SHA2(option_value,256) AS digest FROM %i WHERE option_name = %s LIMIT 1 FOR UPDATE'
			: 'SELECT LENGTH(option_value) AS bytes, SHA2(option_value,256) AS digest FROM %i WHERE option_name = %s LIMIT 1';
		$rows = ( $this->query )( $sql, array( $this->options, self::source_option( $source ) ), true, $lock );
		if ( false === $rows || ( isset( $rows[0] ) && ! is_string( $rows[0]['digest'] ?? null ) ) ) {
			return false;
		}
		return array() === $rows ? null : array(
			'bytes'  => (int) $rows[0]['bytes'],
			'digest' => $rows[0]['digest'],
		);
	}

	private function reader( int $source, array $pin, int $offset ): StaticOwnershipLegacyReader {
		return new StaticOwnershipLegacyReader(
			function ( int $start, int $count ) use ( $source, $pin ): ?string {
				$rows = ( $this->query )( 'SELECT SUBSTRING(BINARY option_value,%d,%d) AS chunk FROM %i WHERE option_name = %s AND LENGTH(option_value) = %d AND SHA2(option_value,256) = %s LIMIT 1', array( $start + 1, $count, $this->options, self::source_option( $source ), $pin['bytes'], $pin['digest'] ), true, false );
				return is_array( $rows ) && is_string( $rows[0]['chunk'] ?? null ) ? $rows[0]['chunk'] : null;
			},
			$pin['bytes'],
			$offset
		);
	}

	public static function source_option( int $source ): string {
		return 0 === $source ? StaticOwnershipStore::LEGACY_OPTION : StaticOwnershipStore::shard_option_name( $source - 1 );
	}
}
