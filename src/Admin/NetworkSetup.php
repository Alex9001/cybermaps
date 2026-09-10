<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\DatabaseSessionLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NetworkSetup {
	public const RETRY_HOOK                  = 'cybermaps_retry_translation_schema_upgrade';
	private const TRANSLATION_SCHEMA_VERSION = '3';
	private const TRANSLATION_SCHEMA_OPTION  = 'cybermaps_translation_schema_version';
	private const UPGRADE_STATE_OPTION       = 'cybermaps_translation_schema_upgrade_state';
	private const UPGRADE_LOCK_RESOURCE      = 'cybermaps_translation_schema_upgrade';
	private const MAX_ATTEMPTS               = 10;

	private static ?DatabaseSessionLock $upgrade_lock = null;

	/**
	 * Upgrade the shared registry only when its schema version changes.
	 *
	 * Every mutating entry path (init, retry, or forced activation) is serialized
	 * by one network-scoped database-session fence. Schema and coordination
	 * writes additionally compare the exact value observed under that fence.
	 */
	public static function maybe_upgrade( bool $force = false ): bool {
		$decision = self::prelock_upgrade_decision( $force );
		if ( null !== $decision ) {
			return $decision;
		}
		if ( ! self::acquire_lock() ) {
			return false;
		}

		try {
			return self::upgrade_under_lock( $force );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Return null when a fenced upgrade attempt is required.
	 */
	private static function prelock_upgrade_decision( bool $force ): ?bool {
		$schema_observation = self::read_site_option( self::TRANSLATION_SCHEMA_OPTION );
		$schema             = self::schema_version( $schema_observation );
		if ( version_compare( $schema, self::TRANSLATION_SCHEMA_VERSION, '>' ) ) {
			return true;
		}

		$state_observation = self::read_site_option( self::UPGRADE_STATE_OPTION );
		if ( ! self::state_belongs_to_current_target( $state_observation ) ) {
			return false;
		}
		$state = self::normalize_state( $state_observation );
		if (
			version_compare( $schema, self::TRANSLATION_SCHEMA_VERSION, '<' )
			&& ! $force
			&& (int) $state['next_retry'] > time()
		) {
			return false;
		}
		if (
			0 === version_compare( $schema, self::TRANSLATION_SCHEMA_VERSION )
			&& ! $state_observation['exists']
			&& false === wp_next_scheduled( self::RETRY_HOOK )
		) {
			return true;
		}

		return null;
	}

	/**
	 * Execute one upgrade attempt while the session fence is held.
	 */
	private static function upgrade_under_lock( bool $force ): bool {
		$schema_observation = self::read_site_option( self::TRANSLATION_SCHEMA_OPTION );
		$schema             = self::schema_version( $schema_observation );
		if ( version_compare( $schema, self::TRANSLATION_SCHEMA_VERSION, '>' ) ) {
			return true;
		}

		$state_observation = self::read_site_option( self::UPGRADE_STATE_OPTION );
		if ( ! self::state_belongs_to_current_target( $state_observation ) ) {
			return false;
		}
		$state = self::normalize_state( $state_observation );
		if ( 0 === version_compare( $schema, self::TRANSLATION_SCHEMA_VERSION ) ) {
			return self::clear_current_coordination( $state_observation );
		}
		if ( ! $force && (int) $state['next_retry'] > time() ) {
			return false;
		}
		if ( ! self::claim_upgrade_state( $state_observation, $state ) ) {
			return false;
		}

		$tables_ready = self::create_tables();
		if ( ! self::maintain_lock() ) {
			return false;
		}
		if ( ! $tables_ready ) {
			return self::record_failure_under_lock();
		}

		return self::stamp_schema_and_clear( $state_observation );
	}

	/**
	 * Claim this target through an exact CAS before dbDelta runs plugin code.
	 *
	 * @param array{stored:mixed,exists:bool} $observation Mutable state observation.
	 * @param array<string,mixed>             $state       Normalized current state.
	 */
	private static function claim_upgrade_state( array &$observation, array $state ): bool {
		$claimed               = $state;
		$claimed['updated_at'] = time();
		if ( $observation['exists'] && $claimed === $observation['stored'] ) {
			return true;
		}
		if ( ! self::replace_site_option_under_lock( self::UPGRADE_STATE_OPTION, $claimed, $observation['stored'], $observation['exists'] ) ) {
			return false;
		}
		$observation = array(
			'stored' => $claimed,
			'exists' => true,
		);
		return true;
	}

	/**
	 * Stamp monotonically and clear only the claimed coordination state.
	 *
	 * @param array{stored:mixed,exists:bool} $state_observation Claimed state.
	 */
	private static function stamp_schema_and_clear( array $state_observation ): bool {
		$schema_observation = self::read_site_option( self::TRANSLATION_SCHEMA_OPTION );
		$schema             = self::schema_version( $schema_observation );
		if ( version_compare( $schema, self::TRANSLATION_SCHEMA_VERSION, '>' ) ) {
			return true;
		}
		if (
			version_compare( $schema, self::TRANSLATION_SCHEMA_VERSION, '<' )
			&& ! self::replace_site_option_under_lock(
				self::TRANSLATION_SCHEMA_OPTION,
				self::TRANSLATION_SCHEMA_VERSION,
				$schema_observation['stored'],
				$schema_observation['exists']
			)
		) {
			return false;
		}

		if ( ! self::maintain_lock() ) {
			return false;
		}
		return self::clear_current_coordination( $state_observation );
	}

	/**
	 * Create the global translation registry table.
	 * Use $wpdb->base_prefix to ensure it's a single global table in multisite.
	 *
	 * The coordinator owns schema stamping; this method only creates and verifies
	 * the physical contract so it cannot independently downgrade a future option.
	 *
	 * @return bool Whether the required unique identity index exists.
	 */
	public static function create_tables(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->base_prefix ) ) {
			return false;
		}

		$table_name      = $wpdb->base_prefix . 'cybermaps_translations';
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			// Older releases allowed duplicate rows during concurrent saves.
			// Retain the newest row before adding the unique identity contract.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The schema migration must reconcile persisted duplicate rows before adding the unique index.
			$deduplicated = $wpdb->query(
				$wpdb->prepare(
					'DELETE older
                FROM %i AS older
                INNER JOIN %i AS newer
                    ON newer.site_id = older.site_id
                    AND newer.item_id = older.item_id
                    AND newer.item_type = older.item_type
					AND newer.id > older.id',
					$table_name,
					$table_name
				)
			);
			// phpcs:enable

			if ( false === $deduplicated ) {
				return false;
			}

			if ( $deduplicated > 0 ) {
				// A retained row may belong to a different group than the
				// duplicate it replaced. Reconcile every represented site's
				// translation and sitemap caches, not only the activation site.
				( new \Cybermaps\Core\TranslationRegistry() )->invalidate_all_relationships();
			}
		}

		$sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            group_id bigint(20) NOT NULL,
            site_id bigint(20) NOT NULL,
            item_id bigint(20) NOT NULL,
            item_type varchar(20) DEFAULT 'post' NOT NULL,
            lang_code varchar(35) NOT NULL,
            PRIMARY KEY  (id),
            KEY group_id (group_id),
            UNIQUE KEY site_item_type (site_id, item_id, item_type)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return self::has_unique_identity_index( $table_name );
	}

	/**
	 * @param array{stored:mixed,exists:bool} $observation Exact state observation.
	 * @return array{target:string,attempts:int,next_retry:int,last_error:string,updated_at:int}
	 */
	private static function normalize_state( array $observation ): array {
		$stored = $observation['exists'] && is_array( $observation['stored'] )
			? $observation['stored']
			: array();

		return array(
			'target'     => self::TRANSLATION_SCHEMA_VERSION,
			'attempts'   => max( 0, min( self::MAX_ATTEMPTS, (int) ( $stored['attempts'] ?? 0 ) ) ),
			'next_retry' => max( 0, (int) ( $stored['next_retry'] ?? 0 ) ),
			'last_error' => sanitize_text_field( (string) ( $stored['last_error'] ?? '' ) ),
			'updated_at' => max( 0, (int) ( $stored['updated_at'] ?? 0 ) ),
		);
	}

	/**
	 * A nonempty or malformed foreign target is owned by another release.
	 *
	 * @param array{stored:mixed,exists:bool} $observation Exact state observation.
	 */
	private static function state_belongs_to_current_target( array $observation ): bool {
		if ( ! $observation['exists'] ) {
			return true;
		}
		if ( ! is_array( $observation['stored'] ) ) {
			return false;
		}

		$target = $observation['stored']['target'] ?? '';
		if ( null === $target || '' === $target ) {
			return true;
		}

		return is_scalar( $target )
			&& self::TRANSLATION_SCHEMA_VERSION === (string) $target;
	}

	/**
	 * @param array{stored:mixed,exists:bool} $observation Exact option observation.
	 */
	private static function schema_version( array $observation ): string {
		if ( ! $observation['exists'] || ! is_scalar( $observation['stored'] ) ) {
			return '0';
		}

		return (string) $observation['stored'];
	}

	private static function record_failure_under_lock(): bool {
		$observation = self::read_site_option( self::UPGRADE_STATE_OPTION );
		if ( ! self::state_belongs_to_current_target( $observation ) ) {
			return false;
		}

		$state               = self::normalize_state( $observation );
		$state['attempts']   = min( self::MAX_ATTEMPTS, (int) $state['attempts'] + 1 );
		$state['next_retry'] = time() + min( HOUR_IN_SECONDS, 30 * ( 2 ** ( (int) $state['attempts'] - 1 ) ) );
		$state['last_error'] = __( 'The translation registry schema or unique identity index could not be verified.', 'cybermaps' );
		$state['updated_at'] = time();
		if (
			! self::replace_site_option_under_lock(
				self::UPGRADE_STATE_OPTION,
				$state,
				$observation['stored'],
				$observation['exists']
			)
		) {
			return false;
		}

		if ( ! self::maintain_lock() ) {
			return false;
		}
		$stored = self::read_site_option( self::UPGRADE_STATE_OPTION );
		if ( ! $stored['exists'] || $state !== $stored['stored'] ) {
			return false;
		}
		if ( false === wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( (int) $state['next_retry'], self::RETRY_HOOK );
		}

		return false;
	}

	/**
	 * Remove only the exact current-target checkpoint while the same fence is
	 * held. Retry cleanup is allowed only after re-reading an exact settled
	 * schema and observing no successor state.
	 *
	 * @param array{stored:mixed,exists:bool} $observation Exact state observation.
	 */
	private static function clear_current_coordination( array $observation ): bool {
		if (
			! self::delete_site_option_under_lock(
				self::UPGRADE_STATE_OPTION,
				$observation['stored'],
				$observation['exists']
			)
		) {
			return false;
		}
		if ( ! self::coordination_is_settled_under_lock() ) {
			return false;
		}

		if ( false !== wp_next_scheduled( self::RETRY_HOOK ) ) {
			if ( ! self::coordination_is_settled_under_lock() ) {
				return false;
			}
			wp_clear_scheduled_hook( self::RETRY_HOOK );
		}

		return true;
	}

	private static function coordination_is_settled_under_lock(): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}

		$schema = self::schema_version( self::read_site_option( self::TRANSLATION_SCHEMA_OPTION ) );
		$state  = self::read_site_option( self::UPGRADE_STATE_OPTION );
		return 0 === version_compare( $schema, self::TRANSLATION_SCHEMA_VERSION )
			&& ! $state['exists'];
	}

	private static function get_lock(): DatabaseSessionLock {
		if ( null === self::$upgrade_lock ) {
			global $wpdb;
			$table_identity     = is_object( $wpdb ) && isset( $wpdb->base_prefix )
				? (string) $wpdb->base_prefix . 'cybermaps_translations'
				: 'cybermaps_translations';
			self::$upgrade_lock = new DatabaseSessionLock(
				self::UPGRADE_LOCK_RESOURCE,
				'table:' . $table_identity
			);
		}

		return self::$upgrade_lock;
	}

	private static function acquire_lock(): bool {
		return self::get_lock()->acquire();
	}

	private static function maintain_lock(): bool {
		return null !== self::$upgrade_lock && self::$upgrade_lock->maintain();
	}

	private static function release_lock(): void {
		if ( null !== self::$upgrade_lock ) {
			self::$upgrade_lock->release();
		}
	}

	/**
	 * @return array{stored:mixed,exists:bool}
	 */
	private static function read_site_option( string $option ): array {
		$missing = new \stdClass();
		$stored  = get_site_option( $option, $missing );

		return array(
			'stored' => $stored,
			'exists' => $missing !== $stored,
		);
	}

	/**
	 * @param mixed $expected Exact logical site-option value last observed.
	 */
	private static function replace_site_option_under_lock( string $option, mixed $value, mixed $expected, bool $expected_exists ): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}

		$store = self::direct_store();
		if ( null !== $store ) {
			return self::replace_direct_site_option( $option, $value, $expected, $expected_exists, $store );
		}

		return self::replace_logical_test_option( $option, $value, $expected, $expected_exists );
	}

	/**
	 * Replace a directly observed option while proving the session fence in SQL.
	 *
	 * @param array{kind:'network'|'site',table:string,network_id:int} $store Store descriptor.
	 */
	private static function replace_direct_site_option( string $option, mixed $value, mixed $expected, bool $expected_exists, array $store ): bool {
		$fence = self::get_lock()->get_fence();
		if ( null === $fence ) {
			return false;
		}
		$current_raw  = self::read_raw_site_option( $option, $store );
		$expected_raw = $expected_exists ? self::serialize_option_value( $expected ) : null;
		if ( $current_raw !== $expected_raw ) {
			return false;
		}
		$value_raw = self::serialize_option_value( $value );
		if ( $expected_exists && $current_raw === $value_raw ) {
			return self::maintain_lock();
		}

		$updated = $expected_exists
			? self::update_direct_site_option( $option, $value_raw, (string) $current_raw, $store, $fence )
			: self::insert_direct_site_option( $option, $value_raw, $store, $fence );
		if ( 1 !== (int) $updated ) {
			return false;
		}
		self::invalidate_site_option_cache( $option, $store );
		return true;
	}

	/**
	 * Update one existing direct-store row.
	 *
	 * @param array{kind:'network'|'site',table:string,network_id:int} $store Store descriptor.
	 * @param array{name:string,connection_id:int} $fence Session fence.
	 */
	private static function update_direct_site_option( string $option, string $value_raw, string $current_raw, array $store, array $fence ): int|false {
		global $wpdb;
		if ( 'network' === $store['kind'] ) {
			return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact site-option CAS and session-fence proof must share one statement.
				$wpdb->prepare(
					'UPDATE %i SET meta_value = %s WHERE site_id = %d AND meta_key = %s AND BINARY meta_value = BINARY %s AND IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d',
					$store['table'],
					$value_raw,
					$store['network_id'],
					$option,
					$current_raw,
					$fence['name'],
					$fence['connection_id'],
					$fence['connection_id']
				)
			);
		}

		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact option CAS and session-fence proof must share one statement.
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = BINARY %s AND IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d',
				$store['table'],
				$value_raw,
				'off',
				$option,
				$current_raw,
				$fence['name'],
				$fence['connection_id'],
				$fence['connection_id']
			)
		);
	}

	/**
	 * Insert one missing direct-store row.
	 *
	 * @param array{kind:'network'|'site',table:string,network_id:int} $store Store descriptor.
	 * @param array{name:string,connection_id:int} $fence Session fence.
	 */
	private static function insert_direct_site_option( string $option, string $value_raw, array $store, array $fence ): int|false {
		global $wpdb;
		if ( 'network' === $store['kind'] ) {
			return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert-only site-option CAS and session-fence proof must share one statement.
				$wpdb->prepare(
					'INSERT INTO %i (site_id, meta_key, meta_value) SELECT %d, %s, %s FROM DUAL WHERE IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d AND NOT EXISTS (SELECT 1 FROM %i WHERE site_id = %d AND meta_key = %s)',
					$store['table'],
					$store['network_id'],
					$option,
					$value_raw,
					$fence['name'],
					$fence['connection_id'],
					$fence['connection_id'],
					$store['table'],
					$store['network_id'],
					$option
				)
			);
		}

		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert-only option CAS and session-fence proof must share one statement.
			$wpdb->prepare(
				'INSERT INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d AND NOT EXISTS (SELECT 1 FROM %i WHERE option_name = %s)',
				$store['table'],
				$option,
				$value_raw,
				'off',
				$fence['name'],
				$fence['connection_id'],
				$fence['connection_id'],
				$store['table'],
				$option
			)
		);
	}

	/**
	 * Use the explicit PHPUnit logical-store fallback; production fails closed.
	 */
	private static function replace_logical_test_option( string $option, mixed $value, mixed $expected, bool $expected_exists ): bool {
		if ( ! self::is_phpunit() || ! self::logical_site_option_matches( $option, $expected, $expected_exists ) ) {
			return false;
		}
		if ( $expected_exists ) {
			update_site_option( $option, $value );
		} elseif ( ! add_site_option( $option, $value ) ) {
			return false;
		}

		return self::maintain_lock() && get_site_option( $option, null ) === $value;
	}

	/**
	 * @param mixed $expected Exact logical site-option value last observed.
	 */
	private static function delete_site_option_under_lock( string $option, mixed $expected, bool $expected_exists ): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}
		if ( ! $expected_exists ) {
			return true;
		}

		$store = self::direct_store();
		if ( null !== $store ) {
			$fence = self::get_lock()->get_fence();
			if ( null === $fence ) {
				return false;
			}

			$expected_raw = self::serialize_option_value( $expected );
			global $wpdb;
			if ( 'network' === $store['kind'] ) {
				$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact site-option cleanup and session-fence proof must share one statement.
					$wpdb->prepare(
						'DELETE FROM %i WHERE site_id = %d AND meta_key = %s AND BINARY meta_value = BINARY %s AND IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d',
						$store['table'],
						$store['network_id'],
						$option,
						$expected_raw,
						$fence['name'],
						$fence['connection_id'],
						$fence['connection_id']
					)
				);
			} else {
				$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact option cleanup and session-fence proof must share one statement.
					$wpdb->prepare(
						'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s AND IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d',
						$store['table'],
						$option,
						$expected_raw,
						$fence['name'],
						$fence['connection_id'],
						$fence['connection_id']
					)
				);
			}
			if ( 1 !== (int) $deleted ) {
				return false;
			}
			self::invalidate_site_option_cache( $option, $store );
			return true;
		}

		if ( ! self::is_phpunit() || ! self::logical_site_option_matches( $option, $expected, true ) ) {
			return false;
		}
		delete_site_option( $option );

		return self::maintain_lock();
	}

	/**
	 * @return array{kind:'network'|'site',table:string,network_id:int}|null
	 */
	private static function direct_store(): ?array {
		global $wpdb;
		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_var' )
			|| ! method_exists( $wpdb, 'query' )
		) {
			return null;
		}

		if ( is_multisite() ) {
			if ( ! isset( $wpdb->sitemeta ) || ! is_string( $wpdb->sitemeta ) ) {
				return null;
			}
			return array(
				'kind'       => 'network',
				'table'      => $wpdb->sitemeta,
				'network_id' => max( 1, (int) get_current_network_id() ),
			);
		}

		if ( ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) ) {
			return null;
		}
		return array(
			'kind'       => 'site',
			'table'      => $wpdb->options,
			'network_id' => 0,
		);
	}

	/**
	 * @param array{kind:'network'|'site',table:string,network_id:int} $store Store descriptor.
	 */
	private static function read_raw_site_option( string $option, array $store ): ?string {
		global $wpdb;
		if ( 'network' === $store['kind'] ) {
			$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact bytes are required for the site-option CAS.
				$wpdb->prepare(
					'SELECT meta_value FROM %i WHERE site_id = %d AND meta_key = %s LIMIT 1',
					$store['table'],
					$store['network_id'],
					$option
				)
			);
		} else {
			$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact bytes are required for the option CAS.
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
					$store['table'],
					$option
				)
			);
		}

		return is_string( $raw ) ? $raw : null;
	}

	/**
	 * @param array{kind:'network'|'site',table:string,network_id:int} $store Store descriptor.
	 */
	private static function invalidate_site_option_cache( string $option, array $store ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		if ( 'network' === $store['kind'] ) {
			wp_cache_delete( $store['network_id'] . ':' . $option, 'site-options' );
			wp_cache_delete( $store['network_id'] . ':notoptions', 'site-options' );
			return;
		}

		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function logical_site_option_matches( string $option, mixed $expected, bool $expected_exists ): bool {
		$observation = self::read_site_option( $option );
		return $expected_exists === $observation['exists']
			&& ( ! $expected_exists || $expected === $observation['stored'] );
	}

	private static function serialize_option_value( mixed $value ): string {
		return function_exists( 'maybe_serialize' )
			? (string) maybe_serialize( $value )
			: serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	private static function is_phpunit(): bool {
		return defined( 'CYBERMAPS_PHPUNIT' ) && true === CYBERMAPS_PHPUNIT;
	}

	/**
	 * Determine whether the shared registry table already exists.
	 */
	private static function table_exists( string $table_name ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema verification must query the database catalog directly.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table_name )
			)
		);
		// phpcs:enable

		return $table_name === (string) $found;
	}

	/**
	 * Verify that dbDelta installed the unique row-identity contract.
	 */
	private static function has_unique_identity_index( string $table_name ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema verification must query the database catalog directly.
		$index = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM %i
            WHERE Key_name = 'site_item_type'
				AND Non_unique = 0",
				$table_name
			),
			2
		);
		// phpcs:enable

		return 'site_item_type' === (string) $index;
	}
}
