<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\NetworkSetup;

final class NetworkSetupTest extends \WP_UnitTestCase {
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		( new \ReflectionProperty( NetworkSetup::class, 'upgrade_lock' ) )->setValue( null, null );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new NetworkSetupWpdbStub(
			array(
				array( 'id' => 1, 'group_id' => 10, 'site_id' => 1, 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'en' ),
				array( 'id' => 2, 'group_id' => 20, 'site_id' => 1, 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'fr' ),
				array( 'id' => 3, 'group_id' => 30, 'site_id' => 1, 'item_id' => 100, 'item_type' => 'term', 'lang_code' => 'fr' ),
			)
		);
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_version'] = '1';
		unset( $GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_upgrade_state'] );
		unset( $GLOBALS['cybermaps_mock_scheduled'][ NetworkSetup::RETRY_HOOK ] );
		$GLOBALS['cybermaps_mock_dbdelta_queries'] = array();
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function ( string $queries ): array {
			if ( str_contains( $queries, 'UNIQUE KEY site_item_type (site_id, item_id, item_type)' ) ) {
				$GLOBALS['wpdb']->has_unique_index = true;
			}
			return array( $queries );
		};
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_dbdelta_callback'],
			$GLOBALS['cybermaps_mock_dbdelta_queries']
		);
		( new \ReflectionProperty( NetworkSetup::class, 'upgrade_lock' ) )->setValue( null, null );
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_upgrade_deduplicates_legacy_rows_and_installs_unique_contract(): void {
		NetworkSetup::maybe_upgrade();

		$this->assertSame( '3', get_site_option( 'cybermaps_translation_schema_version' ) );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows );
		$this->assertSame(
			array( 2, 3 ),
			array_column( $GLOBALS['wpdb']->rows, 'id' ),
			'The newest duplicate post row and the distinct item_type row should remain.'
		);
		$this->assertTrue( $GLOBALS['wpdb']->has_unique_index );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_dbdelta_queries'] );
		$this->assertStringContainsString(
			'UNIQUE KEY site_item_type (site_id, item_id, item_type)',
			$GLOBALS['cybermaps_mock_dbdelta_queries'][0]
		);
		$this->assertStringContainsString( 'lang_code varchar(35) NOT NULL', $GLOBALS['cybermaps_mock_dbdelta_queries'][0] );
	}

	public function test_current_schema_version_skips_database_work(): void {
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_version'] = '3';

		NetworkSetup::maybe_upgrade();

		$this->assertSame( 0, $GLOBALS['wpdb']->query_count );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_dbdelta_queries'] );
	}

	public function test_new_install_creates_schema_without_running_legacy_deduplication(): void {
		$GLOBALS['wpdb']->table_exists = false;
		$GLOBALS['wpdb']->rows = array();

		$this->assertTrue( NetworkSetup::create_tables() );
		$this->assertSame( 0, $GLOBALS['wpdb']->query_count );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_dbdelta_queries'] );
	}

	public function test_failed_unique_index_install_is_retried_later(): void {
		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );

		$this->assertFalse( NetworkSetup::create_tables() );
		$this->assertSame( '1', get_site_option( 'cybermaps_translation_schema_version' ) );
	}

	public function test_future_schema_and_its_retry_evidence_are_never_downgraded_or_cleaned(): void {
		$future_state = array(
			'target'     => '4',
			'attempts'   => 2,
			'next_retry' => time() + 900,
			'last_error' => 'Future schema worker checkpoint.',
			'updated_at' => time(),
		);
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_version']       = '4';
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_upgrade_state'] = $future_state;
		$retry = time() + 900;
		$GLOBALS['cybermaps_mock_scheduled'][ NetworkSetup::RETRY_HOOK ] = $retry;

		$this->assertTrue( NetworkSetup::maybe_upgrade() );
		$this->assertTrue( NetworkSetup::maybe_upgrade( true ) );
		$this->assertSame( '4', get_site_option( 'cybermaps_translation_schema_version' ) );
		$this->assertSame( $future_state, get_site_option( 'cybermaps_translation_schema_upgrade_state' ) );
		$this->assertSame( $retry, wp_next_scheduled( NetworkSetup::RETRY_HOOK ) );
		$this->assertSame( 0, $GLOBALS['wpdb']->query_count );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_dbdelta_queries'] );
	}

	public function test_current_schema_does_not_clean_a_future_target_checkpoint(): void {
		$future_state = array(
			'target'     => '4',
			'attempts'   => 1,
			'next_retry' => 0,
			'last_error' => 'Schema four is in progress.',
			'updated_at' => time(),
		);
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_version']       = '3';
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_upgrade_state'] = $future_state;
		$retry = time() + 300;
		$GLOBALS['cybermaps_mock_scheduled'][ NetworkSetup::RETRY_HOOK ] = $retry;

		$this->assertFalse( NetworkSetup::maybe_upgrade() );
		$this->assertFalse( NetworkSetup::maybe_upgrade( true ) );
		$this->assertSame( $future_state, get_site_option( 'cybermaps_translation_schema_upgrade_state' ) );
		$this->assertSame( $retry, wp_next_scheduled( NetworkSetup::RETRY_HOOK ) );
		$this->assertSame( 0, $GLOBALS['wpdb']->query_count );
	}

	public function test_forced_activation_bypasses_only_the_current_targets_backoff(): void {
		$current_state = array(
			'target'     => '3',
			'attempts'   => 2,
			'next_retry' => time() + 900,
			'last_error' => 'Earlier schema attempt failed.',
			'updated_at' => time(),
		);
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_upgrade_state'] = $current_state;
		$GLOBALS['cybermaps_mock_scheduled'][ NetworkSetup::RETRY_HOOK ] = $current_state['next_retry'];

		$this->assertFalse( NetworkSetup::maybe_upgrade() );
		$this->assertSame( 0, $GLOBALS['wpdb']->query_count );

		$this->assertTrue( NetworkSetup::maybe_upgrade( true ) );
		$this->assertSame( '3', get_site_option( 'cybermaps_translation_schema_version' ) );
		$this->assertFalse( get_site_option( 'cybermaps_translation_schema_upgrade_state', false ) );
		$this->assertFalse( wp_next_scheduled( NetworkSetup::RETRY_HOOK ) );
		$this->assertGreaterThan( 0, $GLOBALS['wpdb']->query_count );
	}

	public function test_retry_entry_resumes_a_due_current_target_checkpoint(): void {
		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );

		$this->assertFalse( NetworkSetup::maybe_upgrade( true ) );
		$failed_state = get_site_option( 'cybermaps_translation_schema_upgrade_state', array() );
		$this->assertSame( '3', $failed_state['target'] ?? '' );
		$this->assertSame( 1, $failed_state['attempts'] ?? 0 );
		$this->assertNotFalse( wp_next_scheduled( NetworkSetup::RETRY_HOOK ) );

		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function ( string $queries ): array {
			if ( str_contains( $queries, 'UNIQUE KEY site_item_type (site_id, item_id, item_type)' ) ) {
				$GLOBALS['wpdb']->has_unique_index = true;
			}
			return array( $queries );
		};
		$failed_state['next_retry'] = 0;
		update_site_option( 'cybermaps_translation_schema_upgrade_state', $failed_state );

		$this->assertTrue( NetworkSetup::maybe_upgrade() );
		$this->assertSame( '3', get_site_option( 'cybermaps_translation_schema_version' ) );
		$this->assertFalse( get_site_option( 'cybermaps_translation_schema_upgrade_state', false ) );
		$this->assertFalse( wp_next_scheduled( NetworkSetup::RETRY_HOOK ) );
	}
}

final class NetworkSetupWpdbStub {
	public string $base_prefix = 'wp_';
	public bool $has_unique_index = false;
	public bool $table_exists = true;
	public int $query_count = 0;

	/** @var array<int,array<string,mixed>> */
	public array $rows;

	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = (string) preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 );
		}
		return $query;
	}

	public function get_var( string $query, int $column = 0 ) {
		unset( $column );
		if ( str_starts_with( $query, 'SHOW TABLES LIKE' ) ) {
			return $this->table_exists ? 'wp_cybermaps_translations' : null;
		}
		if ( str_starts_with( $query, 'SHOW INDEX FROM' ) ) {
			return $this->has_unique_index ? 'site_item_type' : null;
		}
		return null;
	}

	public function get_results( string $query ): array {
		if ( ! str_contains( $query, 'SELECT DISTINCT site_id' ) ) {
			return array();
		}

		return array_map(
			static fn( int $site_id ): object => (object) array( 'site_id' => $site_id ),
			array_values(
				array_unique(
					array_map(
						static fn( array $row ): int => (int) $row['site_id'],
						$this->rows
					)
				)
			)
		);
	}

	public function query( string $query ) {
		++$this->query_count;
		if ( ! str_starts_with( ltrim( $query ), 'DELETE older' ) ) {
			return 1;
		}

		$newest = array();
		foreach ( $this->rows as $row ) {
			$key = $row['site_id'] . ':' . $row['item_id'] . ':' . $row['item_type'];
			if ( ! isset( $newest[ $key ] ) || (int) $row['id'] > (int) $newest[ $key ]['id'] ) {
				$newest[ $key ] = $row;
			}
		}
		$deleted = count( $this->rows ) - count( $newest );
		$this->rows = array_values( $newest );
		usort(
			$this->rows,
			static fn( array $left, array $right ): int => (int) $left['id'] <=> (int) $right['id']
		);
		return $deleted;
	}
}
