<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Content\VisibleTextExtractor;
use Cybermaps\Core\CacheManager;
use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\URLManager;
use Cybermaps\Sitemap\Orchestrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the community llms.txt convention and the opt-in literal full corpus.
 */
class LLMS {
	public const SUMMARY_CACHE_KEY         = 'cybermaps_llms_cache';
	public const FULL_CACHE_KEY            = 'cybermaps_llms_full_cache';
	public const OUTPUT_MAX_BYTES          = ( 4 * 1024 * 1024 ) - 1;
	public const FULL_OUTPUT_MAX_BYTES     = ( 32 * 1024 * 1024 ) - 1;
	private const CACHE_TTL                = 12 * HOUR_IN_SECONDS;
	private const DATABASE_CACHE_MAX_BYTES = 512 * 1024;

	/**
	 * Handle canonical and localized LLMS requests.
	 */
	public function handle(): void {
		$path  = (string) URLManager::get_request_path();
		$route = $this->match_request( $path );
		if ( null === $route ) {
			return;
		}
		$lang       = $route['language'];
		$is_summary = 'summary' === $route['type'];
		$is_full    = 'full' === $route['type'];

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if (
			empty( $settings['enable_discovery_hub'] )
			|| ( $is_full && empty( $settings['enable_llms_full'] ) )
			|| ( '' !== $lang && ! $this->localized_request_is_enabled( $lang, $settings ) )
		) {
			return;
		}
		PublicationRequestGuard::enforce_active_route();
		if ( '' !== $lang ) {
			$lang = (string) \Cybermaps\Core\TranslationHelper::resolve_active_language( $lang );
		}

		$original_language = '';
		if ( '' !== $lang ) {
			$original_language = (string) \Cybermaps\Core\TranslationHelper::get_current_language();
			\Cybermaps\Core\TranslationHelper::switch_to_language( $lang );
		}

		try {
			try {
				$output = $this->get_llms_content( $is_full, false, $lang );
			} finally {
				if ( '' !== $lang ) {
					\Cybermaps\Core\TranslationHelper::switch_to_language( $original_language );
				}
			}
		} catch ( BuildUnavailableException $error ) {
			PublicationRequestGuard::serve_unavailable( $error );
		} catch ( PublicationSizeLimitException $error ) {
			$this->serve_size_limit_error( $error );
		}

		Integrity::send_headers( $output );
		header( 'Content-Type: text/markdown; charset=utf-8' );

		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text protocol body.
			echo $output;
		}
		exit;
	}

	/**
	 * Build the concise map or the complete literal publication.
	 */
	public function get_llms_content(
		bool $is_full = false,
		bool $skip_cache = false,
		string $language = ''
	): string {
		// Language variants share generation fences, but never cached bodies.
		$key = $is_full ? self::FULL_CACHE_KEY : self::SUMMARY_CACHE_KEY;
		if ( '' !== $language ) {
			$key .= ':' . sanitize_key( $language );
		}
		$producer = function () use ( $is_full, $language ): string {
			$settings  = \Cybermaps\Core\ConfigurationStore::settings();
			$inventory = new PublicationInventory( $settings, null, $language );
			$extractor = new VisibleTextExtractor();
			return $is_full
				? $this->generate_full( $settings, $inventory, $extractor )
				: $this->generate_summary( $settings, $inventory, $extractor );
		};

		return (string) CacheManager::remember(
			$key,
			self::CACHE_TTL,
			'discovery',
			$producer,
			fn ( $output ): bool =>
				\is_string( $output )
				&& '' !== $output
				&& \strlen( $output ) <= self::OUTPUT_MAX_BYTES
				&& $this->can_cache_without_large_database_value( $output ),
			60,
			$skip_cache || $is_full
		);
	}

	/**
	 * Match a canonical or localized LLMS route.
	 *
	 * @return array{type:string,language:string}|null
	 */
	protected function match_request( string $path ): ?array {
		if ( '/llms.txt' === $path ) {
			return array(
				'type'     => 'summary',
				'language' => '',
			);
		}
		if ( '/llms-full.txt' === $path ) {
			return array(
				'type'     => 'full',
				'language' => '',
			);
		}
		if ( 1 === preg_match( '/^\/([a-z0-9_-]{2,16})\/(llms(?:-full)?\.txt)$/', $path, $matches ) ) {
			return array(
				'type'     => 'llms.txt' === (string) $matches[2] ? 'summary' : 'full',
				'language' => sanitize_key( (string) $matches[1] ),
			);
		}

		return null;
	}

	/**
	 * Localized routes exist only for the enabled multilingual publication hub.
	 */
	protected function localized_request_is_enabled( string $language, array $settings ): bool {
		return ! empty( $settings['enable_multilingual_hub'] )
			&& \Cybermaps\Core\TranslationHelper::is_active_language( $language );
	}

	/**
	 * Clear both literal LLMS publications.
	 */
	public static function invalidate_cache(): void {
		delete_transient( self::SUMMARY_CACHE_KEY );
		delete_transient( self::FULL_CACHE_KEY );
		CacheManager::clear_family( 'discovery' );
	}

	/**
	 */
	private function generate_summary(
		array $settings,
		PublicationInventory $inventory,
		VisibleTextExtractor $extractor
	): string {
		$title    = $this->site_title( $settings );
		$mission  = $this->mission( $settings );
		$guidance = PublisherGuidance::get( $settings );
		$output   = '';
		$this->append_complete( $output, '# ' . $this->markdown_text( $title ) . "\n\n", 'llms.txt' );
		$this->append_summary_intro( $output, $mission, $guidance, $settings );
		$counts = $this->append_summary_entries( $output, $settings, $inventory, $extractor );
		$this->append_summary_footer( $output, $settings, $counts['eligible'], $counts['selected'], $counts['truncated'] );
		return rtrim( $output ) . "\n";
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function append_summary_intro(
		string &$output,
		string $mission,
		string $guidance,
		array $settings
	): void {
		if ( '' !== $mission ) {
			$this->append_complete(
				$output,
				'> ' . str_replace( "\n", "\n> ", $mission ) . "\n\n",
				'llms.txt'
			);
		}
		if ( '' !== $guidance ) {
			$this->append_complete(
				$output,
				'Publisher guidance: ' . $guidance . "\n\n",
				'llms.txt'
			);
		}

		$license = PublicationConstraints::content_license(
			$settings['llms_content_license'] ?? ''
		);
		if ( '' !== $license ) {
			$this->append_complete(
				$output,
				'Content license: ' . $this->markdown_text( $license ) . "\n\n",
				'llms.txt'
			);
		}
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return array{eligible:int,selected:int,truncated:bool}
	 */
	private function append_summary_entries(
		string &$output,
		array $settings,
		PublicationInventory $inventory,
		VisibleTextExtractor $extractor
	): array {
		$current_post_type = '';
		$eligible_count    = 0;
		$selected_count    = 0;
		$link_limit        = PublicationConstraints::llms_link_limit(
			$settings['llms_link_limit'] ?? PublicationConstraints::LLMS_LINK_LIMIT_DEFAULT
		);
		$scan              = new PublicationScanBudget( PublicationConstraints::SUMMARY_CANDIDATE_SCAN_MAX );
		foreach ( $inventory->iterate_posts( array(), $scan ) as $post ) {
			++$eligible_count;
			if ( $selected_count >= $link_limit ) {
				break;
			}

			$post_type = (string) ( $post->post_type ?? 'content' );
			if ( $post_type !== $current_post_type ) {
				if ( '' !== $current_post_type ) {
					$this->append_complete( $output, "\n", 'llms.txt' );
				}
				$this->append_complete(
					$output,
					'## ' . $this->markdown_text( $this->post_type_label( $post_type ) ) . "\n\n",
					'llms.txt'
				);
				$current_post_type = $post_type;
			}

			$post_title = $this->markdown_text( (string) get_the_title( $post ) );
			$url        = MarkdownAlternate::url_for_post( $post );
			if ( '' === $url ) {
				$url = URLManager::rewrite_url( (string) get_permalink( $post ) );
			}
			$summary = $extractor->summary( $post, 40 );
			$entry   = '- [' . $post_title . '](' . $url . ')';
			if ( '' !== $summary ) {
				$entry .= ': ' . str_replace( "\n", ' ', $summary );
			}
			$this->append_complete( $output, $entry . "\n", 'llms.txt' );
			++$selected_count;
		}
		$scan->checkpoint();
		return array(
			'eligible'  => $eligible_count,
			'selected'  => $selected_count,
			'truncated' => $scan->truncated(),
		);
	}

	private function post_type_label( string $post_type ): string {
		$type_object = get_post_type_object( $post_type );
		return is_object( $type_object ) && isset( $type_object->labels->name )
			? (string) $type_object->labels->name
			: ucfirst( str_replace( array( '-', '_' ), ' ', $post_type ) );
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function append_summary_footer(
		string &$output,
		array $settings,
		int $eligible_count,
		int $selected_count,
		bool $truncated
	): void {
		if ( $selected_count > 0 ) {
			$this->append_complete( $output, "\n", 'llms.txt' );
		} elseif ( ! $truncated ) {
			$this->append_complete( $output, "No eligible published content is available.\n\n", 'llms.txt' );
		}
		if ( $truncated ) {
			$this->append_complete( $output, 'Coverage: selected ' . $selected_count . " eligible resources within the candidate scan limit; additional content may be available.\n\n", 'llms.txt' );
		} elseif ( $eligible_count > $selected_count ) {
			$this->append_complete(
				$output,
				'Coverage: selected ' . $selected_count . " eligible resources; additional eligible resources are available.\n\n",
				'llms.txt'
			);
		} elseif ( $eligible_count > 0 ) {
			$this->append_complete(
				$output,
				'Coverage: selected ' . $selected_count . ' of ' . $eligible_count . " eligible resources.\n\n",
				'llms.txt'
			);
		}

		$resources = array();
		if ( ! empty( $settings['llms_include_sitemap_link'] ) || $eligible_count > $selected_count || $truncated ) {
			$base        = Orchestrator::get_sitemap_base();
			$resources[] = '- [XML sitemap](' . URLManager::get_home_url( '/' . $base . '.xml' ) . ')';
		}
		$registry = EndpointRegistry::get_instance();
		if ( ! empty( $settings['enable_llms_full'] ) ) {
			$resources[] = '- [Complete literal content publication](' . $registry->get_url( 'llms_full' ) . ')';
		}
		if ( ! empty( $settings['enable_llms_tldr'] ) ) {
			$resources[] = '- [Experimental budgeted site briefing](' . $registry->get_url( 'llms_tldr' ) . ')';
		}
		if ( ! empty( $resources ) ) {
			$this->append_complete(
				$output,
				"## Optional\n\n" . implode( "\n", $resources ) . "\n",
				'llms.txt'
			);
		}
	}

	/**
	 * Build a complete literal publication or fail without returning a partial
	 * body when the hard in-memory ceiling would be crossed.
	 */
	private function generate_full(
		array $settings,
		PublicationInventory $inventory,
		VisibleTextExtractor $extractor
	): string {
		$output = '';
		$this->append_complete(
			$output,
			'# ' . $this->markdown_text( $this->site_title( $settings ) ) . " — Full Content\n\n"
				. "> Complete literal publication of eligible stored WordPress content. Dynamic blocks and shortcodes are not executed.\n\n",
			'llms-full.txt'
		);
		$this->append_full_intro( $output, $settings );
		$eligible_count = $this->append_full_entries( $output, $inventory, $extractor );
		if ( 0 === $eligible_count ) {
			$this->append_complete(
				$output,
				"No eligible published content is available.\n",
				'llms-full.txt'
			);
		}
		return rtrim( $output ) . "\n";
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function append_full_intro( string &$output, array $settings ): void {
		$guidance = PublisherGuidance::get( $settings );
		if ( '' !== $guidance ) {
			$this->append_complete(
				$output,
				"## Publisher guidance\n\n" . $guidance . "\n\n",
				'llms-full.txt'
			);
		}

		$license = PublicationConstraints::content_license(
			$settings['llms_content_license'] ?? ''
		);
		if ( '' !== $license ) {
			$this->append_complete(
				$output,
				'License assertion: ' . $this->markdown_text( $license ) . "\n\n",
				'llms-full.txt'
			);
		}
	}

	private function append_full_entries(
		string &$output,
		PublicationInventory $inventory,
		VisibleTextExtractor $extractor
	): int {
		$eligible_count = 0;
		foreach ( $inventory->iterate_posts() as $post ) {
			$this->append_full_post( $output, $post, $extractor );
			++$eligible_count;
		}
		return $eligible_count;
	}

	private function append_full_post( string &$output, object $post, VisibleTextExtractor $extractor ): void {
		$title     = $this->markdown_text( (string) get_the_title( $post ) );
		$url       = URLManager::rewrite_url( (string) get_permalink( $post ) );
		$modified  = (string) ( $post->post_modified_gmt ?? $post->post_date_gmt ?? '' );
		$metadata  = '## ' . $title . "\n\n";
		$metadata .= '- URL: ' . $url . "\n";
		$metadata .= '- Content-Type: ' . sanitize_key( (string) ( $post->post_type ?? 'post' ) ) . "\n";
		if ( '' !== $modified && '0000-00-00 00:00:00' !== $modified ) {
			$metadata .= '- Last-Modified: ' . gmdate( 'c', strtotime( $modified . ' UTC' ) ) . "\n";
		}
		$this->append_complete( $output, $metadata . "\n", 'llms-full.txt' );
		$raw_content = isset( $post->post_content ) && is_scalar( $post->post_content )
			? (string) $post->post_content
			: '';
		if ( strlen( $raw_content ) > self::FULL_OUTPUT_MAX_BYTES ) {
			throw new PublicationSizeLimitException( 'llms-full.txt', self::FULL_OUTPUT_MAX_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception data is later JSON-encoded or escaped by status views.
		}
		$this->require_memory_headroom( strlen( $raw_content ), 'llms-full.txt' );
		$text = $extractor->from_post( $post );
		$this->append_complete(
			$output,
			'' !== $text ? $text . "\n\n" : "[No visible stored text]\n\n",
			'llms-full.txt'
		);
	}

	/**
	 * Append one complete chunk without ever constructing a partial oversized
	 * publication.
	 */
	private function append_complete( string &$output, string $chunk, string $publication ): void {
		$current_bytes = strlen( $output );
		$chunk_bytes   = strlen( $chunk );
		$maximum_bytes = 'llms-full.txt' === $publication ? self::FULL_OUTPUT_MAX_BYTES : self::OUTPUT_MAX_BYTES;
		if (
			$current_bytes > $maximum_bytes
			|| $chunk_bytes > $maximum_bytes - $current_bytes
		) {
			throw new PublicationSizeLimitException( $publication, $maximum_bytes ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception data is later JSON-encoded or escaped by status views.
		}
		$this->require_memory_headroom( $current_bytes + $chunk_bytes, $publication );

		$output .= $chunk;
	}

	/** Reserve room for extraction, string copies, hashing, and WordPress shutdown. */
	private function require_memory_headroom( int $bytes, string $publication ): void {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return;
		}
		$available = max( 0, $limit - memory_get_usage( true ) - 16 * 1024 * 1024 );
		if ( $bytes > intdiv( $available, 4 ) ) {
			throw new PublicationSizeLimitException( $publication, intdiv( $available, 4 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception data is later JSON-encoded or escaped by status views.
		}
	}

	/**
	 * Do not turn a large generated body into a large database transient.
	 * Persistent object-cache installations can retain the bounded summary
	 * without inflating the options table.
	 */
	private function can_cache_without_large_database_value( string $output ): bool {
		return strlen( $output ) <= self::DATABASE_CACHE_MAX_BYTES
			|| (
				function_exists( 'wp_using_ext_object_cache' )
				&& wp_using_ext_object_cache()
			);
	}

	/**
	 * Emit a complete, machine-readable failure instead of a truncated LLMS
	 * document when Core's hard publication ceiling is exceeded.
	 */
	private function serve_size_limit_error( PublicationSizeLimitException $error ): never {
		$payload = wp_json_encode(
			array(
				'type'        => 'about:blank',
				'title'       => __( 'LLMS publication exceeds the safe output limit.', 'cybermaps' ),
				'status'      => 507,
				'detail'      => $error->getMessage(),
				'publication' => $error->get_publication(),
				'max_bytes'   => $error->get_maximum_bytes(),
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $payload ) ) {
			$payload = '{"title":"LLMS publication exceeds the safe output limit.","status":507}';
		}

		status_header( 507 );
		nocache_headers();
		header( 'X-Cybermaps-Version: ' . CYBERMAPS_VERSION );
		header( 'X-Cybermaps-Error: publication_too_large' );
		header( "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'" );
		header( 'Content-Type: application/problem+json; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 9457 JSON protocol body.
			echo $payload;
		}
		exit;
	}

	private function site_title( array $settings ): string {
		$title = is_scalar( $settings['llms_title_override'] ?? null )
			? sanitize_text_field( (string) $settings['llms_title_override'] )
			: '';
		$title = trim(
			PublicationConstraints::bounded_text(
				$title,
				\Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH
			)
		);
		return '' !== $title
			? $title
			: PublicationConstraints::bounded_text(
				sanitize_text_field( (string) get_bloginfo( 'name' ) ),
				\Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH
			);
	}

	private function mission( array $settings ): string {
		$stored_mission = is_scalar( $settings['llms_mission_statement'] ?? null )
			? sanitize_textarea_field( (string) $settings['llms_mission_statement'] )
			: '';
		$mission        = PublicationConstraints::bounded_text(
			trim( $stored_mission ),
			PublicationConstraints::MISSION_MAX_LENGTH
		);
		return '' !== $mission
			? $mission
			: PublicationConstraints::bounded_text(
				trim( sanitize_textarea_field( (string) get_bloginfo( 'description' ) ) ),
				PublicationConstraints::MISSION_MAX_LENGTH
			);
	}

	private function markdown_text( string $text ): string {
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) ) ?? trim( $text );
		return str_replace( array( '\\', '[', ']', '*' ), array( '\\\\', '\\[', '\\]', '\\*' ), $text );
	}
}
