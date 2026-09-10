<?php
/**
 * AI Token-Rate Limiting Service
 *
 * Endpoint-aware rate limiting with three tiers:
 * - cheap: lightweight endpoints (ai.json, ai-usage.json, skill.md)
 * - medium: standard endpoints (llms.txt, feed.json, sitemaps)
 * - expensive: compute-heavy endpoints (llms-full.txt, llms-tldr.txt, knowledge-graph.json, ai-sitemap.xml)
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\AtomicMinuteCounter;
use Cybermaps\Core\ClientIPResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Throttler {

	/** @var array<string, int> Tier → default TPM limit */
	private const TIER_LIMITS = array(
		'cheap'     => 120,
		'medium'    => 60,
		'expensive' => 30,
	);

	/**
	 * Register both early public-request and REST lifecycle coverage.
	 */
	public function register_hooks(): void {
		add_action( 'parse_request', array( $this, 'check_throttle' ), 0, 1 );
		add_filter( 'rest_pre_dispatch', array( $this, 'check_rest_throttle' ), 10, 3 );
	}

	public function check_throttle(): void {
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_allowed( \Cybermaps\Core\ReadOnlyRequest::method() ) ) {
			return;
		}

		$path = \Cybermaps\Core\URLManager::get_request_path();
		if ( $this->is_discovery_path( $path ) && $this->request_is_exceeded( $path ) ) {
			$this->throttle_response();
		}
	}

	/**
	 * Apply the same limiter before Cybermaps REST callbacks execute.
	 *
	 * @param mixed $result  Preemptive REST response.
	 * @param mixed $server  REST server instance.
	 * @param mixed $request REST request instance.
	 * @return mixed
	 */
	public function check_rest_throttle( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		$raw_route = $request->get_route();
		$route     = \is_scalar( $raw_route ) ? (string) $raw_route : '';
		if ( '' === $route ) {
			return $result;
		}
		$match = \Cybermaps\Core\EndpointRegistry::get_instance()->match_rest_path( $route );
		if ( null === $match || ! $this->is_active_rest_match( $match ) ) {
			return $result;
		}
		$raw_method = \method_exists( $request, 'get_method' )
			? $request->get_method()
			: 'GET';
		$method     = \is_scalar( $raw_method )
			? \strtoupper( (string) $raw_method )
			: '';
		$id         = (string) ( $match['id'] ?? '' );
		if (
			( 'rest_purge' === $id && 'POST' !== $method )
			|| (
				'rest_purge' !== $id
				&& ! \Cybermaps\Core\ReadOnlyRequest::is_allowed( $method )
			)
		) {
			return $result;
		}

		if ( ! $this->request_is_exceeded( $route, $route ) ) {
			return $result;
		}

		header( 'Retry-After: 60' );
		header( 'X-Cybermaps-Throttled: true' );
		return new \WP_Error(
			'cybermaps_rate_limited',
			__( 'Rate limit exceeded. Please retry after 60 seconds.', 'cybermaps' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Check one crawler-signature match against its configured endpoint tier.
	 */
	private function request_is_exceeded( string $path, string $rest_route = '' ): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) && \is_scalar( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		$bot_id = \Cybermaps\Core\CrawlerRegistry::identify_bot( $ua );
		if ( ! $bot_id ) {
			// Cost protection must not depend on a caller truthfully identifying
			// itself. Unknown and empty User-Agents use the normal tier limit and
			// an IP-scoped counter, while known crawlers retain per-bot overrides.
			$bot_id = 'unidentified';
		}

		$tier  = $this->get_endpoint_tier( $path, $rest_route );
		$limit = $this->bot_limit( $bot_id );
		if ( 0 === $limit ) {
			$limit = self::TIER_LIMITS[ $tier ] ?? self::TIER_LIMITS['medium'];
		}
		if ( $limit <= 0 ) {
			return false;
		}

		$client_ip = ClientIPResolver::get_ip();
		$material  = '' !== $client_ip
			? $client_ip
			: AtomicMinuteCounter::UNRESOLVED_CLIENT_BUCKET;
		$cache_key = AtomicMinuteCounter::requester_bucket(
			'cm_tpm',
			$bot_id . '_' . $tier,
			$material
		);

		return AtomicMinuteCounter::increment( $cache_key ) > $limit;
	}

	private function bot_limit( string $bot_id ): int {
		$manager   = \Cybermaps\Core\ConfigurationStore::robots();
		$overrides = isset( $manager['overrides'] ) && \is_array( $manager['overrides'] )
			? $manager['overrides']
			: array();
		$override  = isset( $overrides[ $bot_id ] ) && \is_array( $overrides[ $bot_id ] )
			? $overrides[ $bot_id ]
			: array();
		$stored    = $override['tpm'] ?? null;
		return \is_scalar( $stored ) && \is_numeric( $stored ) ? (int) $stored : 0;
	}

	/**
	 * Determine the tier from canonical publication metadata.
	 */
	private function get_endpoint_tier( string $path, string $rest_route = '' ): string {
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		if ( '' !== $rest_route ) {
			$match = $registry->match_rest_path( $rest_route );
			return null !== $match
				? (string) ( $match['definition']['throttle_tier'] ?? 'medium' )
				: 'medium';
		}

		$match = $registry->match_path( $path );
		if ( null !== $match ) {
			return (string) ( $match['definition']['throttle_tier'] ?? 'medium' );
		}
		return $this->parameterized_tier( $path );
	}

	private function parameterized_tier( string $path ): string {
		if ( 1 === preg_match( '#^/discovery/chunks/[1-9][0-9]*\.json$#', $path ) ) {
			return 'expensive';
		}
		if ( 1 === preg_match( '#^/[a-z0-9_-]{2,16}/llms-tldr\.txt$#', $path ) ) {
			return 'expensive';
		}
		if ( 1 === preg_match( '#^/[a-z0-9_-]{2,16}/llms-full\.txt$#', $path ) ) {
			return 'expensive';
		}
		if ( 1 === preg_match( '#^/[a-z0-9_-]{2,16}/llms\.txt$#', $path ) ) {
			return 'medium';
		}
		if ( MarkdownAlternate::is_candidate_request( $path ) ) {
			return 'medium';
		}

		return 'medium';
	}

	private function throttle_response(): void {
		status_header( 429 );
		header( 'Retry-After: 60' );
		header( 'X-Cybermaps-Throttled: true' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		nocache_headers();
		esc_html_e( 'Rate limit exceeded. Please retry after 60 seconds.', 'cybermaps' );
		exit;
	}

	private function is_discovery_path( string $path ): bool {
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		$match    = $registry->match_path( $path );
		if ( null !== $match ) {
			return Integrity::is_hub_enabled()
				&& $registry->is_enabled( (string) ( $match['id'] ?? '' ) );
		}
		$parameterized = $this->parameterized_path_active( $path );
		if ( null !== $parameterized ) {
			return $parameterized;
		}
		return $this->is_negotiated_request() || $this->sitemap_path_active( $path );
	}

	private function is_negotiated_request(): bool {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) && is_scalar( $_SERVER['HTTP_ACCEPT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) )
			: '';
		return MarkdownNegotiation::is_enabled() && AcceptNegotiator::prefers_markdown( $accept );
	}

	private function parameterized_path_active( string $path ): ?bool {
		if ( 1 === preg_match( '#^/discovery/chunks/[1-9][0-9]*\.json$#', $path ) ) {
			$settings = \Cybermaps\Core\ConfigurationStore::settings();
			return Integrity::is_hub_enabled() && ! empty( $settings['enable_rag_chunks'] );
		}
		if ( 1 === preg_match( '#^/[a-z0-9_-]{2,16}/(llms(?:-full|-tldr)?\.txt)$#', $path, $matches ) ) {
			$settings = \Cybermaps\Core\ConfigurationStore::settings();
			if ( ! Integrity::is_hub_enabled() || empty( $settings['enable_multilingual_hub'] ) ) {
				return false;
			}
			if ( 'llms-full.txt' === $matches[1] ) {
				return ! empty( $settings['enable_llms_full'] );
			}
			if ( 'llms-tldr.txt' === $matches[1] ) {
				return ! empty( $settings['enable_llms_tldr'] );
			}
			return true;
		}
		if ( MarkdownAlternate::is_candidate_request( $path ) ) {
			return Integrity::is_hub_enabled();
		}
		return null;
	}

	private function sitemap_path_active( string $path ): bool {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$route    = \Cybermaps\Sitemap\SitemapRouteMatcher::match_path( $path, $settings );
		if ( null === $route ) {
			return false;
		}

		if ( 'news' === $route['route'] ) {
			return ! empty( $settings['enable_google_news'] );
		}
		if ( 'rss' === $route['route'] ) {
			return ! empty( $settings['enable_rss_sitemap'] );
		}
		if ( 'network' === $route['route'] ) {
			$network_settings = \function_exists( 'get_site_option' )
				? (array) get_site_option( 'cybermaps_network_settings', array() )
				: array();
			return \is_multisite()
				&& \is_main_site()
				&& ! empty( $network_settings['enable_master_index'] );
		}

		return true;
	}

	/**
	 * Avoid consuming counters for public REST publications that permissions
	 * will reject as disabled. Private integration routes remain protected
	 * independently of AI Publication Hub visibility.
	 *
	 * @param array{id: string, definition: array<string, mixed>} $route_match Registry match.
	 */
	private function is_active_rest_match( array $route_match ): bool {
		$id = (string) ( $route_match['id'] ?? '' );
		if ( ! in_array( $id, array( 'rest_root', 'rest_search', 'rest_llms_tldr' ), true ) ) {
			return true;
		}
		if ( ! Integrity::is_hub_enabled() ) {
			return false;
		}

		return \Cybermaps\Core\EndpointRegistry::get_instance()->is_enabled( $id );
	}
}
