<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Cybermaps\Discovery\BotCategory;

/**
 * Bot metadata DTO.
 *
 * Represents a registered crawler or bot with its configuration.
 */
readonly class BotMetadata {
	/**
	 * @param string      $name       Display name of the bot.
	 * @param string      $company    Company behind the bot.
	 * @param string      $ua         Primary User-Agent product token.
	 * @param BotCategory $category   Category of the bot.
	 * @param array{robots: bool, llm: bool} $default Default permissions.
	 * @param string        $desc                 Description of the bot's purpose.
	 * @param array<string> $ua_aliases           Additional explicit or retired User-Agent product tokens governed by the same policy.
	 * @param bool          $recognizes_requests  Whether the robots token also appears in HTTP requests.
	 * @param array<string> $request_only_aliases Request signatures that must not be emitted as robots.txt product tokens.
	 * @param bool          $supports_robots_policy Whether the crawler exposes a meaningful robots.txt policy control.
	 */
	public function __construct(
		public string $name,
		public string $company,
		public string $ua,
		public BotCategory $category,
		public array $default, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Public DTO constructor signature is retained for named-argument compatibility.
		public string $desc,
		public array $ua_aliases = array(),
		public bool $recognizes_requests = true,
		public array $request_only_aliases = array(),
		public bool $supports_robots_policy = true
	) {}

	/**
	 * Get every explicit User-Agent product token for this crawler.
	 *
	 * The primary token remains separate because it is the canonical token
	 * emitted in discovery metadata.
	 *
	 * @return array<string>
	 */
	public function get_user_agent_signatures(): array {
		if ( ! $this->recognizes_requests ) {
			return array();
		}

		$signatures = array_merge(
			array( $this->ua ),
			$this->ua_aliases,
			$this->request_only_aliases
		);
		$signatures = array_filter(
			array_map( 'trim', $signatures ),
			static fn( string $signature ): bool => '' !== $signature
		);

		return array_values( array_unique( $signatures ) );
	}

	/**
	 * Get every robots.txt product token governed by this control.
	 *
	 * Policy-only entries deliberately return their primary token even though
	 * that token never appears in an HTTP request User-Agent.
	 *
	 * @return array<string>
	 */
	public function get_robots_tokens(): array {
		if ( ! $this->supports_robots_policy ) {
			return array();
		}

		$tokens = array_merge( array( $this->ua ), $this->ua_aliases );
		$tokens = array_filter(
			array_map( 'trim', $tokens ),
			static fn( string $token ): bool => '' !== $token
		);

		return array_values( array_unique( $tokens ) );
	}
}
