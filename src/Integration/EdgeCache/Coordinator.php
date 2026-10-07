<?php
/**
 * Edge cache invalidation coordinator.
 *
 * @package Cybermaps\Integration\EdgeCache
 */

declare(strict_types=1);

namespace Cybermaps\Integration\EdgeCache;

use Cybermaps\Discovery\PublicationCachePolicy;
use Cybermaps\Core\RawOptionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates portable invalidation events and opt-in adapter delivery.
 */
final class Coordinator {
	private const STATUS_OPTION  = 'cybermaps_edge_cache_delivery_status';
	private const PENDING_OPTION = 'cybermaps_edge_cache_pending_static';
	private const MAX_URLS       = 50;
	private const MAX_EVENTS     = 20;
	private const MAX_RETRIES    = 3;
	private const MAX_URL_LENGTH = 2048;
	private const CAS_ATTEMPTS   = 8;
	private string $queue_error  = 'queue_storage_failed';

	/**
	 * Register completion, retry and bounded scheduling recovery hooks. Core
	 * does not alter a server or cache without an explicitly enabled adapter.
	 */
	public function register_hooks(): void {
		add_action( 'cybermaps_static_sync_complete', array( $this, 'on_static_sync_complete' ), 10, 1 );
		add_action( 'cybermaps_edge_cache_retry', array( $this, 'retry_pending' ) );
		add_action( 'init', array( $this, 'recover_pending_retry' ) );
	}

	/**
	 * Dispatch an invalidation after the caller has cleared WordPress-local
	 * cache state. The generic action always fires, even with no vendor adapter.
	 *
	 * @param string   $family Publication family.
	 * @param string[] $urls Exact public URLs.
	 * @param bool     $wait_for_static Require a complete static reconciliation.
	 * @param bool     $url_scope_complete Whether the exact URL list covers the affected publication scope.
	 * @return array<string,mixed>
	 */
	public function invalidate( string $family, array $urls = array(), bool $wait_for_static = false, bool $url_scope_complete = true ): array {
		$event = $this->event( $family, $urls, $url_scope_complete );
		if ( $wait_for_static ) {
			$queued = $this->queue_static( $event );
			return $this->record(
				$event,
				array(
					'status'   => $queued ? 'pending_static_sync' : $this->queue_error,
					'adapters' => array(),
				)
			);
		}

		return $this->dispatch( $event );
	}

	/**
	 * Dispatch pending static events only after a complete, conflict-free run.
	 *
	 * @param array<string,mixed> $report StaticBridge report.
	 */
	public function on_static_sync_complete( array $report ): void {
		if ( 'complete' !== (string) ( $report['status'] ?? '' ) || ! empty( $report['conflicted'] ) || ! empty( $report['failed'] ) || ! empty( $report['retained'] ) ) {
			return;
		}

		foreach ( $this->pending_events() as $event ) {
			if ( ! is_array( $event ) || '' === $this->event_token( $event ) ) {
				continue;
			}
			$event['static_ready'] = true;
			$event['next_attempt'] = 0;
			if ( ! $this->replace_pending_event( $event ) ) {
				$this->record( $event, array( 'status' => $this->queue_error ) );
				continue;
			}
			$this->deliver_pending_event( $event );
		}
	}

	/** Recover a rejected cron schedule on a later request without delivery. */
	public function recover_pending_retry(): void {
		$this->schedule_pending_retry();
	}

	/** Recover stranded scheduling without dispatching or advancing attempts. */
	private function schedule_pending_retry(): void {
		$next_attempt = null;
		foreach ( $this->pending_events() as $event ) {
			if ( ! is_array( $event ) || empty( $event['static_ready'] ) || '' === $this->event_token( $event ) ) {
				continue;
			}
			$timestamp    = (int) ( $event['next_attempt'] ?? 0 );
			$next_attempt = null === $next_attempt ? $timestamp : min( $next_attempt, $timestamp );
		}
		if ( null !== $next_attempt ) {
			$scheduled = $this->schedule_retry( $next_attempt );
			foreach ( $this->pending_events() as $event ) {
				if ( is_array( $event ) && ! empty( $event['static_ready'] ) ) {
					$this->record( $event, array( 'status' => $scheduled ? 'retry_pending' : 'schedule_failed' ) );
				}
			}
		}
	}

	/** Retry only events that a completed static sync already made publishable. */
	public function retry_pending(): void {
		$now = time();
		foreach ( $this->pending_events() as $event ) {
			if ( ! is_array( $event ) || empty( $event['static_ready'] ) || (int) ( $event['next_attempt'] ?? 0 ) > $now ) {
				continue;
			}
			$this->deliver_pending_event( $event );
		}
		$this->schedule_pending_retry();
	}

	/**
	 * Return bounded delivery status for REST/admin diagnostics.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_status(): array {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? array_slice( $status, 0, self::MAX_EVENTS ) : array();
	}

	/**
	 * @param string[] $urls Exact public URLs.
	 * @param bool     $url_scope_complete Whether all affected URLs were supplied.
	 * @return array<string,mixed>
	 */
	private function event( string $family, array $urls, bool $url_scope_complete ): array {
		$policy              = PublicationCachePolicy::for_publication( $family, 'invalidation' );
		$requested_url_count = count( $urls );
		$truncated_url_count = max( 0, $requested_url_count - self::MAX_URLS );
		$urls                = self::normalize_purge_urls( $urls, $policy );

		return array(
			'id'                  => wp_generate_uuid4(),
			'time'                => time(),
			'family'              => (string) $policy['family'],
			'generation'          => (int) $policy['generation'],
			'tags'                => PublicationCachePolicy::tags( $policy ),
			'urls'                => array_slice( $urls, 0, self::MAX_URLS ),
			'requested_url_count' => $requested_url_count,
			'truncated_url_count' => $truncated_url_count,
			'url_scope_complete'  => $url_scope_complete && 0 === $truncated_url_count && count( $urls ) === $requested_url_count,
			'attempts'            => 0,
			'next_attempt'        => 0,
			'static_ready'        => false,
		);
	}

	/**
	 * Validate exact public targets before any adapter can forward their Host.
	 *
	 * @param string[]           $urls Candidate exact public URLs.
	 * @param array<string,mixed> $event Event/policy context for allowlist filters.
	 * @return string[]
	 */
	public static function normalize_purge_urls( array $urls, array $event = array() ): array {
		$valid = array();
		foreach ( array_slice( $urls, 0, self::MAX_URLS ) as $url ) {
			if ( ! is_string( $url ) || ! self::is_allowed_purge_url( $url, $event ) ) {
				continue;
			}
			$valid[] = $url;
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * A target must be an exact HTTP(S) URL for the current origin or an
	 * explicitly allowlisted host. This intentionally does not trust Host or
	 * forwarding headers.
	 *
	 * @param array<string,mixed> $event Event context.
	 */
	public static function is_allowed_purge_url( string $url, array $event = array() ): bool {
		if (
			'' === $url
			|| self::MAX_URL_LENGTH < strlen( $url )
			|| 1 === preg_match( '/[\x00-\x20\x7F]/', $url )
			|| 1 === preg_match( '/%(?:00|0a|0d)/i', $url )
			|| str_contains( $url, '\\' )
		) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['fragment'] )
			|| ( isset( $parts['port'] ) && ( (int) $parts['port'] < 1 || (int) $parts['port'] > 65535 ) )
		) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( '' === $host || 1 === preg_match( '/[\x00-\x20\x7F@]/', $host ) ) {
			return false;
		}

		$origin = wp_parse_url( home_url( '/' ) );
		if ( is_array( $origin ) && isset( $origin['scheme'], $origin['host'] ) ) {
			$same_scheme = 0 === strcasecmp( (string) $parts['scheme'], (string) $origin['scheme'] );
			$same_host   = 0 === strcasecmp( $host, (string) $origin['host'] );
			$same_port   = self::normalized_port( $parts ) === self::normalized_port( $origin );
			if ( $same_scheme && $same_host && $same_port ) {
				return true;
			}
		}

		$allowed = apply_filters( 'cybermaps_edge_purge_hosts', array(), $event );
		if ( ! is_array( $allowed ) ) {
			return false;
		}
		foreach ( array_slice( $allowed, 0, self::MAX_URLS ) as $allowed_host ) {
			if ( is_string( $allowed_host ) && 0 === strcasecmp( trim( $allowed_host ), $host ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $parts */
	private static function normalized_port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80;
	}

	/**
	 * @param array<string,mixed> $event Event.
	 */
	private function queue_static( array $event ): bool {
		return $this->replace_pending_event( $event, true );
	}

	/**
	 * @param array<string,mixed> $event Event.
	 * @return array<string,mixed>
	 */
	private function dispatch( array $event ): array {
		/**
		 * Fires for every Cybermaps edge invalidation. Generic CDNs/reverse-proxy
		 * integrations should consume this event rather than requiring Core to
		 * store their credentials or mutate their server configuration.
		 *
		 * @param array<string,mixed> $event Bounded invalidation event.
		 */
		do_action( 'cybermaps_edge_invalidation', $event );

		$adapters   = array();
		$litespeed  = new LiteSpeedAdapter();
		$adapters[] = $litespeed->purge( $event );
		$adapters[] = ( new VarnishAdapter() )->purge( $event );

		$failed_delivery           = $this->has_failed_delivery( $adapters );
		$result                    = $this->record(
			$event,
			array(
				'status'          => $this->has_incomplete_delivery( $adapters ) ? 'incomplete' : 'dispatched',
				'adapters'        => $adapters,
				'failed_delivery' => $failed_delivery,
			)
		);
		$result['failed_delivery'] = $failed_delivery;
		do_action( 'cybermaps_edge_invalidation_complete', $result, $event );

		return $result;
	}

	/** @param array<string,mixed> $event */
	private function deliver_pending_event( array $event ): void {
		$token = $this->event_token( $event );
		if ( '' === $token ) {
			return;
		}
		$result = $this->dispatch( $event );
		if ( empty( $result['failed_delivery'] ) ) {
			$this->remove_pending_event( $token );
			return;
		}

		$attempts = max( 0, (int) ( $event['attempts'] ?? 0 ) ) + 1;
		if ( self::MAX_RETRIES < $attempts ) {
			$this->remove_pending_event( $token );
			$this->record(
				$event,
				array(
					'status'   => 'failed',
					'adapters' => (array) ( $result['adapters'] ?? array() ),
				)
			);
			return;
		}

		$event['attempts']     = $attempts;
		$event['next_attempt'] = time() + min( 3600, 60 * ( 2 ** ( $attempts - 1 ) ) );
		if ( ! $this->replace_pending_event( $event ) ) {
			$this->record(
				$event,
				array(
					'status'   => $this->queue_error,
					'adapters' => $result['adapters'] ?? array(),
				)
			);
			return;
		}
		$scheduled = $this->schedule_retry( (int) $event['next_attempt'] );
		$this->record(
			$event,
			array(
				'status'   => $scheduled ? 'retry_pending' : 'schedule_failed',
				'adapters' => (array) ( $result['adapters'] ?? array() ),
			)
		);
	}

	/** @param array<int,array<string,mixed>> $adapters */
	private function has_failed_delivery( array $adapters ): bool {
		foreach ( $adapters as $adapter ) {
			$status = is_array( $adapter ) ? (string) ( $adapter['status'] ?? '' ) : '';
			if ( in_array( $status, array( 'partial', 'transport_error', 'transport_unavailable', 'invalid_configuration' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<int,array<string,mixed>> $adapters */
	private function has_incomplete_delivery( array $adapters ): bool {
		foreach ( $adapters as $adapter ) {
			$status = is_array( $adapter ) ? (string) ( $adapter['status'] ?? '' ) : '';
			if ( in_array( $status, array( 'unsupported_scope', 'incomplete_scope' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<int,array<string,mixed>> */
	private function pending_events(): array {
		global $wpdb;
		$raw     = RawOptionStore::read( $wpdb, self::PENDING_OPTION );
		$pending = is_string( $raw ) ? maybe_unserialize( $raw ) : array();
		return is_array( $pending ) ? $pending : array();
	}

	/** @param array<string,mixed> $event Event. */
	private function replace_pending_event( array $event, bool $append = false ): bool {
		$token = $this->event_token( $event );
		return $this->mutate_pending(
			static function ( array $pending ) use ( $token, $event, $append ): array|false {
				foreach ( $pending as $index => $stored ) {
					if ( is_array( $stored ) && ( $stored['id'] ?? '' ) === $token ) {
						$pending[ $index ] = $event;
						return $pending;
					}
				}
				if ( ! $append || '' === $token ) {
					return $pending;
				}
				if ( count( $pending ) >= self::MAX_EVENTS ) {
					return false;
				}
				$pending[] = $event;
				return $pending;
			}
		);
	}

	private function remove_pending_event( string $token ): void {
		$removed = $this->mutate_pending(
			static fn( array $pending ): array => array_values(
				array_filter( $pending, static fn( mixed $event ): bool => ! is_array( $event ) || ( $event['id'] ?? '' ) !== $token )
			)
		);
		if ( ! $removed ) {
			$this->record( array( 'id' => $token ), array( 'status' => $this->queue_error ) );
		}
	}

	/** Atomically change the observed queue, retrying conflicts without eviction.
	 *
	 * @param callable(array):array|false $mutation Queue operation.
	 */
	private function mutate_pending( callable $mutation ): bool {
		global $wpdb;
		$this->queue_error = 'queue_storage_failed';
		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$raw = RawOptionStore::read( $wpdb, self::PENDING_OPTION );
			if ( false === $raw ) {
				return false;
			}
			$pending = null === $raw ? array() : maybe_unserialize( $raw );
			if ( ! is_array( $pending ) || count( $pending ) > self::MAX_EVENTS ) {
				return false;
			}
			$next = $mutation( $pending );
			if ( false === $next ) {
				$this->queue_error = 'queue_full';
				return false;
			}
			if ( $next === $pending ) {
				return true;
			}
			$result = $this->write_pending( $raw, $next );
			if ( false === $result ) {
				return false;
			}
			if ( 1 === $result ) {
				RawOptionStore::invalidate( self::PENDING_OPTION );
				return true;
			}
		}
		$this->queue_error = 'queue_contention';
		return false;
	}

	/** @param array<int,array<string,mixed>> $next Queue snapshot. */
	private function write_pending( ?string $raw, array $next ): int|false {
		global $wpdb;
		$serialized = maybe_serialize( array_values( $next ) );
		return null === $raw
			? RawOptionStore::insert( $wpdb, self::PENDING_OPTION, $serialized )
			: RawOptionStore::replace( $wpdb, self::PENDING_OPTION, $raw, $serialized );
	}

	private function event_token( array $event ): string {
		$token = $event['id'] ?? '';
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9-]{1,96}$/', $token ) ? $token : '';
	}

	private function schedule_retry( int $timestamp ): bool {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return false;
		}
		$timestamp = max( time() + 1, $timestamp );
		$scheduled = wp_next_scheduled( 'cybermaps_edge_cache_retry' );
		if ( false !== $scheduled ) {
			if ( $scheduled <= $timestamp ) {
				return true;
			}
			wp_clear_scheduled_hook( 'cybermaps_edge_cache_retry' );
		}
		return true === wp_schedule_single_event( $timestamp, 'cybermaps_edge_cache_retry', array(), true );
	}

	/**
	 * @param array<string,mixed> $event Event.
	 * @param array<string,mixed> $result Result.
	 * @return array<string,mixed>
	 */
	private function record( array $event, array $result ): array {
		$row     = array_merge(
			$this->event_url_coverage( $event ),
			array(
				'id'         => (string) ( $event['id'] ?? '' ),
				'time'       => (int) ( $event['time'] ?? time() ),
				'family'     => (string) ( $event['family'] ?? '' ),
				'generation' => (int) ( $event['generation'] ?? 0 ),
				'tag_count'  => count( (array) ( $event['tags'] ?? array() ) ),
				'url_count'  => count( (array) ( $event['urls'] ?? array() ) ),
				'status'     => (string) ( $result['status'] ?? 'unknown' ),
				'adapters'   => array_slice( (array) ( $result['adapters'] ?? array() ), 0, 4 ),
			)
		);
		$history = get_option( self::STATUS_OPTION, array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift( $history, $row );
		update_option( self::STATUS_OPTION, array_slice( $history, 0, self::MAX_EVENTS ), false );
		return $row;
	}

	/** @param array<string,mixed> $event Event. @return array<string,int|bool> */
	private function event_url_coverage( array $event ): array {
		return array(
			'requested_url_count' => max( count( (array) ( $event['urls'] ?? array() ) ), (int) filter_var( $event['requested_url_count'] ?? 0, FILTER_VALIDATE_INT ) ),
			'truncated_url_count' => max( 0, (int) filter_var( $event['truncated_url_count'] ?? 0, FILTER_VALIDATE_INT ) ),
			'url_scope_complete'  => true === ( $event['url_scope_complete'] ?? false ),
		);
	}
}
