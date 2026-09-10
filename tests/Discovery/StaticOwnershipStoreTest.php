<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Discovery\StaticOwnershipStore;
use Cybermaps\Discovery\StaticSyncRunner;
use Cybermaps\Discovery\StaticWriteIntentStore;

final class StaticOwnershipStoreTest extends \WP_UnitTestCase {
	/** @var string[] */
	private array $created_files = array();

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_options']      = array(
			'blog_public'        => '1',
			'cybermaps_settings' => array(
				'static_engine_mode'   => 'all',
				'enable_discovery_hub' => '1',
			),
		);
		$GLOBALS['cybermaps_mock_scheduled']    = array();
		unset(
			$GLOBALS['cybermaps_mock_update_option_behavior'],
			$GLOBALS['cybermaps_mock_get_option_observer'],
			$GLOBALS['cybermaps_mock_wp_filesystem_move_observer']
		);

		\Cybermaps\Core\ConfigurationStore::reset_memo();
		$this->reset_bridge_state();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_update_option_behavior'],
			$GLOBALS['cybermaps_mock_get_option_observer'],
			$GLOBALS['cybermaps_mock_wp_filesystem_move_observer']
		);
		foreach ( $this->created_files as $path ) {
			if ( \file_exists( $path ) ) {
				\wp_delete_file( $path );
			}
		}
		StaticOwnershipStore::delete_all();
		\delete_option( StaticWriteIntentStore::OPTION );
		\delete_option( StaticBridge::OPERATION_LOCK_OPTION );
		\delete_option( 'cybermaps_static_sync_state' );
		\delete_option( 'cybermaps_static_write_errors' );
		\delete_option( 'cybermaps_static_write_errors_dropped' );
		$this->reset_bridge_state();

		parent::tearDown();
	}

	public function test_failed_migration_retains_legacy_authority_and_can_be_retried(): void {
		$legacy_path = 'legacy\\owned.txt';
		$hash        = \str_repeat( 'a', 32 );
		$normalized  = StaticOwnershipStore::normalize_path( $legacy_path );
		$shard       = StaticOwnershipStore::shard_for_path( $normalized );
		$shard_name  = StaticOwnershipStore::shard_option_name( $shard );

		\update_option( StaticOwnershipStore::LEGACY_OPTION, array( $legacy_path => $hash ), false );
		\update_option( 'cybermaps_static_sync_epoch', 7, false );
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( $shard_name ): void {
			unset( $value );
			if ( $shard_name === $option && 'after' === $stage ) {
				unset( $GLOBALS['cybermaps_mock_options'][ $option ] );
			}
		};

		$store = new StaticOwnershipStore();
		$this->assertFalse( $store->migrate_if_needed() );
		$this->assertArrayHasKey( StaticOwnershipStore::LEGACY_OPTION, $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame( array( $legacy_path => $hash ), $GLOBALS['cybermaps_mock_options'][ StaticOwnershipStore::LEGACY_OPTION ] );
		$this->assertArrayNotHasKey( StaticOwnershipStore::SCHEMA_OPTION, $GLOBALS['cybermaps_mock_options'] );

		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		$this->assertTrue( $store->migrate_if_needed() );
		$this->assertSame( StaticOwnershipStore::SCHEMA_VERSION, StaticOwnershipStore::current_schema() );
		$this->assertArrayNotHasKey( StaticOwnershipStore::LEGACY_OPTION, $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame(
			array(
				$normalized => array(
					'hash'       => $hash,
					'generation' => 7,
				),
			),
			$GLOBALS['cybermaps_mock_options'][ $shard_name ]
		);
	}

	public function test_migration_rejects_ambiguous_normalized_path_collision(): void {
		$backslash_path = 'collision\\owned.txt';
		$normalized     = StaticOwnershipStore::normalize_path( $backslash_path );
		$legacy         = array(
			$backslash_path => \str_repeat( 'a', 32 ),
			$normalized     => \str_repeat( 'b', 32 ),
		);
		\update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy, false );

		$this->assertFalse( ( new StaticOwnershipStore() )->migrate_if_needed() );
		$this->assertSame( $legacy, $GLOBALS['cybermaps_mock_options'][ StaticOwnershipStore::LEGACY_OPTION ] );
		$this->assertSame( 0, StaticOwnershipStore::current_schema() );
	}

	public function test_migration_collapses_equivalent_normalized_paths_without_losing_ownership(): void {
		$hash           = \str_repeat( 'c', 32 );
		$backslash_path = 'equivalent\\owned.txt';
		$normalized     = StaticOwnershipStore::normalize_path( $backslash_path );
		\update_option(
			StaticOwnershipStore::LEGACY_OPTION,
			array(
				$backslash_path => $hash,
				$normalized     => $hash,
			),
			false
		);

		$store = new StaticOwnershipStore();
		$this->assertTrue( $store->migrate_if_needed() );
		$this->assertSame( array( $normalized => $hash ), $store->read_flat_hashes() );
		$this->assertSame( $hash, $store->get_hash( $backslash_path ) );
		$this->assertSame( $hash, $store->get_hash( $normalized ) );
	}

	public function test_newer_schema_is_preserved_during_a_downgrade_attempt(): void {
		$path    = 'future-owned.txt';
		$records = array(
			$path => array(
				'hash'       => \str_repeat( 'f', 32 ),
				'generation' => 99,
			),
		);
		$option  = StaticOwnershipStore::shard_option_name( StaticOwnershipStore::shard_for_path( $path ) );
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION + 1, false );
		\update_option( $option, $records, false );

		$this->assertFalse( ( new StaticOwnershipStore() )->migrate_if_needed() );
		$this->assertSame( StaticOwnershipStore::SCHEMA_VERSION + 1, \get_option( StaticOwnershipStore::SCHEMA_OPTION ) );
		$this->assertSame( $records, \get_option( $option ) );
	}

	public function test_orphaned_shards_without_a_schema_or_legacy_source_fail_closed(): void {
		$path    = 'orphaned-owned.txt';
		$records = array(
			$path => array(
				'hash'       => \str_repeat( '1', 32 ),
				'generation' => 4,
			),
		);
		$option  = StaticOwnershipStore::shard_option_name( StaticOwnershipStore::shard_for_path( $path ) );
		\update_option( $option, $records, false );

		$this->assertFalse( ( new StaticOwnershipStore() )->migrate_if_needed() );
		$this->assertSame( 0, StaticOwnershipStore::current_schema() );
		$this->assertSame( $records, \get_option( $option ) );
	}

	public function test_migration_preserves_valid_conflicting_shard_evidence(): void {
		$paths        = $this->filenames_for_shard( 0, 2 );
		$legacy       = array( $paths[0] => \str_repeat( '2', 32 ) );
		$conflicting  = array(
			$paths[1] => array(
				'hash'       => \str_repeat( '3', 32 ),
				'generation' => 9,
			),
		);
		$shard_option = StaticOwnershipStore::shard_option_name( 0 );
		\update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy, false );
		\update_option( 'cybermaps_static_sync_epoch', 4, false );
		\update_option( $shard_option, $conflicting, false );

		$this->assertFalse( ( new StaticOwnershipStore() )->migrate_if_needed() );
		$this->assertSame( $conflicting, \get_option( $shard_option ) );
		$this->assertSame( $legacy, \get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		$this->assertSame( 0, StaticOwnershipStore::current_schema() );
	}

	public function test_migration_preserves_malformed_shard_evidence(): void {
		$path         = $this->filenames_for_shard( 0, 1 )[0];
		$legacy       = array( $path => \str_repeat( '4', 32 ) );
		$malformed    = array(
			$path => array(
				'hash'       => 'not-an-md5',
				'generation' => 5,
			),
		);
		$shard_option = StaticOwnershipStore::shard_option_name( 0 );
		\update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy, false );
		\update_option( $shard_option, $malformed, false );

		$this->assertFalse( ( new StaticOwnershipStore() )->migrate_if_needed() );
		$this->assertSame( $malformed, \get_option( $shard_option ) );
		$this->assertSame( $legacy, \get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		$this->assertSame( 0, StaticOwnershipStore::current_schema() );
	}

	public function test_schema_zero_mutations_preserve_malformed_legacy_evidence(): void {
		$legacy = array(
			'valid-owned.txt'    => \str_repeat( 'a', 32 ),
			'malformed-evidence' => 'not-an-md5',
		);
		\update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy, false );

		$store = new StaticOwnershipStore();
		$this->assertFalse( $store->set_hash( 'new-owned.txt', \str_repeat( 'b', 32 ), 1, true ) );
		$this->assertFalse( $store->delete_hash( 'valid-owned.txt', true ) );
		$this->assertFalse(
			$store->stage_hashes(
				array( 'valid-owned.txt' => \str_repeat( 'a', 32 ) ),
				1,
				true
			)
		);
		$this->assertSame( $legacy, \get_option( StaticOwnershipStore::LEGACY_OPTION ) );
	}

	public function test_schema_zero_flush_rejects_malformed_evidence_that_appears_after_the_write(): void {
		$legacy = array( 'valid-owned.txt' => \str_repeat( 'c', 32 ) );
		\update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy, false );
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ): void {
			unset( $value );
			if ( StaticOwnershipStore::LEGACY_OPTION === $option && 'after' === $stage ) {
				$GLOBALS['cybermaps_mock_options'][ $option ]['malformed-evidence'] = 'not-an-md5';
			}
		};

		$this->assertFalse(
			( new StaticOwnershipStore() )->set_hash(
				'new-owned.txt',
				\str_repeat( 'd', 32 ),
				1,
				true
			)
		);
		$this->assertSame(
			array(
				'valid-owned.txt'    => \str_repeat( 'c', 32 ),
				'new-owned.txt'      => \str_repeat( 'd', 32 ),
				'malformed-evidence' => 'not-an-md5',
			),
			\get_option( StaticOwnershipStore::LEGACY_OPTION )
		);
	}

	public function test_legacy_pre_option_filter_preserves_an_upstream_short_circuit(): void {
		$upstream = array( 'third-party' => \str_repeat( 'd', 32 ) );
		$this->assertSame( $upstream, StaticOwnershipStore::filter_legacy_option_read( $upstream ) );

		$path  = 'authoritative.txt';
		$hash  = \str_repeat( 'e', 32 );
		$shard = StaticOwnershipStore::shard_for_path( $path );
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option(
			StaticOwnershipStore::shard_option_name( $shard ),
			array(
				$path => array(
					'hash'       => $hash,
					'generation' => 2,
				),
			),
			false
		);

		$this->assertSame(
			array( $path => $hash ),
			StaticOwnershipStore::filter_legacy_option_read( false )
		);
	}

	public function test_unverified_epoch_write_aborts_before_stale_reconciliation(): void {
		$filename = 'epoch-write-failure.txt';
		$content  = 'still owned';
		$path     = $this->create_file( $filename, $content );
		$shard    = StaticOwnershipStore::shard_for_path( $filename );
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option( 'cybermaps_static_sync_epoch', 5, false );
		\update_option(
			StaticOwnershipStore::shard_option_name( $shard ),
			array(
				$filename => array(
					'hash'       => \md5( $content ),
					'generation' => 4,
				),
			),
			false
		);
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ): void {
			unset( $value );
			if ( 'cybermaps_static_sync_epoch' === $option && 'after' === $stage ) {
				unset( $GLOBALS['cybermaps_mock_options'][ $option ] );
			}
		};

		$report = StaticBridge::get_instance()->sync_all();

		$this->assertSame( 'sync_state_checkpoint_failed', $report['failed']['operation']['code'] );
		$this->assertFileExists( $path );
		\WP_Filesystem();
		global $wp_filesystem;
		$this->assertSame( $content, $wp_filesystem->get_contents( $path ) );
		$this->assertArrayNotHasKey( 'cybermaps_static_sync_state', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( StaticBridge::OPERATION_LOCK_OPTION, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_invalid_continuation_mode_epoch_and_phase_cannot_resume_at_stale_deletion(): void {
		$settings = array(
			'static_engine_mode'   => 'all',
			'enable_discovery_hub' => '1',
		);
		$valid    = array(
			'schema'     => StaticSyncRunner::STATE_SCHEMA,
			'status'     => 'pending',
			'generation' => 9,
			'mode'       => 'all',
			'epoch'      => 12,
			'phase'      => StaticSyncRunner::PHASE_STALE_RECONCILIATION,
			'cursor'     => array(
				'shard' => 4,
				'path'  => 'last-owned.txt',
			),
			'context'    => array(
				'topology' => ( new StaticSyncRunner( StaticBridge::get_instance() ) )->topology_fingerprint( $settings, 'all' ),
			),
			'started_at' => '2026-08-28T00:00:00+00:00',
			'retry_at'   => 0,
			'writes'     => 0,
			'last_error' => '',
		);
		$runner   = new StaticSyncRunner( StaticBridge::get_instance() );
		$this->assertTrue( $runner->can_resume( $valid, $settings, 9, 'all', 12 ) );

		$invalid_states = array(
			'wrong mode'  => array_replace( $valid, array( 'mode' => 'well_known' ) ),
			'wrong epoch' => array_replace( $valid, array( 'epoch' => 11 ) ),
			'bad phase'   => array_replace(
				$valid,
				array(
					'phase'  => 'delete_everything',
					'cursor' => array(),
				)
			),
			'complete'    => array_replace(
				$valid,
				array(
					'phase'  => StaticSyncRunner::PHASE_COMPLETE,
					'cursor' => array(),
				)
			),
		);
		foreach ( $invalid_states as $label => $state ) {
			$this->assertFalse(
				$runner->can_resume( $state, $settings, 9, 'all', 12 ),
				$label . ' must restart at the first phase'
			);
		}
	}

	public function test_standalone_write_respects_a_foreign_lock_and_releases_its_own_lock(): void {
		$filename              = 'standalone-lock-owned.txt';
		$path                  = ABSPATH . $filename;
		$this->created_files[] = $path;
		$foreign_lock          = array(
			'token' => 'another-request',
			'time'  => \time(),
		);
		\update_option( StaticBridge::OPERATION_LOCK_OPTION, $foreign_lock, false );

		$bridge = StaticBridge::get_instance();
		$this->assertFalse( $bridge->write_file( $filename, 'blocked' ) );
		$this->assertSame( 'operation_locked', $bridge->get_last_write_result()['code'] );
		$this->assertSame( $foreign_lock, \get_option( StaticBridge::OPERATION_LOCK_OPTION ) );
		$this->assertFileDoesNotExist( $path );

		\delete_option( StaticBridge::OPERATION_LOCK_OPTION );
		$this->assertTrue( $bridge->write_file( $filename, 'owned' ) );
		$this->assertFileExists( $path );
		$this->assertArrayNotHasKey( StaticBridge::OPERATION_LOCK_OPTION, $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame( \md5( 'owned' ), ( new StaticOwnershipStore() )->get_hash( $filename ) );
	}

	public function test_distinct_write_failures_retain_a_bounded_normalized_sample_and_count_dropped_records(): void {
		\update_option( 'cybermaps_static_write_errors_dropped', 7, false );
		$bridge = StaticBridge::get_instance();

		for ( $index = 0; $index < 205; ++$index ) {
			$this->assertFalse(
				$bridge->write_file( '../diagnostic-' . $index . '.txt', 'blocked' )
			);
		}

		$errors = \get_option( 'cybermaps_static_write_errors', array() );
		$this->assertIsArray( $errors );
		$this->assertCount( 200, $errors );
		$this->assertSame( 12, (int) \get_option( 'cybermaps_static_write_errors_dropped', 0 ) );
		$this->assertArrayNotHasKey( 'invalid-path-' . \md5( '../diagnostic-0.txt' ), $errors );
		$this->assertArrayHasKey( 'invalid-path-' . \md5( '../diagnostic-204.txt' ), $errors );

		foreach ( $errors as $key => $record ) {
			$this->assertIsString( $key );
			$this->assertIsArray( $record );
			$this->assertSame( 'invalid_path', $record['code'] ?? null );
			$this->assertLessThanOrEqual( 32, \count( $record ) );
			foreach ( $record as $value ) {
				$this->assertTrue( \is_scalar( $value ) || null === $value );
				if ( \is_string( $value ) ) {
					$this->assertLessThanOrEqual( 4096, \strlen( $value ) );
				}
			}
		}
	}

	public function test_malformed_and_oversized_persisted_write_diagnostics_are_compacted(): void {
		$valid = array(
			'code'                 => 'fixture_failure',
			'message'              => \str_repeat( 'm', 5000 ),
			'nested'               => array( 'not persisted' ),
			\str_repeat( 'k', 65 ) => 'oversized field name',
		);
		for ( $index = 0; $index < 35; ++$index ) {
			$valid[ 'field_' . $index ] = $index;
		}
		$oversized_key = \str_repeat( 'p', StaticOwnershipStore::MAX_PATH_LENGTH + 1 );
		\update_option(
			'cybermaps_static_write_errors',
			array(
				'valid.txt'    => $valid,
				'not-an-array' => 'malformed',
				'missing-code' => array( 'message' => 'No machine-readable code.' ),
				$oversized_key => array( 'code' => 'oversized_path' ),
			),
			false
		);

		$bridge = StaticBridge::get_instance();
		$this->assertFalse( $bridge->write_file( '../fresh-diagnostic.txt', 'blocked' ) );
		$errors = \get_option( 'cybermaps_static_write_errors', array() );

		$this->assertCount( 2, $errors );
		$this->assertArrayHasKey( 'valid.txt', $errors );
		$this->assertArrayHasKey( 'invalid-path-' . \md5( '../fresh-diagnostic.txt' ), $errors );
		$this->assertArrayNotHasKey( 'nested', $errors['valid.txt'] );
		$this->assertArrayNotHasKey( \str_repeat( 'k', 65 ), $errors['valid.txt'] );
		$this->assertSame( 4096, \strlen( $errors['valid.txt']['message'] ) );
		$this->assertLessThanOrEqual( 32, \count( $errors['valid.txt'] ) );
		$this->assertSame( 3, (int) \get_option( 'cybermaps_static_write_errors_dropped', 0 ) );
	}

	public function test_lock_contender_does_not_mutate_the_active_owners_write_diagnostics(): void {
		$diagnostics  = array(
			'owner.txt' => array(
				'code'    => 'active_owner_failure',
				'message' => 'This option belongs to the active lease holder.',
			),
		);
		$foreign_lock = array(
			'token' => 'active-owner',
			'time'  => \time(),
		);
		\update_option( 'cybermaps_static_write_errors', $diagnostics, false );
		\update_option( 'cybermaps_static_write_errors_dropped', 9, false );
		\update_option( StaticBridge::OPERATION_LOCK_OPTION, $foreign_lock, false );

		$bridge = StaticBridge::get_instance();
		$this->assertFalse( $bridge->write_file( '../contender-diagnostic.txt', 'blocked' ) );

		$this->assertSame( 'operation_locked', $bridge->get_last_write_result()['code'] );
		$this->assertSame( $foreign_lock, \get_option( StaticBridge::OPERATION_LOCK_OPTION ) );
		$this->assertSame( $diagnostics, \get_option( 'cybermaps_static_write_errors' ) );
		$this->assertSame( 9, (int) \get_option( 'cybermaps_static_write_errors_dropped', 0 ) );
	}

	public function test_successor_adopts_an_exact_post_move_body_from_the_durable_intent(): void {
		$filename              = 'post-move-intent-adoption.txt';
		$content               = 'new body awaiting ownership';
		$path                  = ABSPATH . $filename;
		$this->created_files[] = $path;
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		$this->steal_lease_after_next_move();

		$bridge = StaticBridge::get_instance();
		$this->assertFalse( $bridge->write_file( $filename, $content ) );
		$this->assertSame( 'operation_lock_lost', $bridge->get_last_write_result()['code'] );
		$this->assertFileExists( $path );
		\WP_Filesystem();
		global $wp_filesystem;
		$this->assertSame( $content, $wp_filesystem->get_contents( $path ) );
		$intent = \get_option( StaticWriteIntentStore::OPTION, null );
		$this->assertIsArray( $intent );
		$this->assertSame( $filename, $intent['filename'] );
		$this->assertSame( \md5( $content ), $intent['new_hash'] );
		$this->assertNull( ( new StaticOwnershipStore() )->get_hash( $filename ) );

		\delete_option( StaticBridge::OPERATION_LOCK_OPTION );
		$this->assertTrue( $bridge->write_file( $filename, $content ) );
		$this->assertSame( 'unchanged', $bridge->get_last_write_result()['code'] );
		$this->assertSame( $content, $wp_filesystem->get_contents( $path ) );
		$this->assertSame( \md5( $content ), ( new StaticOwnershipStore() )->get_hash( $filename ) );
		$this->assertNull( \get_option( StaticWriteIntentStore::OPTION, null ) );
	}

	public function test_successor_preserves_a_third_party_body_while_resolving_the_intent(): void {
		$filename              = 'post-move-intent-conflict.txt';
		$content               = 'body written before lease loss';
		$third_party           = 'independent third-party body';
		$path                  = ABSPATH . $filename;
		$this->created_files[] = $path;
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		$this->steal_lease_after_next_move();

		$bridge = StaticBridge::get_instance();
		$this->assertFalse( $bridge->write_file( $filename, $content ) );
		$this->assertIsArray( \get_option( StaticWriteIntentStore::OPTION, null ) );
		\WP_Filesystem();
		global $wp_filesystem;
		$this->assertTrue( $wp_filesystem->put_contents( $path, $third_party, 0644 ) );

		\delete_option( StaticBridge::OPERATION_LOCK_OPTION );
		$this->assertFalse( $bridge->write_file( $filename, $content ) );
		$this->assertSame( 'untracked_existing_file', $bridge->get_last_write_result()['code'] );
		$this->assertSame( $third_party, $wp_filesystem->get_contents( $path ) );
		$this->assertNull( ( new StaticOwnershipStore() )->get_hash( $filename ) );
		$this->assertNull( \get_option( StaticWriteIntentStore::OPTION, null ) );
	}

	public function test_lease_loss_after_intent_creation_stops_before_move_and_leaves_recovery_evidence(): void {
		$filename              = 'pre-move-intent-fence.txt';
		$path                  = ABSPATH . $filename;
		$this->created_files[] = $path;
		$move_attempted        = false;
		$lease_stolen          = false;
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		$GLOBALS['cybermaps_mock_wp_filesystem_move_observer'] = static function () use ( &$move_attempted ): void {
			$move_attempted = true;
		};
		$GLOBALS['cybermaps_mock_get_option_observer']         = static function ( string $option ) use ( &$lease_stolen ): void {
			if (
				$lease_stolen
				|| StaticWriteIntentStore::OPTION !== $option
				|| ! \array_key_exists( StaticWriteIntentStore::OPTION, $GLOBALS['cybermaps_mock_options'] )
			) {
				return;
			}
			$lease_stolen = true;
			unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
			\update_option(
				StaticBridge::OPERATION_LOCK_OPTION,
				array(
					'token' => 'pre-move-successor',
					'time'  => \time(),
				),
				false
			);
		};

		$bridge = StaticBridge::get_instance();
		$this->assertFalse( $bridge->write_file( $filename, 'body that must not move' ) );

		$this->assertTrue( $lease_stolen );
		$this->assertFalse( $move_attempted );
		$this->assertSame( 'operation_lock_lost', $bridge->get_last_write_result()['code'] );
		$this->assertFalse( $bridge->get_last_write_result()['move_attempted'] );
		$this->assertTrue( $bridge->get_last_write_result()['intent_pending'] );
		$this->assertFileDoesNotExist( $path );
		$intent = \get_option( StaticWriteIntentStore::OPTION, null );
		$this->assertIsArray( $intent );
		$this->assertSame( $filename, $intent['filename'] );
		$this->assertSame( 'pre-move-successor', \get_option( StaticBridge::OPERATION_LOCK_OPTION )['token'] );
		$this->assertNotFalse( \wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
	}

	public function test_failed_revision_commit_uses_an_aba_safe_incident_and_acknowledgement_sequence(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		$before = (int) \get_option( 'cybermaps_static_ownership_revision', 0 );
		$this->set_bridge_property( 'ownership_revision_pending', true );
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( $before ): void {
			unset( $value );
			if ( 'cybermaps_static_ownership_revision' === $option && 'after' === $stage ) {
				$GLOBALS['cybermaps_mock_options'][ $option ] = $before;
			}
		};

		$this->invoke_private( $bridge, 'release_operation_lock' );

		$this->assertSame( $before, (int) \get_option( 'cybermaps_static_ownership_revision', 0 ) );
		$this->assertSame( 1, (int) \get_option( StaticOwnershipStore::REPAIR_OPTION, 0 ) );
		$this->assertSame( 0, (int) \get_option( StaticOwnershipStore::REPAIR_ACK_OPTION, 0 ) );
		$this->assertNotFalse( \wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );

		$interleaved                                      = false;
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( &$interleaved ): void {
			unset( $value );
			if ( ! $interleaved && 'cybermaps_static_ownership_revision' === $option && 'after' === $stage ) {
				$interleaved = true;
				\update_option( StaticOwnershipStore::REPAIR_OPTION, 2, false );
			}
		};
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		try {
			$this->assertTrue( $interleaved );
			$this->assertSame( $before + 1, (int) \get_option( 'cybermaps_static_ownership_revision', 0 ) );
			$this->assertSame( 2, (int) \get_option( StaticOwnershipStore::REPAIR_OPTION, 0 ) );
			$this->assertSame( 1, (int) \get_option( StaticOwnershipStore::REPAIR_ACK_OPTION, 0 ) );
		} finally {
			$this->invoke_private( $bridge, 'release_operation_lock' );
		}

		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		try {
			$this->assertSame( $before + 2, (int) \get_option( 'cybermaps_static_ownership_revision', 0 ) );
			$this->assertSame( 2, (int) \get_option( StaticOwnershipStore::REPAIR_OPTION, 0 ) );
			$this->assertSame( 2, (int) \get_option( StaticOwnershipStore::REPAIR_ACK_OPTION, 0 ) );
		} finally {
			$this->invoke_private( $bridge, 'release_operation_lock' );
		}
	}

	public function test_repair_acknowledgement_ahead_of_incident_fails_closed(): void {
		\update_option( StaticOwnershipStore::REPAIR_OPTION, 1, false );
		\update_option( StaticOwnershipStore::REPAIR_ACK_OPTION, 2, false );

		$bridge = StaticBridge::get_instance();
		$this->assertFalse( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		$this->assertSame( 1, (int) \get_option( StaticOwnershipStore::REPAIR_OPTION, 0 ) );
		$this->assertSame( 2, (int) \get_option( StaticOwnershipStore::REPAIR_ACK_OPTION, 0 ) );
		$this->assertArrayNotHasKey( StaticBridge::OPERATION_LOCK_OPTION, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_out_of_range_shard_writes_are_rejected_without_touching_boundary_shards(): void {
		$first_path = $this->filenames_for_shard( 0, 1 )[0];
		$last_path  = $this->filenames_for_shard( StaticOwnershipStore::SHARD_COUNT - 1, 1 )[0];
		$first      = array(
			$first_path => array(
				'hash'       => \str_repeat( 'a', 32 ),
				'generation' => 1,
			),
		);
		$last       = array(
			$last_path => array(
				'hash'       => \str_repeat( 'b', 32 ),
				'generation' => 1,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option( StaticOwnershipStore::shard_option_name( 0 ), $first, false );
		\update_option( StaticOwnershipStore::shard_option_name( StaticOwnershipStore::SHARD_COUNT - 1 ), $last, false );

		$store = new StaticOwnershipStore();
		$this->assertFalse( $store->write_shard_records( -1, array(), false ) );
		$this->assertFalse( $store->write_shard_records( StaticOwnershipStore::SHARD_COUNT, array(), false ) );
		$this->assertSame( $first, \get_option( StaticOwnershipStore::shard_option_name( 0 ) ) );
		$this->assertSame(
			$last,
			\get_option( StaticOwnershipStore::shard_option_name( StaticOwnershipStore::SHARD_COUNT - 1 ) )
		);
	}

	public function test_force_flush_on_an_unchanged_record_persists_prior_dirty_work(): void {
		$paths  = $this->filenames_for_shard( 0, 2 );
		$first  = $paths[0];
		$second = $paths[1];
		$option = StaticOwnershipStore::shard_option_name( 0 );
		$old    = array(
			$first  => array(
				'hash'       => \str_repeat( 'a', 32 ),
				'generation' => 1,
			),
			$second => array(
				'hash'       => \str_repeat( 'b', 32 ),
				'generation' => 1,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option( $option, $old, false );

		$store = new StaticOwnershipStore();
		$this->assertTrue( $store->set_hash( $first, \str_repeat( 'c', 32 ), 2 ) );
		$this->assertTrue( $store->set_hash( $second, \str_repeat( 'b', 32 ), 1, true, false ) );
		$this->assertSame(
			array(
				$first  => array(
					'hash'       => \str_repeat( 'c', 32 ),
					'generation' => 2,
				),
				$second => $old[ $second ],
			),
			\get_option( $option )
		);
	}

	public function test_force_flush_on_an_absent_delete_persists_prior_dirty_work(): void {
		$paths   = $this->filenames_for_shard( 0, 3 );
		$first   = $paths[0];
		$second  = $paths[1];
		$missing = $paths[2];
		$option  = StaticOwnershipStore::shard_option_name( 0 );
		$old     = array(
			$first  => array(
				'hash'       => \str_repeat( 'd', 32 ),
				'generation' => 1,
			),
			$second => array(
				'hash'       => \str_repeat( 'e', 32 ),
				'generation' => 1,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option( $option, $old, false );

		$store = new StaticOwnershipStore();
		$this->assertTrue( $store->set_hash( $first, \str_repeat( 'f', 32 ), 2 ) );
		$this->assertTrue( $store->delete_hash( $missing, true, false ) );
		$this->assertSame(
			array(
				$first  => array(
					'hash'       => \str_repeat( 'f', 32 ),
					'generation' => 2,
				),
				$second => $old[ $second ],
			),
			\get_option( $option )
		);
	}

	public function test_schema_zero_rejects_shard_writes_without_touching_orphaned_data(): void {
		$path    = $this->filenames_for_shard( 0, 1 )[0];
		$option  = StaticOwnershipStore::shard_option_name( 0 );
		$records = array(
			$path => array(
				'hash'       => \str_repeat( '1', 32 ),
				'generation' => 3,
			),
		);
		\update_option( $option, $records, false );

		$this->assertFalse( ( new StaticOwnershipStore() )->write_shard_records( 0, array(), false ) );
		$this->assertSame( $records, \get_option( $option ) );
	}

	public function test_future_schema_rejects_shard_writes_without_touching_future_data(): void {
		$path    = $this->filenames_for_shard( 0, 1 )[0];
		$option  = StaticOwnershipStore::shard_option_name( 0 );
		$records = array(
			$path => array(
				'hash'       => \str_repeat( '2', 32 ),
				'generation' => 99,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION + 1, false );
		\update_option( $option, $records, false );

		$this->assertFalse( ( new StaticOwnershipStore() )->write_shard_records( 0, array(), false ) );
		$this->assertSame( $records, \get_option( $option ) );
	}

	public function test_partial_multi_shard_flush_advances_revision_and_retries_only_uncommitted_work(): void {
		$first_path    = $this->filenames_for_shard( 0, 1 )[0];
		$second_path   = $this->filenames_for_shard( 1, 1 )[0];
		$first_option  = StaticOwnershipStore::shard_option_name( 0 );
		$second_option = StaticOwnershipStore::shard_option_name( 1 );
		$first_record  = array(
			$first_path => array(
				'hash'       => \str_repeat( '3', 32 ),
				'generation' => 7,
			),
		);
		$second_record = array(
			$second_path => array(
				'hash'       => \str_repeat( '4', 32 ),
				'generation' => 7,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( $second_option ): void {
			unset( $value );
			if ( $second_option === $option && 'after' === $stage ) {
				unset( $GLOBALS['cybermaps_mock_options'][ $option ] );
			}
		};

		$store = new StaticOwnershipStore();
		$this->assertTrue( $store->set_hash( $first_path, \str_repeat( '3', 32 ), 7 ) );
		$this->assertTrue( $store->set_hash( $second_path, \str_repeat( '4', 32 ), 7 ) );
		$this->assertFalse( $store->flush( 7 ) );
		$this->assertSame( $first_record, \get_option( $first_option ) );
		$this->assertArrayNotHasKey( $second_option, $GLOBALS['cybermaps_mock_options'] );
		$this->assertSame( 1, (int) \get_option( 'cybermaps_static_ownership_revision', 0 ) );

		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		$this->assertTrue( $store->flush( 7 ) );
		$this->assertSame( $first_record, \get_option( $first_option ) );
		$this->assertSame( $second_record, \get_option( $second_option ) );
		$this->assertSame( 2, (int) \get_option( 'cybermaps_static_ownership_revision', 0 ) );
	}

	public function test_stale_shard_insert_cannot_erase_a_successor_commit(): void {
		$shard       = 0;
		$paths       = $this->filenames_for_shard( $shard, 2 );
		$first_path  = $paths[0];
		$second_path = $paths[1];
		$option      = StaticOwnershipStore::shard_option_name( $shard );
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );

		$first_writer = new StaticOwnershipStore();
		$stale_writer = new StaticOwnershipStore();
		$this->assertTrue( $first_writer->set_hash( $first_path, \str_repeat( '6', 32 ), 9 ) );
		$this->assertTrue( $stale_writer->set_hash( $second_path, \str_repeat( '7', 32 ), 9 ) );
		$this->assertTrue( $first_writer->flush( 9 ) );
		$this->assertFalse( $stale_writer->flush( 9 ) );

		$this->assertSame(
			array(
				$first_path => array(
					'hash'       => \str_repeat( '6', 32 ),
					'generation' => 9,
				),
			),
			\get_option( $option )
		);
	}

	public function test_stale_shard_delete_cannot_erase_a_successor_commit(): void {
		$shard  = 0;
		$path   = $this->filenames_for_shard( $shard, 1 )[0];
		$option = StaticOwnershipStore::shard_option_name( $shard );
		$base   = array(
			$path => array(
				'hash'       => \str_repeat( '8', 32 ),
				'generation' => 9,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option( $option, $base, false );

		$successor_writer = new StaticOwnershipStore();
		$stale_deleter    = new StaticOwnershipStore();
		$this->assertTrue( $successor_writer->set_hash( $path, \str_repeat( '9', 32 ), 10 ) );
		$this->assertTrue( $stale_deleter->delete_hash( $path ) );
		$this->assertTrue( $successor_writer->flush( 10 ) );
		$this->assertFalse( $stale_deleter->flush( 10 ) );

		$this->assertSame(
			array(
				$path => array(
					'hash'       => \str_repeat( '9', 32 ),
					'generation' => 10,
				),
			),
			\get_option( $option )
		);
	}

	public function test_shard_flush_preserves_malformed_evidence_that_appears_after_the_write_on_retry(): void {
		$shard                            = 0;
		$paths                            = $this->filenames_for_shard( $shard, 2 );
		$desired_path                     = $paths[0];
		$malformed_path                   = $paths[1];
		$option                           = StaticOwnershipStore::shard_option_name( $shard );
		$desired                          = array(
			$desired_path => array(
				'hash'       => \str_repeat( '5', 32 ),
				'generation' => 8,
			),
		);
		$with_evidence                    = $desired;
		$with_evidence[ $malformed_path ] = array(
			'hash'       => 'not-an-md5',
			'generation' => 8,
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $written_option, mixed $value, string $stage ) use ( $option, $malformed_path ): void {
			unset( $value );
			if ( $option === $written_option && 'after' === $stage ) {
				$GLOBALS['cybermaps_mock_options'][ $written_option ][ $malformed_path ] = array(
					'hash'       => 'not-an-md5',
					'generation' => 8,
				);
			}
		};

		$store = new StaticOwnershipStore();
		$this->assertTrue( $store->set_hash( $desired_path, \str_repeat( '5', 32 ), 8 ) );
		$this->assertFalse( $store->flush( 8 ) );
		$this->assertSame( $with_evidence, \get_option( $option ) );

		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		$this->assertFalse( $store->flush( 8 ) );
		$this->assertSame( $with_evidence, \get_option( $option ) );
	}

	public function test_shard_writes_preserve_malformed_raw_records(): void {
		$shard       = 0;
		$path        = $this->filenames_for_shard( $shard, 1 )[0];
		$option      = StaticOwnershipStore::shard_option_name( $shard );
		$malformed   = array(
			$path => array(
				'hash'       => 'not-an-md5',
				'generation' => 1,
			),
		);
		$replacement = array(
			$path => array(
				'hash'       => \str_repeat( 'c', 32 ),
				'generation' => 2,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option( $option, $malformed, false );

		$store = new StaticOwnershipStore();
		$this->assertFalse( $store->is_shard_valid( $shard ) );
		$this->assertFalse( $store->write_shard_records( $shard, $replacement, false ) );
		$this->assertSame( $malformed, \get_option( $option ) );
	}

	public function test_shard_writes_preserve_well_formed_records_stored_in_the_wrong_shard(): void {
		$shard          = 0;
		$misplaced_path = $this->filenames_for_shard( 1, 1 )[0];
		$option         = StaticOwnershipStore::shard_option_name( $shard );
		$misplaced      = array(
			$misplaced_path => array(
				'hash'       => \str_repeat( 'd', 32 ),
				'generation' => 1,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option( $option, $misplaced, false );

		$store = new StaticOwnershipStore();
		$this->assertFalse( $store->is_shard_valid( $shard ) );
		$this->assertFalse( $store->write_shard_records( $shard, array(), false ) );
		$this->assertSame( $misplaced, \get_option( $option ) );
	}

	public function test_stale_reconciliation_surfaces_an_invalid_shard_without_consuming_the_cursor(): void {
		$shard          = 0;
		$misplaced_path = $this->filenames_for_shard( 1, 1 )[0];
		$content        = 'misplaced ownership evidence';
		$file           = $this->create_file( $misplaced_path, $content );
		$option         = StaticOwnershipStore::shard_option_name( $shard );
		$misplaced      = array(
			$misplaced_path => array(
				'hash'       => \md5( $content ),
				'generation' => 1,
			),
		);
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		\update_option( $option, $misplaced, false );
		$store = ( new \ReflectionProperty( StaticBridge::class, 'ownership_store' ) )->getValue( $bridge );
		$store->clear_local_cache();
		$this->set_bridge_property( 'sync_active', true );
		$this->set_bridge_property( 'sync_epoch', 2 );
		$this->set_bridge_property( 'sync_started_at', \microtime( true ) );
		$report = array(
			'deleted'  => array(),
			'retained' => array(),
			'failed'   => array(),
		);
		$cursor = array(
			'shard' => $shard,
			'path'  => '',
		);

		try {
			$this->assertTrue( $bridge->runner_reconcile_stale_slice( $report, $cursor ) );
			$this->assertArrayHasKey( 'stale_reconciliation', $report['failed'] );
			$this->assertSame( 'invalid_ownership_shard', $report['failed']['stale_reconciliation']['code'] );
			$this->assertSame(
				array(
					'shard' => $shard,
					'path'  => '',
				),
				$cursor
			);
			$this->assertFalse( ( new \ReflectionProperty( StaticBridge::class, 'sync_deferred' ) )->getValue( $bridge ) );
			$this->assertSame( $misplaced, \get_option( $option ) );
			$this->assertFileExists( $file );
			$this->assertSame( array(), $report['deleted'] );
		} finally {
			$this->set_bridge_property( 'sync_active', false );
			$this->invoke_private( $bridge, 'release_operation_lock' );
		}
	}

	public function test_stale_reconciliation_checkpoints_at_one_hundred_records_and_crosses_shards(): void {
		$first_shard_files = $this->filenames_for_shard( 0, 101 );
		$second_shard_file = $this->filenames_for_shard( 1, 1, 10000 )[0];
		$records           = array();
		foreach ( $first_shard_files as $filename ) {
			$content = 'stale:' . $filename;
			$this->create_file( $filename, $content );
			$records[ $filename ] = array(
				'hash'       => \md5( $content ),
				'generation' => 1,
			);
		}
		$second_content = 'stale:' . $second_shard_file;
		$this->create_file( $second_shard_file, $second_content );

		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		\update_option( 'cybermaps_static_sync_epoch', 2, false );
		\update_option( StaticOwnershipStore::shard_option_name( 0 ), $records, false );
		\update_option(
			StaticOwnershipStore::shard_option_name( 1 ),
			array(
				$second_shard_file => array(
					'hash'       => \md5( $second_content ),
					'generation' => 1,
				),
			),
			false
		);

		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		$this->set_bridge_property( 'sync_active', true );
		$this->set_bridge_property( 'sync_epoch', 2 );
		$this->set_bridge_property( 'sync_started_at', \microtime( true ) );
		$report = array(
			'deleted'  => array(),
			'retained' => array(),
			'failed'   => array(),
		);
		$cursor = array(
			'shard' => 0,
			'path'  => '',
		);

		try {
			$this->assertFalse( $bridge->runner_reconcile_stale_slice( $report, $cursor ) );
			$this->assertSame( 0, $cursor['shard'] );
			$this->assertNotSame( '', $cursor['path'] );
			$this->assertCount( 100, $report['deleted'] );
			$this->assertCount( 1, $this->existing_files( $first_shard_files ) );
			$this->assertFileExists( ABSPATH . $second_shard_file );

			$this->assertFalse( $bridge->runner_reconcile_stale_slice( $report, $cursor ) );
			$this->assertSame(
				array(
					'shard' => 1,
					'path'  => '',
				),
				$cursor
			);
			$this->assertCount( 101, $report['deleted'] );

			$this->assertFalse( $bridge->runner_reconcile_stale_slice( $report, $cursor ) );
			$this->assertSame(
				array(
					'shard' => 2,
					'path'  => '',
				),
				$cursor
			);
			$this->assertCount( 102, $report['deleted'] );
			$this->assertFileDoesNotExist( ABSPATH . $second_shard_file );

			$iterations = 0;
			while ( ! $bridge->runner_reconcile_stale_slice( $report, $cursor ) ) {
				++$iterations;
				$this->assertLessThan( StaticOwnershipStore::SHARD_COUNT, $iterations );
			}
			$this->assertSame(
				array(
					'shard' => StaticOwnershipStore::SHARD_COUNT,
					'path'  => '',
				),
				$cursor
			);
		} finally {
			$this->set_bridge_property( 'sync_active', false );
			$this->invoke_private( $bridge, 'release_operation_lock' );
		}
	}

	private function create_file( string $filename, string $content ): string {
		$path = ABSPATH . $filename;
		\WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem->put_contents( $path, $content, 0644 ) ) {
			throw new \RuntimeException( 'Could not create a static ownership fixture.' );
		}
		$this->created_files[] = $path;
		return $path;
	}

	/**
	 * @return string[]
	 */
	private function filenames_for_shard( int $shard, int $count, int $start = 0 ): array {
		$filenames = array();
		$attempt   = $start;
		$found     = 0;
		while ( $count > $found ) {
			$filename = \sprintf( 'stale-owned-%05d.txt', $attempt );
			if ( StaticOwnershipStore::shard_for_path( $filename ) === $shard ) {
				$filenames[] = $filename;
				++$found;
			}
			++$attempt;
		}
		return $filenames;
	}

	/**
	 * @param string[] $filenames
	 * @return string[]
	 */
	private function existing_files( array $filenames ): array {
		return \array_values(
			\array_filter(
				$filenames,
				static fn ( string $filename ): bool => \file_exists( ABSPATH . $filename )
			)
		);
	}

	private function steal_lease_after_next_move(): void {
		$GLOBALS['cybermaps_mock_wp_filesystem_move_observer'] = static function (): void {
			unset( $GLOBALS['cybermaps_mock_wp_filesystem_move_observer'] );
			\update_option(
				StaticBridge::OPERATION_LOCK_OPTION,
				array(
					'token' => 'successor-ready-stale-lease',
					'time'  => \time() - 3600,
				),
				false
			);
		};
	}

	private function invoke_private( StaticBridge $bridge, string $method ): mixed {
		return ( new \ReflectionMethod( StaticBridge::class, $method ) )->invoke( $bridge );
	}

	private function set_bridge_property( string $property, mixed $value ): void {
		( new \ReflectionProperty( StaticBridge::class, $property ) )->setValue(
			StaticBridge::get_instance(),
			$value
		);
	}

	private function reset_bridge_state(): void {
		$bridge = StaticBridge::get_instance();
		foreach (
				array(
					'operation_lock_token'        => null,
					'operation_generation'        => null,
					'operation_lock_lost'         => false,
					'ownership_revision_pending'  => false,
					'ownership_repair_needed'     => false,
					'ownership_dirty'             => false,
					'ownership_ready'             => true,
					'ownership_changes'           => 0,
					'sync_active'                 => false,
					'sync_deferred'               => false,
					'write_error_dropped_pending' => 0,
					'sync_written_count'          => 0,
					'sync_started_at'             => 0.0,
					'sync_epoch'                  => 0,
					'sync_generation'             => 0,
					'sync_mode'                   => 'off',
					'sync_report_started_at'      => '',
					'sync_completed'              => array(),
					'sync_omitted'                => array(),
				) as $property => $value
		) {
			( new \ReflectionProperty( StaticBridge::class, $property ) )->setValue( $bridge, $value );
		}
		$lock = ( new \ReflectionProperty( StaticBridge::class, 'operation_lock' ) )->getValue( $bridge );
		$lock->reset_local_state();
		$store = ( new \ReflectionProperty( StaticBridge::class, 'ownership_store' ) )->getValue( $bridge );
		$store->clear_local_cache();
	}
}
