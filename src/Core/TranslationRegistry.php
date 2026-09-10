<?php
declare(strict_types=1);
namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TranslationRegistry {
	/**
	 * Keep generated group IDs in a collision-resistant namespace while
	 * remaining exactly representable by browsers and JavaScript.
	 */
	private const GENERATED_GROUP_OFFSET = 1000000000000;

	/**
	 * A language relationship set is expected to be small. Bound public reads
	 * so a corrupt or manually overloaded group cannot make one sitemap row
	 * consume unbounded memory.
	 */
	private const MAX_PUBLIC_GROUP_MEMBERS = 100;


	/**
	 * Get all translations in the same group as the given item.
	 *
	 * @param int    $site_id Site ID.
	 * @param int    $item_id Item ID (e.g. Post ID).
	 * @param string $type    Item type (post, term, etc).
	 * @return array List of translations.
	 */
	public function get_translations( $site_id, $item_id, $type = 'post' ) {
		$site_id = is_scalar( $site_id ) ? max( 0, (int) $site_id ) : 0;
		$item_id = is_scalar( $item_id ) ? max( 0, (int) $item_id ) : 0;
		$type    = is_scalar( $type )
			? substr( sanitize_key( (string) $type ), 0, 20 )
			: '';
		if ( $site_id < 1 || $item_id < 1 || '' === $type ) {
			return array();
		}

		$cache_key = "cybermaps_trans_{$site_id}_{$item_id}_{$type}";
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $this->normalize_translation_rows(
				$cached,
				0,
				$type,
				$site_id,
				$item_id
			);
		}

		global $wpdb;
		$table = $wpdb->base_prefix . 'cybermaps_translations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Translation relationships require exact reads from the shared registry; public group reads are bounded and cached below.
		$group_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT group_id FROM %i WHERE site_id = %d AND item_id = %d AND item_type = %s',
				$table,
				$site_id,
				$item_id,
				$type
			)
		);
		if ( ! $group_id ) {
			if ( empty( $wpdb->last_error ) ) {
				CacheManager::set( $cache_key, array(), 12 * HOUR_IN_SECONDS, 'translations' );
			}
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT group_id, site_id, item_id, item_type, lang_code
            FROM %i
            WHERE group_id = %d
                AND item_type = %s
                AND site_id > 0
                AND item_id > 0
            ORDER BY (site_id = %d AND item_id = %d) DESC, id ASC
				LIMIT %d',
				$table,
				$group_id,
				$type,
				$site_id,
				$item_id,
				self::MAX_PUBLIC_GROUP_MEMBERS
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $results ) ) {
			return array();
		}

		$normalized = $this->normalize_translation_rows( $results, (int) $group_id, $type );

		CacheManager::set( $cache_key, $normalized, 12 * HOUR_IN_SECONDS, 'translations' );
		return $normalized;
	}

	/**
	 * Update or create a translation relationship.
	 *
	 * @param int    $group_id Group ID. If 0, a new group will be created or an existing one found.
	 * @param int    $site_id  Site ID.
	 * @param int    $item_id  Item ID.
	 * @param string $lang     Language code.
	 * @param string $type     Item type.
	 * @return int The group ID.
	 */
	public function update_relationship( $group_id, $site_id, $item_id, $lang, $type = 'post' ) {
		global $wpdb;
		$table    = $wpdb->base_prefix . 'cybermaps_translations';
		$group_id = $this->positive_scalar( $group_id );
		$site_id  = $this->positive_scalar( $site_id );
		$item_id  = $this->positive_scalar( $item_id );
		$type     = $this->translation_type( $type );
		$lang     = TranslationHelper::normalize_hreflang( $lang );

		if ( $site_id < 1 || $item_id < 1 || '' === $type || '' === $lang ) {
			return 0;
		}

		// Capture the previous group so moving an item invalidates both sides.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The previous group must be read exactly before the relationship write so both affected cache families can be invalidated.
		$previous_group_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT group_id FROM %i WHERE site_id = %d AND item_id = %d AND item_type = %s',
				$table,
				$site_id,
				$item_id,
				$type
			)
		);
		// phpcs:enable

		if ( $group_id < 1 ) {
			$group_id = $previous_group_id;
		}

		if ( $group_id > 0 ) {
			$write_result = $this->upsert_relationship(
				$table,
				$group_id,
				$site_id,
				$item_id,
				$type,
				$lang
			);
			if ( null === $write_result ) {
				return 0;
			}
			$saved                = true;
			$relationship_changed = $write_result;
		} else {
			$group_id             = $this->create_group_relationship(
				$table,
				$site_id,
				$item_id,
				$type,
				$lang
			);
			$saved                = $group_id > 0;
			$relationship_changed = $saved;
		}

		if ( ! $saved ) {
			return 0;
		}

		if ( $relationship_changed ) {
			$affected_site_ids = array( $site_id );
			foreach ( array_unique( array_filter( array( $previous_group_id, (int) $group_id ) ) ) as $affected_group_id ) {
				$affected_site_ids = array_merge(
					$affected_site_ids,
					$this->get_group_site_ids( (int) $affected_group_id, $type )
				);
			}
			$this->invalidate_sites( $affected_site_ids );
		}

		return (int) $group_id;
	}

	/**
	 * Atomically insert or update one registry identity.
	 */
	private function upsert_relationship(
		string $table,
		int $group_id,
		int $site_id,
		int $item_id,
		string $type,
		string $lang
	): ?bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The registry requires an atomic upsert that the WordPress database helpers do not expose.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i
                    (group_id, site_id, item_id, item_type, lang_code)
                VALUES (%d, %d, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                    group_id = VALUES(group_id),
					lang_code = VALUES(lang_code)',
				$table,
				$group_id,
				$site_id,
				$item_id,
				$type,
				$lang
			)
		);
		// phpcs:enable

		return false === $result ? null : $result > 0;
	}

	/**
	 * Create a group without using MAX(group_id), which races under concurrency.
	 *
	 * The unique site/item/type key converges simultaneous saves on one row.
	 * LAST_INSERT_ID(id) returns that row's stable identity for both the insert
	 * and duplicate-key paths.
	 */
	private function create_group_relationship(
		string $table,
		int $site_id,
		int $item_id,
		string $type,
		string $lang
	): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional insert/update coordination must observe exact registry state.
		$transaction_started = false !== $wpdb->query( 'START TRANSACTION' );
		$inserted            = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i
                    (group_id, site_id, item_id, item_type, lang_code)
                VALUES (0, %d, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
					lang_code = VALUES(lang_code)',
				$table,
				$site_id,
				$item_id,
				$type,
				$lang
			)
		);

		if ( false === $inserted ) {
			$this->finish_transaction( $transaction_started, false );
			return 0;
		}

		$row_id = (int) ( $wpdb->insert_id ?? 0 );
		if ( $row_id < 1 ) {
			$row_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i
					WHERE site_id = %d AND item_id = %d AND item_type = %s',
					$table,
					$site_id,
					$item_id,
					$type
				)
			);
		}

		if ( $row_id < 1 ) {
			$this->finish_transaction( $transaction_started, false );
			return 0;
		}

		$group_id = $this->generated_group_id( $table, $row_id );
		if ( $group_id < 1 ) {
			$this->finish_transaction( $transaction_started, false );
			return 0;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
                SET group_id = %d
				WHERE id = %d AND group_id = 0',
				$table,
				$group_id,
				$row_id
			)
		);
		if ( false === $updated ) {
			$this->finish_transaction( $transaction_started, false );
			return 0;
		}

		$stored_group_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT group_id FROM %i WHERE id = %d',
				$table,
				$row_id
			)
		);
		if ( $stored_group_id < 1 ) {
			$this->finish_transaction( $transaction_started, false );
			return 0;
		}

		$this->finish_transaction( $transaction_started, true );
		// phpcs:enable

		return $stored_group_id;
	}

	/**
	 * Derive a new group ID from the database-assigned row ID.
	 */
	private function generated_group_id( string $table, int $row_id ): int {
		global $wpdb;

		for ( $namespace = 1; $namespace <= 8; ++$namespace ) {
			$candidate = ( self::GENERATED_GROUP_OFFSET * $namespace ) + $row_id;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Collision detection must observe the exact shared registry state.
			$collision = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i
                    WHERE group_id = %d AND id <> %d
					LIMIT 1',
					$table,
					$candidate,
					$row_id
				)
			);
			// phpcs:enable

			if ( $collision < 1 ) {
				return $candidate;
			}
		}

		return 0;
	}

	/**
	 * Commit or roll back an optional transaction.
	 */
	private function finish_transaction( bool $started, bool $commit ): void {
		if ( ! $started ) {
			return;
		}

		global $wpdb;
		if ( $commit ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed transaction-control statement; there are no values to prepare.
			$wpdb->query( 'COMMIT' );
		} else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed transaction-control statement; there are no values to prepare.
			$wpdb->query( 'ROLLBACK' );
		}
	}

	/**
	 * Delete a single translation relationship.
	 *
	 * @param int    $site_id Site ID.
	 * @param int    $item_id Item ID.
	 * @param string $type    Item type.
	 * @return bool True on success, false on failure.
	 */
	public function delete_relationship( $site_id, $item_id, $type = 'post' ) {
		$site_id = is_scalar( $site_id ) ? max( 0, (int) $site_id ) : 0;
		$item_id = is_scalar( $item_id ) ? max( 0, (int) $item_id ) : 0;
		$type    = is_scalar( $type )
			? substr( sanitize_key( (string) $type ), 0, 20 )
			: '';
		if ( $site_id < 1 || $item_id < 1 || '' === $type ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->base_prefix . 'cybermaps_translations';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Deletion must read the exact prior group before changing the shared relationship table.
		$group_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT group_id FROM %i WHERE site_id = %d AND item_id = %d AND item_type = %s',
				$table,
				$site_id,
				$item_id,
				$type
			)
		);
		$result   = $wpdb->delete(
			$table,
			array(
				'site_id'   => $site_id,
				'item_id'   => $item_id,
				'item_type' => $type,
			),
			array( '%d', '%d', '%s' )
		);
		// phpcs:enable

		if ( $result ) {
			$affected_site_ids = array( $site_id );
			if ( $group_id > 0 ) {
				$affected_site_ids = array_merge(
					$affected_site_ids,
					$this->get_group_site_ids( $group_id, $type )
				);
			}
			$this->invalidate_sites( $affected_site_ids );
		}

		return false !== $result;
	}

	/**
	 * Remove same-site group members no longer reported by a translation plugin.
	 *
	 * Relationships on other sites and for other item types are intentionally
	 * preserved so automatic plugin sync cannot erase manual cross-site groups.
	 *
	 * @param int   $group_id Group being synchronized.
	 * @param int   $site_id  Site whose provider supplied the current inventory.
	 * @param int[] $item_ids Item IDs still present in that provider inventory.
	 * @param string $type    Item type.
	 * @return int Number of stale rows removed.
	 */
	public function prune_group_relationships(
		int $group_id,
		int $site_id,
		array $item_ids,
		string $type = 'post'
	): int {
		if ( $group_id < 1 || $site_id < 1 ) {
			return 0;
		}

		$type = substr( sanitize_key( $type ), 0, 20 );
		if ( '' === $type ) {
			return 0;
		}

		$retained = array_fill_keys(
			array_values(
				array_filter(
					array_map( 'intval', $item_ids ),
					static fn( int $item_id ): bool => $item_id > 0
				)
			),
			true
		);

		global $wpdb;
		$table = $wpdb->base_prefix . 'cybermaps_translations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Pruning compares exact shared registry membership before bounded row deletion.
		$members = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT item_id
				FROM %i
                WHERE group_id = %d
                    AND site_id = %d
					AND item_type = %s',
				$table,
				$group_id,
				$site_id,
				$type
			)
		);
		// phpcs:enable

		$removed = 0;
		foreach ( is_array( $members ) ? $members : array() as $member ) {
			if (
				! is_object( $member )
			) {
				continue;
			}

			$raw_item_id = $member->item_id ?? 0;
			$item_id     = is_scalar( $raw_item_id ) ? (int) $raw_item_id : 0;
			if ( $item_id < 1 || isset( $retained[ $item_id ] ) ) {
				continue;
			}

			if ( $this->delete_relationship( $site_id, $item_id, $type ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Invalidate cached publications for the relationship containing one item.
	 *
	 * This is used when the relationship row itself did not change but the
	 * translated resource's URL or indexability may have changed.
	 */
	public function invalidate_relationship( $site_id, $item_id, $type = 'post' ): bool {
		$site_id = is_scalar( $site_id ) ? max( 0, (int) $site_id ) : 0;
		$item_id = is_scalar( $item_id ) ? max( 0, (int) $item_id ) : 0;
		$type    = substr( sanitize_key( (string) $type ), 0, 20 );
		if ( $site_id < 1 || $item_id < 1 || '' === $type ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->base_prefix . 'cybermaps_translations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Invalidation must resolve the current persisted relationship rather than a potentially stale cache entry.
		$group_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT group_id FROM %i
				WHERE site_id = %d AND item_id = %d AND item_type = %s',
				$table,
				$site_id,
				$item_id,
				$type
			)
		);
		// phpcs:enable
		if ( $group_id < 1 ) {
			return false;
		}

		$this->invalidate_sites(
			array_merge(
				array( $site_id ),
				$this->get_group_site_ids( $group_id, $type )
			)
		);
		return true;
	}

	/**
	 * Invalidate every site connected to a relationship owned by one site.
	 *
	 * Site-level settings and SEO-provider options can change the eligibility
	 * of many members at once, so an item-specific lookup is insufficient.
	 */
	public function invalidate_site_relationships( $site_id ): bool {
		$site_id = is_scalar( $site_id ) ? max( 0, (int) $site_id ) : 0;
		if ( $site_id < 1 ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->base_prefix . 'cybermaps_translations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Site-wide invalidation must enumerate the exact shared relationship graph.
		$sites = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT related.site_id
				FROM %i AS source
				INNER JOIN %i AS related
                    ON related.group_id = source.group_id
                    AND related.item_type = source.item_type
                WHERE source.site_id = %d
                    AND source.group_id > 0
					AND related.site_id > 0',
				$table,
				$table,
				$site_id
			)
		);
		// phpcs:enable

		$site_ids = $this->site_ids_from_rows( $sites );
		if ( empty( $site_ids ) ) {
			return false;
		}

		$this->invalidate_sites( array_merge( array( $site_id ), $site_ids ) );
		return true;
	}

	/**
	 * Clear publication caches for all sites represented by registry rows.
	 */
	public function invalidate_all_relationships(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'cybermaps_translations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Full relationship invalidation must enumerate every represented site from the shared registry.
		$sites = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT site_id
            FROM %i
				WHERE site_id > 0',
				$table
			)
		);
		// phpcs:enable

		$this->invalidate_sites( $this->site_ids_from_rows( $sites ) );
	}

	/**
	 * Return the distinct valid site IDs represented by one group.
	 *
	 * @return int[]
	 */
	private function get_group_site_ids( int $group_id, string $type ): array {
		$type = substr( sanitize_key( $type ), 0, 20 );
		if ( $group_id < 1 || '' === $type ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->base_prefix . 'cybermaps_translations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Cache invalidation must enumerate the exact persisted sites in the relationship group.
		$sites = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT site_id
				FROM %i
                WHERE group_id = %d
                    AND item_type = %s
					AND site_id > 0',
				$table,
				$group_id,
				$type
			)
		);
		// phpcs:enable

		return $this->site_ids_from_rows( $sites );
	}

	/**
	 * @param mixed $rows Database rows containing a site_id field.
	 * @return int[]
	 */
	private function site_ids_from_rows( $rows ): array {
		$site_ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row         = is_object( $row ) ? get_object_vars( $row ) : $row;
			$raw_site_id = is_array( $row ) ? ( $row['site_id'] ?? 0 ) : 0;
			$site_id     = is_scalar( $raw_site_id ) ? (int) $raw_site_id : 0;
			if ( $site_id > 0 ) {
				$site_ids[ $site_id ] = true;
			}
		}

		return array_map( 'intval', array_keys( $site_ids ) );
	}

	/**
	 * Normalize database and legacy cached rows to one safe public shape.
	 *
	 * @param mixed  $rows              Candidate relationship rows.
	 * @param int    $expected_group_id Required group, or zero for a cached row
	 *                                  whose lookup group is not yet known.
	 * @param string $type              Required item type.
	 * @param int    $lookup_site_id     Cached lookup site identity.
	 * @param int    $lookup_item_id     Cached lookup item identity.
	 * @return array<int,array{group_id:int,site_id:int,item_id:int,item_type:string,lang_code:string}>
	 */
	private function normalize_translation_rows(
		$rows,
		int $expected_group_id,
		string $type,
		int $lookup_site_id = 0,
		int $lookup_item_id = 0
	): array {
		$rows              = is_array( $rows ) ? $rows : array();
		$normalized        = array();
		$resolved_group_id = $this->resolved_cached_group_id( $rows, $expected_group_id, $type, $lookup_site_id, $lookup_item_id );
		if ( 0 === $resolved_group_id && $lookup_site_id > 0 && $lookup_item_id > 0 ) {
			return array();
		}

		foreach ( $rows as $row ) {
			$member = $this->normalized_translation_member( $row, $resolved_group_id, $type );
			if ( null === $member ) {
				continue;
			}

			$resolved_group_id = $member['group_id'];
			$normalized[]      = $member;
			if ( count( $normalized ) >= self::MAX_PUBLIC_GROUP_MEMBERS ) {
				break;
			}
		}

		return $normalized;
	}

	private function positive_scalar( mixed $value ): int {
		return is_scalar( $value ) ? max( 0, (int) $value ) : 0;
	}

	private function translation_type( mixed $value ): string {
		return is_scalar( $value ) ? substr( sanitize_key( (string) $value ), 0, 20 ) : '';
	}

	private function resolved_cached_group_id( array $rows, int $expected_group_id, string $type, int $site_id, int $item_id ): int {
		$resolved = max( 0, $expected_group_id );
		if ( $resolved > 0 || $site_id < 1 || $item_id < 1 ) {
			return $resolved;
		}
		foreach ( $rows as $row ) {
			$member = $this->normalized_translation_member( $row, 0, $type );
			if ( null !== $member && $site_id === $member['site_id'] && $item_id === $member['item_id'] ) {
				return $member['group_id'];
			}
		}
		return 0;
	}

	/** @return array{group_id:int,site_id:int,item_id:int,item_type:string,lang_code:string}|null */
	private function normalized_translation_member( mixed $row, int $expected_group_id, string $type ): ?array {
		$row = is_object( $row ) ? get_object_vars( $row ) : $row;
		if ( ! is_array( $row ) ) {
			return null;
		}
		$member = array(
			'group_id'  => $this->positive_scalar( $row['group_id'] ?? 0 ),
			'site_id'   => $this->positive_scalar( $row['site_id'] ?? 0 ),
			'item_id'   => $this->positive_scalar( $row['item_id'] ?? 0 ),
			'item_type' => $this->translation_type( $row['item_type'] ?? '' ),
			'lang_code' => TranslationHelper::normalize_hreflang( $row['lang_code'] ?? '' ),
		);
		if ( $member['group_id'] < 1 || ( $expected_group_id > 0 && $member['group_id'] !== $expected_group_id ) || $member['site_id'] < 1 || $member['item_id'] < 1 || $type !== $member['item_type'] || '' === $member['lang_code'] ) {
			return null;
		}
		return $member;
	}

	/**
	 * Clear relationship and sitemap caches once per affected site and fence a
	 * full-static generation before scheduling its replacement.
	 *
	 * @param int[] $site_ids Affected WordPress site IDs.
	 */
	private function invalidate_sites( array $site_ids ): void {
		$site_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $site_ids ),
					static fn( int $site_id ): bool => $site_id > 0
				)
			)
		);
		if ( empty( $site_ids ) ) {
			return;
		}

		$current_site_id = function_exists( 'get_current_blog_id' )
			? (int) get_current_blog_id()
			: (int) reset( $site_ids );
		$multisite       = function_exists( 'is_multisite' ) && is_multisite();

		foreach ( $site_ids as $site_id ) {
			if ( ! $multisite && $site_id !== $current_site_id ) {
				continue;
			}
			if (
				$multisite
				&& $site_id !== $current_site_id
				&& function_exists( 'get_site' )
				&& ! get_site( $site_id )
			) {
				continue;
			}

			$switched = false;
			if ( $multisite && $site_id !== $current_site_id ) {
				$switched = (bool) switch_to_blog( $site_id );
				if ( ! $switched ) {
					continue;
				}
			}

			try {
				CacheManager::clear_family( 'translations' );
				CacheManager::clear_family( 'sitemap' );

				if ( 'all' === \Cybermaps\Discovery\StaticBridge::get_mode() ) {
					$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
					$bridge->invalidate();
					$bridge->request_sync();
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}
}
