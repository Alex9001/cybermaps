<?php
declare(strict_types=1);

namespace Cybermaps\Core;

use Cybermaps\Discovery\StaticOwnershipStore;
use Cybermaps\Discovery\StaticWriteIntentStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs bounded one-time data migrations for the current Core foundation.
 */
final class Upgrade {
	public const RETRY_HOOK                       = 'cybermaps_retry_upgrade';
	private const DATA_VERSION_OPTION             = 'cybermaps_data_version';
	private const STATE_OPTION                    = 'cybermaps_upgrade_state';
	private const LOCK_OPTION                     = 'cybermaps_upgrade_lock';
	private const LOCK_TTL                        = 300;
	private const LOCK_RENEW_INTERVAL             = 60;
	private const TARGET_DATA_VERSION             = '6.6.0';
	private const MIGRATION_STEPS                 = array(
		'schedule_logs',
		'migrate_sitemap_meta',
		'normalize_settings',
		'normalize_discovery',
		'normalize_config_autoload',
		'remove_retired_state',
		'migrate_static_ownership',
		'upgrade_audit_schema',
		'upgrade_oauth_schema',
		'reconcile_publications',
	);
	private static ?OptionLeaseLock $upgrade_lock = null;

	public static function run(): void {
		$current = (string) get_option( self::DATA_VERSION_OPTION, '0.0.0' );
		if ( version_compare( $current, self::TARGET_DATA_VERSION, '>' ) ) {
			// A newer plugin owns that schema. Never replay, clean up, or downgrade it.
			return;
		}

		\Cybermaps\Admin\NetworkSetup::maybe_upgrade();

		if ( version_compare( $current, self::TARGET_DATA_VERSION, '>=' ) ) {
			self::run_settled_upgrade();
			return;
		}

		self::run_pending_upgrade();
	}

	private static function run_pending_upgrade(): void {
		// A table can be removed or an activation can be interrupted after the
		// version checkpoint. Re-provision these idempotent runtime supports on
		// every current-version request instead of requiring a version bump.
		$raw_state = self::read_option( self::STATE_OPTION );
		if ( ! self::state_belongs_to_current_target( $raw_state ) ) {
			return;
		}
		$state = self::normalize_state_observation( $raw_state )['state'];
		if ( (int) $state['next_retry'] > time() ) {
			self::schedule_retry( (int) $state['next_retry'] );
			return;
		}
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			self::run_locked_upgrade();
		} finally {
			self::release_lock();
		}
	}

	private static function run_settled_upgrade(): void {
		self::schedule_logs();
		$observation = self::read_option( self::STATE_OPTION );
		if ( ! self::state_belongs_to_current_target( $observation ) ) {
			return;
		}
		$state = self::normalize_state_observation( $observation )['state'];
		if ( 1 === (int) $state['static_pending'] ) {
			self::retry_deferred_static_migration();
			return;
		}
		if ( false !== get_option( self::STATE_OPTION, false ) || false !== wp_next_scheduled( self::RETRY_HOOK ) ) {
			self::clear_completed_coordination_state();
		}
	}

	private static function run_locked_upgrade(): void {
		$version_observation = self::read_option( self::DATA_VERSION_OPTION );
		$locked_version      = self::observed_version( $version_observation );
		$state_observation   = self::read_option( self::STATE_OPTION );
		if ( ! self::state_belongs_to_current_target( $state_observation ) || version_compare( $locked_version, self::TARGET_DATA_VERSION, '>' ) ) {
			return;
		}
		$state_data   = self::normalize_state_observation( $state_observation );
		$state        = $state_data['state'];
		$state_stored = $state_data['stored'];
		$state_exists = $state_data['exists'];
		if ( version_compare( $locked_version, self::TARGET_DATA_VERSION, '>=' ) ) {
			self::handle_settled_upgrade_under_lease( $state_data );
			return;
		}
		if ( (int) $state['next_retry'] > time() ) {
			if ( self::maintain_lock() ) {
				self::schedule_retry( (int) $state['next_retry'] );
			}
			return;
		}
		if ( ! self::run_migration_steps( $state, $state_stored, $state_exists ) ) {
			return;
		}
		self::finalize_migration( $state, $state_stored, $state_exists );
	}

	/** @param array<string,int|string> $state @param mixed $state_stored */
	private static function run_migration_steps( array &$state, mixed &$state_stored, bool &$state_exists ): bool {
		$step_count = count( self::MIGRATION_STEPS );
		while ( (int) $state['step'] < $step_count ) {
			if ( ! self::maintain_lock() ) {
				return false;
			}
			$step = self::MIGRATION_STEPS[ (int) $state['step'] ];
			if ( ! self::run_migration_step( $step, $state, $state_stored, $state_exists ) ) {
				return false;
			}
			if ( ! self::complete_migration_step( $state, $state_stored, $state_exists ) ) {
				return false;
			}
			if ( 1 === (int) $state['static_pending'] ) {
				if ( ! self::maintain_lock() ) {
					return false;
				}
				self::schedule_retry( (int) $state['static_next_retry'] );
			}
		}
		return true;
	}

	/** @param array<string,int|string> $state @param mixed $state_stored */
	private static function run_migration_step( string $step, array &$state, mixed $state_stored, bool $state_exists ): bool {
		try {
			$complete = self::run_step( $step, $state );
		} catch ( \Throwable $error ) {
			return self::record_step_failure( $state, $step, $error->getMessage(), $state_stored, $state_exists );
		}
		if ( ! self::maintain_lock() ) {
			return false;
		}
		return $complete ? true : self::record_step_failure( $state, $step, __( 'The migration step did not verify its changes.', 'cybermaps' ), $state_stored, $state_exists );
	}

	/** @param array<string,int|string> $state @param mixed $state_stored */
	private static function record_step_failure( array &$state, string $step, string $message, mixed $state_stored, bool $state_exists ): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}
		self::apply_failure( $state, $step, $message );
		if ( ! self::persist_state( $state, $state_stored, $state_exists ) ) {
			return false;
		}
		if ( self::maintain_lock() ) {
			self::schedule_retry( (int) $state['next_retry'] );
		}
		return false;
	}

	/** @param array<string,int|string> $state @param mixed $state_stored */
	private static function complete_migration_step( array &$state, mixed &$state_stored, bool &$state_exists ): bool {
		++$state['step'];
		$state['attempts']   = 0;
		$state['next_retry'] = 0;
		$state['last_error'] = '';
		$state['updated_at'] = time();
		if ( ! self::maintain_lock() || ! self::persist_state( $state, $state_stored, $state_exists ) ) {
			return false;
		}
		$state_stored = $state;
		$state_exists = true;
		return true;
	}

	/** @param array<string,int|string> $state @param mixed $state_stored */
	private static function finalize_migration( array &$state, mixed $state_stored, bool $state_exists ): void {
		if ( ! self::maintain_lock() ) {
			return;
		}
		$version_observation = self::read_option( self::DATA_VERSION_OPTION );
		$version             = self::observed_version( $version_observation );
		if ( version_compare( $version, self::TARGET_DATA_VERSION, '>' ) ) {
			return;
		}
		if ( ! self::stamp_target_version( $version, $version_observation ) || ! self::maintain_lock() ) {
			self::record_step_failure( $state, 'stamp_version', __( 'WordPress did not retain the upgraded data version.', 'cybermaps' ), $state_stored, $state_exists );
			return;
		}
		self::settle_completed_migration( $state, $state_stored, $state_exists );
	}

	/** @param array{stored:mixed,exists:bool} $observation */
	private static function observed_version( array $observation ): string {
		return $observation['exists'] && is_scalar( $observation['stored'] ) ? (string) $observation['stored'] : '0.0.0';
	}

	/** @param array{stored:mixed,exists:bool} $observation */
	private static function stamp_target_version( string $version, array $observation ): bool {
		return version_compare( $version, self::TARGET_DATA_VERSION, '>=' ) || self::lease_guarded_replace_option( self::DATA_VERSION_OPTION, self::TARGET_DATA_VERSION, $observation['stored'], $observation['exists'] );
	}

	/** @param array<string,int|string> $state @param mixed $state_stored */
	private static function settle_completed_migration( array &$state, mixed $state_stored, bool $state_exists ): void {
		if ( 1 !== (int) $state['static_pending'] ) {
			if ( self::maintain_lock() ) {
				self::clear_coordination_state_under_lease( $state_stored, $state_exists );
			}
			return;
		}
		$state['step']       = count( self::MIGRATION_STEPS );
		$state['attempts']   = 0;
		$state['next_retry'] = 0;
		$state['last_step']  = '';
		$state['last_error'] = '';
		$state['updated_at'] = time();
		if ( self::maintain_lock() && self::persist_state( $state, $state_stored, $state_exists ) && self::maintain_lock() ) {
			self::schedule_retry( (int) $state['static_next_retry'] );
		}
	}

	/**
	 * Stamp a fresh activation so it never replays historical migrations.
	 */
	public static function stamp_fresh_install(): void {
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			// Activation can race an init/retry worker. Treat every value read before
			// this lease as stale and decide freshness only from observations made
			// while holding the same lease used by run().
			$version_observation = self::read_option( self::DATA_VERSION_OPTION );
			if ( $version_observation['exists'] ) {
				return;
			}

			$state_observation = self::read_option( self::STATE_OPTION );
			if ( $state_observation['exists'] ) {
				// Any checkpoint, including malformed or foreign-target state, is
				// evidence that this is not a provably fresh installation.
				return;
			}

			foreach ( array( 'cybermaps_settings', 'cybermaps_discovery_center', 'cybermaps_robots_manager', 'cybermaps_identity_data' ) as $configuration_option ) {
				if ( self::read_option( $configuration_option )['exists'] ) {
					return;
				}
			}

			if ( ! self::maintain_lock() ) {
				return;
			}

			// Missing-row CAS is insert-only. A future/concurrent version that
			// appears after the observations above wins and is never overwritten.
			self::lease_guarded_replace_option(
				self::DATA_VERSION_OPTION,
				self::TARGET_DATA_VERSION,
				$version_observation['stored'],
				false
			);
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Return the persisted upgrade diagnostic for administration and support.
	 *
	 * @return array<string, int|string>
	 */
	public static function get_status(): array {
		return self::get_state();
	}

	public static function provision_runtime_support(): bool {
		$runtime_ready = self::ensure_runtime_counter_store();
		$queue_ready   = \Cybermaps\Discovery\IndexNowQueue::create_table();
		if ( $queue_ready ) {
			\Cybermaps\Discovery\IndexNowQueue::migrate_legacy_option();
		}

		return $runtime_ready && $queue_ready;
	}

	/**
	 * Run one named, idempotent migration step.
	 *
	 * @param array<string,int|string> $state Persisted upgrade state.
	 */
	private static function run_step( string $step, array &$state ): bool {
		if ( 'migrate_static_ownership' === $step ) {
			return self::migrate_static_ownership( $state );
		}
		$steps = array(
			'schedule_logs'             => array( self::class, 'schedule_logs' ),
			'migrate_sitemap_meta'      => array( self::class, 'migrate_sitemap_exclusion_meta' ),
			'normalize_settings'        => array( self::class, 'normalize_settings' ),
			'normalize_discovery'       => array( self::class, 'normalize_discovery_center' ),
			'normalize_config_autoload' => array( self::class, 'normalize_config_autoload' ),
			'remove_retired_state'      => array( self::class, 'remove_retired_state' ),
			'upgrade_audit_schema'      => array( self::class, 'upgrade_audit_schema' ),
			'upgrade_oauth_schema'      => array( self::class, 'upgrade_oauth_schema' ),
			'reconcile_publications'    => array( self::class, 'reconcile_publications' ),
		);
		return isset( $steps[ $step ] ) && (bool) call_user_func( $steps[ $step ] );
	}

	/**
	 * Static ownership is an optional materialization concern. Failure remains
	 * visible and retriable, but cannot hold unrelated schema/configuration
	 * migrations or the Core data-version checkpoint hostage.
	 *
	 * @param array<string,int|string> $state Persisted upgrade state.
	 */
	private static function migrate_static_ownership( array &$state ): bool {
		$result = self::attempt_static_ownership_migration();
		if ( $result['complete'] ) {
			self::clear_static_migration_failure( $state );
			return true;
		}

		self::apply_static_migration_failure( $state, $result['error'] );
		return true;
	}

	/**
	 * @return array{complete:bool,error:string}
	 */
	private static function attempt_static_ownership_migration(): array {
		$lock = new OptionLeaseLock(
			'cybermaps_static_operation_lock',
			1800,
			300,
			true,
			static fn(): bool => null === ( new StaticWriteIntentStore() )->read()
		);
		if ( ! $lock->database_fence_is_supported() ) {
			return array(
				'complete' => false,
				'error'    => __( 'Static ownership migration was deferred because this wpdb implementation cannot guarantee one connection-bound database fence. Dynamic publication remains available.', 'cybermaps' ),
			);
		}
		if ( ! $lock->acquire() ) {
			return array(
				'complete' => false,
				'error'    => __( 'Static ownership migration is waiting for the static-operation lease or its database fence.', 'cybermaps' ),
			);
		}

		try {
			$complete = ( new StaticOwnershipStore( $lock ) )->migrate_if_needed(
				static fn(): bool => $lock->maintain() && self::maintain_lock(),
				true
			);
			return array(
				'complete' => $complete,
				'error'    => $complete
					? ''
					: __( 'Static ownership migration did not verify its ownership schema and was deferred.', 'cybermaps' ),
			);
		} catch ( \Throwable $error ) {
			return array(
				'complete' => false,
				'error'    => $error->getMessage(),
			);
		} finally {
			$lock->release();
		}
	}

	private static function normalize_config_autoload(): bool {
		wp_set_option_autoload_values(
			array_fill_keys(
				array(
					'cybermaps_settings',
					'cybermaps_discovery_center',
					'cybermaps_robots_manager',
					'cybermaps_identity_data',
					RuntimeCounterStore::READY_OPTION,
					\Cybermaps\Discovery\IndexNowQueue::OPTION,
					\Cybermaps\Discovery\IndexNowQueueSchema::VERSION_OPTION,
					\Cybermaps\Discovery\IndexNowQueueRepository::LAST_RESULT_OPTION,
					'cybermaps_edge_cache_delivery_status',
					'cybermaps_edge_cache_pending_static',
				),
				false
			)
		);

		return true;
	}

	private static function schedule_logs(): bool {
		$runtime_ready  = self::provision_runtime_support();
		$logs_ready     = true;
		$counters_ready = true;

		if ( ! wp_next_scheduled( 'cybermaps_cleanup_logs_event' ) ) {
			$logs_ready = false !== wp_schedule_event( time(), 'daily', 'cybermaps_cleanup_logs_event' );
		}
		if ( ! wp_next_scheduled( Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK ) ) {
			$counters_ready = false !== wp_schedule_event( time(), 'hourly', Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK );
		}

		return $runtime_ready && $logs_ready && $counters_ready;
	}

	private static function ensure_runtime_counter_store(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( RuntimeCounterStore::schema_sql() );
		$table_name = RuntimeCounterStore::table_name();
		$exists     = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the schema immediately after dbDelta; a cached result could mark a failed installation ready.
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);
		if ( $table_name !== (string) $exists ) {
			return false;
		}

		update_option( RuntimeCounterStore::READY_OPTION, '1', false );
		wp_set_option_autoload_values(
			array(
				RuntimeCounterStore::READY_OPTION => false,
			)
		);

		return '1' === (string) get_option( RuntimeCounterStore::READY_OPTION, '' );
	}

	private static function normalize_settings(): bool {
		$settings = self::settings_array();
		self::migrate_media_discovery_intensity( $settings );
		self::migrate_rss_sitemap_types( $settings );
		self::migrate_sitemap_exclusions( $settings );
		foreach ( self::retired_setting_keys() as $retired_key ) {
			unset( $settings[ $retired_key ] );
		}
		self::normalize_setting_defaults( $settings );
		return self::save_and_verify_option( 'cybermaps_settings', $settings );
	}

	private static function settings_array(): array {
		$value = get_option( 'cybermaps_settings', array() );
		return is_array( $value ) ? $value : array();
	}

	private static function migrate_media_discovery_intensity( array &$settings ): void {
		if ( ! isset( $settings['media_discovery_intensity'] ) && isset( $settings['include_images'] ) ) {
			$settings['media_discovery_intensity'] = ! empty( $settings['include_images'] ) ? 'standard' : 'none';
		}
	}

	private static function migrate_rss_sitemap_types( array &$settings ): void {
		if ( isset( $settings['rss_sitemap_types'] ) || ! isset( $settings['rss_sitemap_post_types'] ) ) {
			return;
		}
		$legacy                        = is_array( $settings['rss_sitemap_post_types'] ) ? $settings['rss_sitemap_post_types'] : explode( ',', (string) $settings['rss_sitemap_post_types'] );
		$settings['rss_sitemap_types'] = array_values( array_unique( array_filter( array_map( static fn( mixed $post_type ): string => is_scalar( $post_type ) ? sanitize_key( (string) $post_type ) : '', $legacy ) ) ) );
	}

	private static function migrate_sitemap_exclusions( array &$settings ): void {
		if ( array_key_exists( 'ai_sitemap_exclude_ids', $settings ) ) {
			$settings['llms_exclude_ids'] = PositiveIdList::to_csv( array_merge( PositiveIdList::parse( $settings['llms_exclude_ids'] ?? '' ), PositiveIdList::parse( $settings['ai_sitemap_exclude_ids'] ) ), \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX, \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES );
		}
	}

	private static function normalize_setting_defaults( array &$settings ): void {
		if ( ! isset( $settings['static_engine_mode'] ) || ! in_array( $settings['static_engine_mode'], array( 'off', 'well_known', 'all' ), true ) ) {
			$settings['static_engine_mode'] = 'well_known';
		}
		$settings['enable_llms_full'] = ! empty( $settings['enable_llms_full'] ) ? '1' : '0';
		$settings['enable_llms_tldr'] = ! empty( $settings['enable_llms_tldr'] ) ? '1' : '0';
	}

	/**
	 * @return string[]
	 */
	private static function retired_setting_keys(): array {
		return array(
			'enable_static_engine',
			'enable_semantic_snippets',
			'ai_custom_skill_prompt',
			'enterprise_features_enabled',
			'llms_tldr_threshold',
			'llms_tldr_pool_size',
			'report_retention',
			'report_include_health',
			'report_include_discovery',
			'report_include_technical',
			'report_include_schema',
			'report_include_priorities',
			'report_section_order',
			'show_cybermaps_attribution',
			'ai_contact_email',
			'include_images',
			'max_urls_per_chunk',
			'post_types',
			'taxonomies',
			'exclude_tags',
			'priority_comment_weight',
			'llms_auto_sync',
			'llms_targeted_bots',
			'llms_targeted_bots_manual',
			'knowledge_graph_url',
			'manual_intent_conversion',
			'manual_intent_research',
			'rss_sitemap_post_types',
			'ai_sitemap_exclude_ids',
		);
	}

	private static function remove_retired_state(): bool {
		delete_option( 'cybermaps_health_history' );
		delete_option( 'cybermaps_llms_yaml_cache' );
		delete_option( 'cybermaps_llms_full_yaml_cache' );
		foreach ( array( 'cybermaps_daily_health_snapshot', 'cybermaps_weekly_health_snapshot', 'cybermaps_weekly_health_check' ) as $retired_hook ) {
			wp_clear_scheduled_hook( $retired_hook );
		}
		foreach ( array( 'cybermaps_health_stats', 'cybermaps_moat_stats', 'cybermaps_knowledge_saturation', 'cybermaps_llms_yaml_cache', 'cybermaps_llms_full_yaml_cache' ) as $retired_transient ) {
			delete_transient( $retired_transient );
		}

		return true;
	}

	private static function upgrade_audit_schema(): bool {
		\Cybermaps\Audit\AuditRunRepository::create_tables();

		return \Cybermaps\Audit\AuditRunRepository::SCHEMA_VERSION
			=== (string) get_option( 'cybermaps_audit_schema_version', '' );
	}

	private static function upgrade_oauth_schema(): bool {
		\Cybermaps\MCP\OAuth\WpdbOAuthRepository::create_tables();

		return \Cybermaps\MCP\OAuth\WpdbOAuthRepository::SCHEMA_VERSION
			=== (string) get_option( \Cybermaps\MCP\OAuth\WpdbOAuthRepository::SCHEMA_OPTION, '' );
	}

	private static function reconcile_publications(): bool {
		CacheManager::clear_family( 'translations' );
		CacheManager::clear_family( 'sitemap' );
		CacheManager::clear_family( 'discovery' );
		\Cybermaps\Sitemap\Orchestrator::add_rewrite_rules();
		\flush_rewrite_rules();
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$bridge->invalidate();
		$bridge->request_sync();

		return true;
	}

	/**
	 * @return array{target:string,step:int,attempts:int,next_retry:int,last_step:string,last_error:string,updated_at:int,static_pending:int,static_attempts:int,static_next_retry:int,static_last_error:string,static_updated_at:int}
	 */
	private static function get_state(): array {
		return self::read_state()['state'];
	}

	/**
	 * Read both the normalized state and the exact logical value on which its
	 * next compare-and-swap must be based.
	 *
	 * @return array{state:array{target:string,step:int,attempts:int,next_retry:int,last_step:string,last_error:string,updated_at:int,static_pending:int,static_attempts:int,static_next_retry:int,static_last_error:string,static_updated_at:int},stored:mixed,exists:bool}
	 */
	private static function read_state(): array {
		return self::normalize_state_observation( self::read_option( self::STATE_OPTION ) );
	}

	/**
	 * @param array{stored:mixed,exists:bool} $observation Raw state option.
	 * @return array{state:array{target:string,step:int,attempts:int,next_retry:int,last_step:string,last_error:string,updated_at:int,static_pending:int,static_attempts:int,static_next_retry:int,static_last_error:string,static_updated_at:int},stored:mixed,exists:bool}
	 */
	private static function normalize_state_observation( array $observation ): array {
		$stored = self::state_storage( $observation );

		$state = array(
			'target'            => self::TARGET_DATA_VERSION,
			'step'              => self::state_integer( $stored, 'step', count( self::MIGRATION_STEPS ) ),
			'attempts'          => self::state_integer( $stored, 'attempts' ),
			'next_retry'        => self::state_integer( $stored, 'next_retry' ),
			'last_step'         => self::state_key( $stored, 'last_step' ),
			'last_error'        => self::state_text( $stored, 'last_error' ),
			'updated_at'        => self::state_integer( $stored, 'updated_at' ),
			'static_pending'    => empty( $stored['static_pending'] ) ? 0 : 1,
			'static_attempts'   => self::state_integer( $stored, 'static_attempts', 10 ),
			'static_next_retry' => self::state_integer( $stored, 'static_next_retry' ),
			'static_last_error' => self::state_text( $stored, 'static_last_error' ),
			'static_updated_at' => self::state_integer( $stored, 'static_updated_at' ),
		);

		return array(
			'state'  => $state,
			'stored' => $observation['stored'],
			'exists' => $observation['exists'],
		);
	}

	private static function state_storage( array $observation ): array {
		$stored = $observation['exists'] && is_array( $observation['stored'] ) ? $observation['stored'] : array();
		return self::TARGET_DATA_VERSION === (string) ( $stored['target'] ?? '' ) ? $stored : array();
	}

	private static function state_integer( array $state, string $key, ?int $maximum = null ): int {
		$value = max( 0, (int) ( $state[ $key ] ?? 0 ) );
		return null === $maximum ? $value : min( $maximum, $value );
	}

	private static function state_key( array $state, string $key ): string {
		return sanitize_key( (string) ( $state[ $key ] ?? '' ) );
	}

	private static function state_text( array $state, string $key ): string {
		return sanitize_text_field( (string) ( $state[ $key ] ?? '' ) );
	}

	/**
	 * A nonempty foreign target is coordination owned by another release. It
	 * must remain byte-for-byte untouched, including its scheduled retry.
	 *
	 * @param array{stored:mixed,exists:bool} $observation Raw state option.
	 */
	private static function state_belongs_to_current_target( array $observation ): bool {
		if ( ! $observation['exists'] || ! is_array( $observation['stored'] ) ) {
			return true;
		}

		$target = $observation['stored']['target'] ?? '';
		if ( null === $target || '' === $target ) {
			return true;
		}
		if ( ! is_scalar( $target ) ) {
			return false;
		}

		return self::TARGET_DATA_VERSION === (string) $target;
	}

	private static function get_lock(): OptionLeaseLock {
		if ( null === self::$upgrade_lock ) {
			self::$upgrade_lock = new OptionLeaseLock(
				self::LOCK_OPTION,
				self::LOCK_TTL,
				self::LOCK_RENEW_INTERVAL
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
	 * Confirm the settled-path schema belongs to this exact release while the
	 * outer lease is held. Older code must not mutate newer coordination state.
	 */
	private static function target_data_version_is_current_under_lease(): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}

		$observation = self::read_option( self::DATA_VERSION_OPTION );
		if ( ! $observation['exists'] || ! is_scalar( $observation['stored'] ) ) {
			return false;
		}

		return 0 === version_compare( (string) $observation['stored'], self::TARGET_DATA_VERSION );
	}

	/**
	 * Retry only the deferred static ownership work after Core's data version has
	 * already settled. This path never replays unrelated upgrade steps.
	 *
	 */
	private static function retry_deferred_static_migration(): void {
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			if ( ! self::target_data_version_is_current_under_lease() ) {
				return;
			}
			$raw_state = self::read_option( self::STATE_OPTION );
			if ( ! self::state_belongs_to_current_target( $raw_state ) ) {
				return;
			}
			self::handle_settled_upgrade_under_lease( self::normalize_state_observation( $raw_state ) );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Handle only coordination belonging to an already-settled target while the
	 * caller holds the outer lease. Historical migration steps are never replayed.
	 *
	 * @param array{state:array<string,int|string>,stored:mixed,exists:bool} $observation State reloaded after lease acquisition.
	 */
	private static function handle_settled_upgrade_under_lease( array $observation ): void {
		$state        = $observation['state'];
		$state_stored = $observation['stored'];
		$state_exists = $observation['exists'];
		if ( 1 !== (int) $state['static_pending'] ) {
			if ( self::maintain_lock() ) {
				self::clear_coordination_state_under_lease( $state_stored, $state_exists );
			}
			return;
		}

		if ( (int) $state['static_next_retry'] > time() ) {
			if ( self::maintain_lock() ) {
				self::schedule_retry( (int) $state['static_next_retry'] );
			}
			return;
		}

		$result = self::attempt_static_ownership_migration();
		if ( ! self::maintain_lock() ) {
			return;
		}
		if ( $result['complete'] ) {
			self::clear_static_migration_failure( $state );
			if ( self::maintain_lock() ) {
				self::clear_coordination_state_under_lease( $state_stored, $state_exists );
			}
			return;
		}

		self::apply_static_migration_failure( $state, $result['error'] );
		if ( ! self::maintain_lock() || ! self::persist_state( $state, $state_stored, $state_exists ) ) {
			return;
		}
		if ( self::maintain_lock() ) {
			self::schedule_retry( (int) $state['static_next_retry'] );
		}
	}

	/**
	 * @param array<string,int|string> $state Persisted upgrade state.
	 */
	private static function apply_static_migration_failure( array &$state, string $message ): void {
		$attempts                   = min( 10, (int) $state['static_attempts'] + 1 );
		$delay                      = min( HOUR_IN_SECONDS, 30 * ( 2 ** ( $attempts - 1 ) ) );
		$state['static_pending']    = 1;
		$state['static_attempts']   = $attempts;
		$state['static_next_retry'] = time() + $delay;
		$state['static_last_error'] = sanitize_text_field( $message );
		$state['static_updated_at'] = time();
	}

	/**
	 * @param array<string,int|string> $state Persisted upgrade state.
	 */
	private static function clear_static_migration_failure( array &$state ): void {
		$state['static_pending']    = 0;
		$state['static_attempts']   = 0;
		$state['static_next_retry'] = 0;
		$state['static_last_error'] = '';
		$state['static_updated_at'] = time();
	}

	private static function schedule_retry( int $timestamp ): void {
		if ( false === wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( max( time() + 1, $timestamp ), self::RETRY_HOOK );
		}
	}

	/**
	 * @param array<string, int|string> $state Current state.
	 */
	private static function apply_failure( array &$state, string $step, string $message ): void {
		$attempts            = min( 10, (int) $state['attempts'] + 1 );
		$delay               = min( HOUR_IN_SECONDS, 30 * ( 2 ** ( $attempts - 1 ) ) );
		$state['attempts']   = $attempts;
		$state['next_retry'] = time() + $delay;
		$state['last_step']  = $step;
		$state['last_error'] = sanitize_text_field( $message );
		$state['updated_at'] = time();
	}

	/**
	 * Remove completed coordination only while this request still owns the
	 * outer lease. A live foreign owner may be carrying newer retry evidence.
	 */
	private static function clear_completed_coordination_state(): void {
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			if ( ! self::target_data_version_is_current_under_lease() ) {
				return;
			}
			$raw_state = self::read_option( self::STATE_OPTION );
			if ( ! self::state_belongs_to_current_target( $raw_state ) ) {
				return;
			}
			$observation = self::normalize_state_observation( $raw_state );
			if ( 1 === (int) $observation['state']['static_pending'] ) {
				return;
			}
			if ( ! self::maintain_lock() ) {
				return;
			}
			self::clear_coordination_state_under_lease( $observation['stored'], $observation['exists'] );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * @param mixed $expected_state Exact logical option value last observed.
	 */
	private static function clear_coordination_state_under_lease( mixed $expected_state, bool $state_exists ): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}
		if ( ! self::lease_guarded_delete_option( self::STATE_OPTION, $expected_state, $state_exists ) ) {
			return false;
		}

		// The cron API owns the serialized cron option. Revalidate directly before
		// each API read/write and leave the event in place if ownership changed.
		if ( ! self::maintain_lock() ) {
			return false;
		}
		if ( false !== wp_next_scheduled( self::RETRY_HOOK ) ) {
			if ( ! self::maintain_lock() ) {
				return false;
			}
			wp_clear_scheduled_hook( self::RETRY_HOOK );
		}

		return true;
	}

	/**
	 * @param array<string,int|string> $state          New normalized state.
	 * @param mixed                    $expected_state Exact logical option value last observed.
	 */
	private static function persist_state( array $state, mixed $expected_state, bool $state_exists ): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}

		return self::lease_guarded_replace_option( self::STATE_OPTION, $state, $expected_state, $state_exists );
	}

	/**
	 * @return array{stored:mixed,exists:bool}
	 */
	private static function read_option( string $option ): array {
		$missing = new \stdClass();
		$stored  = get_option( $option, $missing );

		return array(
			'stored' => $stored,
			'exists' => $missing !== $stored,
		);
	}

	/**
	 * Replace one option only if both its exact prior value and the exact lease
	 * row observed by this owner still exist in the same database statement.
	 *
	 * @param mixed $expected Exact logical option value last observed.
	 */
	private static function lease_guarded_replace_option( string $option, mixed $value, mixed $expected, bool $expected_exists ): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}

		global $wpdb;
		if ( self::supports_direct_option_cas( $wpdb ) ) {
			return self::direct_lease_guarded_replace_option( $wpdb, $option, $value, $expected, $expected_exists );
		}

		// The shipped PHPUnit WordPress shim has no SQL option table. Its
		// synchronous option mutation is an explicit test-only equivalent; a real
		// WordPress request without direct option CAS support fails closed here.
		if ( ! self::is_phpunit() || ! self::logical_option_matches( $option, $expected, $expected_exists ) ) {
			return false;
		}
		if ( ! $expected_exists ) {
			if ( ! add_option( $option, $value, '', false ) ) {
				return false;
			}
		} else {
			update_option( $option, $value, false );
		}

		return self::maintain_lock() && get_option( $option, null ) === $value;
	}

	private static function direct_lease_guarded_replace_option( mixed $wpdb, string $option, mixed $value, mixed $expected, bool $expected_exists ): bool {
		$lease_raw = self::read_raw_database_option( self::LOCK_OPTION );
		if ( ! self::raw_lease_belongs_to_current_owner( $lease_raw ) ) {
			return false;
		}
		$current_raw  = self::read_raw_database_option( $option );
		$expected_raw = $expected_exists ? self::serialize_option_value( $expected ) : null;
		if ( $current_raw !== $expected_raw ) {
			return false;
		}
		$value_raw = self::serialize_option_value( $value );
		if ( $expected_exists && $current_raw === $value_raw ) {
			return self::raw_database_lease_is_unchanged( $lease_raw );
		}
		$updated = $expected_exists ? self::update_direct_option( $wpdb, $option, $value_raw, $current_raw, $lease_raw ) : self::insert_direct_option( $wpdb, $option, $value_raw, $lease_raw );
		if ( 1 !== (int) $updated ) {
			return false;
		}
		self::invalidate_option_cache( $option );
		return true;
	}

	private static function update_direct_option( mixed $wpdb, string $option, string $value, string $current, string $lease ): int|false {
		return $wpdb->query( $wpdb->prepare( 'UPDATE %i AS target INNER JOIN %i AS lease ON lease.option_name = %s AND BINARY lease.option_value = BINARY %s SET target.option_value = %s, target.autoload = %s WHERE target.option_name = %s AND BINARY target.option_value = BINARY %s', $wpdb->options, $wpdb->options, self::LOCK_OPTION, $lease, $value, 'off', $option, $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One statement binds the state/version CAS to the exact outer lease row.
	}

	private static function insert_direct_option( mixed $wpdb, string $option, string $value, string $lease ): int|false {
		return $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM %i AS lease WHERE lease.option_name = %s AND BINARY lease.option_value = BINARY %s', $wpdb->options, $option, $value, 'off', $wpdb->options, self::LOCK_OPTION, $lease ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT SELECT is authorized by the exact outer lease row in the same statement.
	}

	/**
	 * Delete one exact option snapshot only while the same exact lease row is
	 * still present in the database statement.
	 *
	 * @param mixed $expected Exact logical option value last observed.
	 */
	private static function lease_guarded_delete_option( string $option, mixed $expected, bool $expected_exists ): bool {
		if ( ! self::maintain_lock() ) {
			return false;
		}
		if ( ! $expected_exists ) {
			return true;
		}

		global $wpdb;
		if ( self::supports_direct_option_cas( $wpdb ) ) {
			$lease_raw = self::read_raw_database_option( self::LOCK_OPTION );
			if ( ! self::raw_lease_belongs_to_current_owner( $lease_raw ) ) {
				return false;
			}

			$expected_raw = self::serialize_option_value( $expected );
			$deleted      = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One statement binds cleanup CAS to the exact outer lease row.
				$wpdb->prepare(
					'DELETE target FROM %i AS target INNER JOIN %i AS lease ON lease.option_name = %s AND BINARY lease.option_value = BINARY %s WHERE target.option_name = %s AND BINARY target.option_value = BINARY %s',
					$wpdb->options,
					$wpdb->options,
					self::LOCK_OPTION,
					$lease_raw,
					$option,
					$expected_raw
				)
			);
			if ( 1 !== (int) $deleted ) {
				return false;
			}
			self::invalidate_option_cache( $option );
			return true;
		}

		if ( ! self::is_phpunit() || ! self::logical_option_matches( $option, $expected, true ) ) {
			return false;
		}
		delete_option( $option );

		return self::maintain_lock();
	}

	private static function supports_direct_option_cas( mixed $database ): bool {
		return is_object( $database )
			&& isset( $database->options )
			&& is_string( $database->options )
			&& method_exists( $database, 'prepare' )
			&& method_exists( $database, 'get_var' )
			&& method_exists( $database, 'query' );
	}

	private static function read_raw_database_option( string $option ): ?string {
		global $wpdb;
		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw bytes are required for the exact lease/state CAS.
			$wpdb->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
				$wpdb->options,
				$option
			)
		);

		return is_string( $raw ) ? $raw : null;
	}

	private static function raw_lease_belongs_to_current_owner( ?string $raw ): bool {
		$token = self::get_lock()->get_token();
		$value = null === $raw ? null : maybe_unserialize( $raw );
		return null !== $token
			&& is_array( $value )
			&& is_scalar( $value['token'] ?? null )
			&& hash_equals( $token, (string) $value['token'] );
	}

	private static function raw_database_lease_is_unchanged( string $expected_raw ): bool {
		return self::maintain_lock()
			&& self::read_raw_database_option( self::LOCK_OPTION ) === $expected_raw;
	}

	private static function logical_option_matches( string $option, mixed $expected, bool $expected_exists ): bool {
		$observation = self::read_option( $option );

		return $expected_exists === $observation['exists']
			&& ( ! $expected_exists || $expected === $observation['stored'] );
	}

	private static function serialize_option_value( mixed $value ): string {
		return function_exists( 'maybe_serialize' )
			? (string) maybe_serialize( $value )
			: serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	private static function invalidate_option_cache( string $option ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function is_phpunit(): bool {
		return defined( 'CYBERMAPS_PHPUNIT' ) && true === CYBERMAPS_PHPUNIT;
	}

	/**
	 * Reduce the strategy option to the fields owned by the current matrix.
	 */
	private static function normalize_discovery_center(): bool {
		$raw  = get_option( 'cybermaps_discovery_center', '' );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			return true;
		}

		$encoded = wp_json_encode( $data );
		if ( ! is_string( $encoded ) ) {
			return false;
		}
		$encoded = \Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer::sanitize( $encoded );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}

		return self::save_and_verify_option( 'cybermaps_discovery_center', $encoded );
	}

	/**
	 * Persist a canonical migration value and confirm that WordPress retained it.
	 *
	 * update_option() legitimately returns false when a value is unchanged, so
	 * the stored value is the only reliable completion signal for a retry-safe
	 * migration.
	 */
	private static function save_and_verify_option( string $option, mixed $value ): bool {
		update_option( $option, $value, false );
		$missing = new \stdClass();

		return get_option( $option, $missing ) === $value;
	}

	/**
	 * Move the retired search-exclusion flag to the canonical sitemap flag.
	 *
	 * Existing canonical values win. The grouped SELECT also collapses any
	 * duplicate legacy rows before the retired key is deleted.
	 */
	private static function migrate_sitemap_exclusion_meta(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->postmeta ) ) {
			return false;
		}

		$postmeta = $wpdb->postmeta;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This one-time migration copies and removes exact rows in WordPress's postmeta table.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (post_id, meta_key, meta_value)
			SELECT legacy.post_id, '_cybermaps_exclude_sitemap', MAX(legacy.meta_value)
			FROM %i AS legacy
			LEFT JOIN %i AS canonical
				ON canonical.post_id = legacy.post_id
				AND canonical.meta_key = '_cybermaps_exclude_sitemap'
			WHERE legacy.meta_key = '_cybermaps_exclude_search'
				AND canonical.meta_id IS NULL
			GROUP BY legacy.post_id",
				$postmeta,
				$postmeta,
				$postmeta
			)
		);
		if ( false === $inserted ) {
			return false;
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE meta_key = '_cybermaps_exclude_search'",
				$postmeta
			)
		);
		// phpcs:enable

		return false !== $deleted;
	}
}
