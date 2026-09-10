<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\PublicationInventory;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Discovery\StaticOwnershipStore;
use Cybermaps\Discovery\StaticWriteIntentStore;

final class StaticBridgeLockTest extends \WP_UnitTestCase {
	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public' => '1',
			'cybermaps_settings' => array(
				'static_engine_mode'   => 'all',
				'enable_discovery_hub' => '1',
				'llms_included_types'  => array( 'post' ),
			),
		);
		$GLOBALS['cybermaps_mock_posts'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'name' => 'post', 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array();
		$GLOBALS['cybermaps_mock_scheduled']                 = array();
		foreach ( StaticOwnershipStore::all_option_names() as $option_name ) {
			delete_option( $option_name );
		}
		delete_option( 'cybermaps_static_schedule_error' );
		unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
		$this->reset_bridge_state();
		$this->delete_test_files();
	}

	protected function tearDown(): void {
		delete_option( 'cybermaps_static_operation_lock' );
		delete_option( StaticWriteIntentStore::OPTION );
		delete_option( 'cybermaps_static_schedule_error' );
		unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
		foreach ( StaticOwnershipStore::all_option_names() as $option_name ) {
			delete_option( $option_name );
		}
		$this->reset_bridge_state();
		$this->delete_test_files();
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array();
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_heartbeat_renews_an_aged_owned_lock(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		$lock = get_option( 'cybermaps_static_operation_lock' );
		$lock['time'] = time() - 301;
		update_option( 'cybermaps_static_operation_lock', $lock, false );

		$this->assertTrue( $bridge->heartbeat() );

		$renewed = get_option( 'cybermaps_static_operation_lock' );
		$this->assertSame( $lock['token'], $renewed['token'] );
		$this->assertGreaterThan( $lock['time'], $renewed['time'] );
		$this->assertLessThanOrEqual( 2, abs( time() - (int) $renewed['time'] ) );
	}

	public function test_lost_owner_fences_writes_and_cannot_release_a_successor_lock(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		$successor = array(
			'token' => 'successor-token',
			'time'  => time(),
		);
		update_option( 'cybermaps_static_operation_lock', $successor, false );

		$this->assertFalse( $bridge->heartbeat() );
		$this->assertFalse( $bridge->write_file( 'lock-fenced.txt', 'must not be written' ) );
		$this->assertSame( 'operation_lock_lost', $bridge->get_last_write_result()['code'] );
		$this->assertFileDoesNotExist( ABSPATH . 'lock-fenced.txt' );

		$this->invoke_private( $bridge, 'release_operation_lock' );
		$this->assertSame( $successor, get_option( 'cybermaps_static_operation_lock' ) );
	}

	public function test_batched_inventory_stops_when_its_static_owner_token_is_replaced(): void {
		for ( $id = 1; $id <= 26; ++$id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array(
				'ID'                => $id,
				'post_title'        => 'Post ' . $id,
				'post_content'      => 'Literal content.',
				'post_excerpt'      => '',
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_password'     => '',
				'post_modified_gmt' => '2026-01-01 00:00:00',
				'post_date_gmt'     => '2026-01-01 00:00:00',
			);
		}

		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		update_option(
			'cybermaps_static_operation_lock',
			array( 'token' => 'successor-token', 'time' => time() ),
			false
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no longer owned its operation lock' );

		iterator_to_array(
			( new PublicationInventory(
				array( 'llms_included_types' => array( 'post' ) )
			) )->iterate_posts(),
			false
		);
	}

	public function test_production_lock_renewal_and_release_use_exact_value_cas(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		$lock = get_option( 'cybermaps_static_operation_lock' );
		$lock['time'] = time() - 301;

		$database = new class( serialize( $lock ) ) {
			public string $options = 'wp_options';
			public ?string $raw;
			public array $queries = array();

			public function __construct( string $raw ) {
				$this->raw = $raw;
			}

			public function prepare( string $query, mixed ...$args ): array {
				return array( 'query' => $query, 'args' => $args );
			}

			public function get_var( array $prepared ): ?string {
				$this->queries[] = $prepared;
				return $this->raw;
			}

			public function query( array $prepared ): int {
				$this->queries[] = $prepared;
				$args = $prepared['args'];
				if ( str_starts_with( ltrim( $prepared['query'] ), 'UPDATE' ) ) {
					if ( $this->raw !== ( $args[3] ?? null ) ) {
						return 0;
					}
					$this->raw = (string) $args[1];
					return 1;
				}
				if ( str_starts_with( ltrim( $prepared['query'] ), 'DELETE' ) ) {
					if ( $this->raw !== ( $args[2] ?? null ) ) {
						return 0;
					}
					$this->raw = null;
					return 1;
				}
				return 0;
			}
		};
		$GLOBALS['wpdb'] = $database;

		$this->assertTrue( $bridge->heartbeat() );
		$renewed = maybe_unserialize( (string) $database->raw );
		$this->assertSame( $lock['token'], $renewed['token'] );
		$this->assertGreaterThan( $lock['time'], $renewed['time'] );

		$this->invoke_private( $bridge, 'release_operation_lock' );
		$this->assertNull( $database->raw );
		$this->assertNotEmpty(
			array_filter(
				$database->queries,
				static fn( array $query ): bool => str_contains( $query['query'], 'AND BINARY option_value = BINARY %s' )
			)
		);
	}

	public function test_stale_operation_lease_requires_explicit_recovery_and_malformed_intent_is_visible(): void {
		$stale_lock = array(
			'token' => 'stale-owner',
			'time'  => time() - 3600,
		);
		$intents    = array(
			array(
				'record' => $this->valid_write_intent( 'pending-valid-intent.txt' ),
				'code'   => 'write_intent_operator_recovery_required',
				'status' => 'pending',
			),
			array(
				'record' => array(
					'schema'    => StaticWriteIntentStore::SCHEMA,
					'intent_id' => 'malformed-pending-intent',
				),
				'code'   => 'write_intent_invalid',
				'status' => 'malformed',
			),
		);

		foreach ( $intents as $case ) {
			$intent = $case['record'];
			update_option( 'cybermaps_static_operation_lock', $stale_lock, false );
			update_option( StaticWriteIntentStore::OPTION, $intent, false );

			$bridge = StaticBridge::get_instance();
			$this->assertFalse( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
			$this->assertSame( $stale_lock, get_option( 'cybermaps_static_operation_lock' ) );
			$this->assertSame( $intent, get_option( StaticWriteIntentStore::OPTION ) );
			$this->assertSame( $case['code'], get_option( 'cybermaps_static_schedule_error' )['code'] );
			$this->assertSame( $case['status'], $bridge->get_pending_intent_recovery_status()['status'] );
			$this->assertTrue( $bridge->get_pending_intent_recovery_status()['operator_action_required'] );

			delete_option( 'cybermaps_static_operation_lock' );
			delete_option( StaticWriteIntentStore::OPTION );
			delete_option( 'cybermaps_static_schedule_error' );
			$this->reset_bridge_state();
		}
	}

	public function test_confirmed_operator_recovery_distinguishes_old_new_and_conflicting_write_bodies(): void {
		$old_body = 'body before interrupted write';
		$new_body = 'body written before interruption';
		$scenarios = array(
			'old'      => array( 'body' => $old_body, 'owned' => \md5( $old_body ), 'diagnostic' => '' ),
			'new'      => array( 'body' => $new_body, 'owned' => \md5( $new_body ), 'diagnostic' => '' ),
			'conflict' => array( 'body' => 'independent body', 'owned' => \md5( $old_body ), 'diagnostic' => 'write_intent_target_conflict' ),
		);

		foreach ( $scenarios as $name => $scenario ) {
			$filename = 'operator-write-' . $name . '.txt';
			$path     = ABSPATH . $filename;
			\file_put_contents( $path, $scenario['body'] );
			$this->assertTrue( ( new StaticOwnershipStore() )->set_hash( $filename, \md5( $old_body ), 4, true ) );
			$intent = $this->valid_write_intent(
				$filename,
				true,
				\md5( $old_body ),
				\md5( $new_body ),
				4
			);
			$this->install_stale_intent( $intent );

			$bridge = StaticBridge::get_instance();
			$this->assertFalse( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
			$this->reset_bridge_state();
			$result = $this->confirmed_operator_recovery( $bridge, $intent );

			$this->assertTrue( $result['success'] );
			$this->assertSame( 'resolved', $result['code'] );
			$this->assertSame( $scenario['body'], \file_get_contents( $path ) );
			$this->assertSame( $scenario['owned'], ( new StaticOwnershipStore() )->get_hash( $filename ) );
			$this->assertNull( get_option( StaticWriteIntentStore::OPTION, null ) );
			if ( '' !== $scenario['diagnostic'] ) {
				$this->assertSame( $scenario['diagnostic'], get_option( 'cybermaps_static_schedule_error' )['code'] );
			}

			\wp_delete_file( $path );
			delete_option( 'cybermaps_static_schedule_error' );
			StaticOwnershipStore::delete_all();
			$this->reset_bridge_state();
		}
	}

	public function test_confirmed_operator_recovery_distinguishes_delete_terminal_states(): void {
		$old_body = 'owned body pending deletion';
		$new_body = 'verified successor body';
		$scenarios = array(
			'missing'   => array( 'body' => null, 'owned' => null, 'diagnostic' => '' ),
			'old'       => array( 'body' => $old_body, 'owned' => null, 'diagnostic' => '' ),
			'conflict'  => array( 'body' => 'third-party body', 'owned' => \md5( $old_body ), 'diagnostic' => 'delete_intent_target_conflict' ),
			'successor' => array( 'body' => $new_body, 'owned' => \md5( $new_body ), 'diagnostic' => 'delete_intent_superseded' ),
		);

		foreach ( $scenarios as $name => $scenario ) {
			$filename = 'operator-delete-' . $name . '.txt';
			$path     = ABSPATH . $filename;
			if ( null !== $scenario['body'] ) {
				\file_put_contents( $path, $scenario['body'] );
			}
			$initial_owned = 'successor' === $name ? \md5( $new_body ) : \md5( $old_body );
			$this->assertTrue( ( new StaticOwnershipStore() )->set_hash( $filename, $initial_owned, 7, true ) );
			$intent = $this->valid_delete_intent( $filename, \md5( $old_body ), 6 );
			$this->install_stale_intent( $intent );

			$bridge = StaticBridge::get_instance();
			$result = $this->confirmed_operator_recovery( $bridge, $intent );

			$this->assertTrue( $result['success'] );
			$this->assertSame( 'resolved', $result['code'] );
			if ( null === $scenario['body'] || 'old' === $name ) {
				$this->assertFileDoesNotExist( $path );
			} else {
				$this->assertSame( $scenario['body'], \file_get_contents( $path ) );
			}
			$this->assertSame( $scenario['owned'], ( new StaticOwnershipStore() )->get_hash( $filename ) );
			$this->assertNull( get_option( StaticWriteIntentStore::OPTION, null ) );
			if ( '' !== $scenario['diagnostic'] ) {
				$this->assertSame( $scenario['diagnostic'], get_option( 'cybermaps_static_schedule_error' )['code'] );
			}

			\wp_delete_file( $path );
			delete_option( 'cybermaps_static_schedule_error' );
			StaticOwnershipStore::delete_all();
			$this->reset_bridge_state();
		}
	}

	public function test_lease_loss_after_delete_tombstone_cannot_later_delete_a_successor_publication(): void {
		$filename = 'delete-fence-successor.txt';
		$path     = ABSPATH . $filename;
		$old_body = 'owned body selected for deletion';
		$new_body = 'successor publication that must survive';
		$stolen   = false;
		$bridge   = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( $filename, $old_body ) );

		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$stolen ): void {
			if (
				$stolen
				|| StaticWriteIntentStore::OPTION !== $option
				|| ! \array_key_exists( StaticWriteIntentStore::OPTION, $GLOBALS['cybermaps_mock_options'] )
			) {
				return;
			}
			$stolen = true;
			unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
			\update_option(
				StaticBridge::OPERATION_LOCK_OPTION,
				array(
					'token' => 'successor-delete-owner',
					'time'  => \time(),
				),
				false
			);
		};

		$result = $bridge->purge_all( '', '', 'stale', array() );
		$this->assertFalse( $result['success'] );
		$this->assertTrue( $stolen );
		$this->assertSame( $old_body, \file_get_contents( $path ) );
		$intent = \get_option( StaticWriteIntentStore::OPTION, null );
		$this->assertIsArray( $intent );
		$this->assertSame( 'delete', StaticWriteIntentStore::operation( $intent ) );

		\file_put_contents( $path, $new_body );
		$this->assertTrue( ( new StaticOwnershipStore() )->set_hash( $filename, \md5( $new_body ), 12, true ) );
		$successor_lock         = \get_option( StaticBridge::OPERATION_LOCK_OPTION );
		$successor_lock['time'] = \time() - 3600;
		\update_option( StaticBridge::OPERATION_LOCK_OPTION, $successor_lock, false );
		$this->reset_bridge_state();

		$this->assertFalse( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		$this->assertSame( $new_body, \file_get_contents( $path ) );
		$this->assertSame( \md5( $new_body ), ( new StaticOwnershipStore() )->get_hash( $filename ) );
		$this->assertSame( $intent, \get_option( StaticWriteIntentStore::OPTION, null ) );
	}

	public function test_fresh_operation_lease_can_recover_an_intent_when_no_stale_option_owner_exists(): void {
		$intent = $this->valid_write_intent( 'pending-fresh-recovery.txt' );
		update_option( StaticWriteIntentStore::OPTION, $intent, false );

		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $this->invoke_private( $bridge, 'acquire_operation_lock' ) );
		$this->assertNull( get_option( StaticWriteIntentStore::OPTION, null ) );

		$this->invoke_private( $bridge, 'release_operation_lock' );
		$this->assertNull( get_option( 'cybermaps_static_operation_lock', null ) );
	}

	public function test_operator_recovery_requires_capability_exact_confirmation_and_intent_nonce(): void {
		$intent = $this->valid_write_intent( 'operator-authorization.txt' );
		$this->install_stale_intent( $intent );
		$bridge = StaticBridge::get_instance();
		$status = $bridge->get_pending_intent_recovery_status();
		$this->assertSame( '', $status['nonce'] );
		$this->assertFalse(
			$bridge->resolve_pending_intent(
				(string) $intent['intent_id'],
				'not-a-nonce',
				StaticBridge::INTENT_RECOVERY_CONFIRMATION
			)['success']
		);
		$this->assertSame( 'forbidden', $bridge->resolve_pending_intent( '', '', '' )['code'] );

		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'manage_options' );
		$status = $bridge->get_pending_intent_recovery_status();
		$this->assertNotSame( '', $status['nonce'] );
		$this->assertSame(
			'confirmation_required',
			$bridge->resolve_pending_intent(
				(string) $intent['intent_id'],
				(string) $status['nonce'],
				'workers might be quiet'
			)['code']
		);
		$this->assertSame(
			'invalid_nonce',
			$bridge->resolve_pending_intent(
				(string) $intent['intent_id'],
				'wrong-intent-bound-nonce',
				StaticBridge::INTENT_RECOVERY_CONFIRMATION
			)['code']
		);
		$this->assertSame( $intent, get_option( StaticWriteIntentStore::OPTION ) );
	}

	private function invoke_private( StaticBridge $bridge, string $method ): mixed {
		return ( new \ReflectionMethod( StaticBridge::class, $method ) )->invoke( $bridge );
	}

	private function reset_bridge_state(): void {
		$bridge = StaticBridge::get_instance();
		foreach (
			array(
				'operation_lock_token' => null,
				'operation_generation' => null,
				'operation_lock_lost'  => false,
				'operator_recovery_intent' => null,
			) as $property => $value
		) {
			$reflection = new \ReflectionProperty( StaticBridge::class, $property );
			$reflection->setValue( $bridge, $value );
		}
		$lock = ( new \ReflectionProperty( StaticBridge::class, 'operation_lock' ) )->getValue( $bridge );
		$lock->reset_local_state();
	}

	/** @return array<string,mixed> */
	private function valid_write_intent(
		string $filename,
		bool $old_exists = false,
		string $old_hash = '',
		?string $new_hash = null,
		int $old_generation = -1
	): array {
		return array(
			'schema'         => StaticWriteIntentStore::SCHEMA,
			'operation'      => 'write',
			'intent_id'      => 'pending-intent-' . md5( $filename ),
			'filename'       => $filename,
			'old_exists'     => $old_exists,
			'old_hash'       => $old_hash,
			'old_generation' => $old_generation,
			'new_hash'       => $new_hash ?? str_repeat( 'a', 32 ),
			'generation'     => 1,
			'epoch'          => 1,
			'lease_digest'   => hash( 'sha256', 'prior-operation-owner' ),
			'created_at'     => time(),
		);
	}

	/** @return array<string,mixed> */
	private function valid_delete_intent( string $filename, string $old_hash, int $old_generation ): array {
		return array(
			'schema'         => StaticWriteIntentStore::SCHEMA,
			'operation'      => 'delete',
			'intent_id'      => 'pending-delete-' . md5( $filename ),
			'filename'       => $filename,
			'old_exists'     => true,
			'old_hash'       => $old_hash,
			'old_generation' => $old_generation,
			'new_hash'       => '',
			'generation'     => 8,
			'epoch'          => 8,
			'lease_digest'   => hash( 'sha256', 'prior-operation-owner' ),
			'created_at'     => time(),
		);
	}

	/** @param array<string,mixed> $intent */
	private function install_stale_intent( array $intent ): void {
		update_option(
			'cybermaps_static_operation_lock',
			array(
				'token' => 'hard-crashed-owner',
				'time'  => time() - 3600,
			),
			false
		);
		update_option( StaticWriteIntentStore::OPTION, $intent, false );
	}

	/**
	 * @param array<string,mixed> $intent
	 * @return array<string,mixed>
	 */
	private function confirmed_operator_recovery( StaticBridge $bridge, array $intent ): array {
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'manage_options' );
		$status = $bridge->get_pending_intent_recovery_status();
		$this->assertSame( 'pending', $status['status'] );
		$this->assertSame( $intent['intent_id'], $status['intent_id'] );
		$this->assertSame( $intent['filename'], $status['path'] );
		$this->assertSame( $intent['operation'], $status['type'] );
		$this->assertNotSame( '', $status['nonce'] );

		return $bridge->resolve_pending_intent(
			(string) $intent['intent_id'],
			(string) $status['nonce'],
			StaticBridge::INTENT_RECOVERY_CONFIRMATION
		);
	}

	private function delete_test_files(): void {
		foreach (
			array(
				'lock-fenced.txt',
				'operator-write-old.txt',
				'operator-write-new.txt',
				'operator-write-conflict.txt',
				'operator-delete-missing.txt',
				'operator-delete-old.txt',
				'operator-delete-conflict.txt',
				'operator-delete-successor.txt',
				'delete-fence-successor.txt',
			) as $filename
		) {
			\wp_delete_file( ABSPATH . $filename );
		}
	}
}
