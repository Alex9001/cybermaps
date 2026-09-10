<?php
/**
 * Privacy-bounded Cybermaps diagnostic logging.
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores short-lived, redacted Cybermaps diagnostic events. */
final class DiagnosticLogger {
	public const STATE_OPTION   = 'cybermaps_debug_state';
	public const ENTRIES_OPTION = 'cybermaps_debug_entries';

	private const DEFAULT_DURATION = DAY_IN_SECONDS;
	private const MAX_ENTRIES      = 200;
	private const MAX_CONTEXT      = 20;
	private const MAX_STRING       = 256;
	private const RETENTION        = 7 * DAY_IN_SECONDS;

	/** @var string[] */
	private const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	/** @var string[] */
	private const CONTEXT_KEYS = array(
		'attempt',
		'conflicted',
		'deleted',
		'delivery',
		'duration_ms',
		'enabled',
		'endpoint_id',
		'error_summary',
		'error_type',
		'expiry_hours',
		'failed',
		'generation',
		'http_status',
		'mode',
		'operation',
		'passed',
		'phase',
		'php_version',
		'plugin_version',
		'reason_code',
		'repair_scheduled',
		'resource_failures',
		'retained',
		'route_kind',
		'scheduled',
		'scope',
		'skipped',
		'source',
		'source_file',
		'source_line',
		'status',
		'total',
		'unchanged',
		'wp_debug',
		'written',
	);

	/** Register low-volume diagnostic sources. */
	public function register_hooks(): void {
		\add_action( 'cybermaps_debug_event', array( $this, 'record_event' ), 10, 3 );
		\add_action( 'cybermaps_static_sync_start', array( $this, 'record_static_start' ), 10, 10 );
		\add_action( 'cybermaps_static_sync_skipped', array( $this, 'record_static_skipped' ), 10, 10 );
		\add_action( 'cybermaps_static_sync_complete', array( $this, 'record_static_complete' ), 10, 10 );
		\add_action( 'cybermaps_static_repair_requested', array( $this, 'record_static_repair' ), 10, 10 );

		if ( self::is_enabled() ) {
			\register_shutdown_function( array( $this, 'capture_fatal_error' ) );
		}
	}

	/** Enable logging for a bounded duration. */
	public static function enable( int $duration = self::DEFAULT_DURATION ): array {
		$duration = self::normalize_duration( $duration );
		$now      = \time();
		\update_option(
			self::STATE_OPTION,
			array(
				'enabled'    => true,
				'enabled_at' => $now,
				'expires_at' => $now + $duration,
			),
			false
		);
		self::log(
			'debug.enabled',
			array(
				'expiry_hours'   => (int) ( $duration / HOUR_IN_SECONDS ),
				'wp_debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'php_version'    => PHP_VERSION,
				'plugin_version' => defined( 'CYBERMAPS_VERSION' ) ? CYBERMAPS_VERSION : 'unknown',
			)
		);

		return self::state_summary();
	}

	/** Stop collecting new events without destroying existing evidence. */
	public static function disable(): array {
		if ( self::is_enabled() ) {
			self::log( 'debug.disabled' );
		}
		$state               = self::load_state();
		$state['enabled']    = false;
		$state['expires_at'] = 0;
		\update_option( self::STATE_OPTION, $state, false );

		return self::state_summary();
	}

	/** Delete all collected diagnostic events. */
	public static function clear(): array {
		\delete_option( self::ENTRIES_OPTION );

		return self::state_summary();
	}

	/** Whether the time-bounded diagnostic session is active. */
	public static function is_enabled(): bool {
		$state = self::load_state();
		if ( empty( $state['enabled'] ) ) {
			return false;
		}
		if ( (int) ( $state['expires_at'] ?? 0 ) > \time() ) {
			return true;
		}
		$state['enabled']    = false;
		$state['expires_at'] = 0;
		\update_option( self::STATE_OPTION, $state, false );

		return false;
	}

	/** Store an event when collection is active. */
	public static function log( string $event, array $context = array(), string $level = 'info' ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		$event = self::normalize_identifier( $event );
		if ( '' === $event ) {
			return;
		}
		$entries   = self::load_entries();
		$entries[] = array(
			'time'    => \gmdate( 'c' ),
			'level'   => \in_array( $level, self::LEVELS, true ) ? $level : 'info',
			'event'   => $event,
			'context' => self::sanitize_context( $context ),
		);
		\update_option( self::ENTRIES_OPTION, \array_slice( $entries, -self::MAX_ENTRIES ), false );
	}

	/** WordPress action adapter for internal components and extensions. */
	public function record_event( mixed $event, mixed $context = array(), mixed $level = 'info' ): void {
		self::log(
			\is_string( $event ) ? $event : '',
			\is_array( $context ) ? $context : array(),
			\is_string( $level ) ? $level : 'info'
		);
	}

	/** Record aggregate static synchronization startup evidence. */
	public function record_static_start( mixed ...$arguments ): void {
		self::log( 'static.sync.started', self::hook_context( $arguments ) );
	}

	/** Record an aggregate static synchronization skip. */
	public function record_static_skipped( mixed ...$arguments ): void {
		self::log( 'static.sync.skipped', self::hook_context( $arguments ), 'warning' );
	}

	/** Record aggregate static synchronization results. */
	public function record_static_complete( mixed ...$arguments ): void {
		$context = self::hook_context( $arguments );
		$level   = (int) ( $context['failed'] ?? 0 ) > 0 ? 'error' : 'info';
		self::log( 'static.sync.completed', $context, $level );
	}

	/** Record a requested static repair without paths or target URLs. */
	public function record_static_repair( mixed ...$arguments ): void {
		self::log( 'static.repair.requested', self::hook_context( $arguments ), 'warning' );
	}

	/** Capture only fatal errors originating inside this plugin. */
	public function capture_fatal_error(): void {
		$error = \error_get_last();
		if ( ! \is_array( $error ) || ! self::is_fatal_type( (int) ( $error['type'] ?? 0 ) ) ) {
			return;
		}
		$file = (string) ( $error['file'] ?? '' );
		if ( ! self::is_plugin_file( $file ) ) {
			return;
		}
		self::log(
			'runtime.fatal_error',
			array(
				'error_type'    => (int) $error['type'],
				'error_summary' => (string) ( $error['message'] ?? 'Fatal error' ),
				'source_file'   => self::relative_plugin_file( $file ),
				'source_line'   => (int) ( $error['line'] ?? 0 ),
			),
			'error'
		);
	}

	/** Public-safe state for the Debugging interface. */
	public static function state_summary(): array {
		$entries = self::load_entries();
		$state   = self::load_state();
		$enabled = self::is_enabled();

		return array(
			'enabled'     => $enabled,
			'expires_at'  => $enabled ? \gmdate( 'c', (int) $state['expires_at'] ) : '',
			'entry_count' => \count( $entries ),
		);
	}

	/** Build the bounded diagnostic portion of a support bundle. */
	public static function support_bundle( array $status_report ): array {
		return array(
			'schema_version' => 1,
			'generated_at'   => \gmdate( 'c' ),
			'status'         => $status_report,
			'debugging'      => array(
				'state'   => self::state_summary(),
				'entries' => self::load_entries(),
			),
			'omitted_data'   => array(
				'credentials',
				'cookies_and_headers',
				'request_bodies',
				'ip_and_email_addresses',
				'urls_and_filesystem_paths',
				'crawler_analytics',
			),
		);
	}

	/** @return array<string, mixed> */
	private static function load_state(): array {
		$state = \get_option( self::STATE_OPTION, array() );

		return \is_array( $state ) ? $state : array();
	}

	/** @return array<int, array<string, mixed>> */
	private static function load_entries(): array {
		$stored         = \get_option( self::ENTRIES_OPTION, array() );
		$entries        = \is_array( $stored ) ? $stored : array();
		$original_count = \count( $entries );
		$cutoff         = \time() - self::RETENTION;

		$entries = \array_filter(
			$entries,
			static fn( mixed $entry ): bool => \is_array( $entry )
				&& (int) \strtotime( (string) ( $entry['time'] ?? '' ) ) >= $cutoff
		);

		$entries = \array_slice( \array_values( $entries ), -self::MAX_ENTRIES );
		if ( \count( $entries ) !== $original_count ) {
			\update_option( self::ENTRIES_OPTION, $entries, false );
		}

		return $entries;
	}

	/** @return array<string, bool|float|int|string> */
	private static function sanitize_context( array $context ): array {
		$sanitized = array();
		foreach ( $context as $key => $value ) {
			$key = self::normalize_identifier( (string) $key );
			if ( ! \in_array( $key, self::CONTEXT_KEYS, true ) || ! self::is_scalar_context( $value ) ) {
				continue;
			}
			$sanitized[ $key ] = \is_string( $value ) ? self::sanitize_string( $value ) : $value;
			if ( \count( $sanitized ) >= self::MAX_CONTEXT ) {
				break;
			}
		}

		return $sanitized;
	}

	private static function sanitize_string( string $value ): string {
		$value = \wp_strip_all_tags( $value );
		$value = \str_replace( array( "\r", "\n", "\t" ), ' ', $value );
		$value = \preg_replace( '/\bBearer\s+\S+/i', '[redacted]', $value ) ?? '[redacted]';
		$value = \preg_replace( '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $value ) ?? '[redacted]';
		$value = \preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $value ) ?? '[redacted]';
		$value = \preg_replace( '#https?://[^\s]+#i', '[url]', $value ) ?? '[redacted]';
		$value = \preg_replace( '/\b[A-Za-z0-9_-]{32,}\b/', '[redacted]', $value ) ?? '[redacted]';
		$value = \str_replace( self::known_paths(), '[path]', $value );
		$value = \preg_replace( '/\s+/', ' ', $value ) ?? '';

		return \substr( \trim( $value ), 0, self::MAX_STRING );
	}

	/** @return string[] */
	private static function known_paths(): array {
		$paths = array();
		foreach ( array( 'CYBERMAPS_PLUGIN_DIR', 'ABSPATH', 'WP_CONTENT_DIR' ) as $constant ) {
			if ( \defined( $constant ) && \is_string( \constant( $constant ) ) ) {
				$paths[] = \constant( $constant );
			}
		}

		return \array_filter( $paths );
	}

	private static function normalize_identifier( string $value ): string {
		$value = \strtolower( $value );
		$value = \preg_replace( '/[^a-z0-9_.-]+/', '_', $value ) ?? '';

		return \substr( \trim( $value, '._-' ), 0, 64 );
	}

	private static function normalize_duration( int $duration ): int {
		$allowed = array( HOUR_IN_SECONDS, 4 * HOUR_IN_SECONDS, DAY_IN_SECONDS );

		return \in_array( $duration, $allowed, true ) ? $duration : self::DEFAULT_DURATION;
	}

	private static function is_scalar_context( mixed $value ): bool {
		return \is_bool( $value ) || \is_float( $value ) || \is_int( $value ) || \is_string( $value );
	}

	private static function is_fatal_type( int $type ): bool {
		return \in_array( $type, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
	}

	private static function is_plugin_file( string $file ): bool {
		return '' !== $file && \str_starts_with( self::normalize_path( $file ), self::plugin_path() );
	}

	private static function relative_plugin_file( string $file ): string {
		return \ltrim( \substr( self::normalize_path( $file ), \strlen( self::plugin_path() ) ), '/' );
	}

	private static function plugin_path(): string {
		return \rtrim( self::normalize_path( (string) CYBERMAPS_PLUGIN_DIR ), '/' ) . '/';
	}

	private static function normalize_path( string $path ): string {
		return \str_replace( '\\', '/', $path );
	}

	/** @return array<string, bool|float|int|string> */
	private static function hook_context( array $arguments ): array {
		$context = array();
		foreach ( $arguments as $argument ) {
			if ( \is_array( $argument ) ) {
				$context = \array_merge( $context, $argument );
			}
		}

		return self::sanitize_context( $context );
	}
}
