<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Robots.txt Service
 */
class Robots {
	/**
	 * Keep malformed or directly modified option values from producing an
	 * unexpectedly large virtual robots.txt response.
	 */
	public const MAX_MANUAL_DIRECTIVES_BYTES = 32768;

	/** Maximum number of path-specific Content-Usage rules. */
	public const MAX_CONTENT_USAGE_OVERRIDES = 50;

	/** Maximum encoded path length accepted for a Content-Usage rule. */
	public const MAX_CONTENT_USAGE_PATH_BYTES = 2048;

	/**
	 * Supported Content-Signal fields in their canonical publication order.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_SIGNAL_KEYS = array( 'ai-train', 'search', 'ai-input' );

	/**
	 * Register the virtual robots.txt delivery contract and content filter.
	 */
	public function register_hooks(): void {
		add_action( 'wp', array( $this, 'guard_request' ), 0 );
		add_action( 'send_headers', array( $this, 'send_content_usage_header' ), 20 );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 10, 2 );
	}

	/**
	 * Add the opt-in AIPREF header to dynamic GET and HEAD responses. The same
	 * hook runs for conditional requests, so a 304 receives the representation
	 * metadata when WordPress serves one.
	 */
	public function send_content_usage_header(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$uri   = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';
		$path  = wp_parse_url( $uri, PHP_URL_PATH );
		$path  = is_string( $path ) ? $path : '/';
		$value = $this->get_content_usage_header_for_path( $path );
		if ( '' !== $value ) {
			header( 'Content-Usage: ' . $value );
		}
	}

	/**
	 * Resolve the normalized AIPREF value for a request path.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	public function get_content_usage_header_for_path( string $path ): string {
		$manager = \Cybermaps\Core\ConfigurationStore::robots();
		if ( empty( $manager['content_usage_enabled'] ) ) {
			return '';
		}

		$path        = self::normalize_content_usage_path( $path );
		$preferences = self::content_usage_preferences( $manager['content_signals'] ?? array() );
		$overrides   = self::content_usage_overrides( $manager['content_usage_overrides'] ?? array() );
		uksort(
			$overrides,
			static function ( string $left, string $right ): int {
				$length_compare = strlen( $right ) <=> strlen( $left );
				return 0 !== $length_compare ? $length_compare : strcmp( $left, $right );
			}
		);

		foreach ( $overrides as $prefix => $override_preferences ) {
			if ( str_starts_with( $path, $prefix ) ) {
				$preferences = $override_preferences;
				break;
			}
		}

		return self::format_content_usage_preferences( $preferences );
	}

	/**
	 * Give WordPress's virtual robots.txt endpoint the same read-only method and
	 * media-type contract as the other Cybermaps public publications.
	 *
	 * Core exits before do_robots() on HEAD requests, so the content type must
	 * be corrected before the template loader runs.
	 */
	public function guard_request(): void {
		if ( ! is_robots() ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		Integrity::send_cors_headers();
		PublicationRequestGuard::enforce_active_route();
	}

	/**
	 * Filter robots.txt output to inject sitemap and AI discovery links.
	 *
	 * @param string $output The robots.txt content.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.publicFound -- Preserve WordPress's established robots_txt callback signature for named callers.
	public function filter_robots_txt( $output, $public ) {
		if ( ! (bool) $public ) {
			return "User-agent: *\nDisallow: /\n";
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$manager  = \Cybermaps\Core\ConfigurationStore::robots();
		$context  = self::robots_output_context( $manager );
		$takeover = ! empty( $manager['takeover_enabled'] );
		$output   = $takeover
			? self::takeover_output( $context )
			: self::append_output( (string) $output, $settings, $context );

		if ( $takeover || ! empty( $settings['inject_robots'] ) ) {
			$output .= self::publication_map( $settings );
		}

		return $output;
	}

	/**
	 * Normalize the values shared by takeover and append rendering.
	 *
	 * @param array<string, mixed> $manager Robots Manager settings.
	 * @return array<string, mixed>
	 */
	private static function robots_output_context( array $manager ): array {
		$content_usage_enabled = ! empty( $manager['content_usage_enabled'] );
		$content_usage         = $content_usage_enabled
			? self::format_content_usage_preferences( self::content_usage_preferences( $manager['content_signals'] ?? array() ) )
			: '';
		$content_overrides     = $content_usage_enabled
			? self::content_usage_overrides( $manager['content_usage_overrides'] ?? array() )
			: array();

		return array(
			'registry'          => \Cybermaps\Core\CrawlerRegistry::get_policy_bots(),
			'overrides'         => isset( $manager['overrides'] ) && is_array( $manager['overrides'] ) ? $manager['overrides'] : array(),
			'manual'            => self::format_manual_directives( is_scalar( $manager['manual_directives'] ?? null ) ? (string) $manager['manual_directives'] : '' ),
			'signals'           => self::format_content_signals( is_array( $manager['content_signals'] ?? null ) ? $manager['content_signals'] : array() ),
			'content_usage'     => $content_usage,
			'content_overrides' => $content_overrides,
			'has_content_usage' => '' !== $content_usage || array() !== $content_overrides,
		);
	}

	/**
	 * Render complete takeover mode output.
	 *
	 * @param array<string, mixed> $context Normalized render context.
	 */
	private static function takeover_output( array $context ): string {
		$output = "# --- CYBERMAPS ROBOTS CONTROL ---\n\n";
		if ( '' !== $context['signals'] ) {
			$output .= self::content_signal_description();
		}

		$output .= "User-agent: *\n";
		if ( '' !== $context['signals'] ) {
			$output .= 'Content-Signal: ' . $context['signals'] . "\n";
		}
		if ( $context['has_content_usage'] ) {
			$output .= self::content_usage_lines( $context['content_usage'], $context['content_overrides'] );
		}
		$output .= "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\n";
		if ( '' !== $context['manual'] ) {
			$output .= $context['manual'] . "\n\n";
		}

		return $output . self::crawler_denials( $context['registry'], $context['overrides'] );
	}

	/**
	 * Render append mode additions.
	 *
	 * @param array<string, mixed> $settings General settings.
	 * @param array<string, mixed> $context  Normalized render context.
	 */
	private static function append_output( string $output, array $settings, array $context ): string {
		if ( empty( $settings['inject_robots'] ) && '' === $context['manual'] && '' === $context['signals'] && ! $context['has_content_usage'] ) {
			return $output;
		}

		$output .= "\n# --- CYBERMAPS ROBOTS ADDITIONS ---\n";
		if ( '' !== $context['signals'] ) {
			$output .= "User-agent: *\nContent-Signal: " . $context['signals'] . "\n\n";
		}
		if ( $context['has_content_usage'] ) {
			$output .= "User-agent: *\n";
			$output .= self::content_usage_lines( $context['content_usage'], $context['content_overrides'] );
			$output .= "\n";
		}
		if ( '' !== $context['manual'] ) {
			$output .= $context['manual'] . "\n\n";
		}

		return $output;
	}

	/**
	 * Explain Content-Signal fields without claiming enforcement.
	 */
	private static function content_signal_description(): string {
		return "# Content-Signal declares machine-readable publisher preferences.\n"
			. "# Client support and interpretation vary by crawler.\n"
			. "# ai-train -> model training or fine-tuning\n"
			. "# search   -> search indexing and result presentation\n"
			. "# ai-input -> retrieval or context use for AI responses\n\n";
	}

	/**
	 * Render global and path-specific AIPREF directives.
	 *
	 * @param array<string, array<string, string>> $overrides Path-specific preferences.
	 */
	private static function content_usage_lines( string $global_preferences, array $overrides ): string {
		$output = '';
		if ( '' !== $global_preferences ) {
			$output .= 'Content-Usage: ' . $global_preferences . "\n";
		}
		foreach ( $overrides as $path => $preferences ) {
			$output .= 'Content-Usage: ' . $path . ' ' . self::format_content_usage_preferences( $preferences ) . "\n";
		}
		return $output;
	}

	/**
	 * Render only crawler-specific denials so allowed bots inherit wildcard rules.
	 *
	 * @param array<string, object>              $registry  Registered policy bots.
	 * @param array<string, array<string, mixed>> $overrides Stored bot overrides.
	 */
	private static function crawler_denials( array $registry, array $overrides ): string {
		$output = '';
		foreach ( $registry as $id => $bot ) {
			$override = isset( $overrides[ $id ] ) && is_array( $overrides[ $id ] ) ? $overrides[ $id ] : array();
			$allowed  = array_key_exists( 'robots', $override ) ? (bool) $override['robots'] : (bool) $bot->default['robots'];
			if ( $allowed ) {
				continue;
			}
			foreach ( $bot->get_robots_tokens() as $token ) {
				$output .= 'User-agent: ' . $token . "\n";
			}
			$output .= "Disallow: /\n\n";
		}
		return $output;
	}

	/**
	 * Render the independently controlled endpoint-advertising block.
	 *
	 * @param array<string, mixed> $settings General settings.
	 */
	private static function publication_map( array $settings ): string {
		$base    = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$output  = "# --- CYBERMAPS PUBLICATION MAP ---\n";
		$output .= 'Sitemap: ' . \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' ) . "\n";
		if ( ! empty( $settings['enable_google_news'] ) ) {
			$news_base = \Cybermaps\Sitemap\Orchestrator::get_news_sitemap_base();
			$output   .= 'Sitemap: ' . \Cybermaps\Core\URLManager::get_home_url( '/' . $news_base . '.xml' ) . "\n";
		}
		if ( ! empty( $settings['enable_rss_sitemap'] ) ) {
			$rss_base = \Cybermaps\Sitemap\Orchestrator::get_rss_sitemap_base();
			$output  .= 'Sitemap: ' . \Cybermaps\Core\URLManager::get_home_url( '/' . $rss_base . '.xml' ) . "\n";
		}
		if ( ! empty( $settings['enable_discovery_hub'] ) ) {
			$output .= self::discovery_map();
		}

		return $output . "# --- END CYBERMAPS ---\n";
	}

	/**
	 * Render discovery endpoint links in their established order.
	 */
	private static function discovery_map(): string {
		$endpoints = \Cybermaps\Core\EndpointRegistry::get_instance();
		return 'Discovery: ' . $endpoints->get_url( 'adp_discovery' ) . "\n"
			. 'Cybermaps-Manifest: ' . $endpoints->get_url( 'manifest' ) . "\n"
			. 'LLMS-Map: ' . $endpoints->get_url( 'llms' ) . "\n"
			. 'AI-Sitemap: ' . $endpoints->get_url( 'ai_sitemap' ) . "\n"
			. 'JSON-Feed: ' . $endpoints->get_url( 'feed' ) . "\n"
			. 'Knowledge-Graph: ' . $endpoints->get_url( 'knowledge_graph' ) . "\n";
	}

	/**
	 * Make publisher-authored rules safe to append as independent robots
	 * groups. Complete explicit groups are retained verbatim. A paragraph that
	 * contains bare Allow or Disallow directives is scoped to User-agent: * so
	 * it cannot become an orphan after a preceding blank line.
	 */
	private static function format_manual_directives( string $manual ): string {
		if ( strlen( $manual ) > self::MAX_MANUAL_DIRECTIVES_BYTES ) {
			$manual = substr( $manual, 0, self::MAX_MANUAL_DIRECTIVES_BYTES );
		}

		$manual = str_replace( array( "\r\n", "\r" ), "\n", trim( $manual ) );
		if ( '' === $manual ) {
			return '';
		}

		$paragraphs = preg_split( '/\n[ \t]*\n+/', $manual );
		if ( false === $paragraphs ) {
			return $manual;
		}

		$formatted = array();
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = self::format_manual_paragraph( $paragraph );
			if ( '' !== $paragraph ) {
				$formatted[] = $paragraph;
			}
		}

		return implode( "\n\n", $formatted );
	}

	/**
	 * Scope one publisher-authored paragraph when it contains path rules.
	 */
	private static function format_manual_paragraph( string $paragraph ): string {
		$paragraph = trim( $paragraph );
		if ( '' === $paragraph || ! preg_match( '/^[ \t]*(?:allow|disallow)[ \t]*:/mi', $paragraph ) ) {
			return $paragraph;
		}

		$lines            = explode( "\n", $paragraph );
		$first_user_agent = self::first_user_agent_line( $lines );
		if ( null === $first_user_agent ) {
			return "User-agent: *\n" . $paragraph;
		}
		if ( 0 === $first_user_agent ) {
			return $paragraph;
		}

		$prefix = implode( "\n", array_slice( $lines, 0, $first_user_agent ) );
		if ( ! preg_match( '/^[ \t]*(?:allow|disallow)[ \t]*:/mi', $prefix ) ) {
			return $paragraph;
		}

		return "User-agent: *\n" . trim( $prefix )
			. "\n\n" . implode( "\n", array_slice( $lines, $first_user_agent ) );
	}

	/**
	 * Locate the first explicit crawler group in a manual paragraph.
	 *
	 * @param string[] $lines Paragraph lines.
	 */
	private static function first_user_agent_line( array $lines ): ?int {
		foreach ( $lines as $index => $line ) {
			if ( preg_match( '/^[ \t]*user-agent[ \t]*:/i', $line ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Accept only the documented Content-Signal vocabulary and publish it in
	 * a deterministic order, even when the stored option was edited directly.
	 *
	 * @param array<mixed> $stored_signals Stored configuration value.
	 */
	private static function format_content_signals( array $stored_signals ): string {
		$signals = array();

		foreach ( self::CONTENT_SIGNAL_KEYS as $key ) {
			$value = $stored_signals[ $key ] ?? null;
			if ( ! is_string( $value ) || ! in_array( $value, array( 'yes', 'no' ), true ) ) {
				continue;
			}

			$signals[] = $key . '=' . $value;
		}

		return implode( ', ', $signals );
	}

	/**
	 * Normalize a leading-slash path for the AIPREF path-prefix contract.
	 *
	 * @param mixed $path Raw path.
	 * @return string Empty when the path is invalid.
	 */
	public static function normalize_content_usage_path( $path ): string {
		if ( ! is_scalar( $path ) ) {
			return '';
		}
		$path = trim( (string) $path );
		$path = (string) preg_replace( '/[?#].*$/', '', $path );
		if ( '' === $path || preg_match( '/[\x00-\x20\x7f]/', $path ) || preg_match( '#(?:^|/)\.\.?(?:/|$)#', $path ) ) {
			return '';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$path = (string) preg_replace( '#/+#', '/', $path );
		if ( strlen( $path ) > self::MAX_CONTENT_USAGE_PATH_BYTES ) {
			return '';
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Convert Content-Signal-compatible settings into AIPREF vocabulary.
	 *
	 * @param mixed $stored Stored preference map.
	 * @return array<string, string>
	 */
	private static function content_usage_preferences( $stored ): array {
		$stored = is_array( $stored ) ? $stored : array();
		$values = array();
		foreach (
			array(
				'ai-train' => 'train-ai',
				'search'   => 'search',
			) as $source => $target
		) {
			$value = $stored[ $source ] ?? null;
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = strtolower( trim( (string) $value ) );
			if ( 'yes' === $value || 'y' === $value ) {
				$values[ $target ] = 'y';
			} elseif ( 'no' === $value || 'n' === $value ) {
				$values[ $target ] = 'n';
			}
		}

		return $values;
	}

	/**
	 * Normalize stored path-specific maps and retain at most 50 valid entries.
	 *
	 * @param mixed $stored Stored override map.
	 * @return array<string, array<string, string>>
	 */
	private static function content_usage_overrides( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$overrides = array();
		foreach ( $stored as $path => $preferences ) {
			$path = self::normalize_content_usage_path( $path );
			if ( '' === $path || ! is_array( $preferences ) ) {
				continue;
			}
			$preferences = self::content_usage_preferences( $preferences );
			if ( array() === $preferences ) {
				continue;
			}
			$overrides[ $path ] = $preferences;
			if ( count( $overrides ) >= self::MAX_CONTENT_USAGE_OVERRIDES ) {
				break;
			}
		}
		ksort( $overrides, SORT_STRING );

		return $overrides;
	}

	/**
	 * Serialize an AIPREF structured-field dictionary in canonical order.
	 *
	 * @param array<string, string> $preferences Preferences.
	 */
	private static function format_content_usage_preferences( array $preferences ): string {
		$values = array();
		foreach ( array( 'train-ai', 'search' ) as $key ) {
			if ( isset( $preferences[ $key ] ) && in_array( $preferences[ $key ], array( 'y', 'n' ), true ) ) {
				$values[] = $key . '=' . $preferences[ $key ];
			}
		}

		return implode( ', ', $values );
	}
}
