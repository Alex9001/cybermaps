<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

use Cybermaps\SEO\PublicationEligibility;
use Cybermaps\SEO\SeoContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded, request-local access to sitemap candidates.
 *
 * Candidate pagination deliberately follows WordPress Core's sitemap model:
 * SQL/WP_Query divides the published resource set into stable raw pages and
 * the canonical publication policy filters only the bounded page being
 * rendered. Counts are therefore safe upper bounds when runtime SEO adapters
 * or custom eligibility filters omit individual resources. This avoids an
 * unbounded cold scan while guaranteeing that no ineligible URL is emitted and
 * that every eligible candidate still appears on exactly one advertised page.
 */
final class EligibleContentRepository {
	private const LASTMOD_SCAN_BATCH   = 100;
	private const LASTMOD_SCAN_BATCHES = 4;
	private const MAX_QUERY_ROWS       = 2000;
	private const WINDOW_SCAN_BATCH    = 1000;
	private const WINDOW_SCAN_LIMIT    = 10000;
	private const ARCHIVE_MONTH_LIMIT  = 2400;
	private const ARCHIVE_CACHE_KEY    = 'cybermaps_archive_month_inventory';

	private array $settings;
	private PublicationEligibility $eligibility;

	/**
	 * @var array<string,array<int,object>>
	 */
	private array $post_pages = array();

	/**
	 * @var array<string,int>
	 */
	private array $post_counts = array();

	/**
	 * @var array<string,string>
	 */
	private array $post_lastmods = array();

	/**
	 * @var array<string,array<int,object>>
	 */
	private array $term_pages = array();

	/**
	 * @var array<string,int>
	 */
	private array $term_counts = array();

	/**
	 * @var array<string,string>
	 */
	private array $term_lastmods = array();

	/**
	 * @var array<string,string>
	 */
	private array $taxonomy_lastmods = array();

	/**
	 * @var array<string,array<int,array{user_id:int,lastmod:string}>>
	 */
	private array $author_pages = array();

	private ?int $author_count      = null;
	private ?string $author_lastmod = null;

	/** @var array<int,array{year:int,month:int,lastmod:string}>|null */
	private ?array $archive_inventory = null;

	/**
	 * @var array<string,array<int,object>>
	 */
	private array $recent_post_rows = array();

	public function __construct( ?array $settings = null, ?PublicationEligibility $eligibility = null ) {
		$this->settings    = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
		$this->eligibility = $eligibility ?? new PublicationEligibility( null, $this->settings );
	}

	/**
	 * Return one stable raw candidate page after applying complete eligibility.
	 *
	 * The page is not backfilled after exclusions. Backfilling would require
	 * walking an unknown portion of the site to reconstruct a compact offset.
	 *
	 * @return object[]
	 */
	public function get_post_page( string $post_type, int $page, int $per_page ): array {
		$page      = max( 1, $page );
		$per_page  = max( 1, $per_page );
		$cache_key = $post_type . ':' . $page . ':' . $per_page;
		if ( array_key_exists( $cache_key, $this->post_pages ) ) {
			return $this->post_pages[ $cache_key ];
		}

		$rows     = $this->query_post_candidates(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		$eligible = array();
		foreach ( $rows as $row ) {
			if ( $this->eligibility->post( $row, PublicationEligibility::SITEMAP )->indexable ) {
				$eligible[] = $row;
			}
		}

		$this->post_pages[ $cache_key ] = $eligible;
		return $eligible;
	}

	/**
	 * Return the published, password-free candidate count.
	 *
	 * Runtime adapters and filters can only be evaluated per resource. The count
	 * is consequently an upper bound used to partition the raw candidate set;
	 * get_post_page() remains the authority for emitted URLs.
	 */
	public function get_post_count( string $post_type ): int {
		if ( array_key_exists( $post_type, $this->post_counts ) ) {
			return $this->post_counts[ $post_type ];
		}

		global $wpdb;
		if ( ! $this->supports_wpdb( $wpdb, array( 'get_var', 'prepare' ) ) ) {
			$this->post_counts[ $post_type ] = 0;
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- This exact candidate count is request-cached below.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i
				WHERE post_type = %s
					AND post_status = 'publish'
					AND post_password = ''",
				$wpdb->posts,
				$post_type
			)
		);
		// phpcs:enable

		$this->post_counts[ $post_type ] = max( 0, (int) $count );
		return $this->post_counts[ $post_type ];
	}

	/**
	 * Find the newest eligible post with a fixed query/row ceiling.
	 */
	public function get_post_lastmod( string $post_type ): string {
		if ( array_key_exists( $post_type, $this->post_lastmods ) ) {
			return $this->post_lastmods[ $post_type ];
		}

		for ( $page = 1; $page <= self::LASTMOD_SCAN_BATCHES; ++$page ) {
			$rows = $this->query_post_candidates(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => self::LASTMOD_SCAN_BATCH,
					'paged'          => $page,
					'orderby'        => array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					),
					'order'          => 'DESC',
				)
			);
			foreach ( $rows as $row ) {
				if ( ! $this->eligibility->post( $row, PublicationEligibility::SITEMAP )->indexable ) {
					continue;
				}

				$lastmod = $this->format_lastmod( (string) ( $row->post_modified_gmt ?? '' ) );
				if ( '' !== $lastmod ) {
					$this->post_lastmods[ $post_type ] = $lastmod;
					return $lastmod;
				}
			}
			if ( count( $rows ) < self::LASTMOD_SCAN_BATCH ) {
				break;
			}
		}

		// Omitting lastmod is safer than attributing an excluded resource's date.
		$this->post_lastmods[ $post_type ] = '';
		return '';
	}

	/**
	 * Return up to $limit eligible posts inside a recent publication window.
	 *
	 * @return object[]
	 */
	public function get_recent_post_rows(
		string $post_type,
		int $timestamp,
		int $window_seconds,
		int $limit
	): array {
		$timestamp      = max( 0, $timestamp );
		$window_seconds = max( 1, $window_seconds );
		$limit          = max( 1, $limit );
		$cutoff         = $timestamp - $window_seconds;
		$cache_key      = implode( ':', array( $post_type, $timestamp, $window_seconds, $limit ) );
		if ( array_key_exists( $cache_key, $this->recent_post_rows ) ) {
			return $this->recent_post_rows[ $cache_key ];
		}

		$eligible       = array();
		$eligible_count = 0;
		$scanned        = 0;
		$page           = 1;
		while ( $eligible_count < $limit && $scanned < self::WINDOW_SCAN_LIMIT ) {
			$batch_size = min( self::WINDOW_SCAN_BATCH, self::WINDOW_SCAN_LIMIT - $scanned );
			$rows       = $this->query_post_candidates(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => $batch_size,
					'paged'          => $page,
					'orderby'        => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'order'          => 'DESC',
					'date_query'     => array(
						array(
							'column'    => 'post_date_gmt',
							'after'     => gmdate( 'Y-m-d H:i:s', $cutoff ),
							'before'    => gmdate( 'Y-m-d H:i:s', $timestamp ),
							'inclusive' => true,
						),
					),
				)
			);
			$scanned   += count( $rows );
			foreach ( $rows as $row ) {
				if ( $this->eligibility->post( $row, PublicationEligibility::SITEMAP )->indexable ) {
					$eligible[] = $row;
					++$eligible_count;
					if ( $eligible_count >= $limit ) {
						break;
					}
				}
			}
			if ( count( $rows ) < $batch_size ) {
				break;
			}
			++$page;
		}

		$this->recent_post_rows[ $cache_key ] = array_slice( $eligible, 0, $limit );
		return $this->recent_post_rows[ $cache_key ];
	}

	/**
	 * Determine whether a bounded publication-date interval has an eligible post.
	 */
	public function has_eligible_post_in_publication_window(
		string $post_type,
		int $after,
		int $before
	): bool {
		if ( $before <= $after ) {
			return false;
		}

		$scanned = 0;
		$page    = 1;
		while ( $scanned < self::WINDOW_SCAN_LIMIT ) {
			$batch_size = min( self::WINDOW_SCAN_BATCH, self::WINDOW_SCAN_LIMIT - $scanned );
			$rows       = $this->query_post_candidates(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => $batch_size,
					'paged'          => $page,
					'orderby'        => array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					),
					'order'          => 'ASC',
					'date_query'     => array(
						array(
							'column'    => 'post_date_gmt',
							'after'     => gmdate( 'Y-m-d H:i:s', $after ),
							'before'    => gmdate( 'Y-m-d H:i:s', $before ),
							'inclusive' => true,
						),
					),
				)
			);
			$scanned   += count( $rows );
			foreach ( $rows as $row ) {
				$published = self::publication_timestamp( $row );
				if (
					$published > $after
					&& $published <= $before
					&& $this->eligibility->post( $row, PublicationEligibility::SITEMAP )->indexable
				) {
					return true;
				}
			}
			if ( count( $rows ) < $batch_size ) {
				break;
			}
			++$page;
		}

		return false;
	}

	/**
	 * Return one stable raw term page after applying term eligibility.
	 *
	 * @return object[]
	 */
	public function get_term_page( string $taxonomy, int $page, int $per_page ): array {
		$page          = max( 1, $page );
		$per_page      = max( 1, $per_page );
		$include_empty = ! empty( $this->settings['include_empty_terms'] );
		$cache_key     = implode( ':', array( $taxonomy, $page, $per_page, $include_empty ? 'all' : 'nonempty' ) );
		if ( array_key_exists( $cache_key, $this->term_pages ) ) {
			return $this->term_pages[ $cache_key ];
		}

		$rows = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => ! $include_empty,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			$this->term_pages[ $cache_key ] = array();
			return array();
		}

		$eligible = array();
		foreach ( array_slice( $rows, 0, $per_page ) as $row ) {
			if ( is_object( $row ) && $this->eligibility->term( $row, $taxonomy, PublicationEligibility::SITEMAP )->indexable ) {
				$eligible[] = $row;
			}
		}

		$this->term_pages[ $cache_key ] = $eligible;
		return $eligible;
	}

	/**
	 * Return the raw term candidate count used for stable page partitioning.
	 */
	public function get_term_count( string $taxonomy ): int {
		$include_empty = ! empty( $this->settings['include_empty_terms'] );
		$cache_key     = $taxonomy . ':' . ( $include_empty ? 'all' : 'nonempty' );
		if ( array_key_exists( $cache_key, $this->term_counts ) ) {
			return $this->term_counts[ $cache_key ];
		}

		$count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => ! $include_empty,
			)
		);
		if ( is_wp_error( $count ) ) {
			$count = 0;
		}

		$this->term_counts[ $cache_key ] = max( 0, (int) $count );
		return $this->term_counts[ $cache_key ];
	}

	/**
	 * Return linked-content lastmod values for a whole rendered term page.
	 *
	 * This is one aggregate query rather than one unbounded post query per term.
	 * The timestamp is advisory and conservative: complete term eligibility is
	 * enforced before a term URL is emitted, while singular post eligibility is
	 * never used to publish a URL from this aggregate.
	 *
	 * @param int[] $term_ids Term IDs on the rendered page.
	 * @return array<int,string>
	 */
	public function get_term_lastmods( array $term_ids, string $taxonomy ): array {
		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		if ( empty( $term_ids ) ) {
			return array();
		}

		list( $result, $missing ) = $this->cached_term_lastmods( $term_ids, $taxonomy );
		if ( empty( $missing ) ) {
			return $result;
		}

		global $wpdb;
		if ( ! $this->supports_wpdb( $wpdb, array( 'get_results', 'prepare' ) ) ) {
			foreach ( $missing as $term_id ) {
				$this->term_lastmods[ $taxonomy . ':' . $term_id ] = '';
			}
			return $result;
		}

		$result = $this->query_missing_term_lastmods( $wpdb, $result, $missing, $taxonomy );
		$this->cache_term_lastmods( $result, $missing, $taxonomy );
		return $result;
	}

	private function cached_term_lastmods( array $term_ids, string $taxonomy ): array {
		$result  = array_fill_keys( $term_ids, '' );
		$missing = array();
		foreach ( $term_ids as $term_id ) {
			$key = $taxonomy . ':' . $term_id;
			if ( array_key_exists( $key, $this->term_lastmods ) ) {
				$result[ $term_id ] = $this->term_lastmods[ $key ];
			} else {
				$missing[] = $term_id;
			}
		}
		return array( $result, $missing );
	}

	private function query_missing_term_lastmods( object $wpdb, array $result, array $missing, string $taxonomy ): array {
		$placeholders = implode( ', ', array_fill( 0, count( $missing ), '%d' ) );
		$query        = "SELECT tt.term_id, MAX(p.post_modified_gmt) AS lastmod FROM %i tt INNER JOIN %i tr ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i p ON tr.object_id = p.ID WHERE tt.taxonomy = %s AND tt.term_id IN ({$placeholders}) AND p.post_status = 'publish' AND p.post_password = '' GROUP BY tt.term_id";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->posts, $taxonomy, ...$missing ) );
		// phpcs:enable
		foreach ( (array) $rows as $row ) {
			$id = (int) ( $row->term_id ?? 0 );
			if ( in_array( $id, $missing, true ) ) {
				$result[ $id ] = $this->format_lastmod( (string) ( $row->lastmod ?? '' ) );
			}
		}
		return $result;
	}

	private function cache_term_lastmods( array $result, array $missing, string $taxonomy ): void {
		foreach ( $missing as $term_id ) {
			$this->term_lastmods[ $taxonomy . ':' . $term_id ] = $result[ $term_id ];
		}
	}

	/**
	 * Return a conservative linked-content lastmod for a taxonomy.
	 */
	public function get_taxonomy_lastmod( string $taxonomy ): string {
		if ( array_key_exists( $taxonomy, $this->taxonomy_lastmods ) ) {
			return $this->taxonomy_lastmods[ $taxonomy ];
		}

		global $wpdb;
		if ( ! $this->supports_wpdb( $wpdb, array( 'get_var', 'prepare' ) ) ) {
			$this->taxonomy_lastmods[ $taxonomy ] = '';
			return '';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- This exact linked-content aggregate is request-cached below.
		$lastmod = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(p.post_modified_gmt)
				FROM %i tt
				INNER JOIN %i tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN %i p ON tr.object_id = p.ID
				WHERE tt.taxonomy = %s
					AND p.post_status = 'publish'
					AND p.post_password = ''",
				$wpdb->term_taxonomy,
				$wpdb->term_relationships,
				$wpdb->posts,
				$taxonomy
			)
		);
		// phpcs:enable

		$this->taxonomy_lastmods[ $taxonomy ] = $this->format_lastmod( (string) $lastmod );
		return $this->taxonomy_lastmods[ $taxonomy ];
	}

	/**
	 * Return a bounded raw-author page after applying author-archive policy.
	 *
	 * @return array<int,array{user_id:int,lastmod:string}>
	 */
	public function get_author_page( int $page, int $per_page ): array {
		if ( empty( $this->settings['include_authors'] ) ) {
			return array();
		}

		$page      = max( 1, $page );
		$per_page  = max( 1, $per_page );
		$cache_key = $page . ':' . $per_page;
		if ( array_key_exists( $cache_key, $this->author_pages ) ) {
			return $this->author_pages[ $cache_key ];
		}

		$post_types = $this->enabled_post_types();
		global $wpdb;
		if ( empty( $post_types ) || ! $this->supports_wpdb( $wpdb, array( 'get_results', 'prepare' ) ) ) {
			$this->author_pages[ $cache_key ] = array();
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$query        = "SELECT post_author AS user_id, MAX(post_modified_gmt) AS lastmod
			FROM %i
			WHERE post_type IN ({$placeholders})
				AND post_status = 'publish'
				AND post_password = ''
				AND post_author > 0
			GROUP BY post_author
			ORDER BY post_author ASC
			LIMIT %d OFFSET %d";
		$args         = array_merge(
			array( $wpdb->posts ),
			$post_types,
			array( $per_page, ( $page - 1 ) * $per_page )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The variadic list supplies the Core identifier, one value per post type, and pagination values; results are request-cached.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$args ) );
		// phpcs:enable

		$authors = array();
		foreach ( (array) $rows as $row ) {
			$user_id = (int) ( $row->user_id ?? 0 );
			if (
				$user_id > 0
				&& $this->eligibility->decide(
					SeoContext::author( $user_id ),
					PublicationEligibility::SITEMAP
				)->indexable
			) {
				$authors[] = array(
					'user_id' => $user_id,
					'lastmod' => $this->format_lastmod( (string) ( $row->lastmod ?? '' ) ),
				);
			}
		}

		$this->author_pages[ $cache_key ] = $authors;
		return $authors;
	}

	/**
	 * Return the raw distinct-author count used to partition author pages.
	 */
	public function get_author_count(): int {
		if ( empty( $this->settings['include_authors'] ) ) {
			return 0;
		}
		if ( null !== $this->author_count ) {
			return $this->author_count;
		}

		$post_types = $this->enabled_post_types();
		global $wpdb;
		if ( empty( $post_types ) || ! $this->supports_wpdb( $wpdb, array( 'get_var', 'prepare' ) ) ) {
			$this->author_count = 0;
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$query        = "SELECT COUNT(DISTINCT post_author)
			FROM %i
			WHERE post_type IN ({$placeholders})
				AND post_status = 'publish'
				AND post_password = ''
				AND post_author > 0";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The variadic list supplies the Core identifier and one value per post type; the result is request-cached.
		$this->author_count = max( 0, (int) $wpdb->get_var( $wpdb->prepare( $query, $wpdb->posts, ...$post_types ) ) );
		// phpcs:enable
		return $this->author_count;
	}

	/**
	 * Return newest raw content date owned by an eligible author archive.
	 */
	public function get_author_lastmod(): string {
		if ( empty( $this->settings['include_authors'] ) ) {
			return '';
		}
		if ( null !== $this->author_lastmod ) {
			return $this->author_lastmod;
		}

		$post_types = $this->enabled_post_types();
		if ( empty( $post_types ) ) {
			$this->author_lastmod = '';
			return '';
		}

		for ( $page = 1; $page <= self::LASTMOD_SCAN_BATCHES; ++$page ) {
			$rows = $this->query_post_candidates(
				array(
					'post_type'      => $post_types,
					'posts_per_page' => self::LASTMOD_SCAN_BATCH,
					'paged'          => $page,
					'orderby'        => array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					),
					'order'          => 'DESC',
				)
			);
			foreach ( $rows as $row ) {
				$user_id = (int) ( $row->post_author ?? 0 );
				if (
					$user_id > 0
					&& $this->eligibility->decide(
						SeoContext::author( $user_id ),
						PublicationEligibility::SITEMAP
					)->indexable
				) {
					$this->author_lastmod = $this->format_lastmod( (string) ( $row->post_modified_gmt ?? '' ) );
					return $this->author_lastmod;
				}
			}
			if ( count( $rows ) < self::LASTMOD_SCAN_BATCH ) {
				break;
			}
		}

		$this->author_lastmod = '';
		return '';
	}

	/**
	 * Return one bounded raw monthly-archive page after archive eligibility.
	 *
	 * @return array<int,array{year:int,month:int,lastmod:string}>
	 */
	public function get_archive_page( int $page, int $per_page ): array {
		if ( empty( $this->settings['include_archives'] ) ) {
			return array();
		}

		$page     = max( 1, $page );
		$per_page = max( 1, min( self::MAX_QUERY_ROWS, $per_page ) );

		return array_slice( $this->get_archive_inventory(), ( $page - 1 ) * $per_page, $per_page );
	}

	/**
	 * Return the raw distinct monthly-archive count.
	 */
	public function get_archive_count(): int {
		if ( empty( $this->settings['include_archives'] ) ) {
			return 0;
		}
		return count( $this->get_archive_inventory() );
	}

	/**
	 * Return the newest raw post change date for date-archive freshness.
	 */
	public function get_archive_lastmod(): string {
		if ( empty( $this->settings['include_archives'] ) ) {
			return '';
		}
		$lastmods = array_column( $this->get_archive_inventory(), 'lastmod' );
		$lastmods = array_values( array_filter( $lastmods, 'is_string' ) );

		return empty( $lastmods ) ? '' : max( $lastmods );
	}

	/**
	 * Load every possible WordPress monthly archive with one bounded aggregate.
	 *
	 * @return array<int,array{year:int,month:int,lastmod:string}>
	 */
	private function get_archive_inventory(): array {
		if ( null !== $this->archive_inventory ) {
			return $this->archive_inventory;
		}

		$generation = \Cybermaps\Core\CacheManager::get_generation( 'sitemap' );
		$cached     = \Cybermaps\Core\CacheManager::get( self::ARCHIVE_CACHE_KEY, 'sitemap', $cache_found );
		if ( $cache_found && is_array( $cached ) ) {
			$this->archive_inventory = $cached;
			return $cached;
		}

		global $wpdb;
		if ( ! $this->supports_wpdb( $wpdb, array( 'get_results', 'prepare' ) ) ) {
			$this->archive_inventory = array();
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- One bounded aggregate is cached and invalidated with the sitemap family.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT YEAR(post_date_gmt) AS archive_year,
					MONTH(post_date_gmt) AS archive_month,
					MAX(post_modified_gmt) AS lastmod
				FROM %i
				WHERE post_type = 'post'
					AND post_status = 'publish'
					AND post_password = ''
					AND post_date_gmt > '0000-00-00 00:00:00'
				GROUP BY YEAR(post_date_gmt), MONTH(post_date_gmt)
				ORDER BY archive_year DESC, archive_month DESC
				LIMIT %d",
				$wpdb->posts,
				self::ARCHIVE_MONTH_LIMIT
			)
		);
		// phpcs:enable

		$archives = array();
		foreach ( (array) $rows as $row ) {
			$year  = (int) ( $row->archive_year ?? 0 );
			$month = (int) ( $row->archive_month ?? 0 );
			if (
				$year < 1
				|| $month < 1
				|| $month > 12
				|| ! $this->eligibility->decide(
					SeoContext::date_archive( $year, $month ),
					PublicationEligibility::SITEMAP
				)->indexable
			) {
				continue;
			}
			$archives[] = array(
				'year'    => $year,
				'month'   => $month,
				'lastmod' => $this->format_lastmod( (string) ( $row->lastmod ?? '' ) ),
			);
		}

		$this->archive_inventory = $archives;
		\Cybermaps\Core\CacheManager::set_if_current(
			self::ARCHIVE_CACHE_KEY,
			$archives,
			12 * HOUR_IN_SECONDS,
			'sitemap',
			$generation
		);
		return $archives;
	}

	/**
	 * Run a deterministic, password-free WP_Query with a hard row bound.
	 *
	 * @return object[]
	 */
	private function query_post_candidates( array $args ): array {
		if ( ! class_exists( '\WP_Query' ) ) {
			return array();
		}

		$per_page = max( 1, min( self::MAX_QUERY_ROWS, (int) ( $args['posts_per_page'] ?? 1 ) ) );
		$args     = array_merge(
			array(
				'post_status'            => 'publish',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			),
			$args,
			array( 'posts_per_page' => $per_page )
		);

		$query = new \WP_Query( $args );
		return array_values(
			array_filter(
				array_slice( (array) $query->posts, 0, $per_page ),
				'is_object'
			)
		);
	}

	/**
	 * @return string[]
	 */
	private function enabled_post_types(): array {
		return array_values(
			array_filter(
				\Cybermaps\Core\PublicationPostTypes::names(),
				static fn ( string $post_type ): bool => PriorityEngine::calculate(
					ProviderIdentity::post_type( $post_type )
				) > 0
			)
		);
	}

	/**
	 * @param string[] $methods Required database methods.
	 */
	private function supports_wpdb( mixed $wpdb, array $methods ): bool {
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		foreach ( $methods as $method ) {
			if ( ! method_exists( $wpdb, $method ) ) {
				return false;
			}
		}
		return isset( $wpdb->posts );
	}

	private function format_lastmod( string $date ): string {
		$timestamp = '' !== trim( $date ) ? strtotime( $date . ' UTC' ) : false;
		return false !== $timestamp ? gmdate( 'c', $timestamp ) : '';
	}

	private static function publication_timestamp( object $row ): int {
		$date      = trim( (string) ( $row->post_date_gmt ?? '' ) );
		$timestamp = '' === $date ? false : strtotime( $date . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}
}
