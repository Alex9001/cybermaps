<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs management for Cybermaps.
 */
class Logs {

	private const SCHEMA_VERSION            = '5';
	private const SCHEMA_VERSION_OPTION     = 'cybermaps_logs_schema_version';
	private const SCHEMA_REPAIR_TRANSIENT   = 'cybermaps_logs_schema_repair';
	private const CLEANUP_CONTINUATION_HOOK = 'cybermaps_continue_logs_cleanup_event';
	private const CLEANUP_BATCH_SIZE        = 1000;
	private const CLEANUP_MAX_BATCHES       = 5;

	private ?CrawlerAnalyticsRecorder $recorder     = null;
	private ?CrawlerAnalyticsRepository $repository = null;

	public function register_hooks(): void {
		$this->recorder = new CrawlerAnalyticsRecorder();

		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cleanup_schedule' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_upgrade_table' ), 0 );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'admin_post_cybermaps_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_cybermaps_export_logs', array( $this, 'handle_export_logs' ) );
		add_action( 'cybermaps_cleanup_logs_event', array( $this, 'cleanup_old_logs' ) );
		add_action( self::CLEANUP_CONTINUATION_HOOK, array( $this, 'cleanup_old_logs' ) );
		add_action( 'init', array( $this, 'begin_frontend_observation' ), 6 );
		add_filter( 'rest_post_dispatch', array( $this, 'observe_rest_response' ), 10, 3 );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_personal_data_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_personal_data_eraser' ) );
		add_action( 'deleted_user', array( $this, 'delete_user_observations' ) );
	}

	/**
	 * Add suggested text to WordPress's Privacy Policy Guide.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! \function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'When crawler analytics is enabled, Cybermaps stores site-local observations for registered endpoint requests that reached PHP, User-Agent-matched crawler visits, and conservative unregistered-crawler candidates. Records can include the request path, response status, HTTP method, coarse accepted media type, self-reported User-Agent, pseudonymous requester key, resolved IP source, and either the resolved IP address or an anonymized IP network according to the site administrator\'s setting. Logged-in site-user observations store the numeric WordPress user ID instead of an IP address.', 'cybermaps' ) . '</p>';
		$content .= '<p>' . esc_html__( 'User-Agent strings are self-reported claims and can be spoofed; a signature match is not provider verification. Requests served directly by a web server, CDN, or static cache never reach PHP and are outside this report. Administrators can export or clear the site-local records, and automatic retention is configurable (30 days by default). Changing IP storage mode affects only future records. Cybermaps does not send these analytics records to its developer.', 'cybermaps' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The public discovery-search endpoint uses an installation-salted, one-way identifier derived from the requester IP address to enforce a per-minute limit. The identifier is stored only in a site-local transient for 60 seconds and is not added to crawler analytics.', 'cybermaps' ) . '</p>';

		\wp_add_privacy_policy_content(
			__( 'Cybermaps', 'cybermaps' ),
			$content
		);
	}

	/**
	 * Register authenticated analytics observations with WordPress privacy tools.
	 *
	 * Anonymous crawler rows cannot be associated with an email address. Rows
	 * recorded while a WordPress user was logged in contain that user's numeric
	 * ID and can therefore be exported or erased through the standard workflow.
	 *
	 * @param array<string,array<string,mixed>> $exporters Existing exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_personal_data_exporter( array $exporters ): array {
		$exporters['cybermaps-authenticated-observations'] = array(
			'exporter_friendly_name' => __( 'Cybermaps authenticated request observations', 'cybermaps' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register the matching personal-data eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Existing erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_personal_data_eraser( array $erasers ): array {
		$erasers['cybermaps-authenticated-observations'] = array(
			'eraser_friendly_name' => __( 'Cybermaps authenticated request observations', 'cybermaps' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Export one bounded page of observations associated with a site user.
	 *
	 * @return array{data:array<int,array<string,mixed>>,done:bool}|\WP_Error
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array|\WP_Error {
		$user = \get_user_by( 'email', $email_address );
		if ( ! \is_object( $user ) || empty( $user->ID ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;
		$limit  = 100;
		$offset = ( max( 1, $page ) - 1 ) * $limit;
		$table  = $wpdb->prefix . 'cybermaps_logs';
		if ( \property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Privacy export must read exact persisted observations for this user.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, time, bot, category, url, request_kind, endpoint_id,
					response_status, request_method, accept_type
				FROM %i
				WHERE wp_user_id = %d
				ORDER BY id ASC
				LIMIT %d OFFSET %d',
				$table,
				(int) $user->ID,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable
		if (
			! \is_array( $rows )
			|| ( \property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error )
		) {
			CrawlerAnalyticsRecorder::persist_health_error();
			return new \WP_Error(
				'cybermaps_analytics_export_failed',
				__( 'Cybermaps could not export the authenticated request observations because the analytics table could not be read. Check the site database logs and retry the export.', 'cybermaps' )
			);
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = self::personal_export_item( $row );
		}

		return array(
			'data' => $data,
			'done' => \count( $rows ) < $limit,
		);
	}

	/**
	 * Convert one persisted observation to the WordPress privacy-export shape.
	 *
	 * @param array<string,mixed> $row Persisted analytics row.
	 * @return array<string,mixed>
	 */
	private static function personal_export_item( array $row ): array {
		return array(
			'group_id'    => 'cybermaps-authenticated-observations',
			'group_label' => __( 'Cybermaps authenticated request observations', 'cybermaps' ),
			'item_id'     => 'cybermaps-observation-' . (int) self::row_value( $row, 'id' ),
			'data'        => array(
				array(
					'name'  => __( 'Observed at', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'time' ),
				),
				array(
					'name'  => __( 'Client label', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'bot' ),
				),
				array(
					'name'  => __( 'Client category', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'category' ),
				),
				array(
					'name'  => __( 'Request path', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'url' ),
				),
				array(
					'name'  => __( 'Request kind', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'request_kind' ),
				),
				array(
					'name'  => __( 'Endpoint ID', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'endpoint_id' ),
				),
				array(
					'name'  => __( 'Response status', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'response_status' ),
				),
				array(
					'name'  => __( 'Request method', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'request_method' ),
				),
				array(
					'name'  => __( 'Accepted media family', 'cybermaps' ),
					'value' => (string) self::row_value( $row, 'accept_type' ),
				),
			),
		);
	}

	/**
	 * Erase one bounded page of observations associated with a site user.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		unset( $page );
		$user = \get_user_by( 'email', $email_address );
		if ( ! \is_object( $user ) || empty( $user->ID ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = self::delete_user_observations_by_id( (int) $user->ID, 100 );
		if ( false === $removed ) {
			CrawlerAnalyticsRecorder::persist_health_error();
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array(
					__( 'Cybermaps could not erase the authenticated request observations because the analytics table could not be read or written. Check the site database logs and retry the erasure.', 'cybermaps' ),
				),
				'done'           => true,
			);
		}

		if ( $removed > 0 ) {
			\Cybermaps\Core\CacheManager::clear_family( 'analytics' );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $removed < 100,
		);
	}

	/**
	 * Remove every authenticated observation when WordPress deletes a user.
	 */
	public function delete_user_observations( int $user_id ): void {
		$removed = self::delete_user_observations_by_id( $user_id, 0 );
		if ( false === $removed ) {
			CrawlerAnalyticsRecorder::persist_health_error();
			return;
		}

		if ( $removed > 0 ) {
			\Cybermaps\Core\CacheManager::clear_family( 'analytics' );
		}
	}

	/**
	 * Restore the daily retention event if cron storage was cleared after
	 * activation.
	 *
	 * Running this on admin_init avoids a cron-option read on public requests
	 * while ensuring the schedule self-heals the next time an administrator
	 * uses WordPress.
	 */
	public static function ensure_cleanup_schedule(): void {
		if ( \wp_next_scheduled( 'cybermaps_cleanup_logs_event' ) ) {
			return;
		}

		$scheduled = \wp_schedule_event(
			\time() + MINUTE_IN_SECONDS,
			'daily',
			'cybermaps_cleanup_logs_event'
		);
		if (
			( false === $scheduled || \is_wp_error( $scheduled ) )
			&& \function_exists( 'add_settings_error' )
		) {
			\add_settings_error(
				'cybermaps_settings',
				'cybermaps_cleanup_schedule_failed',
				__( 'Cybermaps could not restore its daily analytics and report-retention schedule. Check WP-Cron and the site database, then reload this administration page.', 'cybermaps' ),
				'error'
			);
		}
	}

	/**
	 * Handle clear logs request.
	 */
	public function handle_clear_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'cybermaps' ) );
		}
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_die(
				esc_html__( 'Invalid request method.', 'cybermaps' ),
				'',
				array( 'response' => 405 )
			);
		}
		check_admin_referer( 'cybermaps_clear_logs', 'cybermaps_clear_logs_nonce' );

		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The explicit clear action removes the complete plugin-owned event stream.
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );

		if ( false === $result ) {
			CrawlerAnalyticsRecorder::persist_health_error();
			wp_safe_redirect( self::analytics_url( array( 'cybermaps_logs_error' => '1' ) ) );
			exit;
		}

		CrawlerAnalyticsRecorder::clear_health_error();
		\Cybermaps\Core\CacheManager::clear_family( 'analytics' );
		wp_safe_redirect( self::analytics_url( array( 'cybermaps_logs_cleared' => '1' ) ) );
		exit;
	}

	/**
	 * Handle export logs request.
	 */
	public function handle_export_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'cybermaps' ) );
		}
		check_admin_referer( 'cybermaps_export_logs' );

		$batch_size = 500;
		$logs       = self::get_export_batch( null, $batch_size );
		if ( null === $logs ) {
			CrawlerAnalyticsRecorder::persist_health_error();
			wp_die(
				esc_html__( 'Cybermaps could not read the analytics history for export.', 'cybermaps' ),
				'',
				array( 'response' => 500 )
			);
		}

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cybermaps-crawler-logs-' . current_time( 'Y-m-d-His' ) . '.csv"' );
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 CSV BOM.
		self::output_csv_row( self::export_header_fields() );

		$before_id = null;
		do {
			self::output_export_rows( $logs );

			$before_id = self::export_before_id( $logs );
			if ( self::export_complete( $logs, $batch_size, $before_id ) ) {
				break;
			}

			$logs = self::get_export_batch( $before_id, $batch_size );
			if ( null === $logs ) {
				self::output_export_error();
				CrawlerAnalyticsRecorder::persist_health_error();
				break;
			}
		} while ( true );

		exit;
	}

	/** @return string[] */
	private static function export_header_fields(): array {
		return array(
			'ID',
			'Time',
			'Client',
			'Category',
			'UA Signature Matched',
			'URL',
			'Request Kind',
			'Endpoint ID',
			'Response Status',
			'Delivery',
			'Stored IP / Network',
			'User Agent',
			'Crawler ID',
			'Identity Status',
			'Verification Method',
			'Client Type',
			'Requester Key',
			'WordPress User ID',
			'Request Method',
			'Accept Type',
			'IP Source',
			'IP Storage',
		);
	}

	/** @param array<int,array<string,mixed>> $logs Persisted analytics rows. */
	private static function output_export_rows( array $logs ): void {
		foreach ( $logs as $row ) {
			self::output_csv_row( self::export_row_fields( $row ) );
		}
	}

	/**
	 * @param array<string,mixed> $row Persisted analytics row.
	 * @return array<int,mixed>
	 */
	private static function export_row_fields( array $row ): array {
		return array(
			self::row_value( $row, 'id' ),
			self::row_value( $row, 'time' ),
			self::row_value( $row, 'bot' ),
			self::row_value( $row, 'category' ),
			self::row_value( $row, 'recognized' ),
			self::row_value( $row, 'url' ),
			self::row_value( $row, 'request_kind' ),
			self::row_value( $row, 'endpoint_id' ),
			self::row_value( $row, 'response_status' ),
			self::row_value( $row, 'delivery' ),
			self::row_value( $row, 'ip_address' ),
			self::row_value( $row, 'user_agent' ),
			self::row_value( $row, 'crawler_id' ),
			self::row_value( $row, 'identity_status', 'legacy' ),
			self::row_value( $row, 'verification_method', 'legacy' ),
			self::row_value( $row, 'client_type', 'legacy' ),
			self::row_value( $row, 'requester_key' ),
			self::row_value( $row, 'wp_user_id' ),
			self::row_value( $row, 'request_method' ),
			self::row_value( $row, 'accept_type' ),
			self::row_value( $row, 'ip_source', 'legacy' ),
			self::row_value( $row, 'ip_storage', 'legacy' ),
		);
	}

	/** @param array<int,array<string,mixed>> $logs Persisted analytics rows. */
	private static function export_before_id( array $logs ): int {
		$last_row = end( $logs );
		if ( ! is_array( $last_row ) ) {
			return 0;
		}

		return (int) self::row_value( $last_row, 'id' );
	}

	/** @param array<int,array<string,mixed>> $logs Persisted analytics rows. */
	private static function export_complete( array $logs, int $batch_size, int $before_id ): bool {
		return count( $logs ) !== $batch_size || $before_id < 1;
	}

	private static function output_export_error(): void {
		self::output_csv_row(
			array_pad(
				array(
					'',
					current_time( 'mysql' ),
					'EXPORT ERROR: ' . __( 'Export stopped before all retained rows could be read.', 'cybermaps' ),
				),
				22,
				''
			)
		);
	}

	/** @param array<string,mixed> $row Persisted analytics row. */
	private static function row_value( array $row, string $key, mixed $default_value = '' ): mixed {
		return isset( $row[ $key ] ) ? $row[ $key ] : $default_value;
	}

	/**
	 * Fetch one keyset-paginated export batch.
	 *
	 * @return array<int,array<string,mixed>>|null Null indicates a database read failure.
	 */
	private static function get_export_batch( ?int $before_id, int $batch_size ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_logs';

		// Keyset pagination keeps export memory bounded even on busy sites.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Export reads an exact bounded page of the persisted analytics stream.
		if ( null === $before_id ) {
			$logs = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT *
					FROM %i
					ORDER BY id DESC
					LIMIT %d',
					$table,
					$batch_size
				),
				ARRAY_A
			);
		} else {
			$logs = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT *
					FROM %i
					WHERE id < %d
					ORDER BY id DESC
					LIMIT %d',
					$table,
					$before_id,
					$batch_size
				),
				ARRAY_A
			);
		}
		// phpcs:enable

		return is_array( $logs ) ? $logs : null;
	}

	/**
	 * Emit one RFC 4180 CSV row without treating the HTTP response as a file.
	 *
	 * @param array<int, mixed> $fields Row fields.
	 */
	private static function output_csv_row( array $fields ): void {
		$escaped = array_map(
			static function ( $field ): string {
				$value = (string) $field;
				if ( preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
					$value = "'" . $value;
				}
				return '"' . str_replace( '"', '""', $value ) . '"';
			},
			$fields
		);

		echo implode( ',', $escaped ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate CSV response.
	}

	public function cleanup_old_logs(): void {
		global $wpdb;
		$table               = $wpdb->prefix . 'cybermaps_logs';
		$settings            = \Cybermaps\Core\ConfigurationStore::settings();
		$days                = isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30;
		$days                = max( 1, min( 365, $days ) );
		$cutoff              = CrawlerAnalyticsRepository::cutoff_mysql( $days );
		$deleted             = 0;
		$more_rows_may_exist = false;

		for ( $batch = 0; $batch < self::CLEANUP_MAX_BATCHES; ++$batch ) {
			// Delete oldest expired rows in small indexed transactions. A large
			// retention backlog must not hold one unbounded table lock.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Retention cleanup must delete exact persisted rows in bounded indexed batches.
			$result = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i
					WHERE time < %s
					ORDER BY time ASC
					LIMIT %d',
					$table,
					$cutoff,
					self::CLEANUP_BATCH_SIZE
				)
			);
			// phpcs:enable

			if ( false === $result ) {
				if ( $deleted > 0 ) {
					\Cybermaps\Core\CacheManager::clear_family( 'analytics' );
				}
				CrawlerAnalyticsRecorder::persist_health_error();
				return;
			}

			$result              = max( 0, (int) $result );
			$deleted            += $result;
			$more_rows_may_exist = self::CLEANUP_BATCH_SIZE === $result;
			if ( ! $more_rows_may_exist ) {
				break;
			}
		}

		CrawlerAnalyticsRecorder::clear_health_error();
		if ( $deleted > 0 ) {
			\Cybermaps\Core\CacheManager::clear_family( 'analytics' );
		}

		if (
			$more_rows_may_exist
			&& ! \wp_next_scheduled( self::CLEANUP_CONTINUATION_HOOK )
		) {
			\wp_schedule_single_event(
				\time() + MINUTE_IN_SECONDS,
				self::CLEANUP_CONTINUATION_HOOK
			);
		}
	}

	/**
	 * Run dbDelta once when the analytics schema changes.
	 */
	public static function maybe_upgrade_table(): void {
		$current_version = (string) get_option( self::SCHEMA_VERSION_OPTION, '' );
		if (
			self::SCHEMA_VERSION === $current_version
			&& '' === CrawlerAnalyticsRecorder::get_health_error()
		) {
			return;
		}

		// A persistent database fault must not turn every frontend request into
		// another dbDelta() attempt. The value carries the target schema version
		// so a future plugin upgrade can bypass a stale repair guard immediately.
		if ( self::SCHEMA_VERSION === (string) get_transient( self::SCHEMA_REPAIR_TRANSIENT ) ) {
			return;
		}

		\Cybermaps\Core\CacheManager::set(
			self::SCHEMA_REPAIR_TRANSIENT,
			self::SCHEMA_VERSION,
			HOUR_IN_SECONDS,
			'schema'
		);
		self::create_table();
	}

	public static function create_table(): void {
		global $wpdb;
		$table_name       = $wpdb->prefix . 'cybermaps_logs';
		$charset_collate  = $wpdb->get_charset_collate();
		$previous_version = (string) get_option( self::SCHEMA_VERSION_OPTION, '' );

		$sql = "CREATE TABLE $table_name (
id bigint(20) NOT NULL AUTO_INCREMENT,
time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
bot varchar(100) NOT NULL,
category varchar(50) NOT NULL,
url varchar(255) NOT NULL,
request_kind varchar(20) DEFAULT 'page' NOT NULL,
endpoint_id varchar(100) DEFAULT '' NOT NULL,
response_status smallint(3) unsigned DEFAULT 200 NOT NULL,
delivery varchar(20) DEFAULT 'php' NOT NULL,
recognized tinyint(1) unsigned DEFAULT 0 NOT NULL,
ip_address varchar(45) NOT NULL,
user_agent text NOT NULL,
crawler_id varchar(100) DEFAULT '' NOT NULL,
identity_status varchar(30) DEFAULT 'legacy' NOT NULL,
verification_method varchar(30) DEFAULT 'legacy' NOT NULL,
client_type varchar(30) DEFAULT 'legacy' NOT NULL,
requester_key varchar(40) DEFAULT '' NOT NULL,
wp_user_id bigint(20) unsigned DEFAULT 0 NOT NULL,
request_method varchar(10) DEFAULT '' NOT NULL,
accept_type varchar(20) DEFAULT '' NOT NULL,
ip_source varchar(64) DEFAULT 'legacy' NOT NULL,
ip_storage varchar(20) DEFAULT 'legacy' NOT NULL,
PRIMARY KEY	 (id),
KEY time (time),
KEY category (category),
KEY bot (bot),
KEY request_kind (request_kind),
KEY recognized (recognized),
KEY endpoint_id (endpoint_id),
KEY endpoint_activity (request_kind, endpoint_id, time),
KEY crawler_id (crawler_id),
KEY identity_status (identity_status),
KEY requester_key (requester_key),
KEY requester_activity (requester_key, time),
KEY wp_user_id (wp_user_id)
) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$wpdb->last_error = '';
		dbDelta( $sql );

		if ( empty( $wpdb->last_error ) ) {
			$backfill_succeeded = true;
			if ( version_compare( $previous_version, '3', '<' ) && method_exists( $wpdb, 'query' ) ) {
				// Existing rows were created only for recognized crawlers.
				$table = $wpdb->prefix . 'cybermaps_logs';
				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The schema migration backfills the exact legacy rows created before recognition state was persisted.
				$backfill_result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE %i
					SET recognized = 1
					WHERE category NOT IN ('unrecognized', 'authenticated')",
						$table
					)
				);
				// phpcs:enable
				$backfill_succeeded = false !== $backfill_result && empty( $wpdb->last_error );
			}
			if ( ! $backfill_succeeded ) {
				CrawlerAnalyticsRecorder::persist_health_error();
				return;
			}
			update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
			CrawlerAnalyticsRecorder::clear_health_error();
			\Cybermaps\Core\CacheManager::clear_family( 'analytics' );
			return;
		}

		CrawlerAnalyticsRecorder::persist_health_error();
	}

	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'cybermaps_crawler_logs',
			__( 'CYBERMAPS: Recent PHP-Observed Requests', 'cybermaps' ),
			array( $this, 'render_widget_content' )
		);
	}

	public function render_widget_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$logs = $this->get_repository()->get_widget_activity();

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No endpoint observations or crawler-signature requests have reached PHP yet.', 'cybermaps' ) . '</p>';
		} else {
			echo '<ul style="margin: 0; padding: 0; list-style: none;">';
			foreach ( $logs as $log ) {
				$time_diff = human_time_diff( self::site_local_log_timestamp( (string) self::row_value( $log, 'time' ) ), time() );
				$kind      = 'endpoint' === (string) self::row_value( $log, 'request_kind' )
					? __( 'endpoint', 'cybermaps' )
					: __( 'content', 'cybermaps' );
				$status    = (int) self::row_value( $log, 'response_status', 200 );
				echo '<li style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f1;">';
				echo '<strong>' . esc_html( (string) self::row_value( $log, 'bot' ) ) . '</strong> (' . esc_html( $kind ) . ', ' . esc_html( (string) $status ) . ')<br>';
				echo '<code style="font-size: 11px;">' . esc_html( (string) self::row_value( $log, 'url' ) ) . '</code><br>';
				echo '<small style="color: #64748b;">';
				printf(
					/* translators: %s: relative time (e.g. 5 minutes) */
					esc_html__( '%s ago', 'cybermaps' ),
					esc_html( $time_diff )
				);
				echo '</small>';
				echo '</li>';
			}
			echo '</ul>';
		}

		$logs_url = admin_url( 'admin.php?page=cybermaps-discovery-analytics#request-log' );

		echo '<p style="margin-top: 15px; margin-bottom: 0;">';
		echo '<a href="' . esc_url( $logs_url ) . '" class="button button-secondary">' . esc_html__( 'Open Discovery Analytics', 'cybermaps' ) . '</a>';
		echo '</p>';
	}

	/**
	 * Start final-status observation for a normal frontend request.
	 */
	public function begin_frontend_observation(): void {
		$this->get_recorder()->begin_frontend_observation();
	}

	/**
	 * Observe completed Cybermaps REST responses.
	 *
	 * @param mixed $response REST response or error.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed Unmodified response.
	 */
	public function observe_rest_response( $response, $server, $request ) {
		return $this->get_recorder()->observe_rest_response( $response, $server, $request );
	}

	/**
	 * Reduce an IP address to a network identifier before local storage.
	 */
	public static function anonymize_ip( string $ip_address ): string {
		return CrawlerAnalyticsRecorder::anonymize_ip( $ip_address );
	}

	/**
	 * Lazily create the recorder for direct method calls in integrations/tests.
	 */
	private function get_recorder(): CrawlerAnalyticsRecorder {
		if ( null === $this->recorder ) {
			$this->recorder = new CrawlerAnalyticsRecorder();
		}

		return $this->recorder;
	}

	private function get_repository(): CrawlerAnalyticsRepository {
		if ( null === $this->repository ) {
			$this->repository = new CrawlerAnalyticsRepository();
		}

		return $this->repository;
	}

	/**
	 * Convert the site-local MySQL timestamp stored by the analytics recorder.
	 */
	private static function site_local_log_timestamp( string $value ): int {
		$timestamp = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, wp_timezone() );
		return false === $timestamp ? time() : $timestamp->getTimestamp();
	}

	/**
	 * Delete a bounded number of rows for one WordPress user.
	 *
	 * A limit of zero removes every matching row and is used only by the
	 * deleted_user lifecycle hook.
	 */
	private static function delete_user_observations_by_id( int $user_id, int $limit ): int|false {
		if ( $user_id < 1 ) {
			return 0;
		}

		global $wpdb;
		$table            = $wpdb->prefix . 'cybermaps_logs';
		$wpdb->last_error = '';
		$result           = $limit > 0
			? self::delete_bounded_user_observations( $wpdb, $table, $user_id, $limit )
			: self::delete_all_user_observations( $wpdb, $table, $user_id );

		return self::normalize_delete_result( $result, (string) $wpdb->last_error );
	}

	/**
	 * Delete the oldest bounded ID page for a WordPress user.
	 *
	 * @param object $database WordPress database adapter.
	 */
	private static function delete_bounded_user_observations( object $database, string $table, int $user_id, int $limit ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Privacy erasure must read the bounded persisted ID set before deleting it.
		$ids = $database->get_col(
			$database->prepare(
				'SELECT id FROM %i WHERE wp_user_id = %d ORDER BY id ASC LIMIT %d',
				$table,
				$user_id,
				$limit
			)
		);
		if ( ! empty( $database->last_error ) ) {
			return false;
		}
		$ids = self::normalized_observation_ids( $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = \implode( ',', \array_fill( 0, \count( $ids ), '%d' ) );
		$query        = $database->prepare(
			"DELETE FROM %i WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One integer placeholder is generated for each bounded, absint-normalized ID.
			...array_merge( array( $table ), $ids )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The complete bounded delete statement is prepared immediately above.
		return $database->query( $query );
	}

	/**
	 * Delete every observation for a user during the deleted_user lifecycle.
	 *
	 * @param object $database WordPress database adapter.
	 */
	private static function delete_all_user_observations( object $database, string $table, int $user_id ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- The deleted_user lifecycle must remove every persisted observation for this exact user ID.
		return $database->query(
			$database->prepare( 'DELETE FROM %i WHERE wp_user_id = %d', $table, $user_id )
		);
	}

	/** @return int[] */
	private static function normalized_observation_ids( mixed $ids ): array {
		$values = \is_array( $ids ) ? $ids : array();
		return \array_values( \array_filter( \array_map( 'absint', $values ) ) );
	}

	private static function normalize_delete_result( mixed $result, string $last_error ): int|false {
		if ( false === $result || '' !== $last_error ) {
			return false;
		}

		return max( 0, (int) $result );
	}

	/**
	 * Build the canonical administration destination for analytics actions.
	 *
	 * @param array<string,string> $query Additional result state.
	 */
	private static function analytics_url( array $query = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => 'cybermaps-discovery-analytics' ), $query ),
			admin_url( 'admin.php' )
		);
	}
}
