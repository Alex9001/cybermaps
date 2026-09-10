<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare-and-swap option lease for bounded background work.
 */
final class OptionLeaseLock {
	private const MAX_CLOCK_SKEW = 60;

	private string $option_name;
	private int $ttl;
	private int $renew_interval;
	private bool $require_database_fence;
	/** @var callable():bool|null */
	private $stale_takeover_guard;
	private ?DatabaseSessionLock $database_session_lock = null;
	private bool $shutdown_registered                   = false;
	private ?string $token                              = null;
	private bool $lost                                  = false;

	/**
	 * @param callable():bool|null $stale_takeover_guard Approval required before deleting a stale row.
	 */
	public function __construct(
		string $option_name,
		int $ttl,
		int $renew_interval,
		bool $require_database_fence = false,
		?callable $stale_takeover_guard = null
	) {
		$this->option_name            = $option_name;
		$this->ttl                    = $ttl;
		$this->renew_interval         = $renew_interval;
		$this->require_database_fence = $require_database_fence;
		$this->stale_takeover_guard   = $stale_takeover_guard;
		if ( $require_database_fence ) {
			$this->database_session_lock = new DatabaseSessionLock( $option_name );
		}
	}

	public function get_token(): ?string {
		return $this->token;
	}

	public function is_lost(): bool {
		return $this->lost;
	}

	public function requires_database_fence(): bool {
		return $this->require_database_fence;
	}

	/**
	 * Report whether a required connection-bound fence can be proven before a
	 * caller attempts a static mutation.
	 */
	public function database_fence_is_supported(): bool {
		return ! $this->require_database_fence
			|| ( null !== $this->database_session_lock && $this->database_session_lock->is_supported() );
	}

	/**
	 * Return the verified database fence held by this lease.
	 *
	 * @return array{name:string,connection_id:int}|null
	 */
	public function get_database_fence(): ?array {
		if ( null === $this->database_session_lock ) {
			return null;
		}

		$fence = $this->database_session_lock->get_fence();
		if ( null === $fence && null !== $this->token ) {
			$this->lost = true;
		}
		return $fence;
	}

	/**
	 * Clear request-local lease state without touching the persisted option row.
	 */
	public function reset_local_state(): void {
		if ( null !== $this->database_session_lock ) {
			$this->database_session_lock->release();
		}
		$this->token = null;
		$this->lost  = false;
	}

	/**
	 * @param callable():void|null $on_acquire Optional callback after the lease is held.
	 */
	public function acquire( ?callable $on_acquire = null ): bool {
		if ( null !== $this->token ) {
			return $this->maintain();
		}
		if ( ! $this->acquire_database_fence() ) {
			return false;
		}

		$token = \wp_generate_password( 32, false, false );
		$lock  = array(
			'token' => $token,
			'time'  => \time(),
		);

		if ( $this->insert_lock( $lock ) ) {
			return $this->accept_lease( $token, $on_acquire );
		}
		return $this->take_over_stale_lock( $lock, $token, $on_acquire );
	}

	/**
	 * Validate the owner token and periodically renew the lease with a
	 * compare-and-swap update.
	 */
	public function maintain(): bool {
		if ( null === $this->token || $this->lost ) {
			return false;
		}
		if (
			null !== $this->database_session_lock
			&& ! $this->database_session_lock->maintain()
		) {
			$this->lost = true;
			return false;
		}

		$observation   = $this->read_lock();
		$current       = $observation['value'];
		$current_token = \is_array( $current ) && \is_scalar( $current['token'] ?? null )
			? (string) $current['token']
			: '';
		if (
			'' === $current_token
			|| ! \hash_equals( $this->token, $current_token )
		) {
			$this->lost = true;
			return false;
		}

		$now       = \time();
		$lock_time = (int) ( $current['time'] ?? 0 );
		if (
			$lock_time > 0
			&& $lock_time <= $now
			&& $now - $lock_time < $this->renew_interval
		) {
			return true;
		}

		$renewed = array(
			'token' => $this->token,
			'time'  => $now,
		);
		if ( ! empty( $observation['direct'] ) ) {
			return $this->renew_direct_lock( $observation, $renewed );
		}

		\update_option( $this->option_name, $renewed, false );
		return true;
	}

	/** @param array{token:string,time:int} $lock */
	private function take_over_stale_lock( array $lock, string $token, ?callable $on_acquire ): bool {
		$observation = $this->read_lock();
		if ( $this->lost || $this->lock_is_current( $observation['value'] ) || ! $this->allow_stale_takeover() || ! $this->delete_observed_lock( $observation ) || ! $this->insert_lock( $lock ) ) {
			$this->release_database_fence();
			return false;
		}
		return $this->accept_lease( $token, $on_acquire );
	}

	private function lock_is_current( mixed $lock ): bool {
		$time = \is_array( $lock ) ? (int) ( $lock['time'] ?? 0 ) : 0;
		$now  = \time();
		return $time > 0 && $time <= $now + self::MAX_CLOCK_SKEW && $now - $time <= $this->ttl;
	}

	/** @param array{value:mixed,raw:string|null,direct:bool} $observation @param array{token:string,time:int} $renewed */
	private function renew_direct_lock( array $observation, array $renewed ): bool {
		$raw = $observation['raw'];
		if ( ! \is_string( $raw ) ) {
			$this->lost = true;
			return false;
		}
		global $wpdb;
		$updated = $this->require_database_fence ? $this->fenced_direct_renewal( $wpdb, $raw, $renewed ) : $this->unfenced_direct_renewal( $wpdb, $raw, $renewed );
		if ( 1 !== (int) $updated ) {
			$this->lost = true;
			return false;
		}
		$this->invalidate_lock_cache();
		return true;
	}

	private function fenced_direct_renewal( mixed $wpdb, string $raw, array $renewed ): int|false {
		$fence = $this->database_fence_for_mutation();
		if ( null === $fence ) {
			return false;
		}
		return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s AND IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d', $wpdb->options, $this->serialize_option_value( $renewed ), $this->option_name, $raw, $fence['name'], $fence['connection_id'], $fence['connection_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The conditional write is the lease compare-and-swap and must bypass caches.
	}

	private function unfenced_direct_renewal( mixed $wpdb, string $raw, array $renewed ): int|false {
		return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, $this->serialize_option_value( $renewed ), $this->option_name, $raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function release(): void {
		$may_delete = null !== $this->token && ! $this->lost;
		if ( $may_delete && null !== $this->database_session_lock ) {
			$may_delete = $this->database_session_lock->maintain();
			if ( ! $may_delete ) {
				$this->lost = true;
			}
		}

		if ( $may_delete ) {
			$observation  = $this->read_lock();
			$current_lock = $observation['value'];
			if (
				\is_array( $current_lock )
				&& \is_scalar( $current_lock['token'] ?? null )
				&& \hash_equals( (string) $this->token, (string) $current_lock['token'] )
			) {
				$this->delete_observed_lock( $observation );
			}
		}

		$this->token = null;
		$this->release_database_fence();
		$this->lost = false;
	}

	private function acquire_database_fence(): bool {
		if ( null === $this->database_session_lock ) {
			return true;
		}

		if ( ! $this->database_session_lock->acquire() ) {
			return false;
		}

		if ( ! $this->shutdown_registered ) {
			\register_shutdown_function( array( $this, 'release' ) );
			$this->shutdown_registered = true;
		}
		return true;
	}

	private function release_database_fence(): void {
		if ( null !== $this->database_session_lock ) {
			$this->database_session_lock->release();
		}
	}

	private function allow_stale_takeover(): bool {
		if ( null === $this->stale_takeover_guard ) {
			return true;
		}

		try {
			return (bool) ( $this->stale_takeover_guard )();
		} catch ( \Throwable $error ) {
			$this->release_database_fence();
			throw $error;
		}
	}

	/**
	 * @return array{name:string,connection_id:int}|null
	 */
	private function database_fence_for_mutation(): ?array {
		if ( null === $this->database_session_lock ) {
			$this->lost = true;
			return null;
		}

		$fence = $this->database_session_lock->get_fence();
		if ( null === $fence ) {
			$this->lost = true;
			return null;
		}
		return $fence;
	}

	/**
	 * @return array{value:mixed,raw:string|null,direct:bool}
	 */
	private function read_lock(): array {
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
					$this->option_name
				)
			);
			$raw = \is_string( $raw ) ? $raw : null;
			return array(
				'value'  => null === $raw ? null : \maybe_unserialize( $raw ),
				'raw'    => $raw,
				'direct' => true,
			);
		}

		return array(
			'value'  => \get_option( $this->option_name, null ),
			'raw'    => null,
			'direct' => false,
		);
	}

	/**
	 * @param array{value:mixed,raw:string|null,direct:bool} $observation
	 */
	private function delete_observed_lock( array $observation ): bool {
		if ( ! empty( $observation['direct'] ) ) {
			$raw = $observation['raw'];
			if ( ! \is_string( $raw ) ) {
				return false;
			}

			global $wpdb;
			if ( $this->require_database_fence ) {
				$fence = $this->database_fence_for_mutation();
				if ( null === $fence ) {
					return false;
				}
				$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The conditional delete binds option ownership to the active database session fence.
					$wpdb->prepare(
						'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s AND IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d',
						$wpdb->options,
						$this->option_name,
						$raw,
						$fence['name'],
						$fence['connection_id'],
						$fence['connection_id']
					)
				);
			} else {
				$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s',
						$wpdb->options,
						$this->option_name,
						$raw
					)
				);
			}
			if ( 1 !== (int) $deleted ) {
				return false;
			}
			$this->invalidate_lock_cache();
			return true;
		}

		$current = \get_option( $this->option_name, null );
		if ( $current !== $observation['value'] ) {
			return false;
		}
		return (bool) \delete_option( $this->option_name );
	}

	private function serialize_option_value( mixed $value ): string {
		return \function_exists( 'maybe_serialize' )
			? (string) \maybe_serialize( $value )
			: \serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Insert only when no owner row exists, then verify the exact token.
	 *
	 * Do not rely on version-specific add_option() insert/upsert semantics for
	 * mutual exclusion when two requests race on an absent option.
	 *
	 * @param array{token:string,time:int} $lock Candidate lease.
	 */
	private function insert_lock( array $lock ): bool {
		global $wpdb;
		if (
			\is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' )
			&& \method_exists( $wpdb, 'query' )
			&& \method_exists( $wpdb, 'get_var' )
		) {
			if ( $this->require_database_fence ) {
				$fence = $this->database_fence_for_mutation();
				if ( null === $fence ) {
					return false;
				}
				$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE plus the same-statement session-fence predicate is the acquisition proof.
					$wpdb->prepare(
						'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d',
						$wpdb->options,
						$this->option_name,
						$this->serialize_option_value( $lock ),
						'off',
						$fence['name'],
						$fence['connection_id'],
						$fence['connection_id']
					)
				);
			} else {
				$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
						$wpdb->options,
						$this->option_name,
						$this->serialize_option_value( $lock ),
						'off'
					)
				);
			}
			if ( 1 !== (int) $inserted ) {
				return false;
			}
			$this->invalidate_lock_cache();
			// INSERT IGNORE affecting one row is the ownership proof. A separate
			// verification SELECT could fail transiently and strand a live lease.
			return true;
		}

		if ( ! \add_option( $this->option_name, $lock, '', false ) ) {
			return false;
		}

		$stored       = $this->read_lock()['value'];
		$stored_token = \is_array( $stored ) && \is_scalar( $stored['token'] ?? null )
			? (string) $stored['token']
			: '';
		return '' !== $stored_token && \hash_equals( $lock['token'], $stored_token );
	}

	/**
	 * Adopt a newly inserted lease and make callback failure release it exactly.
	 */
	private function accept_lease( string $token, ?callable $on_acquire ): bool {
		$this->token = $token;
		$this->lost  = false;
		try {
			if ( null !== $on_acquire ) {
				$on_acquire();
			}
		} catch ( \Throwable $error ) {
			$this->release();
			throw $error;
		}

		return true;
	}

	private function invalidate_lock_cache(): void {
		if ( ! \function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		\wp_cache_delete( $this->option_name, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
		// A lock left by an older release may still be present in alloptions.
		\wp_cache_delete( 'alloptions', 'options' );
	}
}
