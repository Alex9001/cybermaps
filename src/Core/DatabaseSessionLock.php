<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection-bound database fence for operations that also use option leases.
 *
 * MySQL advisory locks belong to one physical connection. This class therefore
 * records the acquiring connection ID and refuses to continue unless
 * IS_USED_LOCK() still reports that exact owner.
 */
final class DatabaseSessionLock {
	private const LOCK_NAME_PREFIX = 'cybermaps:';
	private const LOCK_HASH_LENGTH = 48;

	/** @var array<string,int> Request-local advisory locks used by ordinary PHPUnit runs. */
	private static array $test_locks = array();

	private static int $next_test_connection_id = 1;

	private string $name;
	private ?int $connection_id   = null;
	private bool $lost            = false;
	private bool $using_test_lock = false;

	/**
	 * @param string|null $scope Explicit database-global resource identity. It
	 *                           must be stable across every node which shares the
	 *                           resource. Null keeps the historical path/site scope.
	 */
	public function __construct( string $resource_name, ?string $scope = null ) {
		$site_id        = \function_exists( 'get_current_blog_id' ) ? (int) \get_current_blog_id() : 0;
		$resource_scope = null === $scope
			? ( \defined( 'ABSPATH' ) ? (string) ABSPATH : '' ) . '|' . $site_id
			: $scope;

		$this->name = self::LOCK_NAME_PREFIX . \substr(
			\hash( 'sha256', $resource_scope . '|' . $resource_name ),
			0,
			self::LOCK_HASH_LENGTH
		);
	}

	/**
	 * Acquire the named advisory lock without waiting.
	 */
	public function acquire(): bool {
		if ( null !== $this->connection_id ) {
			return $this->maintain();
		}

		if ( $this->lost ) {
			return false;
		}

		if ( $this->should_use_test_lock() ) {
			if ( isset( self::$test_locks[ $this->name ] ) ) {
				return false;
			}

			$this->using_test_lock           = true;
			$this->connection_id             = self::$next_test_connection_id++;
			self::$test_locks[ $this->name ] = $this->connection_id;
			return true;
		}

		if ( ! $this->has_supported_database_session() ) {
			return false;
		}

		global $wpdb;
		$acquired = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A connection-bound advisory lock cannot use the options API or an object cache.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->name )
		);
		if ( ! $this->is_success_result( $acquired ) ) {
			return false;
		}

		$connection_id = $this->read_connection_id();
		if ( null === $connection_id ) {
			$this->release_unverified_database_lock();
			return false;
		}

		if ( ! $this->database_session_is_current( $connection_id ) ) {
			$this->release_unverified_database_lock();
			return false;
		}

		$this->connection_id = $connection_id;
		return true;
	}

	/**
	 * Prove that the same database connection still owns the advisory lock.
	 */
	public function maintain(): bool {
		if ( null === $this->connection_id || $this->lost ) {
			return false;
		}

		if ( $this->using_test_lock ) {
			if ( ( self::$test_locks[ $this->name ] ?? null ) === $this->connection_id ) {
				return true;
			}

			$this->lost = true;
			return false;
		}

		if ( ! $this->has_supported_database_session() ) {
			$this->lost = true;
			return false;
		}

		if ( ! $this->database_session_is_current( $this->connection_id ) ) {
			$this->lost = true;
			return false;
		}

		return true;
	}

	/**
	 * Return verified fence identity, or null when this request no longer owns it.
	 *
	 * @return array{name:string,connection_id:int}|null
	 */
	public function get_fence(): ?array {
		if ( ! $this->maintain() ) {
			return null;
		}

		return array(
			'name'          => $this->name,
			'connection_id' => (int) $this->connection_id,
		);
	}

	public function is_lost(): bool {
		return $this->lost;
	}

	/**
	 * Whether this request can prove that advisory-lock statements stay on one
	 * database connection. Unsupported routing drop-ins must fail static
	 * mutation closed without preventing unrelated dynamic/plugin upgrades.
	 */
	public function is_supported(): bool {
		return $this->should_use_test_lock() || $this->has_supported_database_session();
	}

	/**
	 * Release the advisory lock when the original connection can still be proven.
	 */
	public function release(): void {
		$connection_id = $this->connection_id;
		if ( null === $connection_id ) {
			$this->lost = false;
			return;
		}

		if ( $this->using_test_lock ) {
			if ( ( self::$test_locks[ $this->name ] ?? null ) === $connection_id ) {
				unset( self::$test_locks[ $this->name ] );
			}
		} elseif ( $this->has_supported_database_session() ) {
			if ( $this->database_session_is_current( $connection_id ) ) {
				$this->release_unverified_database_lock();
			}
		}

		$this->connection_id   = null;
		$this->lost            = false;
		$this->using_test_lock = false;
	}

	private function should_use_test_lock(): bool {
		return \defined( 'CYBERMAPS_PHPUNIT' )
			&& true === CYBERMAPS_PHPUNIT
			&& empty( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );
	}

	/**
	 * Core wpdb keeps reads and advisory-lock calls on one known connection.
	 * Database drop-ins can route individual queries independently, so they are
	 * rejected unless a dedicated test explicitly opts into the SQL path.
	 */
	private function has_supported_database_session(): bool {
		global $wpdb;
		if (
			! \is_object( $wpdb )
			|| ! \method_exists( $wpdb, 'prepare' )
			|| ! \method_exists( $wpdb, 'get_var' )
		) {
			return false;
		}

		if (
			\defined( 'CYBERMAPS_PHPUNIT' )
			&& true === CYBERMAPS_PHPUNIT
			&& true === ( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] ?? null )
		) {
			return true;
		}

		return 'wpdb' === \get_class( $wpdb );
	}

	private function read_connection_id(): ?int {
		global $wpdb;
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection identity must be read from the same live wpdb session.
			'SELECT CONNECTION_ID()'
		);
		return $this->positive_integer( $value );
	}

	private function database_session_is_current( int $connection_id ): bool {
		global $wpdb;
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory-lock ownership is connection-local and cannot be cached.
			$wpdb->prepare(
				'SELECT (IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d)',
				$this->name,
				$connection_id,
				$connection_id
			)
		);
		return $this->is_success_result( $value );
	}

	private function release_unverified_database_lock(): void {
		global $wpdb;
		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- RELEASE_LOCK() must run on the owning live database session.
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name )
		);
	}

	private function is_success_result( mixed $value ): bool {
		return 1 === $value || '1' === $value;
	}

	private function positive_integer( mixed $value ): ?int {
		if ( \is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! \is_string( $value ) || '' === $value || ! \ctype_digit( $value ) ) {
			return null;
		}

		$integer = (int) $value;
		return $integer > 0 ? $integer : null;
	}
}
