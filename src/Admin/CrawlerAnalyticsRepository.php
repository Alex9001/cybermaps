<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\CacheManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read model for PHP-observed crawler analytics.
 *
 * The analytics table stores site-local WordPress timestamps. Reporting cutoffs
 * are therefore calculated in the same timezone instead of relying on the
 * database server's NOW() timezone.
 */
final class CrawlerAnalyticsRepository {

	public const REPORTING_DAYS        = 30;
	private const OVERVIEW_CACHE_KEY   = 'cybermaps_analytics_overview_v2';
	private const ACTIVITY_CACHE_KEY   = 'cybermaps_analytics_activity_v2';
	private const WIDGET_CACHE_KEY     = 'cybermaps_recent_logs_widget';
	private const RECENT_PER_KIND      = 50;
	private const WIDGET_LIMIT         = 5;
	private const UNKNOWN_CLIENT_LIMIT = 50;

	/**
	 * Return the complete rolling analytics overview.
	 *
	 * @return array{
	 *     summary:array<string,int>,
	 *     endpoints:array<int,array<string,mixed>>,
	 *     content:array<int,array<string,mixed>>,
	 *     crawlers:array<int,array<string,mixed>>,
	 *     categories:array<int,array<string,mixed>>,
	 *     trend:array<int,array<string,mixed>>,
	 *     unknown_clients:array<int,array<string,mixed>>
	 * }
	 */
	public function get_overview(): array {
		$generation = CacheManager::get_generation( 'analytics' );
		$cached     = CacheManager::get( self::OVERVIEW_CACHE_KEY, 'analytics', $cache_found );
		if ( $cache_found && \is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'cybermaps_logs';
		$cutoff = self::reporting_cutoff_mysql();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- These exact aggregates read the plugin-owned analytics event table and are cached as one reporting model below.
		$summary = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN request_kind = 'endpoint' THEN 1 ELSE 0 END) AS php_endpoint_requests,
					SUM(CASE WHEN request_kind = 'endpoint' AND recognized = 1 THEN 1 ELSE 0 END) AS recognized_endpoint_requests,
					SUM(CASE WHEN request_kind = 'page' AND recognized = 1 THEN 1 ELSE 0 END) AS recognized_page_requests,
					COUNT(DISTINCT CASE WHEN recognized = 1 THEN bot ELSE NULL END) AS distinct_signatures,
					SUM(CASE
						WHEN NOT (identity_status = 'internal' OR category = 'authenticated')
							AND (identity_status = 'claimed' OR recognized = 1)
						THEN 1 ELSE 0
					END) AS claimed_requests,
					SUM(CASE
						WHEN request_kind = 'endpoint'
							AND NOT (identity_status = 'internal' OR category = 'authenticated')
							AND (identity_status = 'claimed' OR recognized = 1)
						THEN 1 ELSE 0
					END) AS claimed_endpoint_requests,
					SUM(CASE
						WHEN request_kind = 'page'
							AND NOT (identity_status = 'internal' OR category = 'authenticated')
							AND (identity_status = 'claimed' OR recognized = 1)
						THEN 1 ELSE 0
					END) AS claimed_page_requests,
					SUM(CASE
						WHEN identity_status = 'internal' OR category = 'authenticated'
						THEN 1 ELSE 0
					END) AS internal_requests,
					SUM(CASE
						WHEN NOT (identity_status = 'internal' OR category = 'authenticated')
							AND recognized = 0
							AND COALESCE(identity_status, 'legacy') NOT IN ('claimed', 'unregistered-bot')
						THEN 1 ELSE 0
					END) AS unknown_requests,
					SUM(CASE
						WHEN NOT (identity_status = 'internal' OR category = 'authenticated')
							AND recognized = 0
							AND identity_status = 'unregistered-bot'
						THEN 1 ELSE 0
					END) AS unregistered_crawler_requests,
					SUM(CASE WHEN request_kind = 'endpoint' AND response_status >= 400 THEN 1 ELSE 0 END) AS endpoint_errors
				FROM %i
				WHERE time >= %s",
				$table,
				$cutoff
			),
			ARRAY_A
		);

		$endpoints = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					endpoint_id,
					MIN(url) AS url,
					COUNT(*) AS php_requests,
					SUM(CASE WHEN recognized = 1 THEN 1 ELSE 0 END) AS recognized_requests,
					COUNT(DISTINCT CASE WHEN recognized = 1 THEN bot ELSE NULL END) AS recognized_signatures,
					SUM(CASE WHEN response_status >= 400 THEN 1 ELSE 0 END) AS errors,
					MAX(time) AS last_observed
				FROM %i
				WHERE time >= %s
					AND request_kind = 'endpoint'
				GROUP BY endpoint_id
				ORDER BY php_requests DESC, endpoint_id ASC
				LIMIT 25",
				$table,
				$cutoff
			),
			ARRAY_A
		);

		$content = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					url,
					COUNT(*) AS requests,
					COUNT(DISTINCT bot) AS recognized_signatures,
					MAX(time) AS last_observed
				FROM %i
				WHERE time >= %s
					AND request_kind = 'page'
					AND recognized = 1
				GROUP BY url
				ORDER BY requests DESC, url ASC
				LIMIT 15",
				$table,
				$cutoff
			),
			ARRAY_A
		);

		$crawlers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					bot,
					category,
					COUNT(*) AS requests,
					SUM(CASE WHEN request_kind = 'endpoint' THEN 1 ELSE 0 END) AS endpoint_requests,
					SUM(CASE WHEN request_kind = 'page' THEN 1 ELSE 0 END) AS page_requests,
					MAX(time) AS last_observed
				FROM %i
				WHERE time >= %s
					AND recognized = 1
				GROUP BY bot, category
				ORDER BY requests DESC, bot ASC
				LIMIT 20",
				$table,
				$cutoff
			),
			ARRAY_A
		);

		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					category,
					COUNT(*) AS requests,
					SUM(CASE WHEN request_kind = 'endpoint' THEN 1 ELSE 0 END) AS endpoint_requests,
					SUM(CASE WHEN request_kind = 'page' THEN 1 ELSE 0 END) AS page_requests
				FROM %i
				WHERE time >= %s
					AND recognized = 1
				GROUP BY category
				ORDER BY requests DESC, category ASC",
				$table,
				$cutoff
			),
			ARRAY_A
		);

		$trend_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(time) AS day,
					SUM(CASE WHEN request_kind = 'endpoint' THEN 1 ELSE 0 END) AS php_endpoint_requests,
					SUM(CASE WHEN request_kind = 'endpoint' AND recognized = 1 THEN 1 ELSE 0 END) AS recognized_endpoint_requests,
					SUM(CASE WHEN request_kind = 'page' AND recognized = 1 THEN 1 ELSE 0 END) AS recognized_page_requests
				FROM %i
				WHERE time >= %s
				GROUP BY DATE(time)
				ORDER BY day ASC",
				$table,
				$cutoff
			),
			ARRAY_A
		);

		$unknown_clients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					identity_status,
					bot AS label,
					LEFT(user_agent, 512) AS user_agent,
					requester_key,
					ip_address,
					ip_source,
					ip_storage,
					request_method,
					accept_type,
					COUNT(DISTINCT CASE
						WHEN request_kind = 'endpoint' AND endpoint_id <> '' THEN endpoint_id
						ELSE NULL
					END) AS endpoint_count,
					SUM(CASE WHEN request_kind = 'endpoint' THEN 1 ELSE 0 END) AS endpoint_requests,
					COUNT(*) AS request_count,
					MIN(time) AS first_seen,
					MAX(time) AS last_seen
				FROM %i
				WHERE time >= %s
					AND recognized = 0
					AND COALESCE(identity_status, 'legacy') <> 'claimed'
					AND COALESCE(identity_status, 'legacy') <> 'internal'
					AND category <> 'authenticated'
				GROUP BY
					identity_status,
					bot,
					LEFT(user_agent, 512),
					requester_key,
					ip_address,
					ip_source,
					ip_storage,
					request_method,
					accept_type
				ORDER BY request_count DESC, last_seen DESC, label ASC
				LIMIT %d",
				$table,
				$cutoff,
				self::UNKNOWN_CLIENT_LIMIT
			),
			ARRAY_A
		);
		// phpcs:enable

		$overview = array(
			'summary'         => $this->normalize_summary( \is_array( $summary ) ? $summary : array() ),
			'endpoints'       => $this->normalize_rows( $endpoints ),
			'content'         => $this->normalize_rows( $content ),
			'crawlers'        => $this->normalize_rows( $crawlers ),
			'categories'      => $this->normalize_rows( $categories ),
			'trend'           => $this->normalize_trend( $trend_rows ),
			'unknown_clients' => $this->normalize_unknown_clients( $unknown_clients ),
		);

		CacheManager::set_compatible_if_current(
			self::OVERVIEW_CACHE_KEY,
			$overview,
			5 * MINUTE_IN_SECONDS,
			'analytics',
			$generation
		);
		return $overview;
	}

	/**
	 * Return a balanced recent stream so one request kind cannot starve the other.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_recent_activity(): array {
		$generation = CacheManager::get_generation( 'analytics' );
		$cached     = CacheManager::get( self::ACTIVITY_CACHE_KEY, 'analytics', $cache_found );
		if ( $cache_found && \is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_logs';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Recent activity must reflect the persisted event stream; the bounded result is cached immediately below.
		$endpoint_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				WHERE request_kind = 'endpoint'
				ORDER BY time DESC, id DESC
				LIMIT %d",
				$table,
				self::RECENT_PER_KIND
			),
			ARRAY_A
		);
		$page_rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM %i
				WHERE request_kind = 'page'
					AND (recognized = 1 OR identity_status = 'unregistered-bot')
				ORDER BY time DESC, id DESC
				LIMIT %d",
				$table,
				self::RECENT_PER_KIND
			),
			ARRAY_A
		);
		// phpcs:enable

		$activity = \array_merge(
			$this->normalize_rows( $endpoint_rows ),
			$this->normalize_rows( $page_rows )
		);
		\usort(
			$activity,
			static function ( array $left, array $right ): int {
				$time_order = \strcmp( (string) ( $right['time'] ?? '' ), (string) ( $left['time'] ?? '' ) );
				return 0 !== $time_order
					? $time_order
					: (int) ( $right['id'] ?? 0 ) <=> (int) ( $left['id'] ?? 0 );
			}
		);

		CacheManager::set_compatible_if_current(
			self::ACTIVITY_CACHE_KEY,
			$activity,
			MINUTE_IN_SECONDS,
			'analytics',
			$generation
		);
		return $activity;
	}

	/**
	 * Return the small projected stream used only by the WordPress dashboard.
	 *
	 * The full analytics page intentionally balances up to fifty rows per
	 * request kind. The dashboard needs only five rows and should not hydrate
	 * that larger read model or select private diagnostic columns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_widget_activity(): array {
		$generation = CacheManager::get_generation( 'analytics' );
		$cached     = CacheManager::get( self::WIDGET_CACHE_KEY, 'analytics', $cache_found );
		if ( $cache_found && \is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_logs';

		// InnoDB secondary indexes include the primary key, so the existing
		// `time` index supports this newest-first time/ID LIMIT without adding a
		// redundant launch-time schema migration.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The dashboard needs a fresh bounded projection, which is cached immediately below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT time, bot, url, request_kind, response_status
				FROM %i
				WHERE request_kind = 'endpoint'
					OR (
						request_kind = 'page'
						AND (recognized = 1 OR identity_status = 'unregistered-bot')
					)
				ORDER BY time DESC, id DESC
				LIMIT %d",
				$table,
				self::WIDGET_LIMIT
			),
			ARRAY_A
		);
		// phpcs:enable

		$activity = $this->normalize_rows( $rows );
		CacheManager::set_compatible_if_current(
			self::WIDGET_CACHE_KEY,
			$activity,
			MINUTE_IN_SECONDS,
			'analytics',
			$generation
		);
		return $activity;
	}

	/**
	 * Calculate a site-local MySQL timestamp before which rows are outside a window.
	 */
	public static function cutoff_mysql( int $days ): string {
		$days     = \max( 1, $days );
		$timezone = \function_exists( 'wp_timezone' ) ? \wp_timezone() : new \DateTimeZone( 'UTC' );
		$now      = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) \current_time( 'mysql' ), $timezone );

		if ( false === $now ) {
			$now = new \DateTimeImmutable( 'now', $timezone );
		}

		return $now->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Return the beginning of the first site-local day shown by the overview.
	 */
	private static function reporting_cutoff_mysql(): string {
		$timezone = \function_exists( 'wp_timezone' ) ? \wp_timezone() : new \DateTimeZone( 'UTC' );
		$today    = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) \current_time( 'mysql' ), $timezone );
		if ( false === $today ) {
			$today = new \DateTimeImmutable( 'now', $timezone );
		}

		return $today
			->setTime( 0, 0 )
			->modify( '-' . ( self::REPORTING_DAYS - 1 ) . ' days' )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param mixed $rows Database result.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_rows( $rows ): array {
		if ( ! \is_array( $rows ) ) {
			return array();
		}

		return \array_values(
			\array_map(
				static fn ( $row ): array => \is_array( $row ) ? $row : (array) $row,
				$rows
			)
		);
	}

	/**
	 * @param array<string,mixed> $summary Raw summary row.
	 * @return array<string,int>
	 */
	private function normalize_summary( array $summary ): array {
		$normalized = array();
		foreach (
			array(
				'php_endpoint_requests',
				'recognized_endpoint_requests',
				'recognized_page_requests',
				'distinct_signatures',
				'claimed_requests',
				'claimed_endpoint_requests',
				'claimed_page_requests',
				'internal_requests',
				'unknown_requests',
				'unregistered_crawler_requests',
				'endpoint_errors',
			) as $key
		) {
			$normalized[ $key ] = (int) ( $summary[ $key ] ?? 0 );
		}

		return $normalized;
	}

	/**
	 * Normalize evidence used to group unidentified client patterns.
	 *
	 * User-Agent strings are sanitized again at the read boundary so historical
	 * rows cannot inject markup into a future analytics presentation.
	 *
	 * @param mixed $rows Database result.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_unknown_clients( $rows ): array {
		$normalized = array();

		foreach ( $this->normalize_rows( $rows ) as $row ) {
			$method = \strtoupper( \sanitize_text_field( (string) self::row_value( $row, 'request_method', '' ) ) );

			$normalized[] = array(
				'identity_status'   => \substr( \sanitize_key( (string) self::row_value( $row, 'identity_status', 'legacy' ) ), 0, 30 ),
				'label'             => \substr( \sanitize_text_field( (string) self::row_value( $row, 'label', '' ) ), 0, 100 ),
				'user_agent'        => \substr( \sanitize_text_field( (string) self::row_value( $row, 'user_agent', '' ) ), 0, 512 ),
				'requester_key'     => \substr( \sanitize_key( (string) self::row_value( $row, 'requester_key', '' ) ), 0, 40 ),
				'ip_address'        => \substr( \sanitize_text_field( (string) self::row_value( $row, 'ip_address', '' ) ), 0, 45 ),
				'ip_source'         => \substr( \sanitize_key( (string) self::row_value( $row, 'ip_source', 'legacy' ) ), 0, 64 ),
				'ip_storage'        => \substr( \sanitize_key( (string) self::row_value( $row, 'ip_storage', 'legacy' ) ), 0, 20 ),
				'request_method'    => 1 === \preg_match( '/^[A-Z]{1,10}$/', $method ) ? $method : '',
				'accept_type'       => \substr( \sanitize_key( (string) self::row_value( $row, 'accept_type', '' ) ), 0, 20 ),
				'endpoint_count'    => (int) self::row_value( $row, 'endpoint_count', 0 ),
				'endpoint_requests' => (int) self::row_value( $row, 'endpoint_requests', 0 ),
				'request_count'     => (int) self::row_value( $row, 'request_count', 0 ),
				'first_seen'        => \substr( \sanitize_text_field( (string) self::row_value( $row, 'first_seen', '' ) ), 0, 19 ),
				'last_seen'         => \substr( \sanitize_text_field( (string) self::row_value( $row, 'last_seen', '' ) ), 0, 19 ),
			);
		}

		return $normalized;
	}

	/**
	 * Fill the reporting window so zero-activity dates remain explicit.
	 *
	 * @param mixed $rows Daily database rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_trend( $rows ): array {
		$indexed = array();
		foreach ( $this->normalize_rows( $rows ) as $row ) {
			$day = (string) self::row_value( $row, 'day', '' );
			if ( '' !== $day ) {
				$indexed[ $day ] = $row;
			}
		}

		$timezone = \function_exists( 'wp_timezone' ) ? \wp_timezone() : new \DateTimeZone( 'UTC' );
		$today    = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) \current_time( 'mysql' ), $timezone );
		if ( false === $today ) {
			$today = new \DateTimeImmutable( 'now', $timezone );
		}

		$trend = array();
		for ( $offset = self::REPORTING_DAYS - 1; $offset >= 0; --$offset ) {
			$day     = $today->modify( '-' . $offset . ' days' )->format( 'Y-m-d' );
			$row     = (array) self::row_value( $indexed, $day, array() );
			$trend[] = array(
				'day'                          => $day,
				'php_endpoint_requests'        => (int) self::row_value( $row, 'php_endpoint_requests', 0 ),
				'recognized_endpoint_requests' => (int) self::row_value( $row, 'recognized_endpoint_requests', 0 ),
				'recognized_page_requests'     => (int) self::row_value( $row, 'recognized_page_requests', 0 ),
			);
		}

		return $trend;
	}

	/**
	 * Return a non-null row value or the same fallback used by null coalescing.
	 *
	 * @param array<array-key,mixed> $row Database or indexed row.
	 */
	private static function row_value( array $row, string $key, mixed $fallback ): mixed {
		return isset( $row[ $key ] ) ? $row[ $key ] : $fallback;
	}
}
