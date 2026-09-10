<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\CrawlerRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classify the evidence presented by one PHP-observed request.
 *
 * A User-Agent match is deliberately described as a claim. Provider
 * verification is a separate concern and must never be inferred from a string.
 */
final class RequestIdentityClassifier {

	/**
	 * Classify a request without claiming more identity than the evidence proves.
	 *
	 * @return array{
	 *     bot:string,
	 *     category:string,
	 *     recognized:int,
	 *     crawler_id:string,
	 *     identity_status:string,
	 *     verification_method:string,
	 *     client_type:string
	 * }
	 */
	public static function classify( string $user_agent, bool $logged_in ): array {
		if ( $logged_in ) {
			return self::identity(
				'Logged-in site user',
				'internal',
				0,
				'',
				'internal',
				'wordpress_session',
				'internal'
			);
		}

		$match = CrawlerRegistry::match_user_agent( $user_agent );
		if ( null !== $match ) {
			return self::identity(
				$match->metadata->name,
				$match->metadata->category->value,
				1,
				$match->id,
				'claimed',
				$match->basis,
				'crawler'
			);
		}

		if ( '' === \trim( $user_agent ) ) {
			return self::identity(
				'Client without User-Agent',
				'unrecognized',
				0,
				'',
				'no-user-agent',
				'none',
				'no-user-agent'
			);
		}

		if ( self::looks_like_unregistered_bot( $user_agent ) ) {
			return self::identity(
				'Unregistered crawler candidate',
				'unregistered-bot',
				0,
				'',
				'unregistered-bot',
				'heuristic',
				'bot-like'
			);
		}

		if ( self::looks_like_automated_client( $user_agent ) ) {
			return self::identity(
				'Automated client',
				'unrecognized',
				0,
				'',
				'automated-client',
				'heuristic',
				'automated'
			);
		}

		if ( self::looks_like_browser( $user_agent ) ) {
			return self::identity(
				'Browser or manual request',
				'unrecognized',
				0,
				'',
				'browser',
				'heuristic',
				'browser'
			);
		}

		return self::identity(
			'Unrecognized request',
			'unrecognized',
			0,
			'',
			'unknown',
			'none',
			'unknown'
		);
	}

	/**
	 * Decide whether an ordinary content request is useful crawler evidence.
	 *
	 * Normal browsers, logged-in users, and ambiguous clients remain excluded
	 * from page analytics. Explicit registry matches and conservative bot-like
	 * candidates are retained.
	 *
	 * @param array<string, mixed> $identity Classified request identity.
	 */
	public static function should_record_page( array $identity ): bool {
		return 1 === (int) ( $identity['recognized'] ?? 0 )
			|| 'unregistered-bot' === (string) ( $identity['identity_status'] ?? '' );
	}

	private static function looks_like_unregistered_bot( string $user_agent ): bool {
		return 1 === \preg_match(
			'~(?:^|[\s(;])(?:[A-Za-z0-9._-]{1,80})?(?:bot|spider|crawler|fetcher|scraper|scanner|indexer|preview)(?:/[A-Za-z0-9._-]+)?(?=$|[\s;,)])~i',
			$user_agent
		);
	}

	private static function looks_like_automated_client( string $user_agent ): bool {
		return 1 === \preg_match(
			'~(?:curl/|Wget/|python-(?:requests|httpx)|Go-http-client/|libwww-perl|GuzzleHttp/|okhttp/|axios/|node-fetch|WordPress/|PostmanRuntime/|Java/)~i',
			$user_agent
		);
	}

	private static function looks_like_browser( string $user_agent ): bool {
		return 1 === \preg_match(
			'~(?:Mozilla/|Chrome/|Chromium/|Firefox/|Safari/|Edg/|OPR/)~i',
			$user_agent
		);
	}

	/**
	 * Build the stable storage shape.
	 *
	 * @return array{
	 *     bot:string,
	 *     category:string,
	 *     recognized:int,
	 *     crawler_id:string,
	 *     identity_status:string,
	 *     verification_method:string,
	 *     client_type:string
	 * }
	 */
	private static function identity(
		string $bot,
		string $category,
		int $recognized,
		string $crawler_id,
		string $identity_status,
		string $verification_method,
		string $client_type
	): array {
		return array(
			'bot'                 => $bot,
			'category'            => $category,
			'recognized'          => $recognized,
			'crawler_id'          => $crawler_id,
			'identity_status'     => $identity_status,
			'verification_method' => $verification_method,
			'client_type'         => $client_type,
		);
	}
}
