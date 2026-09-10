<?php
/**
 * Edge cache invalidation coordinator.
 *
 * @package Cybermaps\Integration\EdgeCache
 */

declare(strict_types=1);

namespace Cybermaps\Integration\EdgeCache;

use Cybermaps\Discovery\PublicationCachePolicy;

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

	/**
	 * Register only the completion hook. Call this from Plugin bootstrap; Core
	 * deliberately does not alter a server or cache without that integration.
	 */
	public function register_hooks(): void {
		add_action( 'cybermaps_static_sync_complete', array( $this, 'on_static_sync_complete' ), 10, 1 );
		add_action( 'cybermaps_edge_cache_retry', array( $this, 'retry_pending' ) );
	}

	/**
	 * Dispatch an invalidation after the caller has cleared WordPress-local
	 * cache state. The generic action always fires, even with no vendor adapter.
	 *
	 * @param string   $family Publication family.
	 * @param string[] $urls Exact public URLs.
	 * @param bool     $wait_for_static Require a complete static reconciliation.
	 * @return array<string,mixed>
	 */
	public function invalidate( string $family, array $urls = array(), bool $wait_for_static = false ): array {
		$event = $this->event( $family, $urls );
		if ( $wait_for_static ) {
			$this->queue_static( $event );
			return $this->record(
				$event,
				array(
					'status'   => 'pending_static_sync',
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
			$this->replace_pending_event( $event );
			$this->deliver_pending_event( $event );
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
	 * @return array<string,mixed>
	 */
	private function event( string $family, array $urls ): array {
		$policy = PublicationCachePolicy::for_publication( $family, 'invalidation' );
		$urls   = self::normalize_purge_urls( $urls, $policy );

		return array(
			'id'           => wp_generate_uuid4(),
			'time'         => time(),
			'family'       => (string) $policy['family'],
			'generation'   => (int) $policy['generation'],
			'tags'         => PublicationCachePolicy::tags( $policy ),
			'urls'         => array_slice( $urls, 0, self::MAX_URLS ),
			'attempts'     => 0,
			'next_attempt' => 0,
			'static_ready' => false,
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
	private function queue_static( array $event ): void {
		$this->replace_pending_event( $event, true );
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
				'status'          => 'dispatched',
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
		$this->replace_pending_event( $event );
		$this->schedule_retry( (int) $event['next_attempt'] );
		$this->record(
			$event,
			array(
				'status'   => 'retry_pending',
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

	/** @return array<int,array<string,mixed>> */
	private function pending_events(): array {
		$pending = get_option( self::PENDING_OPTION, array() );
		return is_array( $pending ) ? array_slice( $pending, 0, self::MAX_EVENTS ) : array();
	}

	/**
	 * Replace only the matching token, preserving events written by another
	 * request after this worker obtained its original snapshot.
	 *
	 * @param array<string,mixed> $event Event.
	 */
	private function replace_pending_event( array $event, bool $append = false ): void {
		$token   = $this->event_token( $event );
		$pending = $this->pending_events();
		$updated = false;
		foreach ( $pending as $index => $stored ) {
			if ( is_array( $stored ) && $token === $this->event_token( $stored ) ) {
				$pending[ $index ] = $event;
				$updated           = true;
				break;
			}
		}
		if ( ! $updated && $append && '' !== $token ) {
			$pending[] = $event;
		}
		update_option( self::PENDING_OPTION, array_slice( array_values( $pending ), -self::MAX_EVENTS ), false );
	}

	private function remove_pending_event( string $token ): void {
		$pending = array_values(
			array_filter(
				$this->pending_events(),
				fn( mixed $event ): bool => ! is_array( $event ) || $token !== $this->event_token( $event )
			)
		);
		update_option( self::PENDING_OPTION, $pending, false );
	}

	private function event_token( array $event ): string {
		$token = $event['id'] ?? '';
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9-]{1,96}$/', $token ) ? $token : '';
	}

	private function schedule_retry( int $timestamp ): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( 'cybermaps_edge_cache_retry' ) ) {
			return;
		}
		wp_schedule_single_event( max( time() + 1, $timestamp ), 'cybermaps_edge_cache_retry' );
	}

	/**
	 * @param array<string,mixed> $event Event.
	 * @param array<string,mixed> $result Result.
	 * @return array<string,mixed>
	 */
	private function record( array $event, array $result ): array {
		$row     = array(
			'id'         => (string) ( $event['id'] ?? '' ),
			'time'       => (int) ( $event['time'] ?? time() ),
			'family'     => (string) ( $event['family'] ?? '' ),
			'generation' => (int) ( $event['generation'] ?? 0 ),
			'tag_count'  => count( (array) ( $event['tags'] ?? array() ) ),
			'url_count'  => count( (array) ( $event['urls'] ?? array() ) ),
			'status'     => (string) ( $result['status'] ?? 'unknown' ),
			'adapters'   => array_slice( (array) ( $result['adapters'] ?? array() ), 0, 4 ),
		);
		$history = get_option( self::STATUS_OPTION, array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift( $history, $row );
		update_option( self::STATUS_OPTION, array_slice( $history, 0, self::MAX_EVENTS ), false );
		return $row;
	}
}
