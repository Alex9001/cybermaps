<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\TranslationRegistry;

final class TranslationRegistryTest extends \WP_UnitTestCase {
	private $previous_wpdb;
	private array $previous_globals = array();

	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		foreach (
			array(
				'cybermaps_mock_blog_stack',
				'cybermaps_mock_current_blog_id',
				'cybermaps_mock_is_multisite',
				'cybermaps_mock_options',
				'cybermaps_mock_options_by_blog',
				'cybermaps_mock_scheduled',
				'cybermaps_mock_switched_blogs',
				'cybermaps_mock_transients',
			) as $key
		) {
			$this->previous_globals[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		$GLOBALS['cybermaps_mock_is_multisite']     = false;
		$GLOBALS['cybermaps_mock_current_blog_id']  = 1;
		$GLOBALS['cybermaps_mock_blog_stack']       = array();
		$GLOBALS['cybermaps_mock_switched_blogs']   = array();
		$GLOBALS['wpdb'] = new TranslationRegistryWpdbStub(
			array(
				array( 'id' => 1, 'group_id' => 10, 'site_id' => 1, 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'en' ),
				array( 'id' => 2, 'group_id' => 10, 'site_id' => 1, 'item_id' => 200, 'item_type' => 'post', 'lang_code' => 'fr' ),
			)
		);
		$GLOBALS['cybermaps_mock_transients'] = array(
			'cybermaps_trans_1_100_post' => array( 'cached' ),
			'cybermaps_trans_1_200_post' => array( 'cached' ),
			'cybermaps_trans_1_999_post' => array( 'unrelated' ),
			'cybermaps_v7_translation_test' => '<xml>stale hreflang</xml>',
		);
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_core_transient_inventory' => array(
				'cybermaps_trans_1_100_post' => array(
					'family'  => 'translations',
					'expires' => time() + HOUR_IN_SECONDS,
				),
				'cybermaps_trans_1_200_post' => array(
					'family'  => 'translations',
					'expires' => time() + HOUR_IN_SECONDS,
				),
				'cybermaps_trans_1_999_post' => array(
					'family'  => 'unrelated',
					'expires' => time() + HOUR_IN_SECONDS,
				),
				'cybermaps_v7_translation_test' => array(
					'family'  => 'sitemap',
					'expires' => time() + HOUR_IN_SECONDS,
				),
			),
		);
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		foreach ( $this->previous_globals as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
		parent::tearDown();
	}

	public function test_update_invalidates_every_member_of_the_group(): void {
		( new TranslationRegistry() )->update_relationship( 10, 1, 100, 'en-gb' );

		$this->assertFalse( get_transient( 'cybermaps_trans_1_100_post' ) );
		$this->assertFalse( get_transient( 'cybermaps_trans_1_200_post' ) );
		$this->assertFalse( get_transient( 'cybermaps_v7_translation_test' ) );
		$this->assertSame( array( 'unrelated' ), get_transient( 'cybermaps_trans_1_999_post' ) );
	}

	public function test_delete_invalidates_remaining_group_members(): void {
		( new TranslationRegistry() )->delete_relationship( 1, 100 );

		$this->assertFalse( get_transient( 'cybermaps_trans_1_100_post' ) );
		$this->assertFalse( get_transient( 'cybermaps_trans_1_200_post' ) );
		$this->assertFalse( get_transient( 'cybermaps_v7_translation_test' ) );
	}

	public function test_group_invalidation_switches_to_remote_member_and_restores_original_site(): void {
		$GLOBALS['cybermaps_mock_is_multisite']    = true;
		$GLOBALS['cybermaps_mock_current_blog_id'] = 7;
		$GLOBALS['wpdb']->rows = array(
			array( 'id' => 1, 'group_id' => 10, 'site_id' => 7, 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'en' ),
			array( 'id' => 2, 'group_id' => 10, 'site_id' => 2, 'item_id' => 200, 'item_type' => 'post', 'lang_code' => 'fr' ),
		);
		$GLOBALS['cybermaps_mock_transients'] = array(
			'cybermaps_trans_7_100_post' => array( 'cached' ),
			'cybermaps_trans_2_200_post' => array( 'cached' ),
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_core_transient_inventory'] = array(
			'cybermaps_trans_7_100_post' => array(
				'family'  => 'translations',
				'expires' => time() + HOUR_IN_SECONDS,
			),
			'cybermaps_trans_2_200_post' => array(
				'family'  => 'translations',
				'expires' => time() + HOUR_IN_SECONDS,
			),
		);

		( new TranslationRegistry() )->update_relationship( 10, 7, 100, 'en-gb' );

		$this->assertFalse( get_transient( 'cybermaps_trans_7_100_post' ) );
		$this->assertFalse( get_transient( 'cybermaps_trans_2_200_post' ) );
		$this->assertSame( array( 2 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( 7, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_blog_stack'] );
	}

	public function test_new_group_uses_database_row_identity_instead_of_racy_max_query(): void {
		$group_id = ( new TranslationRegistry() )->update_relationship( 0, 1, 300, 'de', 'post' );

		$this->assertSame( 1000000000003, $group_id );
		$this->assertSame( $group_id, $GLOBALS['wpdb']->rows[2]['group_id'] );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $GLOBALS['wpdb']->transactions );
		$this->assertFalse( $GLOBALS['wpdb']->queried_group_max );
	}

	public function test_atomic_upsert_preserves_one_row_per_site_item_and_type(): void {
		$registry = new TranslationRegistry();
		$registry->update_relationship( 20, 1, 100, 'en-gb', 'post' );
		$registry->update_relationship( 30, 1, 100, 'de-DE-formal', 'term' );

		$post_rows = array_values(
			array_filter(
				$GLOBALS['wpdb']->rows,
				static fn( array $row ): bool => 1 === (int) $row['site_id']
					&& 100 === (int) $row['item_id']
					&& 'post' === $row['item_type']
			)
		);
		$this->assertCount( 1, $post_rows );
		$this->assertSame( 20, $post_rows[0]['group_id'] );
		$this->assertSame( 'en-GB', $post_rows[0]['lang_code'] );

		$term_rows = array_values(
			array_filter(
				$GLOBALS['wpdb']->rows,
				static fn( array $row ): bool => 1 === (int) $row['site_id']
					&& 100 === (int) $row['item_id']
					&& 'term' === $row['item_type']
			)
		);
		$this->assertCount( 1, $term_rows );
		$this->assertSame( 30, $term_rows[0]['group_id'] );
		$this->assertSame( 'de-DE-formal', $term_rows[0]['lang_code'] );
	}

	public function test_pruning_removes_only_stale_members_for_the_synced_site_and_type(): void {
		$GLOBALS['wpdb']->rows[] = array(
			'id' => 3, 'group_id' => 10, 'site_id' => 2, 'item_id' => 300, 'item_type' => 'post', 'lang_code' => 'de',
		);
		$GLOBALS['wpdb']->rows[] = array(
			'id' => 4, 'group_id' => 10, 'site_id' => 1, 'item_id' => 200, 'item_type' => 'term', 'lang_code' => 'fr',
		);

		$removed = ( new TranslationRegistry() )->prune_group_relationships( 10, 1, array( 100 ), 'post' );

		$this->assertSame( 1, $removed );
		$this->assertSame(
			array(
				'1:100:post',
				'2:300:post',
				'1:200:term',
			),
			array_map(
				static fn( array $row ): string => $row['site_id'] . ':' . $row['item_id'] . ':' . $row['item_type'],
				$GLOBALS['wpdb']->rows
			)
		);
	}

	public function test_public_reads_reject_cross_type_and_malformed_rows(): void {
		delete_transient( 'cybermaps_trans_1_100_post' );
		$GLOBALS['wpdb']->rows[] = array(
			'id' => 3, 'group_id' => 10, 'site_id' => 1, 'item_id' => 300, 'item_type' => 'term', 'lang_code' => 'de-DE',
		);
		$GLOBALS['wpdb']->rows[] = array(
			'id' => 4, 'group_id' => 10, 'site_id' => 1, 'item_id' => 400, 'item_type' => 'post', 'lang_code' => '--bad--',
		);
		$GLOBALS['wpdb']->rows[] = array(
			'id' => 5, 'group_id' => 10, 'site_id' => 2, 'item_id' => 500, 'item_type' => 'post', 'lang_code' => 'pt_BR',
		);

		$rows = ( new TranslationRegistry() )->get_translations( 1, 100, 'post' );

		$this->assertSame(
			array(
				array( 'group_id' => 10, 'site_id' => 1, 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'en' ),
				array( 'group_id' => 10, 'site_id' => 1, 'item_id' => 200, 'item_type' => 'post', 'lang_code' => 'fr' ),
				array( 'group_id' => 10, 'site_id' => 2, 'item_id' => 500, 'item_type' => 'post', 'lang_code' => 'pt-BR' ),
			),
			$rows
		);
	}

	public function test_legacy_cached_rows_are_revalidated_against_the_lookup_identity(): void {
		set_transient(
			'cybermaps_trans_1_100_post',
			array(
				'bad scalar',
				array( 'group_id' => array( 10 ), 'site_id' => 1, 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'en-US' ),
				array( 'group_id' => 10, 'site_id' => array( 1 ), 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'en-US' ),
				array( 'group_id' => 10, 'site_id' => 1, 'item_id' => array( 100 ), 'item_type' => 'post', 'lang_code' => 'en-US' ),
				array( 'group_id' => 10, 'site_id' => 1, 'item_id' => 100, 'item_type' => array( 'post' ), 'lang_code' => 'en-US' ),
				array( 'group_id' => 99, 'site_id' => 9, 'item_id' => 900, 'item_type' => 'post', 'lang_code' => 'de-DE' ),
				array( 'group_id' => 10, 'site_id' => 1, 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'en_US' ),
				array( 'group_id' => 10, 'site_id' => 2, 'item_id' => 200, 'item_type' => 'post', 'lang_code' => 'fr_FR' ),
				array( 'group_id' => 11, 'site_id' => 3, 'item_id' => 300, 'item_type' => 'post', 'lang_code' => 'it-IT' ),
			),
			HOUR_IN_SECONDS
		);

		$this->assertSame(
			array(
				array( 'group_id' => 10, 'site_id' => 1, 'item_id' => 100, 'item_type' => 'post', 'lang_code' => 'en-US' ),
				array( 'group_id' => 10, 'site_id' => 2, 'item_id' => 200, 'item_type' => 'post', 'lang_code' => 'fr-FR' ),
			),
			( new TranslationRegistry() )->get_translations( 1, 100, 'post' )
		);
	}

	public function test_invalid_language_does_not_mutate_the_registry(): void {
		$before = $GLOBALS['wpdb']->rows;

		$this->assertSame(
			0,
			( new TranslationRegistry() )->update_relationship( 10, 1, 100, 'not a tag' )
		);
		$this->assertSame( $before, $GLOBALS['wpdb']->rows );
	}

	public function test_relationship_change_fences_and_schedules_full_static_output(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'all',
		);
		$GLOBALS['cybermaps_mock_scheduled'] = array();

		( new TranslationRegistry() )->update_relationship( 10, 1, 100, 'en-GB' );

		$this->assertSame(
			1,
			$GLOBALS['cybermaps_mock_options']['cybermaps_static_generation'] ?? 0
		);
		$this->assertArrayHasKey(
			'cybermaps_bg_sync_static_files',
			$GLOBALS['cybermaps_mock_scheduled']
		);
	}

	public function test_unchanged_relationship_does_not_schedule_redundant_static_work(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'static_engine_mode' => 'all',
		);
		$GLOBALS['cybermaps_mock_scheduled'] = array();

		$this->assertSame(
			10,
			( new TranslationRegistry() )->update_relationship( 10, 1, 100, 'en' )
		);

		$this->assertArrayNotHasKey(
			'cybermaps_static_generation',
			$GLOBALS['cybermaps_mock_options']
		);
		$this->assertArrayNotHasKey(
			'cybermaps_bg_sync_static_files',
			$GLOBALS['cybermaps_mock_scheduled']
		);
		$this->assertSame(
			array( 'cached' ),
			get_transient( 'cybermaps_trans_1_100_post' )
		);
	}
}

final class TranslationRegistryWpdbStub {
	public string $base_prefix = 'wp_';
	public int $insert_id = 0;
	public bool $queried_group_max = false;

	/** @var string[] */
	public array $transactions = array();

	/** @var array<int,array<string,mixed>> */
	public array $rows;

	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			preg_match( '/%[dis]/', $query, $placeholder );
			$replacement = '%i' === ( $placeholder[0] ?? '' )
				? (string) $arg
				: ( is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'" );
			$query = (string) preg_replace( '/%[dis]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_var( string $query ) {
		if ( str_contains( $query, 'MAX(group_id)' ) ) {
			$this->queried_group_max = true;
			return max( array_column( $this->rows, 'group_id' ) ) + 1;
		}
		if ( preg_match( '/WHERE id = ([0-9]+)/', $query, $matches ) ) {
			foreach ( $this->rows as $row ) {
				if ( (int) $row['id'] === (int) $matches[1] ) {
					return str_contains( $query, 'SELECT group_id' ) ? $row['group_id'] : $row['id'];
				}
			}
			return null;
		}
		if ( str_contains( $query, 'group_id = ' ) && str_contains( $query, 'id <>' ) ) {
			preg_match( '/group_id = ([0-9]+)/', $query, $group_match );
			preg_match( '/id <> ([0-9]+)/', $query, $id_match );
			foreach ( $this->rows as $row ) {
				if (
					(int) $row['group_id'] === (int) ( $group_match[1] ?? 0 )
					&& (int) $row['id'] !== (int) ( $id_match[1] ?? 0 )
				) {
					return $row['id'];
				}
			}
			return null;
		}
		foreach ( $this->rows as $row ) {
			if ( ! $this->matches_item_query( $row, $query ) ) {
				continue;
			}
			return str_contains( $query, 'SELECT id ' ) ? $row['id'] : $row['group_id'];
		}
		return null;
	}

	public function get_results( string $query, $output = null ): array {
		if ( str_contains( $query, 'SELECT DISTINCT related.site_id' ) ) {
			preg_match( '/source\\.site_id = ([0-9]+)/', $query, $matches );
			$source_site_id = (int) ( $matches[1] ?? 0 );
			$groups = array();
			foreach ( $this->rows as $row ) {
				if ( (int) $row['site_id'] === $source_site_id && (int) $row['group_id'] > 0 ) {
					$groups[ $row['group_id'] . ':' . $row['item_type'] ] = true;
				}
			}
			$site_ids = array();
			foreach ( $this->rows as $row ) {
				if ( isset( $groups[ $row['group_id'] . ':' . $row['item_type'] ] ) ) {
					$site_ids[ (int) $row['site_id'] ] = true;
				}
			}
			return array_map(
				static fn( int $site_id ): object => (object) array( 'site_id' => $site_id ),
				array_keys( $site_ids )
			);
		}
		if ( str_contains( $query, 'SELECT DISTINCT site_id' ) ) {
			$rows = $this->rows;
			if ( preg_match( '/group_id = ([0-9]+)/', $query, $matches ) ) {
				$group_id = (int) $matches[1];
				$rows = array_filter(
					$rows,
					static fn( array $row ): bool => (int) $row['group_id'] === $group_id
				);
			}
			$site_ids = array_values(
				array_unique(
					array_map(
						static fn( array $row ): int => (int) $row['site_id'],
						$rows
					)
				)
			);
			return array_map(
				static fn( int $site_id ): object => (object) array( 'site_id' => $site_id ),
				$site_ids
			);
		}

		preg_match( '/group_id = ([0-9]+)/', $query, $matches );
		$group_id = (int) ( $matches[1] ?? 0 );
		preg_match( "/item_type = '([^']+)'/", $query, $type_matches );
		$type = (string) ( $type_matches[1] ?? '' );
		preg_match( '/AND site_id = ([0-9]+)/', $query, $site_matches );
		$site_id = (int) ( $site_matches[1] ?? 0 );
		$rows = array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => (int) $row['group_id'] === $group_id
					&& ( '' === $type || (string) $row['item_type'] === $type )
					&& ( $site_id < 1 || (int) $row['site_id'] === $site_id )
			)
		);
		usort(
			$rows,
			static fn( array $left, array $right ): int => (int) $left['id'] <=> (int) $right['id']
		);
		if ( preg_match( '/LIMIT ([0-9]+)/', $query, $limit_match ) ) {
			$rows = array_slice( $rows, 0, (int) $limit_match[1] );
		}

		return ARRAY_A === $output
			? $rows
			: array_map( static fn( array $row ): object => (object) $row, $rows );
	}

	public function query( string $query ) {
		$query = trim( $query );
		if ( in_array( $query, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			$this->transactions[] = $query;
			return 1;
		}

		if ( str_starts_with( $query, 'INSERT INTO' ) ) {
			preg_match(
				"/VALUES \\(([0-9]+), ([0-9]+), ([0-9]+), '([^']+)', '([^']+)'\\)/",
				$query,
				$matches
			);
			if ( empty( $matches ) ) {
				return false;
			}

			$group_id = (int) $matches[1];
			$site_id = (int) $matches[2];
			$item_id = (int) $matches[3];
			$type = $matches[4];
			$lang = $matches[5];

			foreach ( $this->rows as &$row ) {
				if (
					(int) $row['site_id'] === $site_id
					&& (int) $row['item_id'] === $item_id
					&& (string) $row['item_type'] === $type
				) {
					$this->insert_id = (int) $row['id'];
					$previous_group = (int) $row['group_id'];
					$previous_lang  = (string) $row['lang_code'];
					if ( str_contains( $query, 'group_id = VALUES(group_id)' ) ) {
						$row['group_id'] = $group_id;
					}
					$row['lang_code'] = $lang;
					unset( $row );
					return $previous_group === $group_id && $previous_lang === $lang
						? 0
						: 2;
				}
			}
			unset( $row );

			$this->insert_id = empty( $this->rows )
				? 1
				: max( array_column( $this->rows, 'id' ) ) + 1;
			$this->rows[] = array(
				'id'        => $this->insert_id,
				'group_id'  => $group_id,
				'site_id'   => $site_id,
				'item_id'   => $item_id,
				'item_type' => $type,
				'lang_code' => $lang,
			);
			return 1;
		}

		if ( str_starts_with( $query, 'UPDATE' ) ) {
			preg_match( '/SET group_id = ([0-9]+)/', $query, $group_match );
			preg_match( '/WHERE id = ([0-9]+)/', $query, $id_match );
			foreach ( $this->rows as &$row ) {
				if (
					(int) $row['id'] === (int) ( $id_match[1] ?? 0 )
					&& 0 === (int) $row['group_id']
				) {
					$row['group_id'] = (int) ( $group_match[1] ?? 0 );
					unset( $row );
					return 1;
				}
			}
			unset( $row );
			return 0;
		}

		return false;
	}

	public function delete( string $table, array $where, array $formats ): int {
		unset( $table, $formats );
		$before = count( $this->rows );
		$this->rows = array_values(
			array_filter(
				$this->rows,
				static function ( array $row ) use ( $where ): bool {
					foreach ( $where as $key => $value ) {
						if ( (string) $row[ $key ] !== (string) $value ) {
							return true;
						}
					}
					return false;
				}
			)
		);
		return $before - count( $this->rows );
	}

	private function matches_item_query( array $row, string $query ): bool {
		preg_match( '/site_id = ([0-9]+)/', $query, $site );
		preg_match( '/item_id = ([0-9]+)/', $query, $item );
		preg_match( "/item_type = '([^']+)'/", $query, $type );
		return (int) $row['site_id'] === (int) ( $site[1] ?? 0 )
			&& (int) $row['item_id'] === (int) ( $item[1] ?? 0 )
			&& (string) $row['item_type'] === (string) ( $type[1] ?? '' );
	}
}
