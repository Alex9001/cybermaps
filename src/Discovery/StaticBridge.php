<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

use Cybermaps\Core\AtomicOptionSequence;
use Cybermaps\Core\OptionLeaseLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

StaticOwnershipStore::register_hooks();

/**
 * Service for bridging static file discovery.
 */
class StaticBridge {
	public const TIME_SENSITIVE_REFRESH_HOOK         = 'cybermaps_refresh_time_sensitive_static_files';
	public const OPERATION_LOCK_OPTION               = 'cybermaps_static_operation_lock';
	public const INTENT_RECOVERY_CONFIRMATION        = 'I CONFIRM ALL CYBERMAPS STATIC WORKERS ARE QUIESCED';
	private const OPERATION_LOCK_TTL                 = 1800;
	private const OPERATION_LOCK_RENEW_INTERVAL      = 300;
	private const GENERATION_OPTION                  = 'cybermaps_static_generation';
	private const OWNERSHIP_REVISION_OPTION          = 'cybermaps_static_ownership_revision';
	private const SUSPENDED_OPTION                   = 'cybermaps_static_suspended';
	private const CLEANUP_AFTER_CANCEL_OPTION        = 'cybermaps_static_cleanup_after_cancel';
	private const LAST_ATTEMPT_OPTION                = 'cybermaps_last_static_sync_attempt';
	private const LAST_REPORT_OPTION                 = 'cybermaps_last_static_sync_report';
	private const SCHEDULE_ERROR_OPTION              = 'cybermaps_static_schedule_error';
	private const WRITE_ERRORS_OPTION                = 'cybermaps_static_write_errors';
	private const WRITE_ERROR_DROPPED_OPTION         = 'cybermaps_static_write_errors_dropped';
	private const WRITE_ERROR_SAMPLE_LIMIT           = 200;
	private const SYNC_STATE_OPTION                  = 'cybermaps_static_sync_state';
	private const FAILED_RETRY_OPTION                = 'cybermaps_static_failed_retry';
	private const SYNC_EPOCH_OPTION                  = 'cybermaps_static_sync_epoch';
	private const LAST_TIME_SENSITIVE_REFRESH_OPTION = 'cybermaps_last_time_sensitive_static_refresh';
	private const TIME_SENSITIVE_RETRY_DELAY         = HOUR_IN_SECONDS;
	private const INTENT_RECOVERY_NONCE_ACTION       = 'cybermaps_recover_static_intent_';
	private const OWNERSHIP_VERIFICATION_MAX_BYTES   = 16 * 1024 * 1024;
	private const SYNC_MAX_WRITES_PER_RUN            = 250;
	private const SYNC_MAX_RUNTIME_SECONDS           = 20;
	private const TRANSITION_CANDIDATE_LIMIT         = 10;
	private const TRANSITION_INDEX_BATCH             = 100;
	private const LEGACY_GENERATED_FILES             = array(
		'.well-known/ai.json',
		'.well-known/ai-discovery.json',
		'.well-known/ai-usage.json',
		'.well-known/ai-actions.json',
		'.well-known/ai-discovery',
		'.well-known/api-catalog',
		'ai-discovery',
	);

	/**
	 * Singleton instance.
	 *
	 * @var StaticBridge|null
	 */
	private static $instance = null;

	/**
	 * Result of the most recent write attempt in this request.
	 *
	 * @var array<string, mixed>
	 */
	private array $last_write_result = array();

	private OptionLeaseLock $operation_lock;
	private StaticOwnershipStore $ownership_store;
	private StaticWriteIntentStore $write_intent_store;
	private StaticSyncRunner $sync_runner;

	/**
	 * Token held by this request while synchronizing or purging.
	 */
	private ?string $operation_lock_token = null;
	private bool $operation_lock_lost     = false;

	/**
	 * Configuration generation captured when this request acquired the lock.
	 */
	private ?int $operation_generation = null;

	private bool $ownership_dirty            = false;
	private bool $ownership_ready            = true;
	private bool $ownership_repair_needed    = false;
	private bool $ownership_revision_pending = false;
	private int $ownership_changes           = 0;
	private bool $shutdown_registered        = false;
	private bool $sync_active                = false;
	private bool $time_sensitive_active      = false;
	private bool $sync_deferred              = false;
	private int $write_error_dropped_pending = 0;
	private int $sync_written_count          = 0;
	private float $sync_started_at           = 0.0;
	private int $sync_epoch                  = 0;
	private int $sync_generation             = 0;
	/** @var array<string,mixed>|null Exact intent authorized for one operator recovery attempt. */
	private ?array $operator_recovery_intent = null;
	private string $sync_mode                = 'off';
	private string $sync_report_started_at   = '';
	/** @var array<string,true> Publications verified in this generation. */
	private array $sync_completed = array();
	/** @var array<string,true> Optional publications already found to be empty. */
	private array $sync_omitted = array();

	/**
	 * Ownership-safe purge triggered after an in-flight generation was cancelled.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $last_cancel_purge_result = null;

	/**
	 * Constructor.
	 *
	 * Hardened to prevent multiple instances.
	 */
	private function __construct() {
		$stale_takeover_guard     = fn(): bool => $this->allow_stale_operation_takeover();
		$this->operation_lock     = new OptionLeaseLock(
			self::OPERATION_LOCK_OPTION,
			self::OPERATION_LOCK_TTL,
			self::OPERATION_LOCK_RENEW_INTERVAL,
			true,
			$stale_takeover_guard
		);
		$this->ownership_store    = new StaticOwnershipStore( $this->operation_lock );
		$this->write_intent_store = new StaticWriteIntentStore(
			$this->operation_lock,
			\defined( 'CYBERMAPS_PHPUNIT' ) && true === CYBERMAPS_PHPUNIT
		);
		$this->sync_runner        = new StaticSyncRunner( $this );
	}

	/**
	 * Clone method.
	 *
	 * Hardened to prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Wakeup method.
	 *
	 * Hardened to prevent unserialization. Public to avoid PHP warnings.
	 */
	public function __wakeup() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return StaticBridge
	 */
	public static function get_instance() {
		StaticOwnershipStore::register_hooks();
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return structured details for the most recent write attempt.
	 *
	 * write_file() retains its historical boolean return value. Callers that need
	 * to distinguish an ownership conflict from an I/O failure can inspect this
	 * result, while persistent failures remain available in the
	 * cybermaps_static_write_errors option.
	 *
	 * @return array<string, mixed>
	 */
	public function get_last_write_result(): array {
		return $this->last_write_result;
	}

	/**
	 * Return body-free recovery metadata for an administrator or CLI adapter.
	 *
	 * The confirmation phrase is deliberately explicit: a stale PHP process
	 * cannot be fenced portably at the filesystem layer, so an operator must
	 * quiesce static workers before authorizing stale-lease takeover.
	 *
	 * @return array<string,mixed>
	 */
	public function get_pending_intent_recovery_status(): array {
		$status                             = $this->write_intent_store->describe();
		$status['operator_action_required'] = 'none' !== $status['status'];
		$status['warning']                  = 'none' === $status['status']
			? ''
			: __( 'Automatic recovery is blocked because a paused stale process could resume a filesystem mutation. Stop or quiesce every Cybermaps static worker before confirming recovery.', 'cybermaps' );
		$status['confirmation']             = self::INTENT_RECOVERY_CONFIRMATION;
		$status['nonce']                    = '';
		if (
			'pending' === $status['status']
			&& \current_user_can( 'manage_options' )
			&& \function_exists( 'wp_create_nonce' )
		) {
			$status['nonce'] = \wp_create_nonce(
				self::INTENT_RECOVERY_NONCE_ACTION . (string) $status['intent_id']
			);
		}

		return $status;
	}

	/**
	 * Explicitly resolve one exact pending intent after workers are quiesced.
	 *
	 * This is an adapter-ready service method, not an automatic recovery path.
	 * An admin UI must supply the intent-bound WordPress nonce; a future CLI
	 * adapter can mint and pass the same nonce only after its own confirmation.
	 *
	 * @return array<string,mixed>
	 */
	public function resolve_pending_intent(
		string $intent_id,
		string $nonce,
		string $confirmation
	): array {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return $this->intent_resolution_result( false, 'forbidden', __( 'You are not allowed to resolve static publication recovery state.', 'cybermaps' ) );
		}
		if ( ! \hash_equals( self::INTENT_RECOVERY_CONFIRMATION, $confirmation ) ) {
			return $this->intent_resolution_result( false, 'confirmation_required', __( 'The exact static-worker quiescence confirmation was not supplied.', 'cybermaps' ) );
		}
		if (
			'' === $intent_id
			|| ! \function_exists( 'wp_verify_nonce' )
			|| ! \wp_verify_nonce( $nonce, self::INTENT_RECOVERY_NONCE_ACTION . $intent_id )
		) {
			return $this->intent_resolution_result( false, 'invalid_nonce', __( 'The static recovery authorization is invalid or expired.', 'cybermaps' ) );
		}

		$intent = $this->write_intent_store->read();
		if ( false === $intent ) {
			$this->record_write_intent_recovery_error(
				'write_intent_invalid',
				__( 'The pending static intent is malformed and was preserved for administrator review.', 'cybermaps' ),
				false
			);
			return $this->intent_resolution_result( false, 'intent_malformed', __( 'The pending static intent is malformed and cannot be resolved automatically.', 'cybermaps' ) );
		}
		if (
			null === $intent
			|| ! \hash_equals( (string) $intent['intent_id'], $intent_id )
		) {
			return $this->intent_resolution_result( false, 'intent_changed', __( 'The pending static intent changed before recovery could begin.', 'cybermaps' ) );
		}

		$this->operator_recovery_intent = $intent;
		try {
			if ( ! $this->acquire_operation_lock() ) {
				return $this->intent_resolution_result( false, 'recovery_busy', __( 'The static operation fence is still active or recovery could not acquire it.', 'cybermaps' ) );
			}

			$remaining = $this->write_intent_store->read();
			if ( null !== $remaining ) {
				return $this->intent_resolution_result( false, 'recovery_incomplete', __( 'The pending static intent was preserved because recovery could not prove a safe terminal state.', 'cybermaps' ) );
			}

			return $this->intent_resolution_result( true, 'resolved', __( 'The pending static intent was reconciled safely.', 'cybermaps' ) );
		} finally {
			$this->operator_recovery_intent = null;
			if ( null !== $this->operation_lock_token ) {
				$this->release_operation_lock();
			}
		}
	}

	/**
	 * Return resumable synchronization state for status and support surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public function get_sync_state(): array {
		$state = \get_option( self::SYNC_STATE_OPTION, array() );

		return \is_array( $state ) ? $state : array();
	}

	/**
	 * Renew a long-running static operation at a bounded work boundary.
	 *
	 * Dynamic publication callers do not hold this lock and therefore pass
	 * through. Batched generators use this integration point so one unusually
	 * large publication cannot let an otherwise active lock age out.
	 */
	public function heartbeat(): bool {
		return null === $this->operation_lock_token
			|| $this->maintain_operation_lock();
	}

	/**
	 * Return the supported Static File Engine modes.
	 *
	 * @return string[] Ordered mode identifiers.
	 */
	public static function get_supported_modes(): array {
		return array( 'off', 'well_known', 'all' );
	}

	/**
	 * Resolve the active Static File Engine mode.
	 *
	 * - 'off'        — nothing written; everything served dynamically by PHP.
	 * - 'well_known' — only the small, extension-bearing Core discovery files
	 *   written to disk; the remaining publications stay dynamic.
	 * - 'all'        — every discovery file and sitemap written to disk.
	 *
	 * @param array|null $settings Optional settings array (avoids a re-read).
	 * @return string One of 'off', 'well_known', 'all'.
	 */
	public static function get_mode( $settings = null ): string {
		// A WordPress multisite installation shares one physical web root across
		// sites (and potentially networks/domains). Core cannot safely materialize
		// site-specific root files there, so multisite always uses dynamic delivery.
		if ( \function_exists( 'is_multisite' ) && \is_multisite() ) {
			return 'off';
		}

		if ( null === $settings ) {
			$settings = \Cybermaps\Core\ConfigurationStore::settings();
		}

		if ( ! \is_array( $settings ) ) {
			$settings = array();
		}

		$mode = $settings['static_engine_mode'] ?? '';
		if ( \in_array( $mode, self::get_supported_modes(), true ) ) {
			return $mode;
		}

		// Missing or invalid values use the compatibility-first default. The
		// canonical mode is deliberately not inferred from a legacy boolean.
		return 'well_known';
	}

	/**
	 * Get absolute path for a filename.
	 *
	 * @param string $filename Filename.
	 * @return string Absolute path.
	 */
	public function get_file_path( $filename ) {
		$resolution = $this->resolve_file_path( (string) $filename );
		return (string) ( $resolution['path'] ?? '' );
	}

	/**
	 * Queue ownership-safe regeneration when a registered static publication is
	 * missing or unreadable and WordPress receives the matching request.
	 *
	 * Dynamic delivery continues for the current request. The existing coalesced
	 * background sync repairs the materialized file without overwriting a file
	 * that Cybermaps cannot prove it owns.
	 */
	public function request_repair_for_path( string $public_path ): bool {
		$mode = self::get_mode();
		if ( 'off' === $mode ) {
			return false;
		}

		$targets = \Cybermaps\Core\EndpointRegistry::get_instance()->get_static_targets( $mode );
		foreach ( $targets as $target ) {
			if ( (string) ( $target['path'] ?? '' ) !== $public_path ) {
				continue;
			}

			return $this->request_repair_for_filename(
				(string) ( $target['filename'] ?? '' ),
				(string) ( $target['bucket'] ?? 'well_known' )
			);
		}

		return false;
	}

	/**
	 * Queue repair for a validated generated filename outside the fixed registry,
	 * such as a sitemap child or the optional RSS sitemap.
	 */
	public function request_repair_for_filename( string $filename, string $required_mode = 'all' ): bool {
		$mode = self::get_mode();
		if (
			'off' === $mode
			|| ( 'all' === $required_mode && 'all' !== $mode )
			|| ! $this->is_safe_generated_path( $filename )
		) {
			return false;
		}

		$resolution = $this->resolve_file_path( $filename );
		$path       = (string) ( $resolution['path'] ?? '' );
		if ( '' === $path || ! $this->load_filesystem_api() || ! \WP_Filesystem() ) {
			return false;
		}

		global $wp_filesystem;
		if ( $wp_filesystem->exists( $path ) && $wp_filesystem->is_readable( $path ) ) {
			return false;
		}

		$this->request_sync();

		/**
		 * Fires after dynamic delivery detects a missing static publication.
		 *
		 * @param string $filename Relative generated filename.
		 * @param string $path Absolute destination path.
		 */
		\do_action( 'cybermaps_static_repair_requested', $filename, $path );
		return true;
	}

	/**
	 * Write content to a file atomically.
	 *
	 * @param string $filename Filename.
	 * @param string $content Content.
	 * @return bool True on success, false on failure.
	 */
	public function write_file( $filename, $content ) {
		$acquired_here = null === $this->operation_lock_token;
		if ( ! $this->acquire_operation_lock() ) {
			$code = $this->operation_lock_lost
				? 'operation_lock_lost'
				: ( $this->ownership_ready ? 'operation_locked' : 'ownership_migration_failed' );
			return $this->complete_write_attempt(
				false,
				(string) $filename,
				$this->operation_lock_lost ? 'skipped' : 'error',
				$code,
				$this->operation_lock_lost
					? $this->get_write_block_message( 'operation_lock_lost' )
					: ( $this->ownership_ready
					? __( 'Another generated-file operation is already running.', 'cybermaps' )
					: __( 'The generated-file ownership inventory could not be prepared safely.', 'cybermaps' ) ),
				'',
				array(),
				true
			);
		}

		try {
			return $this->write_file_unlocked( $filename, $content );
		} finally {
			if ( $acquired_here ) {
				$this->release_operation_lock();
			}
		}
	}

	/**
	 * Write while the installation-local generated-file lease is held.
	 *
	 * @param mixed $filename Filename.
	 * @param mixed $content Content.
	 */
	private function write_file_unlocked( $filename, $content ): bool {
		$state = array(
			'filename'            => $filename,
			'content'             => $content,
			'path'                => '',
			'filesystem'          => null,
			'new_hash'            => '',
			'current_hash'        => '',
			'recorded_hash'       => null,
			'recorded_generation' => -1,
			'has_valid_record'    => false,
			'target_exists'       => false,
			'previous_contents'   => null,
			'tmp_file'            => '',
			'exists_before_move'  => false,
			'write_intent'        => array(),
		);

		return $this->prepare_write_context( $state )
			?? $this->continue_write_unlocked( $state );
	}

	/**
	 * Run the transactional write phases after context initialization.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 */
	private function continue_write_unlocked( array &$state ): bool {
		return $this->verify_existing_write_target( $state )
			?? $this->finish_unchanged_existing_write( $state )
			?? $this->prepare_temporary_write( $state )
			?? $this->verify_write_target_before_move( $state )
			?? $this->checkpoint_write_intent( $state )
			?? $this->move_prepared_write( $state )
			?? $this->handle_uncheckpointed_write_block( $state )
			?? $this->checkpoint_published_ownership( $state )
			?? $this->handle_checkpointed_write_block( $state )
			?? $this->finish_published_write( $state );
	}

	/**
	 * Validate a write and initialize its shared state.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function prepare_write_context( array &$state ): ?bool {
		$filename            =& $state['filename'];
		$content             =& $state['content'];
		$path                =& $state['path'];
		$filesystem          =& $state['filesystem'];
		$new_hash            =& $state['new_hash'];
		$recorded_hash       =& $state['recorded_hash'];
		$recorded_generation =& $state['recorded_generation'];
		$has_valid_record    =& $state['has_valid_record'];
		$target_exists       =& $state['target_exists'];
		$previous_contents   =& $state['previous_contents'];

		if ( ! \is_string( $filename ) || ! $this->is_safe_generated_path( $filename ) ) {
			return $this->complete_write_attempt(
				false,
				(string) $filename,
				'error',
				'invalid_path',
				__( 'The generated-file path is invalid.', 'cybermaps' ),
				'',
				array(),
				true
			);
		}

		$content        = (string) $content;
		$blocked_reason = $this->get_write_block_reason( $filename );
		if ( null !== $blocked_reason ) {
			return $this->complete_write_attempt(
				false,
				$filename,
				'skipped',
				$blocked_reason,
				$this->get_write_block_message( $blocked_reason ),
				'',
				array(),
				false
			);
		}
		if (
			$this->is_full_llms_generated_path( $filename )
			&& \strlen( $content ) > LLMS::FULL_OUTPUT_MAX_BYTES
		) {
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'publication_too_large',
				(
					new PublicationSizeLimitException(
						\basename( $filename ),
						LLMS::FULL_OUTPUT_MAX_BYTES
					)
				)->getMessage(),
				'',
				array( 'max_bytes' => LLMS::FULL_OUTPUT_MAX_BYTES ),
				true
			);
		}

		if ( ! $this->load_filesystem_api() ) {
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'filesystem_api_unavailable',
				__( 'The WordPress filesystem API is unavailable.', 'cybermaps' ),
				'',
				array(),
				true
			);
		}

		$resolution = $this->resolve_file_path( $filename );
		if ( empty( $resolution['path'] ) ) {
			$reason = (string) ( $resolution['reason'] ?? 'publication_root_unavailable' );
			return $this->complete_write_attempt(
				false,
				$filename,
				'skipped',
				$reason,
				(string) ( $resolution['message'] ?? __( 'The physical publication root could not be resolved safely.', 'cybermaps' ) ),
				'',
				array(),
				true
			);
		}
		$path = (string) $resolution['path'];

		if ( ! \WP_Filesystem() ) {
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'filesystem_init_failed',
				__( 'WordPress could not initialize the filesystem.', 'cybermaps' ),
				$path,
				array(),
				true
			);
		}

		global $wp_filesystem;
		$filesystem          = $wp_filesystem;
		$new_hash            = \md5( $content );
		$recorded_hash       = $this->ownership_store->get_hash( $filename );
		$recorded_generation = $this->ownership_store->get_generation( $filename );
		$has_valid_record    = \is_string( $recorded_hash )
			&& 1 === \preg_match( '/^[a-f0-9]{32}$/i', $recorded_hash );
		$target_exists       = $wp_filesystem->exists( $path );
		$previous_contents   = null;
		return null;
	}

	/**
	 * Verify the initial ownership state of an existing target.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function verify_existing_write_target( array &$state ): ?bool {
		$filename          =& $state['filename'];
		$path              =& $state['path'];
		$wp_filesystem     =& $state['filesystem'];
		$new_hash          =& $state['new_hash'];
		$current_hash      =& $state['current_hash'];
		$recorded_hash     =& $state['recorded_hash'];
		$has_valid_record  =& $state['has_valid_record'];
		$target_exists     =& $state['target_exists'];
		$previous_contents =& $state['previous_contents'];

		if ( $target_exists ) {
			$verification_issue = $this->get_ownership_verification_issue(
				$filename,
				$path,
				$wp_filesystem
			);
			if ( null !== $verification_issue ) {
				return $this->complete_write_attempt(
					false,
					$filename,
					'conflict',
					(string) $verification_issue['code'],
					(string) $verification_issue['message'],
					$path,
					array(
						'recorded_hash' => $has_valid_record ? \strtolower( (string) $recorded_hash ) : '',
						'desired_hash'  => $new_hash,
						'current_bytes' => (int) ( $verification_issue['current_bytes'] ?? 0 ),
					),
					true
				);
			}

			$current_contents = $wp_filesystem->get_contents( $path );
			if ( false === $current_contents ) {
				return $this->complete_write_attempt(
					false,
					$filename,
					'error',
					'read_failed',
					__( 'The existing file could not be read, so it was not overwritten.', 'cybermaps' ),
					$path,
					array( 'desired_hash' => $new_hash ),
					true
				);
			}
			$previous_contents = $current_contents;

			$current_hash = \md5( $current_contents );
			if ( ! $has_valid_record ) {
				return $this->complete_write_attempt(
					false,
					$filename,
					'conflict',
					null === $recorded_hash ? 'untracked_existing_file' : 'invalid_ownership_record',
					__( 'An existing file is not proven to be owned by Cybermaps and was not overwritten.', 'cybermaps' ),
					$path,
					array(
						'current_hash' => $current_hash,
						'desired_hash' => $new_hash,
					),
					true
				);
			}

			if ( ! \hash_equals( \strtolower( $recorded_hash ), $current_hash ) ) {
				return $this->complete_write_attempt(
					false,
					$filename,
					'conflict',
					'owned_file_modified',
					__( 'The file changed after Cybermaps wrote it and was not overwritten.', 'cybermaps' ),
					$path,
					array(
						'recorded_hash' => \strtolower( $recorded_hash ),
						'current_hash'  => $current_hash,
						'desired_hash'  => $new_hash,
					),
					true
				);
			}
		}
		return null;
	}

	/**
	 * Complete an existing write whose body is already current.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function finish_unchanged_existing_write( array &$state ): ?bool {
		$filename      =& $state['filename'];
		$path          =& $state['path'];
		$new_hash      =& $state['new_hash'];
		$current_hash  =& $state['current_hash'];
		$target_exists =& $state['target_exists'];

		if ( ! $target_exists ) {
			return null;
		}
		if ( ! \hash_equals( $current_hash, $new_hash ) ) {
			return null;
		}
		if ( ! $this->record_ownership_hash( $filename, $new_hash ) ) {
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'ownership_checkpoint_failed',
				__( 'The file is current, but its ownership checkpoint could not be persisted.', 'cybermaps' ),
				$path,
				array(),
				true
			);
		}
		return $this->complete_write_attempt(
			true,
			$filename,
			'success',
			'unchanged',
			__( 'The generated file is already current.', 'cybermaps' ),
			$path
		);
	}

	/**
	 * Create and populate the temporary publication file.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function prepare_temporary_write( array &$state ): ?bool {
		$filename      =& $state['filename'];
		$content       =& $state['content'];
		$path          =& $state['path'];
		$wp_filesystem =& $state['filesystem'];
		$tmp_file      =& $state['tmp_file'];

		$dir = \dirname( $path );

		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			if ( ! \wp_mkdir_p( $dir ) ) {
				return $this->complete_write_attempt(
					false,
					$filename,
					'error',
					'directory_create_failed',
					__( 'The destination directory could not be created.', 'cybermaps' ),
					$path,
					array(),
					true
				);
			}
		}

		if ( ! $wp_filesystem->is_writable( $dir ) ) {
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'directory_not_writable',
				__( 'The destination directory is not writable.', 'cybermaps' ),
				$path,
				array(),
				true
			);
		}

		$tmp_file = \wp_tempnam( $filename, \rtrim( $dir, '/\\' ) . '/' );
		if ( ! $tmp_file ) {
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'temporary_file_failed',
				__( 'A temporary file could not be created.', 'cybermaps' ),
				$path,
				array(),
				true
			);
		}

		if ( ! $wp_filesystem->put_contents( $tmp_file, $content, 0644 ) ) {
			\wp_delete_file( $tmp_file );
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'temporary_write_failed',
				__( 'The generated content could not be written to a temporary file.', 'cybermaps' ),
				$path,
				array(),
				true
			);
		}

		$blocked_reason = $this->get_write_block_reason( $filename );
		if ( null !== $blocked_reason ) {
			\wp_delete_file( $tmp_file );
			return $this->complete_write_attempt(
				false,
				$filename,
				'skipped',
				$blocked_reason,
				$this->get_write_block_message( $blocked_reason ),
				$path
			);
		}
		return null;
	}

	/**
	 * Re-check the destination immediately before publication.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function verify_write_target_before_move( array &$state ): ?bool {
		$filename           =& $state['filename'];
		$path               =& $state['path'];
		$wp_filesystem      =& $state['filesystem'];
		$tmp_file           =& $state['tmp_file'];
		$new_hash           =& $state['new_hash'];
		$recorded_hash      =& $state['recorded_hash'];
		$has_valid_record   =& $state['has_valid_record'];
		$target_exists      =& $state['target_exists'];
		$exists_before_move =& $state['exists_before_move'];

		// Re-check ownership immediately before the destructive move. This closes
		// the common race where another process creates or edits the target while
		// Cybermaps is preparing its temporary file.
		$exists_before_move = $wp_filesystem->exists( $path );
		if ( ! $target_exists && $exists_before_move ) {
			\wp_delete_file( $tmp_file );
			return $this->complete_write_attempt(
				false,
				$filename,
				'conflict',
				'target_appeared_during_write',
				__( 'A file appeared at the destination during generation and was not overwritten.', 'cybermaps' ),
				$path,
				array( 'desired_hash' => $new_hash ),
				true
			);
		}

		if ( $target_exists && $exists_before_move ) {
			$verification_issue = $this->get_ownership_verification_issue(
				$filename,
				$path,
				$wp_filesystem
			);
			if ( null !== $verification_issue ) {
				\wp_delete_file( $tmp_file );
				return $this->complete_write_attempt(
					false,
					$filename,
					'conflict',
					'target_too_large_to_verify_during_write',
					__( 'The destination became too large to verify safely during generation and was not overwritten.', 'cybermaps' ),
					$path,
					array(
						'recorded_hash' => $has_valid_record ? \strtolower( (string) $recorded_hash ) : '',
						'desired_hash'  => $new_hash,
						'current_bytes' => (int) ( $verification_issue['current_bytes'] ?? 0 ),
					),
					true
				);
			}

			$current_contents = $wp_filesystem->get_contents( $path );
			$current_hash     = false === $current_contents ? '' : \md5( $current_contents );
			if (
				false === $current_contents
				|| ! $has_valid_record
				|| ! \hash_equals( \strtolower( (string) $recorded_hash ), $current_hash )
			) {
				\wp_delete_file( $tmp_file );
				return $this->complete_write_attempt(
					false,
					$filename,
					'conflict',
					'target_changed_during_write',
					__( 'The destination changed during generation and was not overwritten.', 'cybermaps' ),
					$path,
					array(
						'recorded_hash' => $has_valid_record ? \strtolower( (string) $recorded_hash ) : '',
						'current_hash'  => $current_hash,
						'desired_hash'  => $new_hash,
					),
					true
				);
			}
		}

		$blocked_reason = $this->get_write_block_reason( $filename );
		if ( null !== $blocked_reason ) {
			\wp_delete_file( $tmp_file );
			return $this->complete_write_attempt(
				false,
				$filename,
				'skipped',
				$blocked_reason,
				$this->get_write_block_message( $blocked_reason ),
				$path
			);
		}
		return null;
	}

	/**
	 * Persist the recovery intent and validate the active lease fence.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function checkpoint_write_intent( array &$state ): ?bool {
		$filename            =& $state['filename'];
		$path                =& $state['path'];
		$tmp_file            =& $state['tmp_file'];
		$new_hash            =& $state['new_hash'];
		$recorded_hash       =& $state['recorded_hash'];
		$recorded_generation =& $state['recorded_generation'];
		$target_exists       =& $state['target_exists'];
		$write_intent        =& $state['write_intent'];

		$ownership_epoch = $this->ownership_epoch();
		if (
			null === $this->operation_lock_token
			|| null === $this->operation_generation
			|| $ownership_epoch < 0
		) {
			\wp_delete_file( $tmp_file );
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'write_intent_context_invalid',
				__( 'The generated write could not establish a durable recovery context.', 'cybermaps' ),
				$path,
				array(),
				true
			);
		}
		$write_intent = $this->write_intent_store->create(
			$filename,
			$target_exists,
			$target_exists ? \strtolower( (string) $recorded_hash ) : '',
			$target_exists ? $recorded_generation : -1,
			$new_hash,
			$this->operation_generation,
			$ownership_epoch,
			$this->operation_lock_token
		);
		if ( null === $write_intent ) {
			\wp_delete_file( $tmp_file );
			$this->sync_deferred = true;
			$this->schedule_retry();
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'write_intent_checkpoint_failed',
				__( 'The generated write was stopped because its durable recovery intent could not be checkpointed.', 'cybermaps' ),
				$path,
				array(),
				true
			);
		}
		if ( ! $this->maintain_operation_lock() ) {
			return $this->stop_prepared_write_before_move(
				$tmp_file,
				$filename,
				$path,
				'operation_lock_lost',
				__( 'The generated write was stopped after preparing its recovery intent because the operation lease was lost.', 'cybermaps' )
			);
		}
		if ( ! StaticWriteIntentStore::matches_lease_token( $write_intent, $this->operation_lock_token ) ) {
			return $this->stop_prepared_write_before_move(
				$tmp_file,
				$filename,
				$path,
				'write_intent_lease_mismatch',
				__( 'The generated write was stopped because its recovery intent does not belong to the active operation lease.', 'cybermaps' )
			);
		}
		if ( ! $this->maintain_operation_lock() ) {
			return $this->stop_prepared_write_before_move(
				$tmp_file,
				$filename,
				$path,
				'operation_lock_lost',
				__( 'The generated write was stopped immediately before publication because the operation lease was lost.', 'cybermaps' )
			);
		}
		return null;
	}

	/**
	 * Move the prepared body into place and verify its exact hash.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function move_prepared_write( array &$state ): ?bool {
		$filename           =& $state['filename'];
		$path               =& $state['path'];
		$wp_filesystem      =& $state['filesystem'];
		$tmp_file           =& $state['tmp_file'];
		$new_hash           =& $state['new_hash'];
		$target_exists      =& $state['target_exists'];
		$exists_before_move =& $state['exists_before_move'];
		$previous_contents  =& $state['previous_contents'];
		$write_intent       =& $state['write_intent'];

		$success = $wp_filesystem->move( $tmp_file, $path, $exists_before_move );
		if ( ! $success ) {
			\wp_delete_file( $tmp_file );
			$this->sync_deferred = true;
			$this->schedule_retry();
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'move_failed',
				__( 'The generated file could not be moved into place.', 'cybermaps' ),
				$path,
				array(),
				true
			);
		}

		$written_contents = $wp_filesystem->get_contents( $path );
		if ( false === $written_contents || ! \hash_equals( $new_hash, \md5( $written_contents ) ) ) {
			$rolled_back    = $this->rollback_uncheckpointed_write(
				$filename,
				$path,
				$new_hash,
				$target_exists,
				$previous_contents
			);
			$intent_cleared = $rolled_back && $this->clear_recovered_write_intent( $write_intent );
			if ( $rolled_back && ! $intent_cleared ) {
				$this->sync_deferred = true;
				$this->schedule_retry();
			}
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'write_verification_failed',
				$rolled_back
					? __( 'The generated file could not be verified after it was written, so the prior file state was restored.', 'cybermaps' )
					: __( 'The generated file could not be verified after it was written, and a safe rollback could not be proven.', 'cybermaps' ),
				$path,
				array(
					'desired_hash'       => $new_hash,
					'rollback_succeeded' => $rolled_back,
					'intent_cleared'     => $intent_cleared,
				),
				true
			);
		}
		return null;
	}

	/**
	 * Handle cancellation after publication but before ownership checkpointing.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function handle_uncheckpointed_write_block( array &$state ): ?bool {
		$filename          =& $state['filename'];
		$path              =& $state['path'];
		$new_hash          =& $state['new_hash'];
		$target_exists     =& $state['target_exists'];
		$previous_contents =& $state['previous_contents'];
		$write_intent      =& $state['write_intent'];

		$blocked_reason = $this->get_write_block_reason( $filename );
		if ( null !== $blocked_reason ) {
			$rolled_back = null;
			if ( 'operation_lock_lost' !== $blocked_reason ) {
				$rolled_back = $this->rollback_uncheckpointed_write(
					$filename,
					$path,
					$new_hash,
					$target_exists,
					$previous_contents
				);
			}
			$intent_cleared = true === $rolled_back
				? $this->clear_recovered_write_intent( $write_intent )
				: false;
			if ( null === $rolled_back || ! $intent_cleared ) {
				$this->sync_deferred = true;
				$this->schedule_retry();
			}
			if ( false === $rolled_back ) {
				$this->sync_deferred = true;
				return $this->complete_write_attempt(
					false,
					$filename,
					'error',
					'rollback_uncheckpointed_failed',
					__( 'The write was cancelled, but the prior file state could not be restored safely.', 'cybermaps' ),
					$path,
					array(
						'blocked_reason'     => $blocked_reason,
						'rollback_succeeded' => false,
					),
					true
				);
			}
			return $this->complete_write_attempt(
				false,
				$filename,
				'skipped',
				$blocked_reason,
				$this->get_write_block_message( $blocked_reason ),
				$path,
				null === $rolled_back
					? array(
						'rollback_attempted' => false,
						'intent_pending'     => true,
					)
					: array(
						'rollback_succeeded' => true,
						'intent_cleared'     => $intent_cleared,
					)
			);
		}
		return null;
	}

	/**
	 * Persist ownership for the verified publication or roll it back.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function checkpoint_published_ownership( array &$state ): ?bool {
		$filename            =& $state['filename'];
		$path                =& $state['path'];
		$new_hash            =& $state['new_hash'];
		$target_exists       =& $state['target_exists'];
		$previous_contents   =& $state['previous_contents'];
		$recorded_hash       =& $state['recorded_hash'];
		$recorded_generation =& $state['recorded_generation'];
		$write_intent        =& $state['write_intent'];

		// Store ownership only after a confirmed write. If the checkpoint cannot
		// be staged, remove the exact body just written rather than leave an
		// unowned file that future reconciliations cannot safely manage.
		if ( ! $this->record_ownership_hash( $filename, $new_hash ) ) {
			$rolled_back = $this->rollback_checkpointed_write(
				$filename,
				$path,
				$new_hash,
				$target_exists,
				$previous_contents,
				$recorded_hash,
				$recorded_generation
			);
			if ( $rolled_back ) {
				$this->ownership_repair_needed = false;
			}
			$intent_cleared = $rolled_back && $this->clear_recovered_write_intent( $write_intent );
			if ( ! $intent_cleared ) {
				$this->sync_deferred = true;
				$this->schedule_retry();
			}
			$message = $rolled_back
				? __( 'The generated write was rolled back because its ownership checkpoint could not be persisted.', 'cybermaps' )
				: __( 'The ownership checkpoint failed, and the prior file state could not be restored completely.', 'cybermaps' );
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'ownership_checkpoint_failed',
				$message,
				$path,
				array(
					'rollback_succeeded' => $rolled_back,
					'intent_cleared'     => $intent_cleared,
				),
				true
			);
		}
		return null;
	}

	/**
	 * Handle cancellation after the ownership checkpoint is durable.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 * @return bool|null Terminal result, or null to continue.
	 */
	private function handle_checkpointed_write_block( array &$state ): ?bool {
		$filename            =& $state['filename'];
		$path                =& $state['path'];
		$new_hash            =& $state['new_hash'];
		$target_exists       =& $state['target_exists'];
		$previous_contents   =& $state['previous_contents'];
		$recorded_hash       =& $state['recorded_hash'];
		$recorded_generation =& $state['recorded_generation'];
		$write_intent        =& $state['write_intent'];

		$blocked_reason = $this->get_write_block_reason( $filename );
		if ( null !== $blocked_reason ) {
			$rolled_back = null;
			if ( 'operation_lock_lost' !== $blocked_reason ) {
				$rolled_back = $this->rollback_checkpointed_write(
					$filename,
					$path,
					$new_hash,
					$target_exists,
					$previous_contents,
					$recorded_hash,
					$recorded_generation
				);
			}
			$intent_cleared = true === $rolled_back
				? $this->clear_recovered_write_intent( $write_intent )
				: false;
			if ( null === $rolled_back || ! $intent_cleared ) {
				$this->sync_deferred = true;
				$this->schedule_retry();
			}
			if ( false === $rolled_back ) {
				$this->sync_deferred = true;
				return $this->complete_write_attempt(
					false,
					$filename,
					'error',
					'rollback_checkpoint_failed',
					__( 'The write was cancelled, but its prior file and ownership checkpoint could not both be restored safely.', 'cybermaps' ),
					$path,
					array(
						'blocked_reason'     => $blocked_reason,
						'rollback_succeeded' => false,
					),
					true
				);
			}
			return $this->complete_write_attempt(
				false,
				$filename,
				'skipped',
				$blocked_reason,
				$this->get_write_block_message( $blocked_reason ),
				$path,
				null === $rolled_back
					? array(
						'rollback_attempted' => false,
						'intent_pending'     => true,
					)
					: array(
						'rollback_succeeded' => true,
						'intent_cleared'     => $intent_cleared,
					)
			);
		}
		return null;
	}

	/**
	 * Clear the recovery journal and complete a published write.
	 *
	 * @param array<string,mixed> $state Mutable write state.
	 */
	private function finish_published_write( array &$state ): bool {
		$filename     =& $state['filename'];
		$path         =& $state['path'];
		$new_hash     =& $state['new_hash'];
		$write_intent =& $state['write_intent'];

		if ( ! $this->clear_recovered_write_intent( $write_intent ) ) {
			$this->sync_deferred = true;
			$this->schedule_retry();
			return $this->complete_write_attempt(
				false,
				$filename,
				'error',
				'write_intent_cleanup_failed',
				__( 'The generated file and ownership checkpoint were written, but the recovery journal could not be cleared safely.', 'cybermaps' ),
				$path,
				array( 'desired_hash' => $new_hash ),
				true
			);
		}

		return $this->complete_write_attempt(
			true,
			$filename,
			'success',
			'written',
			__( 'The generated file was written successfully.', 'cybermaps' ),
			$path
		);
	}

	/**
	 * Request a synchronization of static files.
	 *
	 * @param bool $immediate Whether to sync immediately.
	 * @param bool $allow_off_cleanup Whether off-mode cleanup may be queued.
	 * @return array<string, mixed>|null Immediate report, or null when queued/not needed.
	 */
	public function request_sync( $immediate = false, bool $allow_off_cleanup = false ) {
		// Handle truthy hook arguments (like $post_id) which should not trigger immediate sync.
		if ( true === $immediate ) {
			$scheduled = \wp_next_scheduled( 'cybermaps_bg_sync_static_files' );
			if ( false !== $scheduled && \function_exists( 'wp_unschedule_event' ) ) {
				\wp_unschedule_event( $scheduled, 'cybermaps_bg_sync_static_files' );
			}
			\delete_option( self::SCHEDULE_ERROR_OPTION );
			return $this->sync_all();
		}

		// Content mutations do not schedule work that cannot publish anything.
		if ( 'off' === self::get_mode() && ! $allow_off_cleanup ) {
			return null;
		}

		if ( ! \wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) ) {
			$schedule_error = $this->queue_background_sync( 60 );
			if ( null !== $schedule_error ) {
				$report                       = $this->new_sync_report( self::get_mode() );
				$report['failed']['schedule'] = $schedule_error;
				return $this->finish_sync_report( $report );
			}
		} else {
			\delete_option( self::SCHEDULE_ERROR_OPTION );
		}

		return null;
	}

	/**
	 * Perform synchronization of all static files.
	 *
	 * @return array<string, mixed> Structured, truthful synchronization report.
	 */
	public function sync_all(): array {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$report   = $this->new_sync_report( self::get_mode( $settings ) );
		$terminal = $this->start_full_sync( $settings, $report );
		if ( null !== $terminal ) {
			return $terminal;
		}
		$terminal = $this->initialize_full_sync( $settings, $report );
		if ( null !== $terminal ) {
			return $terminal;
		}
		$report['started_at'] = $this->sync_report_started_at;
		try {
			$report = $this->perform_sync( $settings, $report );
		} catch ( \Throwable $error ) {
			$this->record_full_sync_exception( $report, $error );
		} finally {
			$this->finalize_full_sync_execution( $report, $settings );
		}

		return $report;
	}

	/**
	 * Acquire the full-sync lock after handling dynamic-only sites.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @param array<string,mixed> $report Initial report.
	 * @return array<string,mixed>|null
	 */
	private function start_full_sync( array $settings, array &$report ): ?array {
		if ( \function_exists( 'is_multisite' ) && \is_multisite() ) {
			\do_action( 'cybermaps_static_sync_start', $report );
			$report['status']               = 'skipped';
			$report['success']              = true;
			$report['skipped']['operation'] = array(
				'code'    => 'multisite_dynamic_only',
				'message' => __( 'Static publication is disabled on WordPress multisite.', 'cybermaps' ),
			);
			return $this->finish_full_sync_report( $report, $settings );
		}
		if ( $this->acquire_operation_lock() ) {
			return null;
		}
		\do_action( 'cybermaps_static_sync_start', $report );
		$report['status']               = $this->ownership_ready ? 'busy' : 'failed';
		$report['skipped']['operation'] = array(
			'code'    => $this->ownership_ready ? 'operation_locked' : 'ownership_migration_failed',
			'message' => $this->ownership_ready
				? __( 'Another generated-file operation is already running.', 'cybermaps' )
				: __( 'The generated-file ownership inventory could not be migrated safely.', 'cybermaps' ),
		);
		\do_action( 'cybermaps_static_sync_skipped', (string) $report['skipped']['operation']['code'], $report );
		$this->schedule_retry();
		return $this->finish_sync_report( $report, false );
	}

	/**
	 * Capture and checkpoint the full-sync snapshot.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @param array<string,mixed> $report Initial report.
	 * @return array<string,mixed>|null
	 */
	private function initialize_full_sync( array &$settings, array &$report ): ?array {
		$snapshot = $this->capture_stable_sync_snapshot();
		if ( null === $snapshot ) {
			\do_action( 'cybermaps_static_sync_start', $report );
			$report['failed']['operation'] = array(
				'code'    => 'configuration_snapshot_unstable',
				'message' => __( 'Configuration changed while static synchronization was starting; a fresh run was queued.', 'cybermaps' ),
			);
			$this->schedule_retry();
			$report = $this->finish_full_sync_report( $report, $settings );
			$this->release_operation_lock();
			return $report;
		}
		$settings                   = $snapshot['settings'];
		$report                     = $this->new_sync_report( $snapshot['mode'] );
		$report['generation']       = $snapshot['generation'];
		$this->operation_generation = $snapshot['generation'];
		\do_action( 'cybermaps_static_sync_start', $report );
		if ( $this->begin_sync_run( $report, $settings ) ) {
			return null;
		}
		$report['failed']['operation'] = array(
			'code'    => 'sync_state_checkpoint_failed',
			'message' => __( 'The static synchronization state could not be checkpointed safely.', 'cybermaps' ),
		);
		$this->sync_active             = false;
		$this->schedule_retry();
		$report = $this->finish_full_sync_report( $report, $settings );
		$this->release_operation_lock();
		return $report;
	}

	/**
	 * Record an uncaught publication exception.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 * @param \Throwable          $error Generation error.
	 */
	private function record_full_sync_exception( array &$report, \Throwable $error ): void {
		$was_recorded                  = isset( $report['failed']['operation'] );
		$report['failed']['operation'] = array(
			'code'    => 'uncaught_generation_error',
			'message' => $error->getMessage(),
		);
		$this->sync_deferred           = true;
		if ( ! $was_recorded ) {
			$this->increment_aggregate_count( $report, 'failed' );
		}
	}

	/**
	 * Finalize a full sync while the operation lock is still held.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 * @param array<string,mixed> $settings Settings snapshot.
	 */
	private function finalize_full_sync_execution( array &$report, array $settings ): void {
		$cancelled       = $this->record_full_sync_cancellation( $report );
		$lock_lost       = $this->record_full_sync_lock_loss( $report );
		$cleanup_runtime = $this->reconcile_cancelled_operation();
		if ( $cancelled && null !== $this->last_cancel_purge_result ) {
			$this->merge_purge_result( $report, $this->last_cancel_purge_result );
		}
		$this->finalize_full_sync_runner_state( $report, $cancelled, $lock_lost );
		if ( $cleanup_runtime ) {
			$this->cleanup_runtime_coordination_options();
		}
		$report = $lock_lost || $cancelled
			? $this->finish_sync_report( $report, false )
			: $this->finish_full_sync_report( $report, $settings );
		$this->release_operation_lock();
		$this->sync_active = false;
	}

	/** Record configuration cancellation in a full-sync report. */
	private function record_full_sync_cancellation( array &$report ): bool {
		$cancelled = null !== $this->operation_generation && $this->operation_generation !== $this->get_generation();
		if ( ! $cancelled ) {
			return false;
		}
		$was_recorded                             = isset( $report['skipped']['operation_cancelled'] );
		$report['skipped']['operation_cancelled'] = array(
			'code'    => 'configuration_changed',
			'message' => __( 'Settings changed during generation; obsolete owned files were reconciled.', 'cybermaps' ),
		);
		if ( ! $was_recorded ) {
			$this->increment_aggregate_count( $report, 'skipped' );
		}
		return true;
	}

	/** Record operation-lease loss in a full-sync report. */
	private function record_full_sync_lock_loss( array &$report ): bool {
		$lock_lost = $this->operation_lock_lost
			|| ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() );
		if ( ! $lock_lost ) {
			return false;
		}
		$was_recorded                       = isset( $report['failed']['operation_lock'] );
		$this->sync_deferred                = true;
		$report['failed']['operation_lock'] = array(
			'code'    => 'operation_lock_lost',
			'message' => __( 'Static synchronization paused because its operation lease was lost.', 'cybermaps' ),
		);
		if ( ! $was_recorded ) {
			$this->increment_aggregate_count( $report, 'failed' );
		}
		return true;
	}

	/** Finalize continuation state after a full-sync attempt. */
	private function finalize_full_sync_runner_state( array $report, bool $cancelled, bool $lock_lost ): void {
		if ( $lock_lost ) {
			$this->schedule_retry();
			return;
		}
		if ( $cancelled ) {
			$this->delete_current_runner_state();
			$this->schedule_retry();
			return;
		}
		if ( $this->sync_deferred ) {
			$this->persist_deferred_sync_state();
			$this->schedule_retry();
			return;
		}
		if ( ! $this->delete_current_runner_state() ) {
			$this->schedule_retry();
		} elseif ( $this->should_retry_failed_sync( $report ) ) {
			$this->schedule_failed_sync_retry( $report );
		} else {
			\delete_option( self::FAILED_RETRY_OPTION );
		}
	}

	/** Persist the current cursor as pending continuation state. */
	private function persist_deferred_sync_state(): void {
		$runner_state = $this->get_runner_state();
		$this->persist_sync_state(
			array_merge(
				$runner_state,
				array(
					'schema'     => StaticSyncRunner::STATE_SCHEMA,
					'status'     => 'pending',
					'generation' => $this->sync_generation,
					'mode'       => $this->sync_mode,
					'phase'      => (string) ( $runner_state['phase'] ?? StaticSyncRunner::PHASE_LEGACY_PURGE ),
					'cursor'     => \is_array( $runner_state['cursor'] ?? null ) ? $runner_state['cursor'] : array(),
					'context'    => \is_array( $runner_state['context'] ?? null ) ? $runner_state['context'] : array(),
					'epoch'      => $this->sync_epoch,
					'started_at' => $this->sync_report_started_at,
					'retry_at'   => \time() + 30,
					'writes'     => $this->sync_written_count,
					'last_error' => '',
				)
			)
		);
	}

	/**
	 * Refresh only static publications whose bodies change as time passes.
	 *
	 * Post writes already request a complete reconciliation. This one-shot cron
	 * path handles quiet sites: Google News entries leaving their 48-hour window
	 * and AI freshness labels crossing a configured age boundary.
	 *
	 * @return array<string,mixed> Structured refresh report.
	 */
	public function refresh_time_sensitive_publications(): array {
		$settings        = \Cybermaps\Core\ConfigurationStore::settings();
		$report          = $this->new_sync_report( self::get_mode( $settings ) );
		$report['scope'] = 'time_sensitive';
		$terminal        = $this->start_time_sensitive_refresh( $settings, $report );
		if ( null !== $terminal ) {
			return $terminal;
		}
		$this->time_sensitive_active = true;
		$this->sync_deferred         = false;
		$this->sync_written_count    = 0;
		$this->sync_started_at       = \microtime( true );

		$checkpoint = max(
			0,
			(int) \get_option( self::LAST_TIME_SENSITIVE_REFRESH_OPTION, 0 )
		);
		$now        = \time();
		$state      = null;

		try {
			$this->run_time_sensitive_refresh( $settings, $report, $state, $checkpoint, $now );
		} finally {
			$this->finalize_time_sensitive_refresh( $report, $state );
		}
		$report = $this->finish_sync_report( $report, false );
		$this->schedule_finished_time_sensitive_refresh( $state );

		\do_action( 'cybermaps_time_sensitive_static_refresh_complete', $report );
		return $report;
	}

	/** Start and validate a time-sensitive refresh. */
	private function start_time_sensitive_refresh( array &$settings, array &$report ): ?array {
		if ( 'all' !== $report['mode'] ) {
			$this->clear_time_sensitive_schedule();
			$report['status']               = 'skipped';
			$report['success']              = true;
			$report['skipped']['operation'] = array(
				'code'    => 'full_static_mode_inactive',
				'message' => __( 'Time-sensitive refresh is needed only in full static mode.', 'cybermaps' ),
			);
			return $this->finish_sync_report( $report, false );
		}
		if ( ! $this->acquire_operation_lock() ) {
			$report['status']               = 'busy';
			$report['skipped']['operation'] = array(
				'code'    => 'operation_locked',
				'message' => __( 'Another generated-file operation is already running.', 'cybermaps' ),
			);
			$this->schedule_time_sensitive_retry();
			return $this->finish_sync_report( $report, false );
		}
		$snapshot = $this->capture_stable_sync_snapshot();
		if ( null === $snapshot ) {
			$report['failed']['operation'] = array(
				'code'    => $this->operation_lock_lost ? 'operation_lock_lost' : 'configuration_snapshot_unstable',
				'message' => $this->operation_lock_lost ? __( 'The time-sensitive refresh paused because its operation lease was lost.', 'cybermaps' ) : __( 'Configuration changed while the time-sensitive refresh was starting; a fresh run was queued.', 'cybermaps' ),
			);
			$this->release_operation_lock();
			$this->schedule_retry();
			return $this->finish_sync_report( $report, false );
		}
		return $this->validate_time_sensitive_snapshot( $snapshot, $settings, $report );
	}

	/** Validate a captured time-sensitive snapshot and publication plan. */
	private function validate_time_sensitive_snapshot( array $snapshot, array &$settings, array &$report ): ?array {
		$settings                   = $snapshot['settings'];
		$report                     = $this->new_sync_report( $snapshot['mode'] );
		$report['scope']            = 'time_sensitive';
		$report['generation']       = $snapshot['generation'];
		$this->operation_generation = $snapshot['generation'];
		if ( 'all' !== $snapshot['mode'] ) {
			$this->clear_time_sensitive_schedule();
			$report['status']               = 'skipped';
			$report['success']              = true;
			$report['skipped']['operation'] = array(
				'code'    => 'full_static_mode_inactive',
				'message' => __( 'Time-sensitive refresh is needed only in full static mode.', 'cybermaps' ),
			);
			$this->release_operation_lock();
			return $this->finish_sync_report( $report, false );
		}
		$publication_plan = $this->sync_runner->validate_publication_plan( $settings, $snapshot['mode'] );
		if ( ! empty( $publication_plan['valid'] ) ) {
			return null;
		}
		unset( $publication_plan['valid'] );
		$report['failed']['publication_plan'] = $publication_plan;
		$this->clear_time_sensitive_schedule();
		$this->release_operation_lock();
		return $this->finish_sync_report( $report, false );
	}

	/** Execute time-sensitive collection and publication with report capture. */
	private function run_time_sensitive_refresh( array $settings, array &$report, ?array &$state, int $checkpoint, int $now ): void {
		try {
			$state = $this->collect_time_sensitive_state( $settings, $checkpoint, $now );
			if ( empty( $state['pending'] ) ) {
				$report['status']               = 'skipped';
				$report['success']              = true;
				$report['skipped']['operation'] = array(
					'code'    => 'no_time_transition',
					'message' => __( 'No time-sensitive publication changed since the last refresh.', 'cybermaps' ),
				);
			} else {
				$report = $this->perform_time_sensitive_refresh( $settings, $report, $state );
			}
		} catch ( \Throwable $error ) {
			$report['failed']['operation'] = array(
				'code'    => 'time_sensitive_refresh_error',
				'message' => $error->getMessage(),
			);
		}
	}

	/** Finalize time-sensitive runtime state while the lease is held. */
	private function finalize_time_sensitive_refresh( array &$report, ?array &$state ): void {
		$cancelled = null !== $this->operation_generation && $this->operation_generation !== $this->get_generation();
		if ( $cancelled ) {
			$report['skipped']['operation_cancelled'] = array(
				'code'    => 'configuration_changed',
				'message' => __( 'Settings changed during generation; obsolete owned files were reconciled.', 'cybermaps' ),
			);
		}
		$lock_lost = $this->operation_lock_lost || ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() );
		if ( $lock_lost ) {
			$report['failed']['operation_lock'] = array(
				'code'    => 'operation_lock_lost',
				'message' => __( 'The time-sensitive refresh paused because its operation lease was lost.', 'cybermaps' ),
			);
		}
		$cleanup_runtime = $lock_lost ? false : $this->reconcile_cancelled_operation();
		if ( $cancelled && null !== $this->last_cancel_purge_result ) {
			$this->merge_purge_result( $report, $this->last_cancel_purge_result ); }
		if ( $cleanup_runtime ) {
			$this->cleanup_runtime_coordination_options(); }
		if ( $lock_lost || $cancelled ) {
			$this->schedule_retry(); }
		$this->checkpoint_time_sensitive_refresh( $report, $state, $cancelled, $lock_lost );
		$this->release_operation_lock();
		$this->time_sensitive_active = false;
	}

	/** Advance the monotonic time-sensitive checkpoint when the run is complete. */
	private function checkpoint_time_sensitive_refresh( array &$report, ?array &$state, bool $cancelled, bool $lock_lost ): void {
		if ( ! \is_array( $state ) || ! empty( $state['pending'] ) || $lock_lost || $cancelled || $this->sync_deferred || $this->time_sensitive_report_has_problems( $report ) ) {
			return;
		}
		$checkpoint_target = $this->report_start_timestamp( $report );
		if ( ! $this->maintain_operation_lock() || AtomicOptionSequence::advance_to( self::LAST_TIME_SENSITIVE_REFRESH_OPTION, $checkpoint_target ) < $checkpoint_target ) {
			$state['pending']               = true;
			$report['failed']['checkpoint'] = array(
				'code'    => 'time_sensitive_checkpoint_failed',
				'message' => __( 'The time-sensitive refresh completed, but its monotonic checkpoint could not be persisted.', 'cybermaps' ),
			);
		}
	}

	/** Schedule the next time-sensitive transition after a finished attempt. */
	private function schedule_finished_time_sensitive_refresh( ?array $state ): void {
		if ( 'all' !== self::get_mode( \Cybermaps\Core\ConfigurationStore::settings() ) ) {
			$this->clear_time_sensitive_schedule();
			return; }
		if ( \is_array( $state ) ) {
			$this->schedule_time_sensitive_state( $state );
			return; }
		$this->schedule_time_sensitive_retry();
	}

	/**
	 * Publish the time-dependent subset while the operation lock is held.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @param array<string,mixed> $report In-progress report.
	 * @param array<string,mixed> $state Time-sensitive inventory.
	 * @return array<string,mixed>
	 */
	private function perform_time_sensitive_refresh( array $settings, array &$report, array &$state ): array {
		$news_succeeded = $this->refresh_time_sensitive_news( $report, $state );
		$ai_state       = $this->prepare_time_sensitive_ai_refresh( $settings, $report, $state );
		$this->publish_time_sensitive_rag_chunks( $settings, $report, $ai_state );
		$this->publish_time_sensitive_ai_sitemap( $settings, $report, $ai_state );
		$this->finish_time_sensitive_ai_refresh( $report, $state, $ai_state, $news_succeeded );
		return $report;
	}

	/** Refresh time-sensitive News outputs and their index. */
	private function refresh_time_sensitive_news( array &$report, array &$state ): bool {
		if ( empty( $state['news_due'] ) ) {
			return true; }
		$orchestrator  = new \Cybermaps\Sitemap\Orchestrator();
		$provider      = $orchestrator->get_provider( \Cybermaps\Sitemap\ProviderIdentity::NEWS );
		$news_base     = $orchestrator::get_news_sitemap_base();
		$problem_count = $this->get_sync_problem_count( $report );
		if ( $provider instanceof \Cybermaps\Sitemap\NewsProvider ) {
			$this->publish_for_sync( $report, $news_base . '.xml', static fn(): string => (string) $orchestrator->generate_xml( \Cybermaps\Sitemap\ProviderIdentity::NEWS, 1 ) );
		} else {
			$this->merge_purge_result( $report, $this->purge_owned_paths_unlocked( array( $news_base . '.xml' ) ) ); }
		$sitemap_base = $orchestrator::get_sitemap_base();
		if ( $this->get_sync_problem_count( $report ) > $problem_count ) {
			$this->skip_sync_publication( $report, $sitemap_base . '.xml', 'dependency_failed', __( 'The sitemap index was not updated because the time-sensitive News sitemap refresh failed.', 'cybermaps' ) );
		} else {
			$this->publish_for_sync( $report, $sitemap_base . '.xml', static fn(): string => (string) $orchestrator->generate_xml( 'index', 1 ) ); }
		$succeeded = $this->get_sync_problem_count( $report ) === $problem_count && ! $this->sync_deferred;
		if ( $succeeded ) {
			$state['news_due'] = false; }
		return $succeeded;
	}

	/** Prepare normalized AI transition work. */
	private function prepare_time_sensitive_ai_refresh( array $settings, array &$report, array $state ): array {
		$changed_by_id = array();
		foreach ( (array) ( $state['ai_changed_posts'] ?? array() ) as $post ) {
			$post_id = \is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
			if ( $post_id > 0 ) {
				$changed_by_id[ $post_id ] = $post; }
		}
		$index_ids   = \array_values( \array_unique( \array_filter( \array_map( 'intval', (array) ( $state['index_post_ids'] ?? array() ) ), static fn ( int $post_id ): bool => $post_id > 0 ) ) );
		$selector    = $state['selector'] ?? null;
		$changed_ids = \array_keys( $changed_by_id );
		if ( ! empty( $changed_by_id ) && ! $selector instanceof AIContentSelector ) {
			$report['failed']['ai-sitemap.xml'] = array(
				'code'    => 'content_selection_failed',
				'message' => __( 'The AI content inventory was unavailable for its time-sensitive refresh.', 'cybermaps' ),
			);
		}
		return array(
			'changed_by_id'       => $changed_by_id,
			'index_ids'           => $index_ids,
			'selector'            => $selector,
			'changed_ids'         => $changed_ids,
			'commit_ids'          => \array_values( \array_diff( $index_ids, $changed_ids ) ),
			'successful_changed'  => array(),
			'index_commit_failed' => false,
		);
	}

	/** Publish changed RAG chunks for a time-sensitive refresh. */
	private function publish_time_sensitive_rag_chunks( array $settings, array &$report, array &$ai_state ): void {
		$selector = $ai_state['selector'];
		if ( ! $selector instanceof AIContentSelector ) {
			return; }
		if ( empty( $ai_state['changed_by_id'] ) ) {
			return; }
		if ( empty( $settings['enable_rag_chunks'] ) ) {
			$ai_state['successful_changed'] = $ai_state['changed_ids'];
			return; }
		$rag_chunk = new RAGChunk( $selector, new Chunker( false ) );
		foreach ( $ai_state['changed_by_id'] as $post_id => $post ) {
			unset( $post );
			$before    = $this->get_sync_problem_count( $report );
			$processed = $this->publish_for_sync(
				$report,
				'discovery/chunks/' . $post_id . '.json',
				static function () use ( $rag_chunk, $post_id ): string {
					$content = $rag_chunk->get_content( $post_id );
					if ( null === $content ) {
						throw new \RuntimeException(
							__( 'The selected post no longer has a publishable RAG chunk payload.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static-sync exceptions become status data; the admin view escapes at its output boundary.
						);
					}
					return $content;
				}
			);
			if ( ! $processed ) {
				break; }
			if ( $this->get_sync_problem_count( $report ) === $before ) {
				$ai_state['successful_changed'][] = $post_id; }
		}
	}

	/** Publish the AI sitemap after changed chunks succeed. */
	private function publish_time_sensitive_ai_sitemap( array $settings, array &$report, array &$ai_state ): void {
		if ( empty( $ai_state['successful_changed'] ) ) {
			return; }
		$target = $this->get_static_target( 'ai_sitemap', $settings );
		if ( null === $target ) {
			$report['failed']['ai-sitemap.xml'] = array(
				'code'    => 'static_target_missing',
				'message' => __( 'The AI sitemap static target is unavailable.', 'cybermaps' ),
			);
			return; }
		$before                      = $this->get_sync_problem_count( $report );
		$generator                   = new DiscoveryPublicationGenerator( $ai_state['selector'] );
		$was_active                  = $this->time_sensitive_active;
		$this->time_sensitive_active = false;
		try {
			$published = $this->publish_for_sync( $report, (string) $target['filename'], static fn(): string => $generator->generate( 'ai_sitemap', $target, $settings ) ); } finally {
			$this->time_sensitive_active = $was_active; }
			if ( $published && $this->get_sync_problem_count( $report ) === $before ) {
				$ai_state['commit_ids'] = \array_values( \array_unique( \array_merge( $ai_state['commit_ids'], $ai_state['successful_changed'] ) ) ); }
	}

	/** Commit transition indexes and preserve any remaining backlog. */
	private function finish_time_sensitive_ai_refresh( array &$report, array &$state, array $ai_state, bool $news_succeeded ): void {
		if ( ! empty( $ai_state['commit_ids'] ) ) {
			$result                          = $this->commit_time_sensitive_indexes( $ai_state['commit_ids'], (int) ( $state['observed_at'] ?? \time() ) );
			$ai_state['index_commit_failed'] = ! $result['success'];
			$state['next']                   = $this->earliest_timestamp( \is_int( $state['next'] ?? null ) ? $state['next'] : null, $result['next'] );
			if ( $ai_state['index_commit_failed'] ) {
				$report['failed']['transition_index'] = array(
					'code'    => 'transition_index_checkpoint_failed',
					'message' => __( 'Time-sensitive files were published, but their post transition index could not be verified.', 'cybermaps' ),
				); }
		}
		$remaining_changed       = $ai_state['index_commit_failed'] ? $ai_state['changed_ids'] : \array_values( \array_diff( $ai_state['changed_ids'], $ai_state['commit_ids'] ) );
		$remaining_indexes       = $ai_state['index_commit_failed'] ? $ai_state['index_ids'] : \array_values( \array_diff( $ai_state['index_ids'], $ai_state['commit_ids'] ) );
		$backlog                 = ! empty( $state['backlog'] ) || ! empty( $remaining_indexes ) || ! empty( $remaining_changed );
		$state['index_post_ids'] = $remaining_indexes;
		$state['backlog']        = $backlog;
		$state['pending']        = ! $news_succeeded || $backlog || $ai_state['index_commit_failed'];
		if ( $state['pending'] ) {
			$this->sync_deferred                     = true;
			$report['skipped']['transition_backlog'] = array(
				'code'    => 'transition_backlog_pending',
				'message' => __( 'Unfinished time-sensitive work was preserved for a bounded retry.', 'cybermaps' ),
			); }
	}

	/**
	 * Inventory transitions that are pending and the next future transition.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return array<string,mixed>
	 */
	private function collect_time_sensitive_state( array $settings, int $checkpoint, int $now ): array {
		$state = array(
			'pending'          => false,
			'next'             => null,
			'news_due'         => false,
			'ai_changed_posts' => array(),
			'index_post_ids'   => array(),
			'selector'         => null,
			'backlog'          => false,
			'observed_at'      => $now,
		);

		if ( ! empty( $settings['enable_google_news'] ) ) {
			$news              = new \Cybermaps\Sitemap\NewsProvider();
			$news_filename     = \Cybermaps\Sitemap\Orchestrator::get_news_sitemap_base() . '.xml';
			$owns_news         = null !== $this->ownership_store->get_hash( $news_filename );
			$state['news_due'] = $checkpoint < 1
				? ( $news->get_count() > 0 || $owns_news )
				: $news->has_expiration_between( $checkpoint, $now );
			$state['pending']  = ! empty( $state['news_due'] );
			$state['next']     = $news->get_next_expiration_timestamp( $now );
		}

		if ( ! empty( $settings['enable_discovery_hub'] ) ) {
			$selector           = new AIContentSelector( $settings );
			$post_types         = \Cybermaps\Core\PublicationPostTypes::names();
			$changed_posts      = array();
			$next_ai_transition = null;
			$posts_by_id        = array();
			$backlog            = false;

			$this->collect_due_transition_posts( $post_types, $checkpoint, $now, $posts_by_id, $backlog );

			$remaining_slots = max( 0, self::TRANSITION_CANDIDATE_LIMIT - \count( $posts_by_id ) );
			$this->collect_unindexed_transition_posts( $post_types, $remaining_slots, $posts_by_id, $backlog );
			$remaining_slots = max( 0, self::TRANSITION_CANDIDATE_LIMIT - \count( $posts_by_id ) );
			$this->collect_stale_transition_posts( $post_types, $checkpoint, $remaining_slots, $posts_by_id, $backlog );
			$this->collect_changed_transition_posts( $posts_by_id, $selector, $checkpoint, $now, $changed_posts, $next_ai_transition );
			$next_ai_transition = $this->next_indexed_transition( $post_types, $now, $next_ai_transition, $backlog );

			$state['selector']         = $selector;
			$state['ai_changed_posts'] = $changed_posts;
			$state['index_post_ids']   = \array_keys( $posts_by_id );
			$state['backlog']          = $backlog;
			$state['pending']          = ! empty( $state['pending'] ) || ! empty( $posts_by_id );
			$state['next']             = $this->earliest_timestamp(
				\is_int( $state['next'] ) ? $state['next'] : null,
				$next_ai_transition
			);
		}

		return $state;
	}

	/** Collect bounded transition rows due since the checkpoint. */
	private function collect_due_transition_posts( array $post_types, int $checkpoint, int $now, array &$posts_by_id, bool &$backlog ): void {
		if ( $checkpoint < 1 || empty( $post_types ) ) {
			return; }
		$posts   = (array) \get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => self::TRANSITION_CANDIDATE_LIMIT + 1,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_key'       => AIMetadata::TRANSITION_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded transition reconciliation requires this metadata predicate and ordering to preserve publication freshness.
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded transition reconciliation requires this metadata predicate and ordering to preserve publication freshness.
					array(
						'key'     => AIMetadata::TRANSITION_META_KEY,
						'value'   => array( $checkpoint, $now ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$backlog = \count( $posts ) > self::TRANSITION_CANDIDATE_LIMIT;
		$this->index_transition_posts( \array_slice( $posts, 0, self::TRANSITION_CANDIDATE_LIMIT ), $posts_by_id );
	}

	/** Collect bounded legacy posts without a transition index. */
	private function collect_unindexed_transition_posts( array $post_types, int $limit, array &$posts_by_id, bool &$backlog ): void {
		if ( empty( $post_types ) || $limit < 1 ) {
			return; }
		$posts = (array) \get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => $limit + 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded transition reconciliation requires this metadata predicate and ordering to preserve publication freshness.
					array(
						'key'     => AIMetadata::TRANSITION_META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		if ( \count( $posts ) > $limit ) {
			$backlog = true; }
		$this->index_transition_posts( \array_slice( $posts, 0, $limit ), $posts_by_id );
	}

	/** Collect bounded posts whose transition index predates the checkpoint. */
	private function collect_stale_transition_posts( array $post_types, int $checkpoint, int $limit, array &$posts_by_id, bool &$backlog ): void {
		if ( $checkpoint < 1 || empty( $post_types ) || $limit < 1 ) {
			return; }
		$posts = (array) \get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => $limit + 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => AIMetadata::TRANSITION_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded transition reconciliation requires this metadata predicate and ordering to preserve publication freshness.
				'meta_value'     => $checkpoint, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded transition reconciliation requires this metadata predicate and ordering to preserve publication freshness.
				'meta_compare'   => '<',
				'meta_type'      => 'NUMERIC',
			)
		);
		if ( \count( $posts ) > $limit ) {
			$backlog = true; }
		$this->index_transition_posts( \array_slice( $posts, 0, $limit ), $posts_by_id );
	}

	/** Index valid post objects by positive ID. */
	private function index_transition_posts( array $posts, array &$posts_by_id ): void {
		foreach ( $posts as $post ) {
			if ( \is_object( $post ) && (int) ( $post->ID ?? 0 ) > 0 ) {
				$posts_by_id[ (int) $post->ID ] = $post; }
		}
	}

	/** Determine changed posts and their next in-memory transitions. */
	private function collect_changed_transition_posts( array $posts_by_id, AIContentSelector $selector, int $checkpoint, int $now, array &$changed_posts, ?int &$next ): void {
		foreach ( $posts_by_id as $post_id => $post ) {
			$current = AIMetadata::freshness_at( $post_id, $now );
			if ( $checkpoint > 0 && '' !== $current && AIMetadata::freshness_at( $post_id, $checkpoint ) !== $current && $selector->contains( $post_id ) ) {
				$changed_posts[] = $post; }
			$next = $this->earliest_timestamp( $next, AIMetadata::next_freshness_transition( $post_id, $now ) );
		}
	}

	/** Resolve the next persisted AI transition timestamp. */
	private function next_indexed_transition( array $post_types, int $now, ?int $next, bool $backlog ): ?int {
		$rows      = empty( $post_types ) ? array() : \get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => AIMetadata::TRANSITION_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded transition reconciliation requires this metadata predicate and ordering to preserve publication freshness.
				'meta_value'     => $now, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded transition reconciliation requires this metadata predicate and ordering to preserve publication freshness.
				'meta_compare'   => '>',
				'meta_type'      => 'NUMERIC',
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
			)
		);
		$next_id   = (int) ( $rows[0] ?? 0 );
		$next_meta = $next_id > 0 ? get_post_meta( $next_id, AIMetadata::TRANSITION_META_KEY, true ) : 0;
		if ( is_scalar( $next_meta ) && is_numeric( $next_meta ) && (int) $next_meta > $now ) {
			$next = $this->earliest_timestamp( $next, (int) $next_meta ); }
		return $backlog ? $this->earliest_timestamp( $next, $now + 60 ) : $next;
	}

	/**
	 * Advance scheduler metadata only after every dependent static publication for
	 * the bounded batch has committed successfully.
	 *
	 * @param int[] $post_ids Selected transition candidates.
	 * @return array{success:bool,next:?int}
	 */
	private function commit_time_sensitive_indexes( array $post_ids, int $observed_at ): array {
		$next = null;
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 ) {
				continue;
			}
			if ( ! $this->maintain_operation_lock() ) {
				$this->sync_deferred = true;
				return array(
					'success' => false,
					'next'    => $next,
				);
			}

			$transition = AIMetadata::refresh_transition_index( $post_id, $observed_at );
			$stored     = \get_post_meta( $post_id, AIMetadata::TRANSITION_META_KEY, true );
			$expected   = null === $transition ? 0 : $transition;
			if ( ! \is_scalar( $stored ) || ! \is_numeric( $stored ) || (int) $stored !== $expected ) {
				return array(
					'success' => false,
					'next'    => $next,
				);
			}
			$next = $this->earliest_timestamp( $next, $transition );
		}

		return array(
			'success' => true,
			'next'    => $next,
		);
	}

	/**
	 * Return one enabled static target by registry ID.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return array<string,mixed>|null
	 */
	private function get_static_target( string $endpoint_id, array $settings ): ?array {
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		$registry->register_extension_endpoints();
		foreach ( $registry->get_static_targets( 'all', $settings ) as $target ) {
			if ( (string) ( $target['id'] ?? '' ) === $endpoint_id ) {
				return $target;
			}
		}

		return null;
	}

	private function earliest_timestamp( ?int $left, ?int $right ): ?int {
		if ( null === $left ) {
			return $right;
		}
		if ( null === $right ) {
			return $left;
		}
		return min( $left, $right );
	}

	/**
	 * Replace the one-shot timer with the next transition or a bounded retry.
	 *
	 * @param array<string,mixed> $state Time-sensitive inventory.
	 */
	private function schedule_time_sensitive_state( array $state ): void {
		$this->clear_time_sensitive_schedule();
		$now         = \time();
		$next        = \is_int( $state['next'] ?? null ) ? (int) $state['next'] : null;
		$retry_delay = ! empty( $state['backlog'] ) ? 60 : self::TIME_SENSITIVE_RETRY_DELAY;
		$timestamp   = ! empty( $state['pending'] )
			? $this->earliest_timestamp( $now + $retry_delay, $next )
			: $next;
		if ( null === $timestamp ) {
			return;
		}

		$this->queue_time_sensitive_refresh( max( $now + 60, $timestamp ) );
	}

	private function schedule_time_sensitive_retry(): void {
		$this->clear_time_sensitive_schedule();
		$this->queue_time_sensitive_refresh( \time() + self::TIME_SENSITIVE_RETRY_DELAY );
	}

	private function clear_time_sensitive_schedule(): void {
		\wp_clear_scheduled_hook( self::TIME_SENSITIVE_REFRESH_HOOK );
		$current_error = \get_option( self::SCHEDULE_ERROR_OPTION, array() );
		if (
			\is_array( $current_error )
			&& 'time_sensitive_refresh' === (string) ( $current_error['event'] ?? '' )
		) {
			\delete_option( self::SCHEDULE_ERROR_OPTION );
		}
	}

	/**
	 * Queue one transition refresh and expose a rejected WP-Cron event.
	 */
	private function queue_time_sensitive_refresh( int $timestamp ): void {
		$result        = \wp_schedule_single_event(
			max( \time() + 1, $timestamp ),
			self::TIME_SENSITIVE_REFRESH_HOOK,
			array(),
			true
		);
		$is_error      = false === $result
			|| ( \function_exists( 'is_wp_error' ) && \is_wp_error( $result ) );
		$current_error = \get_option( self::SCHEDULE_ERROR_OPTION, array() );
		$current_error = \is_array( $current_error ) ? $current_error : array();

		if ( ! $is_error ) {
			if ( 'time_sensitive_refresh' === (string) ( $current_error['event'] ?? '' ) ) {
				\delete_option( self::SCHEDULE_ERROR_OPTION );
			}
			return;
		}

		// Do not hide a more immediate full-publication scheduling failure.
		if (
			! empty( $current_error )
			&& 'time_sensitive_refresh' !== (string) ( $current_error['event'] ?? '' )
		) {
			return;
		}

		$message = \function_exists( 'is_wp_error' ) && \is_wp_error( $result )
			? (string) $result->get_error_message()
			: __( 'WordPress rejected the time-sensitive static refresh event.', 'cybermaps' );
		$error   = array(
			'status'  => 'error',
			'code'    => 'time_sensitive_schedule_failed',
			'message' => $message,
			'time'    => \time(),
			'event'   => 'time_sensitive_refresh',
		);
		\update_option( self::SCHEDULE_ERROR_OPTION, $error, false );
		\do_action( 'cybermaps_static_sync_skipped', 'time_sensitive_schedule_failed', $error );
	}

	/**
	 * Invalidate in-flight work before settings or lifecycle state changes.
	 */
	public function invalidate( bool $suspend = false, bool $cleanup_after_cancel = false ): int {
		$generation = AtomicOptionSequence::increment( self::GENERATION_OPTION );

		if ( $suspend || 'all' !== self::get_mode() ) {
			$this->clear_time_sensitive_schedule();
		}
		if ( $suspend ) {
			\update_option( self::SUSPENDED_OPTION, '1', false );
		}
		if ( $cleanup_after_cancel ) {
			\update_option( self::CLEANUP_AFTER_CANCEL_OPTION, '1', false );
		}

		return $generation;
	}

	/**
	 * Cancel in-flight generation and purge ownership-safe output.
	 *
	 * If another operation owns the lock, its finally block observes the new
	 * generation and performs a full ownership-safe purge before releasing.
	 *
	 * @param string $scope Purge scope.
	 * @param bool   $suspend Keep generation disabled until resume() is called.
	 * @param bool   $cleanup_after_cancel Remove coordination options after uninstall.
	 * @return array<string, mixed>
	 */
	public function cancel_and_purge(
		string $scope = 'all',
		bool $suspend = false,
		bool $cleanup_after_cancel = false
	): array {
		$this->invalidate( $suspend, $cleanup_after_cancel );
		return $this->purge_all( '', '', $scope );
	}

	/**
	 * Resume static generation after plugin activation.
	 */
	public function resume(): void {
		\delete_option( self::SUSPENDED_OPTION );
		\delete_option( self::CLEANUP_AFTER_CANCEL_OPTION );
		$this->invalidate();
	}

	private function perform_sync( array $settings, array &$report ): array {
		return $this->sync_runner->run( $settings, $report );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_runner_state(): array {
		$state = \get_option( self::SYNC_STATE_OPTION, array() );
		if ( ! \is_array( $state ) ) {
			return array();
		}
		if ( StaticSyncRunner::STATE_SCHEMA === (int) ( $state['schema'] ?? 0 ) ) {
			return $state;
		}
		return array();
	}

	/** @internal For StaticSyncRunner. */
	public function runner_merge_purge( array &$report, array $purge ): void {
		$this->merge_purge_result( $report, $purge );
	}

	/** @internal For StaticSyncRunner. */
	public function runner_purge_unlocked( string $old_base, string $old_news_base, string $scope, array $desired_files = array() ): array {
		return $this->purge_all_unlocked( $old_base, $old_news_base, $scope, $desired_files );
	}

	/** @internal For StaticSyncRunner. */
	public function runner_purge_legacy_publications( array &$report, array &$cursor ): bool {
		$legacy_count = \count( self::LEGACY_GENERATED_FILES );
		$index        = max( 0, min( $legacy_count, (int) ( $cursor['index'] ?? 0 ) ) );
		while ( $index < $legacy_count ) {
			if ( $this->runner_budget_exhausted() ) {
				$this->sync_deferred = true;
				$cursor['index']     = $index;
				return false;
			}

			$filename = self::LEGACY_GENERATED_FILES[ $index ];
			$purge    = $this->purge_owned_paths_unlocked( array( $filename ) );
			$this->merge_purge_result( $report, $purge );
			if ( 'operation_lock_lost' === (string) ( $purge['status'] ?? '' ) ) {
				$this->sync_deferred = true;
				$cursor['index']     = $index;
				return false;
			}

			++$index;
			$cursor['index'] = $index;
		}

		return true;
	}

	/** @internal For StaticSyncRunner. */
	public function runner_purge_stale_generation(): array {
		return $this->purge_all_unlocked(
			'',
			'',
			'stale_generation',
			array(),
			$this->sync_epoch
		);
	}

	/**
	 * Reconcile at most one bounded ownership-shard slice.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 * @param array<string,mixed> $cursor Persisted shard/path cursor.
	 * @internal For StaticSyncRunner.
	 */
	public function runner_reconcile_stale_slice( array &$report, array &$cursor ): bool {
		$position   = $this->get_stale_slice_position( $cursor );
		$shard      = $position['shard'];
		$after_path = $position['path'];
		if ( $shard >= StaticOwnershipStore::SHARD_COUNT ) {
			return true;
		}
		if ( ! $this->load_filesystem_api() || ! \WP_Filesystem() ) {
			$report['failed']['stale_reconciliation'] = array(
				'code'    => 'filesystem_init_failed',
				'message' => __( 'WordPress could not initialize the filesystem for stale-file reconciliation.', 'cybermaps' ),
			);
			return true;
		}
		if ( ! $this->maintain_operation_lock() ) {
			$this->sync_deferred = true;
			return false;
		}

		$records = $this->ownership_store->load_shard( $shard );
		if ( ! $this->ownership_store->is_shard_valid( $shard ) ) {
			$report['failed']['stale_reconciliation'] = array(
				'code'    => 'invalid_ownership_shard',
				'message' => \sprintf(
					/* translators: %d: zero-based ownership shard number. */
					__( 'Ownership shard %d is malformed and was preserved for administrator review.', 'cybermaps' ),
					$shard
				),
			);
			$cursor                                   = array(
				'shard' => $shard,
				'path'  => $after_path,
			);
			// This is a deterministic administrator-repair condition, not
			// resumable work. Complete the phase with a failed report so the
			// continuation worker does not hot-loop on preserved evidence.
			return true;
		}
		\ksort( $records, SORT_STRING );
		$paths = \array_values(
			\array_filter(
				\array_keys( $records ),
				static fn ( string $path ): bool => '' === $after_path || \strcmp( $path, $after_path ) > 0
			)
		);
		if ( empty( $paths ) ) {
			$cursor = array(
				'shard' => $shard + 1,
				'path'  => '',
			);
			return $shard + 1 >= StaticOwnershipStore::SHARD_COUNT;
		}

		$batch   = \array_slice( $paths, 0, 100 );
		$errors  = $this->load_write_errors();
		$changed = false;
		$last    = $after_path;
		global $wp_filesystem;

		foreach ( $batch as $file ) {
			if ( ! $this->reconcile_stale_slice_record( $file, $shard, $report, $records, $errors, $changed, $last, $wp_filesystem ) ) {
				break; }
		}
		return $this->finish_stale_slice( $report, $cursor, $shard, $after_path, $last, $paths, $batch, $records, $errors, $changed );
	}

	/** Normalize a stale-slice cursor position. */
	private function get_stale_slice_position( array $cursor ): array {
		return array(
			'shard' => max( 0, min( StaticOwnershipStore::SHARD_COUNT, (int) ( $cursor['shard'] ?? 0 ) ) ),
			'path'  => \is_string( $cursor['path'] ?? null ) ? $cursor['path'] : '',
		);
	}

	/** Reconcile one stale ownership record. */
	private function reconcile_stale_slice_record( string $file, int $shard, array &$report, array &$records, array &$errors, bool &$changed, string &$last, $filesystem ): bool {
		if ( ! $this->can_continue_stale_slice() ) {
			$this->sync_deferred = true;
			return false; }
		$last       = $file;
		$record     = $records[ $file ];
		$generation = $this->get_stale_record_generation( $record );
		if ( $generation === $this->sync_epoch ) {
			return true; }
		$recorded_hash = (string) ( $record['hash'] ?? '' );
		$path          = $this->validate_stale_slice_record( $file, $recorded_hash, $report, $records, $errors, $changed, $filesystem );
		if ( null === $path || ! $this->verify_stale_slice_body( $file, $path, $recorded_hash, $report, $errors, $filesystem ) ) {
			return true; }
		$deletion = $this->delete_owned_publication( $file, \strtolower( $recorded_hash ), $generation );
		if ( ! empty( $deletion['pending'] ) ) {
			$report['retained'][ $file ] = (string) $deletion['code'];
			$this->sync_deferred         = true;
			return false; }
		if ( 'conflict' === $deletion['status'] || 'successor_preserved' === $deletion['code'] ) {
			$report['retained'][ $file ] = (string) $deletion['code'];
			$records                     = $this->ownership_store->load_shard( $shard );
			return true; }
		if ( empty( $deletion['success'] ) ) {
			$report['retained'][ $file ] = (string) $deletion['code'];
			return true; }
		if ( ! empty( $deletion['deleted'] ) ) {
			$report['deleted'][] = $file; }
		unset( $records[ $file ], $errors[ $file ] );
		return true;
	}

	/** Return the normalized generation of a stale ownership record. */
	private function get_stale_record_generation( array $record ): int {
		return (int) ( $record['generation'] ?? 0 );
	}

	/** Determine whether the current stale slice may process another record. */
	private function can_continue_stale_slice(): bool {
		return ! $this->sync_budget_exhausted() && $this->maintain_operation_lock();
	}

	/** Validate one stale inventory record and resolve its path. */
	private function validate_stale_slice_record( string $file, string $hash, array &$report, array &$records, array &$errors, bool &$changed, $filesystem ): ?string {
		if ( ! $this->is_safe_generated_path( $file ) || 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $hash ) ) {
			$report['retained'][ $file ] = 'invalid_inventory_record';
			$this->set_write_error( $errors, $file, $this->build_write_result( $file, 'error', 'invalid_inventory_record', __( 'An invalid generated-file ownership record was retained.', 'cybermaps' ), '' ) );
			return null;
		}
		$resolution = $this->resolve_file_path( $file );
		$path       = (string) ( $resolution['path'] ?? '' );
		if ( '' === $path ) {
			$report['retained'][ $file ] = (string) ( $resolution['reason'] ?? 'publication_root_unavailable' );
			return null; }
		if ( ! $filesystem->exists( $path ) ) {
			unset( $records[ $file ], $errors[ $file ] );
			$changed = true;
			return null; }
		$issue = $this->get_ownership_verification_issue( $file, $path, $filesystem );
		if ( null !== $issue ) {
			$report['retained'][ $file ] = (string) $issue['code'];
			$this->set_write_error( $errors, $file, $this->build_write_result( $file, 'conflict', 'purge_' . (string) $issue['code'], (string) $issue['message'], $path ) );
			return null; }
		return $path;
	}

	/** Verify a stale record still owns its filesystem body. */
	private function verify_stale_slice_body( string $file, string $path, string $hash, array &$report, array &$errors, $filesystem ): bool {
		$current = $filesystem->get_contents( $path );
		if ( false !== $current && \hash_equals( \strtolower( $hash ), \md5( $current ) ) ) {
			return true; }
		$report['retained'][ $file ] = false === $current ? 'read_failed' : 'content_changed';
		$this->set_write_error( $errors, $file, $this->build_write_result( $file, false === $current ? 'error' : 'conflict', false === $current ? 'purge_read_failed' : 'purge_owned_file_modified', __( 'The stale file could not be proven unchanged and was retained.', 'cybermaps' ), $path ) );
		return false;
	}

	/** Checkpoint one processed stale-shard slice and advance its cursor. */
	private function finish_stale_slice( array &$report, array &$cursor, int $shard, string $after_path, string $last, array $paths, array $batch, array $records, array $errors, bool $changed ): bool {
		$retry_cursor = array(
			'shard' => $shard,
			'path'  => $after_path,
		);
		if ( $this->operation_lock_lost ) {
			$cursor = $retry_cursor;
			return false; }
		if ( $changed && ! $this->maintain_operation_lock() ) {
			$this->sync_deferred = true;
			$cursor              = $retry_cursor;
			return false; }
		if ( $changed && ! $this->ownership_store->write_shard_records( $shard, $records, false ) ) {
			$report['failed']['stale_reconciliation_checkpoint'] = array(
				'code'    => 'ownership_checkpoint_failed',
				'message' => __( 'Stale-file reconciliation could not checkpoint its ownership shard.', 'cybermaps' ),
			);
			$this->sync_deferred                                 = true;
			$cursor = $retry_cursor;
			return false; }
		if ( $changed ) {
			$this->ownership_revision_pending = true; }
		if ( ! $this->maintain_operation_lock() ) {
			$this->sync_deferred = true;
			$cursor              = $retry_cursor;
			return false; }
		$this->persist_write_errors( $errors );
		if ( $this->sync_deferred || \count( $paths ) > \count( $batch ) ) {
			$cursor = array(
				'shard' => $shard,
				'path'  => $last,
			);
			return false; }
		$cursor = array(
			'shard' => $shard + 1,
			'path'  => '',
		);
		return $shard + 1 >= StaticOwnershipStore::SHARD_COUNT;
	}

	/** @internal For StaticSyncRunner. */
	public function runner_publish( array &$report, string $filename, callable $content_factory, bool $allow_omission = false ): bool {
		return $this->publish_for_sync( $report, $filename, $content_factory, $allow_omission );
	}

	/** @internal For StaticSyncRunner. */
	public function runner_skip( array &$report, string $filename, string $code, string $message ): void {
		$this->skip_sync_publication( $report, $filename, $code, $message );
	}

	/** @internal For StaticSyncRunner. */
	public function runner_get_localized_languages(): array {
		$languages = array();
		foreach ( \Cybermaps\Core\TranslationHelper::get_active_languages() as $language ) {
			$language = \sanitize_key( (string) $language );
			if ( '' !== $language && ! \in_array( $language, $languages, true ) ) {
				$languages[] = $language;
			}
		}
		return $languages;
	}

	/** @internal For StaticSyncRunner. */
	public function runner_publish_localized_target( array &$report, string $language, string $target ): bool {
		return $this->publish_localized_discovery_target( $report, $language, $target );
	}

	/** @internal For StaticSyncRunner. */
	public function runner_get_problem_count( array $report ): int {
		return $this->get_sync_problem_count( $report );
	}

	/** @internal For StaticSyncRunner. */
	public function runner_budget_exhausted(): bool {
		return $this->operation_lock_lost
			|| (
				null !== $this->operation_generation
				&& $this->operation_generation !== $this->get_generation()
			)
			|| $this->sync_budget_exhausted();
	}

	/** @internal For StaticSyncRunner. */
	public function runner_mark_deferred(): void {
		$this->sync_deferred = true;
	}

	/** @internal For StaticSyncRunner. */
	public function runner_get_epoch(): int {
		return $this->sync_epoch;
	}

	/**
	 * @param array<string,mixed> $cursor
	 * @internal For StaticSyncRunner.
	 */
	public function runner_save_state( string $phase, array $cursor, bool $pending, array $context = array() ): void {
		if ( ! $pending && StaticSyncRunner::PHASE_COMPLETE === $phase ) {
			return;
		}
		if (
			$this->operation_lock_lost
			|| null === $this->operation_lock_token
			|| ! $this->maintain_operation_lock()
			|| $this->sync_generation !== $this->get_generation()
		) {
			$this->sync_deferred = true;
			return;
		}
		if ( ! $this->persist_sync_state(
			array(
				'schema'     => StaticSyncRunner::STATE_SCHEMA,
				'status'     => $pending ? 'pending' : 'running',
				'generation' => $this->sync_generation,
				'mode'       => $this->sync_mode,
				'phase'      => $phase,
				'cursor'     => $cursor,
				'context'    => $context,
				'epoch'      => $this->sync_epoch,
				'started_at' => $this->sync_report_started_at,
				'retry_at'   => $pending ? \time() + 30 : 0,
				'writes'     => $this->sync_written_count,
				'last_error' => '',
			)
		) ) {
			$this->sync_deferred = true;
		}
	}

	/**
	 * Create an empty synchronization report.
	 *
	 * @return array<string, mixed>
	 */
	private function new_sync_report( string $mode ): array {
		return array(
			'success'     => false,
			'status'      => 'running',
			'mode'        => $mode,
			'generation'  => $this->get_generation(),
			'started_at'  => \gmdate( 'c' ),
			'finished_at' => '',
			'desired'     => array(),
			'written'     => array(),
			'unchanged'   => array(),
			'omitted'     => array(),
			'conflicted'  => array(),
			'failed'      => array(),
			'skipped'     => array(),
			'deleted'     => array(),
			'retained'    => array(),
			'counts'      => array(),
		);
	}

	/**
	 * Generate and write one desired publication while recording its outcome.
	 *
	 * @param array<string, mixed> $report In-progress report.
	 * @param callable             $content_factory Deferred publication generator.
	 * @param bool                 $allow_omission Whether null intentionally omits this target.
	 */
	private function publish_for_sync(
		array &$report,
		string $filename,
		callable $content_factory,
		bool $allow_omission = false
	): bool {
		// The caller owns the phase cursor. Report that no target was consumed so
		// the exact same target is resumed in the next bounded request.
		if ( $this->sync_budget_exhausted() ) {
			$this->sync_deferred = true;
			return false;
		}

		if ( \in_array( $filename, (array) $report['desired'], true ) ) {
			$report['failed'][ $filename ] = array(
				'code'    => 'duplicate_static_target',
				'message' => __( 'Multiple registry entries resolved to the same static filename.', 'cybermaps' ),
			);
			return true;
		}
		if ( $this->resume_completed_publication( $report, $filename ) ) {
			return true;
		}

		$report['desired'][] = $filename;

		// Avoid expensive sitemap/chunk generation when the current mode, a
		// settings-generation fence, or an unresolved headless/origin root already
		// makes this publication impossible.
		if (
			null !== $this->get_write_block_reason( $filename )
			|| '' === (string) ( $this->resolve_file_path( $filename )['path'] ?? '' )
		) {
			$this->record_sync_write_result(
				$report,
				$filename,
				$this->write_file( $filename, '' )
			);
			return true;
		}

		$generated = $this->generate_sync_publication_content(
			$report,
			$filename,
			$content_factory,
			$allow_omission
		);
		if ( ! empty( $generated['handled'] ) ) {
			return true;
		}
		$content = (string) $generated['content'];

		$this->record_sync_write_result(
			$report,
			$filename,
			$this->write_file( $filename, $content )
		);
		return true;
	}

	/**
	 * Generate and validate one static publication body.
	 *
	 * @param array<string,mixed> $report In-progress sync report.
	 * @param string              $filename Relative generated filename.
	 * @param callable            $content_factory Publication body factory.
	 * @param bool                $allow_omission Whether null omits the publication.
	 * @return array{handled:bool,content:string}
	 */
	private function generate_sync_publication_content(
		array &$report,
		string $filename,
		callable $content_factory,
		bool $allow_omission
	): array {
		try {
			$content = $content_factory();
			if ( $allow_omission && null === $content ) {
				$report['desired']               = \array_values(
					\array_filter(
						(array) $report['desired'],
						static fn ( string $desired ): bool => $filename !== $desired
					)
				);
				$this->sync_omitted[ $filename ] = true;
				$report['omitted'][]             = $filename;
				unset( $this->sync_completed[ $filename ] );
				return array(
					'handled' => true,
					'content' => '',
				);
			}
			if ( ! \is_string( $content ) ) {
				throw new \RuntimeException( __( 'The publication generator did not return a string body.', 'cybermaps' ) );
			}
			if ( $this->is_full_llms_generated_path( $filename ) && \strlen( $content ) > LLMS::FULL_OUTPUT_MAX_BYTES ) {
				throw new PublicationSizeLimitException( \basename( $filename ), LLMS::FULL_OUTPUT_MAX_BYTES );
			}
			return array(
				'handled' => false,
				'content' => $content,
			);
		} catch ( PublicationSizeLimitException $error ) {
			$report['failed'][ $filename ] = array(
				'code'      => 'publication_too_large',
				'message'   => $error->getMessage(),
				'max_bytes' => $error->get_maximum_bytes(),
			);
			$this->merge_purge_result(
				$report,
				$this->purge_owned_paths_unlocked( array( $filename ), true )
			);
			return array(
				'handled' => true,
				'content' => '',
			);
		} catch ( \Throwable $error ) {
			$report['failed'][ $filename ] = array(
				'code'    => 'generation_failed',
				'message' => $error->getMessage(),
			);
			return array(
				'handled' => true,
				'content' => '',
			);
		}
	}

	/**
	 * Add the most recent write_file() result to an in-progress sync report.
	 *
	 * @param array<string, mixed> $report In-progress report.
	 */
	private function record_sync_write_result( array &$report, string $filename, bool $success ): void {
		$result = $this->get_last_write_result();
		$code   = (string) ( $result['code'] ?? '' );

		if ( $success ) {
			$this->sync_completed[ $filename ] = true;
			unset( $this->sync_omitted[ $filename ] );
			if ( 'unchanged' === $code ) {
				$report['unchanged'][] = $filename;
			} else {
				$report['written'][] = $filename;
				++$this->sync_written_count;
			}
			return;
		}

		$status = (string) ( $result['status'] ?? 'error' );
		if ( 'conflict' === $status ) {
			$report['conflicted'][ $filename ] = $result;
		} elseif ( 'skipped' === $status ) {
			$report['skipped'][ $filename ] = $result;
		} else {
			$report['failed'][ $filename ] = $result;
		}
	}

	/**
	 * Stop one request at a deterministic write or wall-clock boundary.
	 */
	private function sync_budget_exhausted(): bool {
		if ( ! $this->sync_active && ! $this->time_sensitive_active ) {
			return false;
		}

		return $this->sync_deferred
			|| $this->sync_written_count >= self::SYNC_MAX_WRITES_PER_RUN
			|| \microtime( true ) - $this->sync_started_at >= self::SYNC_MAX_RUNTIME_SECONDS;
	}

	/**
	 * Reuse a publication already proven by an earlier bounded continuation.
	 *
	 * Configuration and content mutations advance the static generation, so the
	 * persisted progress applies only to the exact generation captured when this
	 * run begins. The ownership hash is rechecked before an expensive body is
	 * skipped.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 */
	private function resume_completed_publication( array &$report, string $filename ): bool {
		if ( isset( $this->sync_omitted[ $filename ] ) ) {
			return true;
		}
		if ( ! isset( $this->sync_completed[ $filename ] ) ) {
			return false;
		}
		if ( ! $this->publication_matches_ownership( $filename ) ) {
			unset( $this->sync_completed[ $filename ] );
			return false;
		}
		if ( ! $this->mark_ownership_seen( $filename ) ) {
			unset( $this->sync_completed[ $filename ] );
			return false;
		}

		$report['desired'][]   = $filename;
		$report['unchanged'][] = $filename;
		return true;
	}

	/**
	 * Verify a prior continuation without regenerating its potentially costly body.
	 */
	private function publication_matches_ownership( string $filename ): bool {
		$recorded_hash = $this->ownership_store->get_hash( $filename );
		if ( ! \is_string( $recorded_hash ) || 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $recorded_hash ) ) {
			return false;
		}

		$resolution = $this->resolve_file_path( $filename );
		$path       = (string) ( $resolution['path'] ?? '' );
		if ( '' === $path || ! $this->load_filesystem_api() || ! \WP_Filesystem() ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem->exists( $path ) ) {
			return false;
		}
		if ( null !== $this->get_ownership_verification_issue( $filename, $path, $wp_filesystem ) ) {
			return false;
		}

		$content = $wp_filesystem->get_contents( $path );
		return false !== $content
			&& \hash_equals( \strtolower( $recorded_hash ), \md5( $content ) );
	}

	/**
	 * Record an intentionally unmodified dependency index as desired but skipped.
	 *
	 * @param array<string, mixed> $report In-progress report.
	 */
	private function skip_sync_publication(
		array &$report,
		string $filename,
		string $code,
		string $message
	): void {
		if ( ! \in_array( $filename, (array) $report['desired'], true ) ) {
			$report['desired'][] = $filename;
		}
		$report['skipped'][ $filename ] = array(
			'status'   => 'skipped',
			'code'     => $code,
			'message'  => $message,
			'time'     => \time(),
			'filename' => $filename,
			'path'     => $this->get_file_path( $filename ),
		);
	}

	/**
	 * Count write-time problems for dependency fencing.
	 *
	 * @param array<string, mixed> $report In-progress report.
	 */
	private function get_sync_problem_count( array $report ): int {
		return \count( (array) $report['conflicted'] )
			+ \count( (array) $report['failed'] )
			+ \count( (array) $report['skipped'] );
	}

	/**
	 * Merge an ownership-safe purge into a synchronization report.
	 *
	 * @param array<string, mixed> $report In-progress report.
	 * @param array<string, mixed> $purge Purge result.
	 */
	private function merge_purge_result( array &$report, array $purge ): void {
		$sample_limit     = 100;
		$aggregate_counts = \is_array( $report['_aggregate_counts'] ?? null )
			? $report['_aggregate_counts']
			: array();
		$had_aggregate    = \is_array( $report['_aggregate_counts'] ?? null );
		$deleted_count    = $this->merge_deleted_purge_samples( $report, $purge, $aggregate_counts, $sample_limit );
		$retained_count   = $this->merge_retained_purge_samples( $report, $purge, $aggregate_counts, $sample_limit );

		if ( $had_aggregate || $deleted_count > $sample_limit || $retained_count > $sample_limit ) {
			$report['_aggregate_counts']             = $aggregate_counts;
			$report['_aggregate_counts']['deleted']  = $deleted_count;
			$report['_aggregate_counts']['retained'] = $retained_count;
		}

		$this->merge_purge_failure( $report, $purge );
	}

	/**
	 * Merge deleted-path samples and return their aggregate count.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 * @param array<string,mixed> $purge Purge result.
	 * @param array<string,mixed> $aggregate_counts Existing aggregate counts.
	 * @param int                 $sample_limit Maximum retained samples.
	 */
	private function merge_deleted_purge_samples( array &$report, array $purge, array $aggregate_counts, int $sample_limit ): int {
		$prior             = \array_values( \array_unique( \array_filter( (array) ( $report['deleted'] ?? array() ), 'is_string' ) ) );
		$incoming          = \array_values( \array_unique( \array_filter( (array) ( $purge['deleted'] ?? array() ), 'is_string' ) ) );
		$count             = max( \count( $prior ), (int) ( $aggregate_counts['deleted'] ?? 0 ) )
			+ \count( \array_diff( $incoming, $prior ) );
		$report['deleted'] = \array_slice(
			\array_values( \array_unique( \array_merge( $prior, $incoming ) ) ),
			0,
			$sample_limit
		);
		return $count;
	}

	/**
	 * Merge retained-path samples and return their aggregate count.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 * @param array<string,mixed> $purge Purge result.
	 * @param array<string,mixed> $aggregate_counts Existing aggregate counts.
	 * @param int                 $sample_limit Maximum retained samples.
	 */
	private function merge_retained_purge_samples( array &$report, array $purge, array $aggregate_counts, int $sample_limit ): int {
		$prior              = \is_array( $report['retained'] ?? null ) ? $report['retained'] : array();
		$incoming           = \is_array( $purge['retained'] ?? null ) ? $purge['retained'] : array();
		$count              = max( \count( $prior ), (int) ( $aggregate_counts['retained'] ?? 0 ) )
			+ \count( \array_diff_key( $incoming, $prior ) );
		$report['retained'] = \array_slice(
			\array_replace( $prior, $incoming ),
			0,
			$sample_limit,
			true
		);
		return $count;
	}

	/**
	 * Merge a failed purge into the report.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 * @param array<string,mixed> $purge Purge result.
	 */
	private function merge_purge_failure( array &$report, array $purge ): void {
		if ( ! empty( $purge['success'] ) ) {
			return;
		}
		$new_failure               = ! isset( $report['failed']['purge'] );
		$report['failed']['purge'] = array(
			'code'    => 'purge_' . (string) ( $purge['status'] ?? 'failed' ),
			'message' => (string) ( $purge['message'] ?? __( 'Static reconciliation failed.', 'cybermaps' ) ),
		);
		if ( $new_failure ) {
			$this->increment_aggregate_count( $report, 'failed' );
		}
	}

	/**
	 * Publish localized discovery text with guaranteed language restoration.
	 *
	 * @param array<string, mixed> $report In-progress report.
	 */
	private function publish_localized_discovery_target( array &$report, string $language, string $target ): bool {
		$original_language = \Cybermaps\Core\TranslationHelper::get_current_language();
		$language          = \sanitize_key( $language );
		if ( '' === $language ) {
			return true;
		}

		$processed = true;
		try {
			\Cybermaps\Core\TranslationHelper::switch_to_language( $language );
			switch ( $target ) {
				case 'llms':
					$llms      = new LLMS();
					$processed = $this->publish_for_sync(
						$report,
						$language . '/llms.txt',
						static fn(): string => (string) $llms->get_llms_content( false, true, $language )
					);
					break;

				case 'llms_full':
					$llms      = new LLMS();
					$processed = $this->publish_for_sync(
						$report,
						$language . '/llms-full.txt',
						static fn(): string => (string) $llms->get_llms_content( true, true, $language )
					);
					break;

				case 'llms_tldr':
					$llms_tldr = new LLMSTLDR();
					$processed = $this->publish_for_sync(
						$report,
						$language . '/llms-tldr.txt',
						static fn(): string => (string) $llms_tldr->get_content( true, $language )
					);
					break;
			}
		} catch ( \Throwable $error ) {
			$report['failed'][ $language . '/' . $target ] = array(
				'code'    => 'localized_generation_failed',
				'message' => $error->getMessage(),
			);
		} finally {
			\Cybermaps\Core\TranslationHelper::switch_to_language( $original_language );
		}

		return $processed;
	}

	/**
	 * Persist a full-sync report, checkpoint successful output, and arm its timer.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return array<string,mixed>
	 */
	private function finish_full_sync_report( array $report, array $settings ): array {
		$report = $this->finish_sync_report( $report, false );
		if ( 'all' !== (string) $report['mode'] ) {
			$this->clear_time_sensitive_schedule();
			return $this->finish_sync_report( $report );
		}

		if ( ! empty( $report['success'] ) && 'complete' === $report['status'] ) {
			$checkpoint_target = $this->report_start_timestamp( $report );
			if (
				AtomicOptionSequence::advance_to(
					self::LAST_TIME_SENSITIVE_REFRESH_OPTION,
					$checkpoint_target
				) < $checkpoint_target
			) {
				$report['failed']['time_sensitive_checkpoint'] = array(
					'code'    => 'time_sensitive_checkpoint_failed',
					'message' => __( 'The full sync completed, but its monotonic freshness checkpoint could not be persisted.', 'cybermaps' ),
				);
				$report                                        = $this->finish_sync_report( $report, false );
			}
		}

		$checkpoint = AtomicOptionSequence::current( self::LAST_TIME_SENSITIVE_REFRESH_OPTION );
		if ( $checkpoint < 0 ) {
			$report['failed']['time_sensitive_checkpoint_read'] = array(
				'code'    => 'time_sensitive_checkpoint_read_failed',
				'message' => __( 'The freshness checkpoint could not be read safely, so its next timer was deferred.', 'cybermaps' ),
			);
			$this->schedule_time_sensitive_retry();
			return $this->finish_sync_report( $report );
		}
		try {
			$state = $this->collect_time_sensitive_state( $settings, $checkpoint, \time() );
			$this->schedule_time_sensitive_state( $state );
		} catch ( \Throwable ) {
			$this->schedule_time_sensitive_retry();
		}

		return $this->finish_sync_report( $report );
	}

	/**
	 * Use the beginning of generation as the conservative freshness checkpoint.
	 */
	private function report_start_timestamp( array $report ): int {
		$started = \strtotime( (string) ( $report['started_at'] ?? '' ) );
		return false === $started ? \time() : max( 0, $started );
	}

	/**
	 * Treat an explicit no-transition result as a drained range, while every write,
	 * dependency, ownership, or backlog skip remains checkpoint-blocking.
	 *
	 * @param array<string,mixed> $report In-progress targeted report.
	 */
	private function time_sensitive_report_has_problems( array $report ): bool {
		if ( ! empty( $report['conflicted'] ) || ! empty( $report['failed'] ) || ! empty( $report['retained'] ) ) {
			return true;
		}

		foreach ( (array) ( $report['skipped'] ?? array() ) as $skip ) {
			if ( ! \is_array( $skip ) || 'no_time_transition' !== (string) ( $skip['code'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Include events appended after the runner hydrated its bounded aggregate.
	 *
	 * @param array<string,mixed> $report In-progress report.
	 */
	private function increment_aggregate_count( array &$report, string $field, int $amount = 1 ): void {
		if ( ! \is_array( $report['_aggregate_counts'] ?? null ) || $amount < 1 ) {
			return;
		}

		$current                               = max(
			0,
			(int) ( $report['_aggregate_counts'][ $field ] ?? 0 )
		);
		$report['_aggregate_counts'][ $field ] = $amount > PHP_INT_MAX - $current
			? PHP_INT_MAX
			: $current + $amount;
	}

	/**
	 * Finalize, persist, and publish one synchronization report.
	 *
	 * @param array<string, mixed> $report In-progress report.
	 * @param bool                 $persist Whether this is the canonical full-sync report.
	 * @return array<string, mixed>
	 */
	private function finish_sync_report( array $report, bool $persist = true ): array {
		$this->normalize_sync_report_lists( $report );
		$this->count_sync_report_fields( $report );
		$this->apply_sync_report_status( $report );

		$report['finished_at'] = \gmdate( 'c' );
		unset( $report['_aggregate_counts'] );
		$this->persist_finished_sync_report( $report, $persist );
		return $report;
	}

	/**
	 * Normalize list fields in a synchronization report.
	 *
	 * @param array<string,mixed> $report Report to normalize.
	 */
	private function normalize_sync_report_lists( array &$report ): void {
		foreach ( array( 'desired', 'written', 'unchanged', 'omitted', 'deleted' ) as $list_key ) {
			$report[ $list_key ] = \array_values(
				\array_unique( \array_filter( (array) $report[ $list_key ], 'is_string' ) )
			);
		}
	}

	/**
	 * Populate aggregate report counts.
	 *
	 * @param array<string,mixed> $report Report to count.
	 */
	private function count_sync_report_fields( array &$report ): void {
		$aggregate_counts = \is_array( $report['_aggregate_counts'] ?? null )
			? $report['_aggregate_counts']
			: array();
		$report['counts'] = array();
		foreach ( array( 'desired', 'written', 'unchanged', 'omitted', 'conflicted', 'failed', 'skipped', 'deleted', 'retained' ) as $field ) {
			$report['counts'][ $field ] = max(
				\count( (array) ( $report[ $field ] ?? array() ) ),
				max( 0, (int) ( $aggregate_counts[ $field ] ?? 0 ) )
			);
		}
	}

	/**
	 * Derive final report status from counts and continuation state.
	 *
	 * @param array<string,mixed> $report Report to finalize.
	 */
	private function apply_sync_report_status( array &$report ): void {
		$has_problems = $this->sync_report_has_problems( $report );
		if ( $this->sync_deferred && 'running' === (string) $report['status'] ) {
			$report['status']  = 'pending';
			$report['success'] = false;
			return;
		}
		if ( \in_array( $report['status'], array( 'busy', 'skipped' ), true ) ) {
			return;
		}
		if ( ! $has_problems ) {
			$report['status']  = 'complete';
			$report['success'] = true;
			return;
		}
		$made_progress     = $report['counts']['written'] > 0
			|| $report['counts']['unchanged'] > 0
			|| $report['counts']['deleted'] > 0;
		$report['status']  = $made_progress ? 'partial' : 'failed';
		$report['success'] = false;
	}

	/**
	 * Determine whether report counts contain a problem.
	 *
	 * @param array<string,mixed> $report Report with counts.
	 */
	private function sync_report_has_problems( array $report ): bool {
		return $report['counts']['conflicted'] > 0
			|| $report['counts']['failed'] > 0
			|| $report['counts']['retained'] > 0
			|| ( $report['counts']['skipped'] > 0 && 'skipped' !== $report['status'] );
	}

	/**
	 * Persist and announce a finished report when requested.
	 *
	 * @param array<string,mixed> $report Finished report.
	 * @param bool                $persist Whether persistence is enabled.
	 */
	private function persist_finished_sync_report( array $report, bool $persist ): void {
		if ( ! $persist ) {
			return;
		}
		\update_option( self::LAST_ATTEMPT_OPTION, $report['finished_at'], false );
		\update_option( self::LAST_REPORT_OPTION, $this->compact_sync_report( $report ), false );
		if ( ! empty( $report['success'] ) && 'off' !== $report['mode'] && 'skipped' !== $report['status'] ) {
			\update_option( 'cybermaps_last_static_sync', $report['finished_at'], false );
			\delete_option( self::SCHEDULE_ERROR_OPTION );
		}
		if ( 'complete' === $report['status'] ) {
			\do_action( 'cybermaps_static_sync_complete', $report );
		}
	}

	/**
	 * Keep the persisted report diagnostic but bounded on very large sites.
	 *
	 * @param array<string, mixed> $report Complete in-memory report.
	 * @return array<string, mixed>
	 */
	private function compact_sync_report( array $report ): array {
		$limit     = 100;
		$truncated = array();

		foreach ( array( 'desired', 'written', 'unchanged', 'omitted', 'deleted' ) as $list_key ) {
			$count = max(
				\count( (array) $report[ $list_key ] ),
				(int) ( $report['counts'][ $list_key ] ?? 0 )
			);
			if ( \count( (array) $report[ $list_key ] ) > $limit ) {
				$report[ $list_key ] = \array_slice( (array) $report[ $list_key ], 0, $limit );
			}
			$sample_count = \count( (array) $report[ $list_key ] );
			if ( $count > $sample_count ) {
				$truncated[ $list_key ] = $count - $sample_count;
			}
		}

		foreach ( array( 'conflicted', 'failed', 'skipped', 'retained' ) as $map_key ) {
			$count = max(
				\count( (array) $report[ $map_key ] ),
				(int) ( $report['counts'][ $map_key ] ?? 0 )
			);
			if ( \count( (array) $report[ $map_key ] ) > $limit ) {
				$report[ $map_key ] = \array_slice( (array) $report[ $map_key ], 0, $limit, true );
			}
			$sample_count = \count( (array) $report[ $map_key ] );
			if ( $count > $sample_count ) {
				$truncated[ $map_key ] = $count - $sample_count;
			}
		}

		$report['truncated'] = $truncated;
		return $report;
	}

	/**
	 * Purge generated files that Cybermaps can prove it still owns.
	 *
	 * The static hash option doubles as the generated-file inventory. A file is
	 * deleted only when its current contents still match the hash recorded after
	 * Cybermaps wrote it. This protects pre-existing or subsequently edited files
	 * such as robots.txt from lifecycle and manual purge operations.
	 *
	 * @param string $old_base Previous sitemap base when purging renamed sitemaps.
	 * @param string $old_news_base Previous News sitemap base when purging renamed sitemaps.
	 * @param string $scope `all`, `web_root`, `discovery`, `sitemaps`, `stale`, or an internal reconciliation scope.
	 * @param string[] $desired_files Desired inventory when reconciling stale output.
	 * @return array Lists of deleted and retained files plus status.
	 */
	public function purge_all( $old_base = '', $old_news_base = '', $scope = 'all', array $desired_files = array() ) {
		$acquired_here = null === $this->operation_lock_token;
		if ( ! $this->acquire_operation_lock() ) {
			return array(
				'success'  => false,
				'status'   => 'busy',
				'message'  => __( 'Another generated-file operation is already running.', 'cybermaps' ),
				'deleted'  => array(),
				'retained' => array(),
			);
		}

		$result = array(
			'success'  => false,
			'status'   => 'error',
			'deleted'  => array(),
			'retained' => array(),
		);
		try {
			$result = $this->purge_all_unlocked( $old_base, $old_news_base, $scope, $desired_files );
		} finally {
			if ( $acquired_here ) {
				$cleanup_runtime = $this->reconcile_cancelled_operation()
					|| (
						'all' === $scope
						&& '1' === (string) \get_option( self::CLEANUP_AFTER_CANCEL_OPTION, '0' )
						&& $this->purge_allows_coordination_cleanup( $result )
					);
				if ( $cleanup_runtime ) {
					$this->cleanup_runtime_coordination_options();
				}
				$this->release_operation_lock();
			}
		}

		return $result;
	}

	/**
	 * Purge generated files while this request owns the operation lock.
	 *
	 * @param string   $old_base Previous sitemap base.
	 * @param string   $old_news_base Previous News sitemap base.
	 * @param string   $scope Purge scope.
	 * @param string[] $desired_files Desired inventory for stale reconciliation.
	 * @return array<string, mixed>
	 */
	private function purge_all_unlocked(
		$old_base,
		$old_news_base,
		$scope,
		array $desired_files,
		?int $desired_generation = null
	): array {
		$preflight = $this->get_purge_all_preflight_error( (string) $scope );
		if ( null !== $preflight ) {
			return $preflight;
		}

		global $wp_filesystem;
		$deleted  = array();
		$retained = array();
		if ( $this->ownership_dirty ) {
			return array(
				'success'  => false,
				'status'   => 'error',
				'message'  => __( 'Static reconciliation stopped because an ownership checkpoint is still unresolved.', 'cybermaps' ),
				'deleted'  => $deleted,
				'retained' => $retained,
			);
		}
		$this->ownership_store->clear_local_cache();
		$hashes = $this->get_ownership_hashes();
		$errors = $this->load_write_errors();

		if ( ! \is_array( $hashes ) ) {
			$hashes = array();
		}
		$sitemap_targets = array_values(
			array_filter(
				array(
					'' !== (string) $old_base ? (string) $old_base . '.xml' : '',
					'' !== (string) $old_news_base ? (string) $old_news_base . '.xml' : '',
				)
			)
		);
		$desired_lookup  = array();
		foreach ( $desired_files as $desired_file ) {
			if ( \is_string( $desired_file ) && $this->is_safe_generated_path( $desired_file ) ) {
				$desired_lookup[ $desired_file ] = true;
			}
		}

		$terminal = $this->purge_inventory_records( $hashes, $errors, $deleted, $retained, (string) $scope, $sitemap_targets, $desired_lookup, $desired_generation, $wp_filesystem );
		if ( null !== $terminal ) {
			return $terminal;
		}

		// A failed publication may have a pre-existing file that is not in Core's
		// ownership inventory. It cannot be deleted, but it must be surfaced so a
		// stale web-server response is not hidden behind the generation error.
		$this->surface_failed_purge_publications( $scope, $desired_lookup, $hashes, $deleted, $retained, $errors, $wp_filesystem );

		// Diagnostics can outlive the hash inventory (for example an untracked
		// conflict or a failed first write). Reconcile them against the same scope
		// so status screens do not show failures for publications no longer desired.
		$this->reconcile_purge_errors( $errors, $hashes, $retained, $scope, $desired_lookup, $sitemap_targets, $desired_generation );

		return $this->finish_purge_all( $hashes, $errors, $deleted, $retained );
	}

	/** Return an early full-purge error, or null when purge may proceed. */
	private function get_purge_all_preflight_error( string $scope ): ?array {
		if ( \function_exists( 'is_multisite' ) && \is_multisite() && ! \is_main_site() ) {
			return array(
				'success'  => false,
				'status'   => 'error',
				'message'  => __( 'Purge is only allowed on the main site.', 'cybermaps' ),
				'deleted'  => array(),
				'retained' => array(),
			);
		}
		if ( ! \in_array( $scope, array( 'all', 'web_root', 'discovery', 'sitemaps', 'stale', 'stale_generation', 'legacy_publications', 'failed_publication' ), true ) ) {
			return array(
				'success'  => false,
				'status'   => 'error',
				'message'  => __( 'The generated-file purge scope is invalid.', 'cybermaps' ),
				'deleted'  => array(),
				'retained' => array(),
			);
		}
		if ( ! $this->load_filesystem_api() || ! \WP_Filesystem() ) {
			return array(
				'success'  => false,
				'status'   => 'error',
				'message'  => __( 'WP_Filesystem initialization failed.', 'cybermaps' ),
				'deleted'  => array(),
				'retained' => array(),
			);
		}
		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			return $this->operation_lock_lost_purge_result(); }
		return null;
	}

	/** Process all ownership inventory records in a full purge. */
	private function purge_inventory_records( array &$hashes, array &$errors, array &$deleted, array &$retained, string $scope, array $sitemap_targets, array $desired_lookup, ?int $desired_generation, $filesystem ): ?array {
		foreach ( $hashes as $file => $recorded_hash ) {
			if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
				return $this->operation_lock_lost_purge_result( $deleted, $retained ); }
			if ( ! \is_string( $file ) || ! $this->is_safe_generated_path( $file ) ) {
				$retained[ (string) $file ] = 'invalid_inventory_path';
				$this->set_write_error( $errors, (string) $file, $this->build_write_result( (string) $file, 'error', 'invalid_inventory_path', __( 'An invalid generated-file inventory path was retained.', 'cybermaps' ), '' ) );
				continue;
			}
			if ( ! $this->purge_scope_includes_file( $file, $scope, $sitemap_targets, $desired_lookup, $desired_generation ) ) {
				continue; }
			$prepared = $this->prepare_inventory_purge_file( $file, $recorded_hash, $hashes, $errors, $retained, $filesystem );
			if ( empty( $prepared['ready'] ) ) {
				continue; }
			$terminal = $this->finish_inventory_purge_file( $file, (string) $recorded_hash, (string) $prepared['path'], (string) $prepared['current_hash'], $hashes, $errors, $deleted, $retained );
			if ( null !== $terminal ) {
				return $terminal; }
		}
		return null;
	}

	/** Persist full-purge inventory and diagnostics. */
	private function finish_purge_all( array $hashes, array $errors, array $deleted, array $retained ): array {
		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			return $this->operation_lock_lost_purge_result( $deleted, $retained ); }
		if ( ! $this->store_ownership_hashes( $hashes ) ) {
			return array(
				'success'  => false,
				'status'   => 'error',
				'message'  => __( 'The generated-file inventory could not be checkpointed after reconciliation.', 'cybermaps' ),
				'deleted'  => $deleted,
				'retained' => $retained,
			); }
		$this->persist_write_errors( $errors );
		return array(
			'success'  => true,
			'status'   => empty( $retained ) ? 'complete' : 'partial',
			'deleted'  => $deleted,
			'retained' => $retained,
		);
	}

	/** Determine whether a purge scope includes an owned path. */
	private function purge_scope_includes_file( string $file, string $scope, array $sitemap_targets, array $desired_lookup, ?int $desired_generation ): bool {
		$included = array(
			'web_root'            => ! $this->is_well_known_mode_static_path( $file ),
			'discovery'           => $this->is_discovery_generated_path( $file ),
			'sitemaps'            => \in_array( $file, $sitemap_targets, true ),
			'legacy_publications' => \in_array( $file, self::LEGACY_GENERATED_FILES, true ),
			'stale'               => ! isset( $desired_lookup[ $file ] ),
			'stale_generation'    => null === $desired_generation || $this->ownership_store->get_generation( $file ) !== $desired_generation,
			'failed_publication'  => isset( $desired_lookup[ $file ] ),
		);
		return $included[ $scope ] ?? true;
	}

	/** Resolve and verify an inventory record before purge deletion. */
	private function prepare_inventory_purge_file( string $file, $recorded_hash, array &$hashes, array &$errors, array &$retained, $filesystem ): array {
		if ( ! \is_string( $recorded_hash ) || 1 !== \preg_match( '/^[a-f0-9]{32}$/i', $recorded_hash ) ) {
			$retained[ $file ] = 'invalid_inventory_hash';
			$this->set_write_error( $errors, $file, $this->build_write_result( $file, 'error', 'invalid_inventory_hash', __( 'The ownership record is invalid, so the file was retained.', 'cybermaps' ), $this->get_file_path( $file ) ) );
			return array( 'ready' => false );
		}
		$resolution = $this->resolve_file_path( $file );
		$path       = (string) ( $resolution['path'] ?? '' );
		if ( '' === $path ) {
			$retained[ $file ] = (string) ( $resolution['reason'] ?? 'publication_root_unavailable' );
			$this->set_write_error( $errors, $file, $this->build_write_result( $file, 'error', (string) ( $resolution['reason'] ?? 'publication_root_unavailable' ), (string) ( $resolution['message'] ?? __( 'The owned file could not be resolved safely for reconciliation.', 'cybermaps' ) ), '' ) );
			return array( 'ready' => false );
		}
		if ( ! $filesystem->exists( $path ) ) {
			unset( $hashes[ $file ], $errors[ $file ] );
			return array( 'ready' => false ); }
		$issue = $this->get_ownership_verification_issue( $file, $path, $filesystem );
		if ( null !== $issue ) {
			$retained[ $file ] = (string) $issue['code'];
			$this->set_write_error(
				$errors,
				$file,
				$this->build_write_result(
					$file,
					'conflict',
					'purge_' . (string) $issue['code'],
					(string) $issue['message'],
					$path,
					array(
						'recorded_hash' => \strtolower( $recorded_hash ),
						'current_bytes' => (int) ( $issue['current_bytes'] ?? 0 ),
					)
				)
			);
			return array( 'ready' => false );
		}
		return $this->verify_inventory_purge_body( $file, $recorded_hash, $path, $errors, $retained, $filesystem );
	}

	/** Verify an inventory record still matches its body. */
	private function verify_inventory_purge_body( string $file, string $recorded_hash, string $path, array &$errors, array &$retained, $filesystem ): array {
		$current = $filesystem->get_contents( $path );
		if ( false === $current ) {
			$retained[ $file ] = 'read_failed';
			$this->set_write_error( $errors, $file, $this->build_write_result( $file, 'error', 'purge_read_failed', __( 'The file could not be read, so it was retained during purge.', 'cybermaps' ), $path, array( 'recorded_hash' => \strtolower( $recorded_hash ) ) ) );
			return array( 'ready' => false );
		}
		$current_hash = \md5( $current );
		if ( ! \hash_equals( \strtolower( $recorded_hash ), $current_hash ) ) {
			$retained[ $file ] = 'content_changed';
			$this->set_write_error(
				$errors,
				$file,
				$this->build_write_result(
					$file,
					'conflict',
					'purge_owned_file_modified',
					__( 'The file changed after Cybermaps wrote it and was retained during purge.', 'cybermaps' ),
					$path,
					array(
						'recorded_hash' => \strtolower( $recorded_hash ),
						'current_hash'  => $current_hash,
					)
				)
			);
			return array( 'ready' => false );
		}
		return array(
			'ready'        => true,
			'path'         => $path,
			'current_hash' => $current_hash,
		);
	}

	/** Delete one verified full-purge inventory record. */
	private function finish_inventory_purge_file( string $file, string $recorded_hash, string $path, string $current_hash, array &$hashes, array &$errors, array &$deleted, array &$retained ): ?array {
		$deletion = $this->delete_owned_publication( $file, $current_hash, $this->ownership_store->get_generation( $file ) );
		if ( ! empty( $deletion['pending'] ) ) {
			$retained[ $file ] = (string) $deletion['code'];
			$this->set_write_error( $errors, $file, $this->build_write_result( $file, 'error', (string) $deletion['code'], __( 'The owned generated file was retained with a durable deletion tombstone for recovery.', 'cybermaps' ), $path, array( 'recorded_hash' => \strtolower( $recorded_hash ) ) ) );
			return array(
				'success'  => false,
				'status'   => 'error',
				'message'  => __( 'Static purge stopped with a pending deletion tombstone.', 'cybermaps' ),
				'deleted'  => $deleted,
				'retained' => $retained,
			);
		}
		if ( 'conflict' === $deletion['status'] || 'successor_preserved' === $deletion['code'] ) {
			$retained[ $file ] = (string) $deletion['code'];
			$current_owned     = $this->ownership_store->get_hash( $file );
			if ( null === $current_owned ) {
				unset( $hashes[ $file ] );
			} else {
				$hashes[ $file ] = $current_owned; }
			return null;
		}
		if ( empty( $deletion['success'] ) ) {
			$retained[ $file ] = (string) $deletion['code'];
			return null; }
		if ( ! empty( $deletion['deleted'] ) ) {
			$deleted[] = $file; }
		unset( $hashes[ $file ], $errors[ $file ] );
		return null;
	}

	/** Surface untracked files after a failed publication. */
	private function surface_failed_purge_publications( string $scope, array $desired_lookup, array $hashes, array $deleted, array &$retained, array &$errors, $filesystem ): void {
		if ( 'failed_publication' !== $scope ) {
			return; }
		foreach ( \array_keys( $desired_lookup ) as $file ) {
			if ( \in_array( $file, $deleted, true ) || isset( $retained[ $file ] ) || isset( $hashes[ $file ] ) ) {
				continue; }
			$resolution = $this->resolve_file_path( $file );
			$path       = (string) ( $resolution['path'] ?? '' );
			if ( '' === $path || ! $filesystem->exists( $path ) ) {
				continue; }
			$retained[ $file ] = 'untracked_existing_file';
			$this->set_write_error( $errors, $file, $this->build_write_result( $file, 'conflict', 'untracked_existing_file', __( 'An existing file is not proven to be owned by Cybermaps and was retained after generation failed.', 'cybermaps' ), $path ) );
		}
	}

	/** Remove diagnostics that no longer belong to a purge scope. */
	private function reconcile_purge_errors( array &$errors, array $hashes, array $retained, string $scope, array $desired_lookup, array $sitemap_targets, ?int $desired_generation ): void {
		foreach ( \array_keys( $errors ) as $file ) {
			if ( isset( $hashes[ $file ] ) || $this->should_retain_purge_error( (string) $file, $scope, $retained, $desired_lookup, $sitemap_targets, $desired_generation ) ) {
				continue; }
			unset( $errors[ $file ] );
		}
	}

	/** Determine whether one diagnostic remains in the active purge scope. */
	private function should_retain_purge_error( string $file, string $scope, array $retained, array $desired_lookup, array $sitemap_targets, ?int $desired_generation ): bool {
		$retain = array(
			'stale'               => isset( $desired_lookup[ $file ] ),
			'stale_generation'    => null !== $desired_generation && $this->ownership_store->get_generation( $file ) === $desired_generation,
			'failed_publication'  => ! isset( $desired_lookup[ $file ] ) || isset( $retained[ $file ] ),
			'web_root'            => $this->is_well_known_mode_static_path( $file ),
			'discovery'           => ! $this->is_discovery_generated_path( $file ),
			'sitemaps'            => ! \in_array( $file, $sitemap_targets, true ),
			'legacy_publications' => ! \in_array( $file, self::LEGACY_GENERATED_FILES, true ),
		);
		return $retain[ $scope ] ?? false;
	}

	/**
	 * Reconcile a constant-size list without flattening the sharded inventory.
	 *
	 * @param string[] $filenames Relative generated paths.
	 * @param bool     $surface_untracked Whether an existing unowned target is a conflict.
	 * @return array<string,mixed>
	 */
	private function purge_owned_paths_unlocked( array $filenames, bool $surface_untracked = false ): array {
		if ( ! $this->load_filesystem_api() || ! \WP_Filesystem() ) {
			return array(
				'success'  => false,
				'status'   => 'error',
				'message'  => __( 'WP_Filesystem initialization failed.', 'cybermaps' ),
				'deleted'  => array(),
				'retained' => array(),
			);
		}
		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			return $this->operation_lock_lost_purge_result();
		}

		global $wp_filesystem;
		$deleted  = array();
		$retained = array();
		$errors   = $this->load_write_errors();

		foreach ( \array_values( \array_unique( $filenames ) ) as $filename ) {
			$terminal = $this->purge_owned_path_entry( $filename, $surface_untracked, $deleted, $retained, $errors, $wp_filesystem );
			if ( null !== $terminal ) {
				return $terminal; }
		}

		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			return $this->operation_lock_lost_purge_result( $deleted, $retained );
		}
		$this->persist_write_errors( $errors );

		return array(
			'success'  => true,
			'status'   => empty( $retained ) ? 'complete' : 'partial',
			'deleted'  => $deleted,
			'retained' => $retained,
		);
	}

	/** Purge one targeted owned path. */
	private function purge_owned_path_entry( $filename, bool $surface_untracked, array &$deleted, array &$retained, array &$errors, $filesystem ): ?array {
		if ( ! \is_string( $filename ) || ! $this->is_safe_generated_path( $filename ) ) {
			return null; }
		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			return $this->operation_lock_lost_purge_result( $deleted, $retained ); }
		$recorded_hash = $this->ownership_store->get_hash( $filename );
		if ( null === $recorded_hash ) {
			$this->surface_untracked_purge_path( $filename, $surface_untracked, $retained, $errors, $filesystem );
			return null; }
		$prepared = $this->prepare_owned_path_deletion( $filename, $recorded_hash, $deleted, $retained, $errors, $filesystem );
		if ( isset( $prepared['result'] ) ) {
			return $prepared['result']; }
		if ( empty( $prepared['ready'] ) ) {
			return null; }
		return $this->finish_owned_path_deletion( $filename, $recorded_hash, $deleted, $retained, $errors );
	}

	/** Surface an untracked targeted path when requested. */
	private function surface_untracked_purge_path( string $filename, bool $surface, array &$retained, array &$errors, $filesystem ): void {
		$resolution = $surface ? $this->resolve_file_path( $filename ) : array();
		$path       = (string) ( $resolution['path'] ?? '' );
		if ( $surface && '' !== $path && $filesystem->exists( $path ) ) {
			$retained[ $filename ] = 'untracked_existing_file';
			$this->set_write_error( $errors, $filename, $this->build_write_result( $filename, 'conflict', 'untracked_existing_file', __( 'An existing file is not proven to be owned by Cybermaps and was retained after generation failed.', 'cybermaps' ), $path ) );
		} else {
			unset( $errors[ $filename ] ); }
	}

	/** Verify a targeted owned path before deletion. */
	private function prepare_owned_path_deletion( string $filename, string $hash, array $deleted, array &$retained, array &$errors, $filesystem ): array {
		$resolution = $this->resolve_file_path( $filename );
		$path       = (string) ( $resolution['path'] ?? '' );
		if ( '' === $path ) {
			$retained[ $filename ] = (string) ( $resolution['reason'] ?? 'publication_root_unavailable' );
			return array( 'ready' => false ); }
		if ( ! $filesystem->exists( $path ) ) {
			if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
				return array( 'result' => $this->operation_lock_lost_purge_result( $deleted, $retained ) ); }
			if ( ! $this->remove_ownership_hash( $filename ) ) {
				$retained[ $filename ] = 'ownership_checkpoint_failed'; }
			unset( $errors[ $filename ] );
			return array( 'ready' => false );
		}
		$issue = $this->get_ownership_verification_issue( $filename, $path, $filesystem );
		if ( null !== $issue ) {
			$retained[ $filename ] = (string) $issue['code'];
			$this->set_write_error( $errors, $filename, $this->build_write_result( $filename, 'conflict', 'purge_' . (string) $issue['code'], (string) $issue['message'], $path ) );
			return array( 'ready' => false ); }
		return $this->verify_targeted_owned_path_body( $filename, $hash, $path, $retained, $errors, $filesystem );
	}

	/** Verify a targeted owned path still matches its recorded hash. */
	private function verify_targeted_owned_path_body( string $filename, string $hash, string $path, array &$retained, array &$errors, $filesystem ): array {
		$current = $filesystem->get_contents( $path );
		if ( false !== $current && \hash_equals( $hash, \md5( $current ) ) ) {
			return array( 'ready' => true ); }
		$retained[ $filename ] = false === $current ? 'read_failed' : 'content_changed';
		$this->set_write_error( $errors, $filename, $this->build_write_result( $filename, false === $current ? 'error' : 'conflict', false === $current ? 'purge_read_failed' : 'purge_owned_file_modified', __( 'The stale file could not be proven unchanged and was retained.', 'cybermaps' ), $path ) );
		return array( 'ready' => false );
	}

	/** Finalize deletion of a verified targeted path. */
	private function finish_owned_path_deletion( string $filename, string $hash, array &$deleted, array &$retained, array &$errors ): ?array {
		$deletion = $this->delete_owned_publication( $filename, \strtolower( $hash ), $this->ownership_store->get_generation( $filename ) );
		if ( ! empty( $deletion['pending'] ) ) {
			$retained[ $filename ] = (string) $deletion['code'];
			return array(
				'success'  => false,
				'status'   => 'error',
				'message'  => __( 'Targeted purge stopped with a pending deletion tombstone.', 'cybermaps' ),
				'deleted'  => $deleted,
				'retained' => $retained,
			); }
		if ( 'conflict' === $deletion['status'] || 'successor_preserved' === $deletion['code'] || empty( $deletion['success'] ) ) {
			$retained[ $filename ] = (string) $deletion['code'];
			return null; }
		if ( ! empty( $deletion['deleted'] ) ) {
			$deleted[] = $filename; }
		unset( $errors[ $filename ] );
		return null;
	}

	/**
	 * Acquire an installation-local lock for generated-file inventory changes.
	 */
	private function allow_stale_operation_takeover(): bool {
		$intent = $this->write_intent_store->read();
		if ( null === $intent ) {
			return true;
		}
		if ( false === $intent ) {
			$this->record_write_intent_recovery_error(
				'write_intent_invalid',
				__( 'A malformed static publication intent is blocking stale-lease takeover and requires administrator review.', 'cybermaps' ),
				false
			);
			return false;
		}

		if (
			null !== $this->operator_recovery_intent
			&& $intent === $this->operator_recovery_intent
		) {
			return true;
		}

		$this->record_write_intent_recovery_error(
			'write_intent_operator_recovery_required',
			__( 'A pending static publication intent blocked automatic stale-lease takeover. Quiesce every static worker, then use the confirmed administrator recovery action.', 'cybermaps' ),
			false
		);
		return false;
	}

	private function acquire_operation_lock(): bool {
		if ( null !== $this->operation_lock_token ) {
			return $this->maintain_operation_lock();
		}

		$this->ownership_ready = true;
		$this->operation_lock->reset_local_state();

		if ( $this->operation_lock->acquire() ) {
			$this->operation_lock_token = $this->operation_lock->get_token();
			$this->refresh_operation_option_caches();
			$this->operation_generation = $this->get_generation();
			$this->operation_lock_lost  = false;
			if ( $this->operation_generation < 0 ) {
				$this->operation_lock->release();
				$this->operation_lock_token = null;
				$this->operation_generation = null;
				return false;
			}
			$this->begin_ownership_batch();
			if ( ! $this->ownership_ready || ! $this->maintain_operation_lock() ) {
				$this->operation_lock->release();
				$this->operation_lock_token = null;
				$this->operation_generation = null;
				$this->ownership_store->clear_local_cache();
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * Validate the owner token and periodically renew the lock with a
	 * compare-and-swap update.
	 */
	private function maintain_operation_lock(): bool {
		if ( ! $this->operation_lock->maintain() ) {
			$this->operation_lock_lost = $this->operation_lock->is_lost();
			return false;
		}
		return true;
	}

	/**
	 * Release only the lock token owned by this request.
	 */
	private function release_operation_lock(): void {
		if ( null === $this->operation_lock_token ) {
			return;
		}
		$this->flush_ownership_checkpoint();
		$repair_needed = $this->ownership_dirty || $this->ownership_repair_needed;
		if ( $this->ownership_revision_pending ) {
			if (
				! $repair_needed
				&& ! $this->operation_lock_lost
				&& $this->maintain_operation_lock()
				&& $this->ownership_store->commit_revision()
			) {
				$this->ownership_revision_pending = false;
			} else {
				$repair_needed = true;
			}
		}
		if ( $repair_needed ) {
			$incident = AtomicOptionSequence::increment( StaticOwnershipStore::REPAIR_OPTION );
			$this->schedule_retry();
			if ( $incident < 1 ) {
				\update_option(
					self::SCHEDULE_ERROR_OPTION,
					array(
						'status'  => 'error',
						'code'    => 'ownership_repair_marker_failed',
						'message' => __( 'An ownership checkpoint needs repair, but its durable retry marker could not be verified.', 'cybermaps' ),
						'time'    => \time(),
					),
					false
				);
			}
		}

		$this->operation_lock->release();
		$this->operation_lock_token = null;
		$this->operation_generation = null;
		$this->operation_lock_lost  = false;
		$this->ownership_store->clear_local_cache();
		$this->ownership_dirty            = false;
		$this->ownership_repair_needed    = false;
		$this->ownership_revision_pending = false;
		$this->ownership_changes          = 0;
	}

	/**
	 * Reconcile cancellation while the current request still owns the lock.
	 *
	 * @return bool Whether uninstall requested removal of coordination options.
	 */
	private function reconcile_cancelled_operation(): bool {
		$this->last_cancel_purge_result = null;
		$cancelled                      = null !== $this->operation_generation
			&& $this->operation_generation !== $this->get_generation();
		$suspended                      = '1' === (string) \get_option( self::SUSPENDED_OPTION, '0' );
		if ( ! $cancelled ) {
			return false;
		}

		if ( $suspended ) {
			// Deactivation/uninstall is an explicit administrative cleanup boundary;
			// normal settings/content cancellation is resumed by the bounded runner.
			$this->last_cancel_purge_result = $this->purge_all_unlocked( '', '', 'all', array() );
		} else {
			$this->schedule_retry();
		}

		return '1' === (string) \get_option( self::CLEANUP_AFTER_CANCEL_OPTION, '0' )
			&& null !== $this->last_cancel_purge_result
			&& $this->purge_allows_coordination_cleanup( $this->last_cancel_purge_result );
	}

	/**
	 * Confirm lifecycle cleanup completed while this request still owns the lease.
	 *
	 * @param array<string,mixed> $purge Purge result.
	 */
	private function purge_allows_coordination_cleanup( array $purge ): bool {
		if (
			null === $this->operation_lock_token
			|| ! $this->maintain_operation_lock()
			|| empty( $purge['success'] )
			|| 'complete' !== (string) ( $purge['status'] ?? '' )
			|| ! empty( $purge['retained'] )
		) {
			return false;
		}

		$this->ownership_store->clear_local_cache();
		return array() === $this->ownership_store->read_flat_hashes();
	}

	/**
	 * Queue a bounded retry after lock contention or cancelled generation.
	 */
	private function schedule_retry(): void {
		if (
			'1' === (string) \get_option( self::SUSPENDED_OPTION, '0' )
			|| \wp_next_scheduled( 'cybermaps_bg_sync_static_files' )
		) {
			return;
		}

		$this->queue_background_sync( 30 );
	}

	/**
	 * Retry transient publication failures with a bounded, generation-scoped
	 * backoff. Deterministic size/contract failures remain visible without a hot
	 * loop, while unsampled failures are conservatively retried.
	 *
	 * @param array<string,mixed> $report In-progress full-sync report.
	 */
	private function should_retry_failed_sync( array $report ): bool {
		$aggregate = \is_array( $report['_aggregate_counts'] ?? null ) ? $report['_aggregate_counts'] : array();
		$failed    = \is_array( $report['failed'] ?? null ) ? $report['failed'] : array();
		$count     = max( \count( $failed ), max( 0, (int) ( $aggregate['failed'] ?? 0 ) ) );
		if ( $count < 1 ) {
			return false;
		}

		$deterministic = array(
			'duplicate_static_target',
			'invalid_static_mode',
			'invalid_ownership_shard',
			'publication_too_large',
			'sitemap_provider_page_limit',
			'static_target_plan_too_large',
			'static_target_missing',
		);
		foreach ( $failed as $failure ) {
			if ( ! \is_array( $failure ) || ! \in_array( (string) ( $failure['code'] ?? '' ), $deterministic, true ) ) {
				return true;
			}
		}

		return $count > \count( $failed );
	}

	/** @param array<string,mixed> $report In-progress report, updated with retry status. */
	private function schedule_failed_sync_retry( array &$report ): void {
		$state    = \get_option( self::FAILED_RETRY_OPTION, array() );
		$state    = \is_array( $state ) ? $state : array();
		$attempts = ( $state['generation'] ?? null ) === $this->sync_generation
			? max( 0, (int) ( $state['attempts'] ?? 0 ) )
			: 0;
		++$attempts;
		$delays = array( 60, 300, 900, 3600, 21600 );
		if ( $attempts > \count( $delays ) ) {
			$state = array(
				'generation' => $this->sync_generation,
				'attempts'   => $attempts,
				'status'     => 'exhausted',
				'updated_at' => \gmdate( 'c' ),
			);
			\update_option( self::FAILED_RETRY_OPTION, $state, false );
			$report['retry'] = $state;
			return;
		}

		$delay = $delays[ $attempts - 1 ];
		$state = array(
			'generation' => $this->sync_generation,
			'attempts'   => $attempts,
			'status'     => 'scheduled',
			'retry_at'   => \time() + $delay,
			'updated_at' => \gmdate( 'c' ),
		);
		\update_option( self::FAILED_RETRY_OPTION, $state, false );
		$report['retry'] = $state;
		if ( ! \wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) ) {
			$error = $this->queue_background_sync( $delay );
			if ( null !== $error ) {
				$report['failed']['retry_schedule'] = $error;
			}
		}
	}

	/**
	 * Queue one background sync and persist a failure instead of claiming work is
	 * pending indefinitely when WP-Cron rejects the event.
	 *
	 * @return array<string, mixed>|null Failure diagnostic, or null on success.
	 */
	private function queue_background_sync( int $delay ): ?array {
		$result   = \wp_schedule_single_event(
			\time() + \max( 1, $delay ),
			'cybermaps_bg_sync_static_files',
			array(),
			true
		);
		$is_error = false === $result
			|| ( \function_exists( 'is_wp_error' ) && \is_wp_error( $result ) );

		if ( ! $is_error ) {
			\delete_option( self::SCHEDULE_ERROR_OPTION );
			return null;
		}

		$message = \function_exists( 'is_wp_error' ) && \is_wp_error( $result )
			? (string) $result->get_error_message()
			: __( 'WordPress rejected the background synchronization event.', 'cybermaps' );
		$error   = array(
			'status'  => 'error',
			'code'    => 'schedule_failed',
			'message' => $message,
			'time'    => \time(),
		);
		\update_option( self::SCHEDULE_ERROR_OPTION, $error, false );
		\do_action( 'cybermaps_static_sync_skipped', 'schedule_failed', $error );

		return $error;
	}

	/**
	 * Read the current static configuration generation.
	 */
	private function get_generation(): int {
		return AtomicOptionSequence::current( self::GENERATION_OPTION );
	}

	/**
	 * Read settings between two matching generation observations.
	 *
	 * The pre-lock settings memo is deliberately discarded. If another request
	 * invalidates publication state during either attempt, this request stops
	 * before allocating an epoch or writing continuation state.
	 *
	 * @return array{settings:array<string,mixed>,generation:int,mode:string}|null
	 */
	private function capture_stable_sync_snapshot(): ?array {
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
				return null;
			}
			$before = $this->get_generation();
			if ( $before < 0 ) {
				return null;
			}
			$this->refresh_configuration_option_caches();
			$settings = \Cybermaps\Core\ConfigurationStore::settings();
			$after    = $this->get_generation();
			if (
				$after >= 0
				&& $before === $after
				&& ( null === $this->operation_lock_token || $this->maintain_operation_lock() )
			) {
				return array(
					'settings'   => $settings,
					'generation' => $after,
					'mode'       => self::get_mode( $settings ),
				);
			}
		}

		return null;
	}

	/**
	 * Discard option snapshots that may predate acquisition of the direct-SQL lease.
	 */
	private function refresh_operation_option_caches(): void {
		if ( \function_exists( 'wp_cache_delete' ) ) {
			$options = \array_merge(
				StaticOwnershipStore::all_option_names(),
				array(
					self::SYNC_STATE_OPTION,
					self::SUSPENDED_OPTION,
					self::CLEANUP_AFTER_CANCEL_OPTION,
					StaticWriteIntentStore::OPTION,
					self::WRITE_ERRORS_OPTION,
					self::WRITE_ERROR_DROPPED_OPTION,
				)
			);
			foreach ( \array_unique( $options ) as $option_name ) {
				\wp_cache_delete( $option_name, 'options' );
			}
			\wp_cache_delete( 'notoptions', 'options' );
			\wp_cache_delete( 'alloptions', 'options' );
		}

		$this->ownership_store->clear_local_cache();
		$this->refresh_configuration_option_caches();
	}

	/**
	 * Refresh all configuration roots before accepting a generation snapshot.
	 */
	private function refresh_configuration_option_caches(): void {
		if ( \function_exists( 'wp_cache_delete' ) ) {
			foreach (
				array(
					'cybermaps_settings',
					'cybermaps_discovery_center',
					'cybermaps_robots_manager',
					'cybermaps_identity_data',
				) as $option_name
			) {
				\wp_cache_delete( $option_name, 'options' );
			}
			\wp_cache_delete( 'notoptions', 'options' );
			\wp_cache_delete( 'alloptions', 'options' );
		}

		\Cybermaps\Core\ConfigurationStore::reset_memo();
	}

	/**
	 * Start one request-local ownership transaction after the operation lock is held.
	 */
	private function begin_ownership_batch(): void {
		$this->ownership_ready = $this->ownership_store->migrate_if_needed(
			fn(): bool => $this->maintain_operation_lock()
		);
		if ( ! $this->ownership_ready ) {
			return;
		}
		$repair_incident = AtomicOptionSequence::current( StaticOwnershipStore::REPAIR_OPTION );
		$repair_ack      = AtomicOptionSequence::current( StaticOwnershipStore::REPAIR_ACK_OPTION );
		if ( $repair_incident < 0 || $repair_ack < 0 || $repair_ack > $repair_incident ) {
			$this->ownership_ready = false;
			return;
		}
		if ( $repair_incident > $repair_ack ) {
			if ( ! $this->maintain_operation_lock() || ! $this->ownership_store->commit_revision() ) {
				$this->ownership_ready = false;
				return;
			}
			if ( AtomicOptionSequence::advance_to( StaticOwnershipStore::REPAIR_ACK_OPTION, $repair_incident ) < $repair_incident ) {
				$this->ownership_ready = false;
				return;
			}
		}
		$this->ownership_store->clear_local_cache();
		if ( ! $this->recover_interrupted_write_intent() ) {
			$this->ownership_ready = false;
			return;
		}
		$this->ownership_dirty            = false;
		$this->ownership_repair_needed    = false;
		$this->ownership_revision_pending = false;
		$this->ownership_changes          = 0;
		if ( ! $this->shutdown_registered ) {
			\register_shutdown_function( array( $this, 'flush_ownership_checkpoint' ) );
			\register_shutdown_function( array( $this, 'recover_interrupted_sync' ) );
			$this->shutdown_registered = true;
		}
	}

	/**
	 * Mark a full synchronization as active before generation begins.
	 *
	 * @param array<string,mixed> $report Initial sync report.
	 * @param array<string,mixed>|null $settings Current settings snapshot.
	 */
	private function begin_sync_run( array $report, ?array $settings = null ): bool {
		if ( ! $this->can_begin_sync_run( $report ) ) {
			return false;
		}
		$context = $this->prepare_sync_run_context( $report, $settings );
		if ( null === $context ) {
			return false;
		}
		$this->activate_sync_run_context( $context, $report );
		return $this->persist_initial_sync_state( $context );
	}

	/**
	 * Validate the active lock and generation before starting a sync run.
	 *
	 * @param array<string,mixed> $report Initial report.
	 */
	private function can_begin_sync_run( array $report ): bool {
		return null !== $this->operation_lock_token
			&& $this->maintain_operation_lock()
			&& null !== $this->operation_generation
			&& $this->operation_generation === $this->get_generation()
			&& (int) ( $report['generation'] ?? -1 ) === $this->operation_generation;
	}

	/**
	 * Build and fence the initial synchronization context.
	 *
	 * @param array<string,mixed>      $report Initial report.
	 * @param array<string,mixed>|null $settings Optional settings snapshot.
	 * @return array<string,mixed>|null
	 */
	private function prepare_sync_run_context( array $report, ?array $settings ): ?array {
		$settings        = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
		$previous        = \get_option( self::SYNC_STATE_OPTION, array() );
		$previous        = \is_array( $previous ) ? $previous : array();
		$generation      = (int) ( $report['generation'] ?? $this->get_generation() );
		$mode            = (string) ( $report['mode'] ?? self::get_mode( $settings ) );
		$persisted_epoch = AtomicOptionSequence::current( self::SYNC_EPOCH_OPTION );
		if ( $persisted_epoch < 0 ) {
			return null;
		}
		$is_continuation  = $this->sync_runner->can_resume( $previous, $settings, $generation, $mode, $persisted_epoch );
		$this->sync_epoch = $is_continuation ? (int) $previous['epoch'] : $this->advance_sync_epoch();
		if ( $this->sync_epoch < 1 ) {
			return null;
		}
		if ( ! $is_continuation ) {
			\delete_option( self::SYNC_STATE_OPTION );
		}
		return array(
			'previous'        => $previous,
			'generation'      => $generation,
			'mode'            => $mode,
			'is_continuation' => $is_continuation,
		);
	}

	/**
	 * Apply a prepared synchronization context to runtime state.
	 *
	 * @param array<string,mixed> $context Prepared context.
	 * @param array<string,mixed> $report Initial report.
	 */
	private function activate_sync_run_context( array $context, array $report ): void {
		$this->sync_completed         = array();
		$this->sync_omitted           = array();
		$this->sync_active            = true;
		$this->sync_deferred          = false;
		$this->sync_written_count     = 0;
		$this->sync_started_at        = \microtime( true );
		$this->sync_generation        = (int) $context['generation'];
		$this->sync_mode              = (string) $context['mode'];
		$this->sync_report_started_at = ! empty( $context['is_continuation'] )
			? (string) ( $context['previous']['started_at'] ?? $report['started_at'] ?? \gmdate( 'c' ) )
			: (string) ( $report['started_at'] ?? \gmdate( 'c' ) );
	}

	/**
	 * Persist the initial runner state for a new or resumed run.
	 *
	 * @param array<string,mixed> $context Prepared context.
	 */
	private function persist_initial_sync_state( array $context ): bool {
		if ( ! empty( $context['is_continuation'] ) ) {
			return $this->persist_sync_state(
				array_merge(
					(array) $context['previous'],
					array(
						'status'     => 'running',
						'generation' => $this->sync_generation,
						'mode'       => $this->sync_mode,
						'epoch'      => $this->sync_epoch,
						'started_at' => $this->sync_report_started_at,
						'retry_at'   => 0,
						'writes'     => 0,
						'last_error' => '',
					)
				)
			);
		}
		return $this->persist_sync_state(
			array(
				'schema'     => StaticSyncRunner::STATE_SCHEMA,
				'status'     => 'running',
				'generation' => $this->sync_generation,
				'mode'       => $this->sync_mode,
				'epoch'      => $this->sync_epoch,
				'phase'      => StaticSyncRunner::PHASE_LEGACY_PURGE,
				'cursor'     => array(),
				'context'    => array(),
				'started_at' => $this->sync_report_started_at,
				'retry_at'   => 0,
				'writes'     => 0,
				'last_error' => '',
			)
		);
	}

	/**
	 * Allocate a monotonic publication epoch while the operation lease is held.
	 */
	private function advance_sync_epoch(): int {
		return AtomicOptionSequence::increment( self::SYNC_EPOCH_OPTION );
	}

	/**
	 * Persist and read back an exact continuation checkpoint.
	 *
	 * @param array<string,mixed> $state State payload.
	 */
	private function persist_sync_state( array $state ): bool {
		\update_option( self::SYNC_STATE_OPTION, $state, false );
		return \get_option( self::SYNC_STATE_OPTION, array() ) === $state;
	}

	/**
	 * Delete only the checkpoint fenced to this exact lease-protected run.
	 */
	private function delete_current_runner_state(): bool {
		if (
			null === $this->operation_lock_token
			|| ! $this->maintain_operation_lock()
		) {
			return false;
		}

		$state = \get_option( self::SYNC_STATE_OPTION, array() );
		if ( ! \is_array( $state ) || array() === $state ) {
			return true;
		}
		if (
			( $state['generation'] ?? null ) !== $this->sync_generation
			|| ( $state['mode'] ?? null ) !== $this->sync_mode
			|| ( $state['epoch'] ?? null ) !== $this->sync_epoch
		) {
			return false;
		}

		\delete_option( self::SYNC_STATE_OPTION );
		return false === \get_option( self::SYNC_STATE_OPTION, false );
	}

	/**
	 * Leave recoverable evidence and queue continuation after a fatal shutdown.
	 */
	public function recover_interrupted_sync(): void {
		if ( $this->time_sensitive_active ) {
			try {
				$this->schedule_time_sensitive_retry();
			} finally {
				$this->time_sensitive_active = false;
				$this->release_operation_lock();
			}
			return;
		}
		if ( ! $this->sync_active ) {
			return;
		}
		try {
			$this->checkpoint_interrupted_sync();
		} finally {
			$this->sync_active = false;
			$this->release_operation_lock();
		}
	}

	/**
	 * Persist recoverable continuation state for an interrupted run.
	 */
	private function checkpoint_interrupted_sync(): void {
		if ( null === $this->operation_lock_token || ! $this->maintain_operation_lock() ) {
			$this->queue_background_sync( 60 );
			return;
		}
		if ( $this->sync_generation !== $this->get_generation() ) {
			$this->queue_background_sync( 60 );
			return;
		}
		$state = $this->get_runner_state();
		if ( ! $this->interrupted_sync_state_matches( $state ) ) {
			$this->queue_background_sync( 60 );
			return;
		}
		$this->persist_sync_state( $this->build_interrupted_sync_state( $state, \time() + 60 ) );
		$this->queue_background_sync( 60 );
	}

	/**
	 * Verify persisted runner state belongs to the interrupted run.
	 *
	 * @param array<string,mixed> $state Persisted runner state.
	 */
	private function interrupted_sync_state_matches( array $state ): bool {
		return ( $state['generation'] ?? null ) === $this->sync_generation
			&& ( $state['mode'] ?? null ) === $this->sync_mode
			&& ( $state['epoch'] ?? null ) === $this->sync_epoch;
	}

	/**
	 * Build the persisted interrupted runner state.
	 *
	 * @param array<string,mixed> $state Current runner state.
	 * @param int                 $retry_at Retry timestamp.
	 * @return array<string,mixed>
	 */
	private function build_interrupted_sync_state( array $state, int $retry_at ): array {
		return array_merge(
			$state,
			array(
				'schema'     => StaticSyncRunner::STATE_SCHEMA,
				'status'     => 'interrupted',
				'generation' => $this->sync_generation,
				'mode'       => $this->sync_mode,
				'epoch'      => $this->sync_epoch,
				'phase'      => (string) ( $state['phase'] ?? StaticSyncRunner::PHASE_LEGACY_PURGE ),
				'cursor'     => \is_array( $state['cursor'] ?? null ) ? $state['cursor'] : array(),
				'context'    => \is_array( $state['context'] ?? null ) ? $state['context'] : array(),
				'started_at' => (string) ( $state['started_at'] ?? $this->sync_report_started_at ),
				'retry_at'   => $retry_at,
				'writes'     => $this->sync_written_count,
				'last_error' => __( 'Static synchronization ended before it could publish a final report.', 'cybermaps' ),
			)
		);
	}

	/**
	 * Return the request-local ownership inventory, loading it once when needed.
	 *
	 * @return array<string,mixed>
	 */
	private function get_ownership_hashes(): array {
		return $this->ownership_store->read_flat_hashes();
	}

	/**
	 * Stage an inventory change and checkpoint only at bounded boundaries.
	 *
	 * @param array<string,mixed> $hashes Updated ownership inventory.
	 */
	private function store_ownership_hashes( array $hashes ): bool {
		$stored                        = $this->ownership_store->stage_hashes(
			$hashes,
			$this->ownership_epoch(),
			true
		);
		$this->ownership_dirty         = ! $stored;
		$this->ownership_repair_needed = ! $stored || $this->ownership_repair_needed;
		$this->ownership_changes       = $stored ? 0 : 1;
		return $stored;
	}

	/**
	 * Stage one verified ownership record in the active publication epoch.
	 */
	private function record_ownership_hash( string $filename, string $hash ): bool {
		$epoch = $this->ownership_epoch();
		if ( $epoch < 0 ) {
			return false;
		}
		if (
			$this->ownership_store->get_hash( $filename ) === $hash
			&& $this->ownership_store->get_generation( $filename ) === $epoch
		) {
			return true;
		}

		$stored                           = $this->ownership_store->set_hash( $filename, $hash, $epoch, true, false );
		$this->ownership_dirty            = ! $stored;
		$this->ownership_repair_needed    = ! $stored || $this->ownership_repair_needed;
		$this->ownership_changes          = $stored ? 0 : 1;
		$this->ownership_revision_pending = $stored || $this->ownership_revision_pending;
		if ( ! $stored ) {
			$this->ownership_store->clear_local_cache();
		}
		return $stored;
	}

	/**
	 * Mark an already-owned file as visited in the active publication epoch.
	 */
	private function mark_ownership_seen( string $filename ): bool {
		$hash = $this->ownership_store->get_hash( $filename );
		return null !== $hash && $this->record_ownership_hash( $filename, $hash );
	}

	/**
	 * Stage removal of one ownership record without rebuilding the inventory.
	 */
	private function remove_ownership_hash( string $filename ): bool {
		if ( null === $this->ownership_store->get_hash( $filename ) ) {
			return true;
		}

		$removed                          = $this->ownership_store->delete_hash( $filename, true, false );
		$this->ownership_dirty            = ! $removed;
		$this->ownership_repair_needed    = ! $removed || $this->ownership_repair_needed;
		$this->ownership_changes          = $removed ? 0 : 1;
		$this->ownership_revision_pending = $removed || $this->ownership_revision_pending;
		if ( ! $removed ) {
			$this->ownership_store->clear_local_cache();
		}
		return $removed;
	}

	/**
	 * Resolve the epoch used for per-file visitation records.
	 */
	private function ownership_epoch(): int {
		if ( $this->sync_active && $this->sync_epoch > 0 ) {
			return $this->sync_epoch;
		}

		return AtomicOptionSequence::current( self::SYNC_EPOCH_OPTION );
	}

	/**
	 * Persist the current ownership checkpoint while this request still owns the lock.
	 *
	 * Public only so PHP's shutdown handler can invoke it after an interrupted sync.
	 */
	public function flush_ownership_checkpoint(): void {
		if ( ! $this->ownership_dirty ) {
			return;
		}
		$standalone_write = null === $this->operation_lock_token;
		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			return;
		}

		if ( $this->ownership_store->flush( $this->ownership_epoch(), $standalone_write ) ) {
			$this->ownership_dirty   = false;
			$this->ownership_changes = 0;
		}
	}

	/**
	 * Clear the exact durable intent after the filesystem and ownership state
	 * have both reached a verified terminal state.
	 *
	 * @param array<string,mixed> $intent Persisted intent record.
	 */
	private function clear_recovered_write_intent( array $intent ): bool {
		return null !== $this->operation_lock_token
			&& $this->maintain_operation_lock()
			&& $this->write_intent_store->delete_exact( $intent );
	}

	/**
	 * Leave a durable intent for recovery when publication is fenced before move.
	 */
	private function stop_prepared_write_before_move(
		string $tmp_file,
		string $filename,
		string $path,
		string $code,
		string $message
	): bool {
		\wp_delete_file( $tmp_file );
		$this->sync_deferred = true;
		$this->schedule_retry();
		return $this->complete_write_attempt(
			false,
			$filename,
			'skipped',
			$code,
			$message,
			$path,
			array(
				'move_attempted' => false,
				'intent_pending' => true,
			)
		);
	}

	/**
	 * Reconcile a request that ended between the atomic file move and its
	 * ownership checkpoint. The successor never overwrites an unrecognized
	 * body: exact new content is adopted, exact old content is restored to the
	 * prior checkpoint, and every other state is reduced to the normal
	 * third-party conflict contract.
	 */
	private function recover_interrupted_write_intent(): bool {
		$intent = $this->write_intent_store->read();
		if ( null === $intent ) {
			return true;
		}
		if ( false === $intent || ! $this->maintain_operation_lock() ) {
			$this->record_write_intent_recovery_error(
				'write_intent_invalid',
				__( 'A pending static write intent is malformed or could not be read safely.', 'cybermaps' )
			);
			return false;
		}
		if ( 'delete' === StaticWriteIntentStore::operation( $intent ) ) {
			$result = $this->reconcile_delete_intent( $intent, true );
			return empty( $result['pending'] );
		}
		$state = $this->prepare_write_intent_recovery( $intent );
		if ( null === $state ) {
			return false;
		}
		$checkpointed = $this->reconcile_write_intent_ownership( $state );
		if ( ! $checkpointed || ! $this->maintain_operation_lock() ) {
			$this->ownership_store->clear_local_cache();
			$this->record_write_intent_recovery_error(
				'write_intent_ownership_failed',
				__( 'A pending static write could not reconcile its ownership checkpoint safely.', 'cybermaps' )
			);
			return false;
		}
		if ( ! $this->write_intent_store->delete_exact( $intent, true ) ) {
			$this->record_write_intent_recovery_error(
				'write_intent_cleanup_failed',
				__( 'A reconciled static write intent could not be cleared safely.', 'cybermaps' )
			);
			return false;
		}

		$this->ownership_store->clear_local_cache();
		return true;
	}

	/**
	 * Resolve and read the target referenced by a write intent.
	 *
	 * @param array<string,mixed> $intent Valid write intent.
	 * @return array<string,mixed>|null
	 */
	private function prepare_write_intent_recovery( array $intent ): ?array {
		$filename = (string) $intent['filename'];
		if ( ! $this->is_safe_generated_path( $filename ) || ! $this->load_filesystem_api() || ! \WP_Filesystem() ) {
			$this->record_write_intent_recovery_error(
				'write_intent_path_unavailable',
				__( 'A pending static write intent could not resolve its publication path safely.', 'cybermaps' )
			);
			return null;
		}
		$resolution = $this->resolve_file_path( $filename );
		if ( empty( $resolution['path'] ) ) {
			$this->record_write_intent_recovery_error(
				'write_intent_path_unavailable',
				__( 'A pending static write intent could not resolve its publication path safely.', 'cybermaps' )
			);
			return null;
		}
		global $wp_filesystem;
		$path    = (string) $resolution['path'];
		$exists  = $wp_filesystem->exists( $path );
		$current = '';
		if ( $exists ) {
			$issue = $this->get_ownership_verification_issue( $filename, $path, $wp_filesystem );
			if ( null !== $issue ) {
				$this->record_write_intent_recovery_error(
					'write_intent_target_unverifiable',
					__( 'A pending static write target is too large or unreadable to reconcile safely.', 'cybermaps' )
				);
				return null;
			}
			$contents = $wp_filesystem->get_contents( $path );
			if ( false === $contents ) {
				$this->record_write_intent_recovery_error(
					'write_intent_target_unreadable',
					__( 'A pending static write target could not be read safely.', 'cybermaps' )
				);
				return null;
			}
			$current = \md5( $contents );
		}
		return array(
			'filename'       => $filename,
			'exists'         => $exists,
			'current'        => $current,
			'new_hash'       => (string) $intent['new_hash'],
			'old_exists'     => (bool) $intent['old_exists'],
			'old_hash'       => (string) $intent['old_hash'],
			'old_generation' => (int) $intent['old_generation'],
			'epoch'          => (int) $intent['epoch'],
		);
	}

	/**
	 * Reconcile ownership according to the observed write-intent target.
	 *
	 * @param array<string,mixed> $state Prepared recovery state.
	 */
	private function reconcile_write_intent_ownership( array $state ): bool {
		$filename = (string) $state['filename'];
		if ( ! empty( $state['exists'] ) && \hash_equals( (string) $state['new_hash'], (string) $state['current'] ) ) {
			return $this->ownership_store->set_hash( $filename, (string) $state['new_hash'], (int) $state['epoch'], true );
		}
		if ( ! empty( $state['old_exists'] ) && ! empty( $state['exists'] ) && \hash_equals( (string) $state['old_hash'], (string) $state['current'] ) ) {
			return $this->ownership_store->set_hash(
				$filename,
				(string) $state['old_hash'],
				max( 0, (int) $state['old_generation'] ),
				true
			);
		}
		if ( empty( $state['old_exists'] ) && empty( $state['exists'] ) ) {
			return $this->ownership_store->delete_hash( $filename, true );
		}

		// A third party changed or removed the body. Preserve that body and
		// restore only the pre-write ownership evidence before clearing the intent.
		$checkpointed = ! empty( $state['old_exists'] )
			? $this->ownership_store->set_hash(
				$filename,
				(string) $state['old_hash'],
				max( 0, (int) $state['old_generation'] ),
				true
			)
			: $this->ownership_store->delete_hash( $filename, true );
		if ( $checkpointed ) {
			$this->record_write_intent_recovery_error(
				'write_intent_target_conflict',
				__( 'A pending static write target changed independently and was preserved for manual review.', 'cybermaps' )
			);
		}
		return $checkpointed;
	}

	/**
	 * Create and execute one durable delete tombstone while the lease is held.
	 *
	 * @return array{success:bool,status:string,code:string,deleted:bool,pending:bool}
	 */
	private function delete_owned_publication(
		string $filename,
		string $recorded_hash,
		int $recorded_generation
	): array {
		if (
			null === $this->operation_lock_token
			|| null === $this->operation_generation
			|| ! $this->maintain_operation_lock()
		) {
			return $this->delete_intent_result( false, 'skipped', 'operation_lock_lost', false, true );
		}

		$epoch  = $this->ownership_epoch();
		$intent = $this->write_intent_store->create_delete(
			$filename,
			$recorded_hash,
			$recorded_generation,
			$this->operation_generation,
			max( 0, $epoch ),
			$this->operation_lock_token
		);
		if ( null === $intent ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_checkpoint_failed',
				__( 'The owned publication was retained because its durable deletion tombstone could not be checkpointed.', 'cybermaps' )
			);
			return $this->delete_intent_result( false, 'error', 'delete_intent_checkpoint_failed', false, true );
		}
		if (
			! $this->maintain_operation_lock()
			|| ! StaticWriteIntentStore::matches_lease_token( $intent, $this->operation_lock_token )
		) {
			$this->record_write_intent_recovery_error(
				'delete_intent_lease_lost',
				__( 'The deletion tombstone was preserved because the operation lease was lost before filesystem deletion.', 'cybermaps' )
			);
			return $this->delete_intent_result( false, 'skipped', 'operation_lock_lost', false, true );
		}

		return $this->reconcile_delete_intent( $intent, false );
	}

	/**
	 * Reconcile one exact typed deletion tombstone.
	 *
	 * Missing means deletion completed; the old hash means deletion never ran;
	 * a body matching a newer ownership checkpoint is a successor publication;
	 * every other body is a third-party conflict and is preserved.
	 *
	 * @param array<string,mixed> $intent Valid deletion intent.
	 * @return array{success:bool,status:string,code:string,deleted:bool,pending:bool}
	 */
	private function reconcile_delete_intent( array $intent, bool $recover_prior_lease ): array {
		$state = $this->prepare_delete_intent_target( $intent, $recover_prior_lease );
		if ( isset( $state['result'] ) ) {
			return $state['result'];
		}
		if ( empty( $state['exists'] ) ) {
			return $this->finalize_completed_delete_intent( $intent, $recover_prior_lease, false );
		}
		if ( ! \hash_equals( (string) $state['old_hash'], (string) $state['current_hash'] ) ) {
			return $this->finalize_changed_delete_target(
				$intent,
				(string) $state['current_hash'],
				$recover_prior_lease
			);
		}
		$state = $this->revalidate_delete_intent_target( $intent, $recover_prior_lease, $state );
		if ( isset( $state['result'] ) ) {
			return $state['result'];
		}
		return $this->delete_revalidated_intent_target( $intent, $recover_prior_lease, $state );
	}

	/**
	 * Prepare and read the target protected by a deletion intent.
	 *
	 * @param array<string,mixed> $intent Deletion intent.
	 * @param bool                $recover_prior_lease Whether a prior lease may be recovered.
	 * @return array<string,mixed>
	 */
	private function prepare_delete_intent_target( array $intent, bool $recover_prior_lease ): array {
		if ( 'delete' !== StaticWriteIntentStore::operation( $intent ) || ! $this->load_filesystem_api() || ! \WP_Filesystem() ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_filesystem_unavailable',
				__( 'The pending deletion tombstone could not initialize the WordPress filesystem API.', 'cybermaps' )
			);
			return array( 'result' => $this->delete_intent_result( false, 'error', 'delete_intent_filesystem_unavailable', false, true ) );
		}
		if ( ! $this->maintain_operation_lock() || ! $this->write_intent_store->is_current_exact( $intent, $recover_prior_lease ) ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_fence_lost',
				__( 'The pending deletion tombstone was preserved because its database fence or exact journal row could not be revalidated.', 'cybermaps' )
			);
			return array( 'result' => $this->delete_intent_result( false, 'skipped', 'operation_lock_lost', false, true ) );
		}
		$filename   = (string) $intent['filename'];
		$resolution = $this->resolve_file_path( $filename );
		$path       = (string) ( $resolution['path'] ?? '' );
		if ( '' === $path ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_path_unavailable',
				__( 'The pending deletion tombstone could not resolve its publication path safely.', 'cybermaps' )
			);
			return array( 'result' => $this->delete_intent_result( false, 'error', 'delete_intent_path_unavailable', false, true ) );
		}
		return $this->read_delete_intent_target( $intent, $filename, $path );
	}

	/**
	 * Read a deletion target after its path and fence are valid.
	 *
	 * @param array<string,mixed> $intent Deletion intent.
	 * @param string              $filename Relative filename.
	 * @param string              $path Physical path.
	 * @return array<string,mixed>
	 */
	private function read_delete_intent_target( array $intent, string $filename, string $path ): array {
		global $wp_filesystem;
		$state = array(
			'filename' => $filename,
			'path'     => $path,
			'old_hash' => (string) $intent['old_hash'],
			'exists'   => $wp_filesystem->exists( $path ),
		);
		if ( ! $state['exists'] ) {
			return $state;
		}
		$issue = $this->get_ownership_verification_issue( $filename, $path, $wp_filesystem );
		if ( null !== $issue ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_target_unverifiable',
				__( 'The pending deletion target could not be verified safely and was preserved.', 'cybermaps' )
			);
			$state['result'] = $this->delete_intent_result( false, 'conflict', 'delete_intent_target_unverifiable', false, true );
			return $state;
		}
		$current_contents = $wp_filesystem->get_contents( $path );
		if ( false === $current_contents ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_target_unreadable',
				__( 'The pending deletion target could not be read and was preserved.', 'cybermaps' )
			);
			$state['result'] = $this->delete_intent_result( false, 'error', 'delete_intent_target_unreadable', false, true );
			return $state;
		}
		$state['current_hash'] = \md5( $current_contents );
		return $state;
	}

	/**
	 * Revalidate a matching deletion target immediately before deletion.
	 *
	 * @param array<string,mixed> $intent Deletion intent.
	 * @param bool                $recover_prior_lease Whether a prior lease may be recovered.
	 * @param array<string,mixed> $state Prepared target state.
	 * @return array<string,mixed>
	 */
	private function revalidate_delete_intent_target( array $intent, bool $recover_prior_lease, array $state ): array {
		if ( ! $this->maintain_operation_lock() || ! $this->write_intent_store->is_current_exact( $intent, $recover_prior_lease ) || ! $this->maintain_operation_lock() ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_fence_lost',
				__( 'The deletion tombstone was preserved because its fence was lost immediately before filesystem deletion.', 'cybermaps' )
			);
			$state['result'] = $this->delete_intent_result( false, 'skipped', 'operation_lock_lost', false, true );
			return $state;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem->exists( $state['path'] ) ) {
			$state['result'] = $this->finalize_completed_delete_intent( $intent, $recover_prior_lease, false );
			return $state;
		}
		$current_contents = $wp_filesystem->get_contents( $state['path'] );
		if ( false === $current_contents ) {
			$state['result'] = $this->delete_intent_result( false, 'error', 'delete_intent_target_unreadable', false, true );
			return $state;
		}
		$current_hash = \md5( $current_contents );
		if ( ! \hash_equals( (string) $state['old_hash'], $current_hash ) ) {
			$state['result'] = $this->finalize_changed_delete_target( $intent, $current_hash, $recover_prior_lease );
		}
		return $state;
	}

	/**
	 * Delete a target after its final portable revalidation boundary.
	 *
	 * @param array<string,mixed> $intent Deletion intent.
	 * @param bool                $recover_prior_lease Whether a prior lease may be recovered.
	 * @param array<string,mixed> $state Revalidated target state.
	 * @return array<string,mixed>
	 */
	private function delete_revalidated_intent_target( array $intent, bool $recover_prior_lease, array $state ): array {
		if ( ! $this->maintain_operation_lock() || ! $this->write_intent_store->is_current_exact( $intent, $recover_prior_lease ) || ! $this->maintain_operation_lock() ) {
			return $this->delete_intent_result( false, 'skipped', 'operation_lock_lost', false, true );
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem->delete( $state['path'], false, 'f' ) || $wp_filesystem->exists( $state['path'] ) ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_delete_failed',
				__( 'The owned publication could not be deleted; its tombstone was preserved for recovery.', 'cybermaps' )
			);
			return $this->delete_intent_result( false, 'error', 'delete_failed', false, true );
		}
		return $this->finalize_completed_delete_intent( $intent, $recover_prior_lease, true );
	}

	/**
	 * Finalize a missing/deleted target only after its ownership checkpoint clears.
	 *
	 * @param array<string,mixed> $intent Exact deletion intent.
	 * @return array{success:bool,status:string,code:string,deleted:bool,pending:bool}
	 */
	private function finalize_completed_delete_intent(
		array $intent,
		bool $recover_prior_lease,
		bool $deleted
	): array {
		$filename = (string) $intent['filename'];
		if (
			! $this->maintain_operation_lock()
			|| ! $this->remove_ownership_hash( $filename )
			|| ! $this->maintain_operation_lock()
			|| ! $this->write_intent_store->delete_exact( $intent, $recover_prior_lease )
		) {
			$this->record_write_intent_recovery_error(
				'delete_intent_checkpoint_failed',
				__( 'The publication body is absent, but its ownership checkpoint or deletion tombstone could not be finalized.', 'cybermaps' )
			);
			return $this->delete_intent_result( false, 'error', 'ownership_checkpoint_failed', $deleted, true );
		}

		$this->ownership_store->clear_local_cache();
		return $this->delete_intent_result( true, 'complete', $deleted ? 'deleted' : 'already_missing', $deleted, false );
	}

	/**
	 * Preserve and classify a body that no longer matches the deletion target.
	 *
	 * @param array<string,mixed> $intent Exact deletion intent.
	 * @return array{success:bool,status:string,code:string,deleted:bool,pending:bool}
	 */
	private function finalize_changed_delete_target(
		array $intent,
		string $current_hash,
		bool $recover_prior_lease
	): array {
		$filename      = (string) $intent['filename'];
		$old_hash      = (string) $intent['old_hash'];
		$recorded_hash = $this->ownership_store->get_hash( $filename );
		$successor     = \is_string( $recorded_hash )
			&& ! \hash_equals( $old_hash, $recorded_hash )
			&& \hash_equals( $recorded_hash, $current_hash );

		if ( ! $successor && null === $recorded_hash ) {
			if (
				! $this->maintain_operation_lock()
				|| ! $this->ownership_store->set_hash(
					$filename,
					$old_hash,
					max( 0, (int) $intent['old_generation'] ),
					true
				)
			) {
				return $this->delete_intent_result( false, 'error', 'ownership_checkpoint_failed', false, true );
			}
		}

		if (
			! $this->maintain_operation_lock()
			|| ! $this->write_intent_store->delete_exact( $intent, $recover_prior_lease )
		) {
			return $this->delete_intent_result( false, 'error', 'delete_intent_cleanup_failed', false, true );
		}

		$this->ownership_store->clear_local_cache();
		if ( $successor ) {
			$this->record_write_intent_recovery_error(
				'delete_intent_superseded',
				__( 'A verified successor publication superseded the pending deletion and was preserved.', 'cybermaps' ),
				false
			);
			return $this->delete_intent_result( true, 'complete', 'successor_preserved', false, false );
		}

		$this->record_write_intent_recovery_error(
			'delete_intent_target_conflict',
			__( 'A third-party body replaced the pending deletion target and was preserved for administrator review.', 'cybermaps' ),
			false
		);
		return $this->delete_intent_result( true, 'conflict', 'content_changed', false, false );
	}

	/** @return array{success:bool,status:string,code:string,deleted:bool,pending:bool} */
	private function delete_intent_result(
		bool $success,
		string $status,
		string $code,
		bool $deleted,
		bool $pending
	): array {
		if ( $pending ) {
			$this->sync_deferred = true;
			$this->schedule_retry();
		}
		return array(
			'success' => $success,
			'status'  => $status,
			'code'    => $code,
			'deleted' => $deleted,
			'pending' => $pending,
		);
	}

	private function record_write_intent_recovery_error(
		string $code,
		string $message,
		bool $retry = true
	): void {
		if ( $retry ) {
			$this->sync_deferred = true;
			$this->schedule_retry();
		}
		\update_option(
			self::SCHEDULE_ERROR_OPTION,
			array(
				'status'  => 'error',
				'code'    => $code,
				'message' => $message,
				'time'    => \time(),
			),
			false
		);
	}

	/** @return array<string,mixed> */
	private function intent_resolution_result( bool $success, string $code, string $message ): array {
		return \array_merge(
			$this->write_intent_store->describe(),
			array(
				'success' => $success,
				'code'    => $code,
				'message' => $message,
			)
		);
	}

	/**
	 * Remove internal coordination state after an uninstall purge completes.
	 */
	private function cleanup_runtime_coordination_options(): void {
		\delete_option( self::GENERATION_OPTION );
		\delete_option( self::SYNC_EPOCH_OPTION );
		\delete_option( self::SUSPENDED_OPTION );
		\delete_option( self::CLEANUP_AFTER_CANCEL_OPTION );
	}

	/**
	 * Explain why a file must not be written under the current configuration.
	 *
	 * @return string|null Machine-readable reason, or null when writing is allowed.
	 */
	private function get_write_block_reason( string $filename ): ?string {
		if ( \function_exists( 'is_multisite' ) && \is_multisite() ) {
			return 'multisite_dynamic_only';
		}

		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			return 'operation_lock_lost';
		}

		if ( '1' === (string) \get_option( self::SUSPENDED_OPTION, '0' ) ) {
			return 'generation_suspended';
		}

		if (
			null !== $this->operation_generation
			&& $this->operation_generation !== $this->get_generation()
		) {
			return 'configuration_changed';
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$mode     = self::get_mode( $settings );
		if ( 'off' === $mode ) {
			return 'engine_disabled';
		}

		if ( $this->is_discovery_generated_path( $filename ) && empty( $settings['enable_discovery_hub'] ) ) {
			return 'discovery_hub_disabled';
		}

		if ( 'well_known' === $mode && ! $this->is_well_known_mode_static_path( $filename, $settings ) ) {
			return 'outside_static_mode';
		}

		return null;
	}

	/**
	 * Return an administrator-facing explanation for a skipped write.
	 */
	private function get_write_block_message( string $reason ): string {
		$messages = array(
			'multisite_dynamic_only' => __( 'Static root files are disabled on WordPress multisite; dynamic delivery remains active.', 'cybermaps' ),
			'generation_suspended'   => __( 'Static generation is suspended while the plugin is inactive or uninstalling.', 'cybermaps' ),
			'configuration_changed'  => __( 'Settings changed during generation, so the obsolete write was cancelled.', 'cybermaps' ),
			'engine_disabled'        => __( 'The Static File Engine is disabled.', 'cybermaps' ),
			'discovery_hub_disabled' => __( 'The AI Publication Hub is disabled.', 'cybermaps' ),
			'outside_static_mode'    => __( 'This file is outside the active Static File Engine mode.', 'cybermaps' ),
			'operation_lock_lost'    => __( 'The generated-file operation no longer owns its coordination lock and was stopped before another write.', 'cybermaps' ),
		);

		return $messages[ $reason ] ?? __( 'The generated-file write was cancelled.', 'cybermaps' );
	}

	/**
	 * Restore the pre-write body when the corresponding ownership mutation fails.
	 */
	private function rollback_uncheckpointed_write(
		string $filename,
		string $path,
		string $new_hash,
		bool $target_existed,
		?string $previous_contents
	): bool {
		global $wp_filesystem;
		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			return false;
		}

		$current_contents = $wp_filesystem->get_contents( $path );
		if ( false === $current_contents || ! \hash_equals( $new_hash, \md5( $current_contents ) ) ) {
			return false;
		}
		if ( ! $target_existed ) {
			$intent = $this->write_intent_store->read();
			if (
				! \is_array( $intent )
				|| 'write' !== StaticWriteIntentStore::operation( $intent )
				|| $filename !== (string) $intent['filename']
				|| ! \hash_equals( $new_hash, (string) $intent['new_hash'] )
				|| null === $this->operation_lock_token
				|| ! StaticWriteIntentStore::matches_lease_token( $intent, $this->operation_lock_token )
				|| ! $this->maintain_operation_lock()
				|| ! $this->write_intent_store->is_current_exact( $intent )
				|| ! $this->maintain_operation_lock()
			) {
				return false;
			}
			return (bool) $wp_filesystem->delete( $path, false, 'f' );
		}
		if ( null === $previous_contents ) {
			return false;
		}

		$tmp_file = \wp_tempnam( $filename, \rtrim( \dirname( $path ), '/\\' ) . '/' );
		if ( ! $tmp_file ) {
			return false;
		}
		if ( ! $wp_filesystem->put_contents( $tmp_file, $previous_contents, 0644 ) ) {
			\wp_delete_file( $tmp_file );
			return false;
		}
		if ( null !== $this->operation_lock_token && ! $this->maintain_operation_lock() ) {
			\wp_delete_file( $tmp_file );
			return false;
		}

		$restored = $wp_filesystem->move( $tmp_file, $path, true );
		if ( ! $restored ) {
			\wp_delete_file( $tmp_file );
			return false;
		}

		$restored_contents = $wp_filesystem->get_contents( $path );
		return false !== $restored_contents
			&& \hash_equals( \md5( $previous_contents ), \md5( $restored_contents ) );
	}

	/**
	 * Restore both the prior body and its ownership record while the lease is held.
	 */
	private function rollback_checkpointed_write(
		string $filename,
		string $path,
		string $new_hash,
		bool $target_existed,
		?string $previous_contents,
		?string $previous_hash,
		int $previous_generation
	): bool {
		if ( ! $this->maintain_operation_lock() ) {
			return false;
		}
		if (
			! $this->rollback_uncheckpointed_write(
				$filename,
				$path,
				$new_hash,
				$target_existed,
				$previous_contents
			)
			) {
				return false;
		}
		if ( ! $this->maintain_operation_lock() ) {
			return false;
		}

		$restored                         = $target_existed
			&& \is_string( $previous_hash )
			&& 1 === \preg_match( '/^[a-f0-9]{32}$/i', $previous_hash )
			? $this->ownership_store->set_hash(
				$filename,
				$previous_hash,
				max( 0, $previous_generation ),
				true,
				false
			)
			: $this->ownership_store->delete_hash( $filename, true, false );
		$this->ownership_dirty            = ! $restored;
		$this->ownership_revision_pending = $restored || $this->ownership_revision_pending;
		return $restored;
	}

	/**
	 * Resolve a generated filename to the physical root that serves its public URL.
	 *
	 * Well-known publications must live at the origin document root, not below a
	 * WordPress subdirectory. A remote/differently rooted headless frontend cannot
	 * be published by guessing a local backend path; hosts/extensions must supply
	 * an explicit physical root with cybermaps_static_publication_root.
	 *
	 * @return array{path:string,reason:string,message:string}
	 */
	private function resolve_file_path( string $filename ): array {
		if ( ! $this->is_safe_generated_path( $filename ) ) {
			return array(
				'path'    => '',
				'reason'  => 'invalid_path',
				'message' => __( 'The generated-file path is invalid.', 'cybermaps' ),
			);
		}

		if ( ! \function_exists( 'get_home_path' ) ) {
			$this->load_filesystem_api();
		}

		$settings  = \Cybermaps\Core\ConfigurationStore::settings();
		$home_path = $this->get_static_home_path();

		/**
		 * Supply the physical directory that serves the configured public base.
		 *
		 * This is required when a headless frontend is hosted separately and may
		 * also be used by hosts with nonstandard URL-to-filesystem mappings.
		 *
		 * @param string               $root     Empty default publication root.
		 * @param string               $filename Relative generated filename.
		 * @param array<string, mixed> $settings Current settings.
		 */
		$custom_root = \apply_filters(
			'cybermaps_static_publication_root',
			'',
			$filename,
			$settings
		);
		if ( \is_string( $custom_root ) && '' !== \trim( $custom_root ) ) {
			return $this->resolve_publication_path( $custom_root, $filename, true );
		}

		$frontend_base = $this->get_static_frontend_base( $settings );
		$backend_home  = \untrailingslashit( (string) \home_url() );
		if ( '' !== $frontend_base && $frontend_base !== $backend_home ) {
			return array(
				'path'    => '',
				'reason'  => 'headless_publication_root_required',
				'message' => __( 'A remote or differently rooted frontend requires an explicit physical publication root.', 'cybermaps' ),
			);
		}

		$publication_root = $this->resolve_origin_publication_root( $home_path, $filename );
		if ( \is_array( $publication_root ) ) {
			return $publication_root;
		}

		return $this->resolve_publication_path( $publication_root, $filename, false );
	}

	/**
	 * Resolve the WordPress home path used by static publication.
	 */
	private function get_static_home_path(): string {
		return \function_exists( 'get_home_path' ) ? (string) \get_home_path() : (string) ABSPATH;
	}

	/**
	 * Read the configured frontend base URL when it is scalar.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function get_static_frontend_base( array $settings ): string {
		if ( ! isset( $settings['frontend_base_url'] ) || ! \is_scalar( $settings['frontend_base_url'] ) ) {
			return '';
		}
		return \untrailingslashit( (string) $settings['frontend_base_url'] );
	}

	/**
	 * Resolve the origin document root for well-known publications.
	 *
	 * @param string $home_path WordPress home path.
	 * @param string $filename Relative generated filename.
	 * @return string|array<string,string> Publication root or resolution error.
	 */
	private function resolve_origin_publication_root( string $home_path, string $filename ) {
		$publication_root = \rtrim( $home_path, '/\\' );
		if ( 0 !== \strpos( $filename, '.well-known/' ) ) {
			return $publication_root;
		}

		$home_url_path = \wp_parse_url( \home_url(), PHP_URL_PATH );
		$home_url_path = \is_string( $home_url_path ) ? \trim( $home_url_path, '/' ) : '';
		if ( '' === $home_url_path ) {
			return $publication_root;
		}

		$normalized_home = \str_replace( '\\', '/', $publication_root );
		$suffix          = '/' . $home_url_path;
		if ( ! \str_ends_with( $normalized_home, $suffix ) ) {
			return array(
				'path'    => '',
				'reason'  => 'origin_publication_root_unresolved',
				'message' => __( 'The origin document root could not be derived safely from the WordPress home path.', 'cybermaps' ),
			);
		}

		$publication_root = (string) \substr( $normalized_home, 0, -\strlen( $suffix ) );
		return '' === $publication_root ? '/' : $publication_root;
	}

	/**
	 * Canonicalize a publication root and prove the destination cannot escape it.
	 *
	 * @return array{path:string,reason:string,message:string}
	 */
	private function resolve_publication_path( string $root, string $filename, bool $require_writable ): array {
		$root = $this->normalize_publication_root( $root );
		if ( ! $this->is_absolute_filesystem_path( $root ) ) {
			return array(
				'path'    => '',
				'reason'  => 'invalid_publication_root',
				'message' => __( 'The physical publication root is not an absolute path.', 'cybermaps' ),
			);
		}

		$canonical_root = \realpath( $root );
		if ( false === $canonical_root || ! \is_dir( $canonical_root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Canonical containment must be proven before WP_Filesystem receives a path.
			return array(
				'path'    => '',
				'reason'  => 'publication_root_missing',
				'message' => __( 'The physical publication root does not exist or is not a directory.', 'cybermaps' ),
			);
		}

		$canonical_root = \str_replace( '\\', '/', $canonical_root );
		if ( '/' !== $canonical_root ) {
			$canonical_root = \rtrim( $canonical_root, '/' );
		}
		$root_prefix = '/' === $canonical_root ? '/' : $canonical_root . '/';
		$candidate   = $root_prefix . $filename;
		if ( ! \str_starts_with( $candidate, $root_prefix ) ) {
			return array(
				'path'    => '',
				'reason'  => 'publication_path_escape',
				'message' => __( 'The generated path escapes the configured publication root.', 'cybermaps' ),
			);
		}

		$resolved_parent = $this->resolve_existing_publication_parent( $candidate, $canonical_root );
		if ( $resolved_parent !== $canonical_root && ! \str_starts_with( $resolved_parent, $root_prefix ) ) {
			return array(
				'path'    => '',
				'reason'  => 'publication_path_escape',
				'message' => __( 'A destination directory resolves outside the configured publication root.', 'cybermaps' ),
			);
		}

		$writable_issue = $this->get_publication_root_writable_issue( $canonical_root, $require_writable );
		if ( null !== $writable_issue ) {
			return $writable_issue;
		}

		return array(
			'path'    => $candidate,
			'reason'  => '',
			'message' => '',
		);
	}

	/**
	 * Normalize a configured publication root without altering filesystem roots.
	 *
	 * @param string $root Configured publication root.
	 */
	private function normalize_publication_root( string $root ): string {
		$root = \trim( $root );
		if ( '/' === $root || 1 === \preg_match( '/^[A-Za-z]:[\\\\\/]$/', $root ) ) {
			return $root;
		}
		return \rtrim( $root, '/\\' );
	}

	/**
	 * Resolve the nearest existing parent for containment verification.
	 *
	 * @param string $candidate Candidate publication path.
	 * @param string $canonical_root Canonical publication root.
	 */
	private function resolve_existing_publication_parent( string $candidate, string $canonical_root ): string {
		$existing_parent = \dirname( $candidate );
		while ( false === \realpath( $existing_parent ) && $existing_parent !== $canonical_root ) {
			$parent = \dirname( $existing_parent );
			if ( $parent === $existing_parent ) {
				break;
			}
			$existing_parent = $parent;
		}
		$resolved_parent = \realpath( $existing_parent );
		$resolved_parent = false === $resolved_parent ? '' : \str_replace( '\\', '/', $resolved_parent );
		return '/' === $resolved_parent ? $resolved_parent : \rtrim( $resolved_parent, '/' );
	}

	/**
	 * Return a configured-root writability error when publication requires it.
	 *
	 * @param string $canonical_root Canonical publication root.
	 * @param bool   $require_writable Whether the root must be writable now.
	 * @return array<string,string>|null
	 */
	private function get_publication_root_writable_issue( string $canonical_root, bool $require_writable ): ?array {
		if ( ! $require_writable ) {
			return null;
		}
		if ( ! $this->load_filesystem_api() || ! \WP_Filesystem() ) {
			return array(
				'path'    => '',
				'reason'  => 'filesystem_init_failed',
				'message' => __( 'WordPress could not initialize the filesystem for the configured publication root.', 'cybermaps' ),
			);
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem->is_dir( $canonical_root ) || ! $wp_filesystem->is_writable( $canonical_root ) ) {
			return array(
				'path'    => '',
				'reason'  => 'publication_root_not_writable',
				'message' => __( 'The configured physical publication root is not writable.', 'cybermaps' ),
			);
		}
		return null;
	}

	/**
	 * Check Unix and Windows absolute path forms.
	 */
	private function is_absolute_filesystem_path( string $path ): bool {
		return '' !== $path
			&& ( '/' === $path[0] || 1 === \preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path ) );
	}

	/**
	 * Load the WordPress filesystem helpers used by both path resolution and I/O.
	 */
	private function load_filesystem_api(): bool {
		if ( ! \function_exists( 'get_home_path' ) || ! \function_exists( 'WP_Filesystem' ) ) {
			$filesystem_api = ABSPATH . 'wp-admin/includes/file.php';
			if ( ! \is_file( $filesystem_api ) ) {
				return false;
			}

			require_once $filesystem_api;
		}

		return \function_exists( 'get_home_path' ) && \function_exists( 'WP_Filesystem' );
	}

	/**
	 * Store the latest write result and maintain persistent per-file failures.
	 *
	 * @param bool                 $success Whether the write succeeded.
	 * @param string               $filename Relative generated-file path.
	 * @param string               $status Result class: success, conflict, error, or skipped.
	 * @param string               $code Machine-readable result code.
	 * @param string               $message Human-readable result summary.
	 * @param string               $path Absolute destination path, when known.
	 * @param array<string, mixed> $context Additional diagnostic fields.
	 * @param bool                 $persist_error Whether a failed result should persist for administrators.
	 * @return bool The supplied success value for write_file() compatibility.
	 */
	private function complete_write_attempt(
		bool $success,
		string $filename,
		string $status,
		string $code,
		string $message,
		string $path,
		array $context = array(),
		bool $persist_error = false
	): bool {
		$result                  = $this->build_write_result( $filename, $status, $code, $message, $path, $context );
		$this->last_write_result = $result;

		if ( ! $success && ! $persist_error ) {
			return false;
		}
		// Persistent diagnostics share the static-operation lease. A request that
		// failed to acquire it still receives last_write_result, but must not race
		// the active owner's bounded option update.
		if ( null === $this->operation_lock_token || ! $this->maintain_operation_lock() ) {
			return $success;
		}

		$error_key = $this->is_safe_generated_path( $filename )
			? $filename
			: 'invalid-path-' . \md5( $filename );
		$errors    = $this->load_write_errors();

		if ( $success ) {
			if ( ! \array_key_exists( $error_key, $errors ) && 0 === $this->write_error_dropped_pending ) {
				return true;
			}
			unset( $errors[ $error_key ] );
		} elseif ( $persist_error ) {
			$this->set_write_error( $errors, $error_key, $result );
		}

		if ( ! $this->maintain_operation_lock() ) {
			return $success;
		}
		$this->persist_write_errors( $errors );

		return $success;
	}

	/**
	 * Load a bounded, scalar-only sample of current per-file diagnostics.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function load_write_errors(): array {
		$stored = \get_option( self::WRITE_ERRORS_OPTION, array() );
		if ( ! \is_array( $stored ) ) {
			$this->write_error_dropped_pending += 1;
			return array();
		}

		$errors = array();
		foreach ( $stored as $key => $record ) {
			if ( ! \is_string( $key ) || '' === $key || \strlen( $key ) > StaticOwnershipStore::MAX_PATH_LENGTH ) {
				++$this->write_error_dropped_pending;
				continue;
			}
			$normalized = $this->normalize_write_error_record( $record );
			if ( null === $normalized ) {
				++$this->write_error_dropped_pending;
				continue;
			}
			$this->set_write_error( $errors, $key, $normalized );
		}

		return $errors;
	}

	/**
	 * Add or refresh one diagnostic while keeping request memory bounded.
	 *
	 * @param array<string,array<string,mixed>> $errors Current sample.
	 * @param array<string,mixed>               $record Diagnostic record.
	 */
	private function set_write_error( array &$errors, string $key, array $record ): void {
		unset( $errors[ $key ] );
		$errors[ $key ] = $record;
		$error_count    = \count( $errors );
		while ( $error_count > self::WRITE_ERROR_SAMPLE_LIMIT ) {
			\array_shift( $errors );
			++$this->write_error_dropped_pending;
			--$error_count;
		}
	}

	/**
	 * Persist the sample and a monotonic count of diagnostics omitted from it.
	 *
	 * @param array<string,array<string,mixed>> $errors Bounded error sample.
	 */
	private function persist_write_errors( array $errors ): bool {
		if ( null === $this->operation_lock_token || ! $this->maintain_operation_lock() ) {
			return false;
		}
		if ( $this->write_error_dropped_pending > 0 ) {
			$current = AtomicOptionSequence::current( self::WRITE_ERROR_DROPPED_OPTION );
			if (
				$current < 0
				|| AtomicOptionSequence::advance_to(
					self::WRITE_ERROR_DROPPED_OPTION,
					$current + $this->write_error_dropped_pending
				) < $current + $this->write_error_dropped_pending
			) {
				return false;
			}
			$this->write_error_dropped_pending = 0;
		}

		if ( empty( $errors ) ) {
			\delete_option( self::WRITE_ERRORS_OPTION );
		} else {
			\update_option( self::WRITE_ERRORS_OPTION, $errors, false );
		}
		return true;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function normalize_write_error_record( mixed $record ): ?array {
		if ( ! \is_array( $record ) ) {
			return null;
		}

		$normalized = array();
		foreach ( \array_slice( $record, 0, 32, true ) as $key => $value ) {
			if ( ! \is_string( $key ) || '' === $key || \strlen( $key ) > 64 || ( ! \is_scalar( $value ) && null !== $value ) ) {
				continue;
			}
			$normalized[ $key ] = \is_string( $value ) ? \substr( $value, 0, 4096 ) : $value;
		}

		return isset( $normalized['code'] ) && \is_string( $normalized['code'] )
			? $normalized
			: null;
	}

	/**
	 * Build a normalized write or purge diagnostic record.
	 *
	 * @param string               $filename Relative generated-file path.
	 * @param string               $status Result class.
	 * @param string               $code Machine-readable result code.
	 * @param string               $message Human-readable summary.
	 * @param string               $path Absolute destination path.
	 * @param array<string, mixed> $context Additional diagnostic fields.
	 * @return array<string, mixed>
	 */
	private function build_write_result(
		string $filename,
		string $status,
		string $code,
		string $message,
		string $path,
		array $context = array()
	): array {
		return \array_merge(
			array(
				'status'   => $status,
				'code'     => $code,
				'message'  => $message,
				'time'     => \time(),
				'filename' => $filename,
				'path'     => $path,
			),
			$context
		);
	}

	/**
	 * Avoid whole-file ownership checks that can recreate the memory problem a
	 * bounded LLMS publication is intended to prevent.
	 *
	 * WP_Filesystem exposes whole-string reads rather than a portable streaming
	 * hash API. A legacy full file up to this separate verification ceiling can
	 * still be proved and reconciled; a larger file is retained explicitly.
	 *
	 * @param object $filesystem Initialized WP_Filesystem implementation.
	 * @return array{code:string,message:string,current_bytes:int}|null
	 */
	private function get_ownership_verification_issue(
		string $filename,
		string $path,
		object $filesystem
	): ?array {
		if ( ! $this->is_full_llms_generated_path( $filename ) ) {
			return null;
		}

		if ( ! \method_exists( $filesystem, 'size' ) ) {
			return array(
				'code'          => 'ownership_size_unavailable',
				'message'       => __( 'The existing complete LLMS file size could not be checked safely, so the file was retained.', 'cybermaps' ),
				'current_bytes' => 0,
			);
		}

		$size = $filesystem->size( $path );
		if ( false === $size || ! \is_numeric( $size ) ) {
			return array(
				'code'          => 'ownership_size_unavailable',
				'message'       => __( 'The existing complete LLMS file size could not be checked safely, so the file was retained.', 'cybermaps' ),
				'current_bytes' => 0,
			);
		}

		$size = max( 0, (int) $size );
		if ( $size <= self::OWNERSHIP_VERIFICATION_MAX_BYTES ) {
			return null;
		}

		return array(
			'code'          => 'owned_file_too_large_to_verify',
			'message'       => __( 'The existing complete LLMS file exceeds the bounded ownership-verification limit and was retained rather than read or overwritten.', 'cybermaps' ),
			'current_bytes' => $size,
		);
	}

	/**
	 * Return a consistent purge failure without performing further mutations.
	 *
	 * @param string[]             $deleted Files removed while the token was valid.
	 * @param array<string,string> $retained Files already retained.
	 * @return array<string,mixed>
	 */
	private function operation_lock_lost_purge_result(
		array $deleted = array(),
		array $retained = array()
	): array {
		return array(
			'success'  => false,
			'status'   => 'operation_lock_lost',
			'message'  => __( 'Static reconciliation stopped because this request no longer owned its operation lock.', 'cybermaps' ),
			'deleted'  => $deleted,
			'retained' => $retained,
		);
	}

	/**
	 * Match canonical and localized complete LLMS static publications.
	 */
	private function is_full_llms_generated_path( string $filename ): bool {
		return 1 === \preg_match( '#(?:^|/)llms-full\.txt$#', $filename );
	}

	/**
	 * Validate a generated-file inventory path before resolving it below home.
	 *
	 * @param string $filename Relative generated-file path.
	 * @return bool Whether the path is safe to resolve.
	 */
	private function is_safe_generated_path( string $filename ): bool {
		if (
			'' === $filename
			|| \strlen( $filename ) > StaticOwnershipStore::MAX_PATH_LENGTH
			|| \ltrim( $filename, '/' ) !== $filename
		) {
			return false;
		}

		if ( false !== \strpos( $filename, "\0" ) || false !== \strpos( $filename, '\\' ) ) {
			return false;
		}

		$segments = \explode( '/', $filename );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an inventory entry belongs to the AI discovery feature surface.
	 */
	private function is_discovery_generated_path( string $filename ): bool {
		if (
			0 === \strpos( $filename, '.well-known/' )
			|| 0 === \strpos( $filename, 'discovery/chunks/' )
		) {
			return true;
		}

		if (
			\in_array(
				$filename,
				array(
					'ai.json',
					'ai-discovery',
					'knowledge-graph.json',
					'feed.json',
					'llms.txt',
					'llms-full.txt',
					'llms-tldr.txt',
					'ai-usage.json',
					'ai-actions.json',
					'ai-sitemap.xml',
					'robots.txt',
					'skill.md',
				),
				true
			)
		) {
			return true;
		}

		return 1 === \preg_match( '#^[^/]+/llms(?:-full|-tldr)?\.txt$#', $filename );
	}

	/**
	 * Whether a filename is eligible for the compatibility static mode.
	 *
	 * The stored `well_known` mode name predates the retirement of proprietary
	 * `/.well-known/` aliases. Resolve the allow-list from the endpoint registry
	 * instead of inferring it from a directory name, so static publication stays
	 * aligned with the public endpoint contract.
	 *
	 * @param array<string,mixed>|null $settings Optional settings snapshot.
	 */
	private function is_well_known_mode_static_path( string $filename, ?array $settings = null ): bool {
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		$registry->register_extension_endpoints();
		foreach ( $registry->get_static_targets( 'well_known', $settings ) as $target ) {
			if ( (string) ( $target['filename'] ?? '' ) === $filename ) {
				return true;
			}
		}

		return false;
	}
}
