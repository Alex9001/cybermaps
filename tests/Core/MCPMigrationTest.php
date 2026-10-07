<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\MCPMigration;
use Cybermaps\Core\OptionLeaseLock;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/mcp-migration-db.php';

final class MCPMigrationTest extends TestCase {
	private \CybermapsMCPMigrationDatabase $db;
	private array $actions;
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		$GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_mcp_retired' => '1', 'cybermaps_settings' => $this->legacy() );
		$GLOBALS['cybermaps_mock_scheduled'] = array( 'cybermaps_mcp_run_task' => 100 );
		$this->actions = $GLOBALS['cybermaps_mock_action_callbacks'] ?? array();
		$GLOBALS['cybermaps_mock_action_callbacks'] = array();
		$this->db = \cybermaps_mock_enable_mcp_migration_database();
		ConfigurationStore::reset_memo();
	}
	protected function tearDown(): void {
		\cybermaps_mock_disable_static_ownership_database();
		$GLOBALS['cybermaps_mock_action_callbacks'] = $this->actions;
		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		ConfigurationStore::reset_memo();
		parent::tearDown();
	}
	private function legacy(): array {
		return array( 'mcp_mode' => 'read_only', 'agent_registration_mode' => 'off', 'enable_llms_full' => '1', 'sitemap_posts_per_page' => 123 );
	}
	private function is_settings_write( array $query ): bool {
		return str_starts_with( $query['query'], 'UPDATE ' ) && ( $query['args'][2] ?? null ) === 'cybermaps_settings';
	}
	private function assert_retryable(): void {
		self::assertSame( '1', get_option( MCPMigration::DONE_OPTION ) );
		self::assertSame( 100, wp_next_scheduled( 'cybermaps_mcp_run_task' ) );
		self::assertContains( 'wp_cybermaps_mcp_tasks', $this->db->existing_tables );
	}
	private function cleanup_only(): bool {
		$lock = new OptionLeaseLock( 'cybermaps_mcp_retirement_lock', 300, 30, true );
		self::assertTrue( $lock->acquire() );
		try { return ( new \ReflectionMethod( MCPMigration::class, 'remove_settings' ) )->invoke( null, $lock ); }
		finally { $lock->release(); }
	}
	public function test_conflicting_authorized_opt_out_is_preserved_and_cleanup_can_retry(): void {
		$winner = array( 'enable_llms_full' => '0', 'enable_mcp_adapter' => '0', 'sitemap_posts_per_page' => 777 );
		$notifications = 0;
		$GLOBALS['cybermaps_mock_action_callbacks']['updated_option'][] = static function ( string $name ) use ( &$notifications ): void { if ( 'cybermaps_settings' === $name ) { ++$notifications; } };
		$this->db->sql->before_query = function ( $db, array $query ) use ( $winner ): void {
			if ( $this->is_settings_write( $query ) ) { $db->before_query = null; $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $winner; }
		};
		( new \ReflectionProperty( ConfigurationStore::class, 'settings_memo' ) )->setValue( null, $this->legacy() );
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $winner, get_option( 'cybermaps_settings' ) );
		self::assertSame( $winner, ConfigurationStore::settings() );
		self::assertNull( ( new \ReflectionProperty( ConfigurationStore::class, 'settings_memo' ) )->getValue() );
		self::assertSame( 0, $notifications );
		$this->assert_retryable();
		self::assertTrue( MCPMigration::run() );
		self::assertSame( $winner, get_option( 'cybermaps_settings' ) );
	}
	public function test_owned_write_notifies_once_and_preserves_explicit_canonical_opt_out(): void {
		$old = $this->legacy() + array( 'enable_mcp_adapter' => '0' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $old;
		$observed = array();
		$GLOBALS['cybermaps_mock_action_callbacks']['updated_option'][] = static function ( $name, $before, $after ) use ( &$observed ): void { if ( 'cybermaps_settings' === $name ) { $observed[] = array( $before, $after ); } };
		self::assertTrue( $this->cleanup_only() );
		$next = array( 'enable_llms_full' => '1', 'sitemap_posts_per_page' => 123, 'enable_mcp_adapter' => '0' );
		self::assertSame( $next, get_option( 'cybermaps_settings' ) );
		self::assertSame( array( array( $old, $next ) ), $observed );
		self::assertTrue( $this->cleanup_only() );
		self::assertCount( 1, $observed );
	}
	public function test_authoritative_read_bypasses_a_stale_settings_memo(): void {
		$winner = array( 'enable_llms_full' => '0', 'enable_mcp_adapter' => '0' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $winner;
		( new \ReflectionProperty( ConfigurationStore::class, 'settings_memo' ) )->setValue( null, $this->legacy() );
		self::assertTrue( $this->cleanup_only() );
		self::assertSame( $winner, get_option( 'cybermaps_settings' ) );
		self::assertNull( ( new \ReflectionProperty( ConfigurationStore::class, 'settings_memo' ) )->getValue() );
	}
	public function test_raw_read_failure_prevents_all_retirement(): void {
		$this->db->sql->failure = static fn( $db, array $query ): bool => str_starts_with( $query['query'], 'SELECT option_value' ) && 'cybermaps_settings' === ( $query['args'][1] ?? null );
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $this->legacy(), get_option( 'cybermaps_settings' ) );
		$this->assert_retryable();
	}
	public function test_consent_version_read_failure_prevents_all_retirement(): void {
		$this->db->sql->failure = static fn( $db, array $query ): bool => str_starts_with( $query['query'], 'SELECT option_value' ) && MCPMigration::DONE_OPTION === ( $query['args'][1] ?? null );
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $this->legacy(), get_option( 'cybermaps_settings' ) );
		$this->assert_retryable();
	}
	public function test_write_failure_preserves_authority_and_retirement_state(): void {
		$this->db->sql->failure = fn( $db, array $query ): bool => $this->is_settings_write( $query );
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $this->legacy(), get_option( 'cybermaps_settings' ) );
		$this->assert_retryable();
	}
	public function test_reconnection_at_cas_cannot_mutate_or_retire_data(): void {
		$this->db->sql->before_query = function ( $db, array $query ): void { if ( $this->is_settings_write( $query ) ) { $db->before_query = null; $db->reconnect( 99 ); } };
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $this->legacy(), get_option( 'cybermaps_settings' ) );
		$this->assert_retryable();
	}
	public function test_lease_loss_after_owned_write_prevents_retirement_and_notification(): void {
		$notifications = 0;
		$GLOBALS['cybermaps_mock_action_callbacks']['updated_option'][] = static function ( $name ) use ( &$notifications ): void { if ( 'cybermaps_settings' === $name ) { ++$notifications; } };
		$this->db->sql->after_query = function ( $db, array $query ): void { if ( $this->is_settings_write( $query ) ) { $db->after_query = null; $db->reconnect( 99 ); } };
		self::assertFalse( MCPMigration::run() );
		self::assertSame( array( 'enable_llms_full' => '1', 'sitemap_posts_per_page' => 123, 'enable_mcp_adapter' => '1' ), get_option( 'cybermaps_settings' ) );
		self::assertSame( 0, $notifications );
		$this->assert_retryable();
	}
	public function test_failed_post_write_read_never_marks_cleanup_complete(): void {
		$reads = 0;
		$this->db->sql->failure = static function ( $db, array $query ) use ( &$reads ): bool {
			return str_starts_with( $query['query'], 'SELECT option_value' ) && 'cybermaps_settings' === ( $query['args'][1] ?? null ) && ++$reads > 1;
		};
		self::assertFalse( MCPMigration::run() );
		self::assertSame( array( 'enable_llms_full' => '1', 'sitemap_posts_per_page' => 123, 'enable_mcp_adapter' => '1' ), get_option( 'cybermaps_settings' ) );
		$this->assert_retryable();
	}
	public function test_post_write_competitor_is_retained_without_notifications_or_done(): void {
		$winner = array( 'enable_mcp_adapter' => '0', 'enable_llms_full' => '0' );
		$notifications = 0;
		$GLOBALS['cybermaps_mock_action_callbacks']['updated_option'][] = static function ( $name ) use ( &$notifications ): void { if ( 'cybermaps_settings' === $name ) { ++$notifications; } };
		$this->db->sql->after_query = function ( $db, array $query ) use ( $winner ): void { if ( $this->is_settings_write( $query ) ) { $db->after_query = null; $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $winner; } };
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $winner, get_option( 'cybermaps_settings' ) );
		self::assertSame( 0, $notifications );
		$this->assert_retryable();
	}
	public function test_option_observer_change_is_not_rolled_back_or_marked_complete(): void {
		$winner = array( 'enable_mcp_adapter' => '0', 'enable_llms_full' => '0' );
		$GLOBALS['cybermaps_mock_action_callbacks']['updated_option'][] = static function ( $name ) use ( $winner ): void { if ( 'cybermaps_settings' === $name ) { $GLOBALS['cybermaps_mock_options'][$name] = $winner; } };
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $winner, get_option( 'cybermaps_settings' ) );
		$this->assert_retryable();
	}
	public function test_absence_and_empty_array_remain_distinct_without_replacement(): void {
		unset( $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		self::assertTrue( $this->cleanup_only() );
		self::assertArrayNotHasKey( 'cybermaps_settings', $GLOBALS['cybermaps_mock_options'] );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array();
		self::assertTrue( $this->cleanup_only() );
		self::assertSame( array(), get_option( 'cybermaps_settings' ) );
		self::assertCount( 0, array_filter( $this->db->sql->queries, fn( array $query ): bool => $this->is_settings_write( $query ) ) );
	}
	public function test_empty_string_scalar_and_corrupt_roots_are_not_replaced(): void {
		foreach ( array( '', 'broken', 42, false, 'a:bad' ) as $value ) {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $value;
			self::assertFalse( MCPMigration::run() );
			self::assertSame( $value, get_option( 'cybermaps_settings' ) );
			$this->assert_retryable();
		}
	}
	public function test_concurrent_insertion_into_absent_settings_is_preserved(): void {
		unset( $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] );
		$winner = array( 'enable_mcp_adapter' => '0', 'enable_llms_full' => '0' );
		$this->db->sql->after_query = static function ( $db, array $query ) use ( $winner ): void {
			if ( str_starts_with( $query['query'], 'SELECT option_value' ) && 'cybermaps_settings' === ( $query['args'][1] ?? null ) ) { $db->after_query = null; $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $winner; }
		};
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $winner, get_option( 'cybermaps_settings' ) );
		$this->assert_retryable();
	}
	public function test_noop_concurrent_write_is_detected_before_retirement(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array();
		$winner = array( 'enable_mcp_adapter' => '0', 'mcp_mode' => 'operations' );
		$this->db->sql->after_query = static function ( $db, array $query ) use ( $winner ): void {
			if ( str_starts_with( $query['query'], 'SELECT option_value' ) && 'cybermaps_settings' === ( $query['args'][1] ?? null ) ) { $db->after_query = null; $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $winner; }
		};
		self::assertFalse( MCPMigration::run() );
		self::assertSame( $winner, get_option( 'cybermaps_settings' ) );
		$this->assert_retryable();
	}
}
