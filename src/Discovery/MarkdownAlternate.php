<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Content\VisibleTextExtractor;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\TranslationHelper;
use Cybermaps\Core\URLManager;
use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves literal Markdown alternates for eligible singular WordPress content.
 *
 * Pretty permalinks use a sibling index.md document. Filename-style
 * permalinks append .md, and plain permalinks use a query marker. Content is
 * extracted from stored post data without rendering blocks or shortcodes.
 */
final class MarkdownAlternate {
	public const QUERY_MARKER = 'cybermaps_markdown';

	/**
	 * Handle one candidate Markdown alternate request.
	 */
	public function handle(): void {
		$path  = (string) URLManager::get_request_path();
		$query = $this->request_query();
		if ( ! self::is_candidate_request( $path, $query ) ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return;
		}

		PublicationRequestGuard::enforce_active_route();

		$post_id = $this->resolve_post_id( $path, $query );
		$post    = $post_id > 0 ? \get_post( $post_id ) : null;
		if (
			! \is_object( $post )
			|| ! ( new PublicationEligibility( null, $settings ) )->post( $post, PublicationEligibility::AI )->indexable
		) {
			return;
		}

		try {
			$output = $this->get_content( $post );
		} catch ( PublicationSizeLimitException $error ) {
			( new MarkdownResponder() )->send_size_limit_error( $error, false );
		}

		( new MarkdownResponder() )->send(
			$output,
			MarkdownResponder::modified_timestamp( $post ),
			$this->get_markdown_response_links( $post ),
			false
		);
	}

	/**
	 * Determine whether a path or plain-permalink query may be a Markdown route.
	 *
	 * @param array<string, mixed>|null $query Query values; defaults to $_GET.
	 */
	public static function is_candidate_request( string $path, ?array $query = null ): bool {
		$query  = null === $query ? $_GET : $query; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public route classification.
		$marker = $query[ self::QUERY_MARKER ] ?? null;
		if ( \is_scalar( $marker ) && '1' === (string) $marker ) {
			return true;
		}

		return 1 === \preg_match( '#^/.+\.md$#i', $path );
	}

	/**
	 * Map a Markdown route to its canonical HTML path.
	 */
	public static function source_path( string $path ): ?string {
		if ( '/index.md' === $path ) {
			return '/';
		}
		if ( \str_ends_with( $path, '/index.md' ) ) {
			return \substr( $path, 0, -\strlen( 'index.md' ) );
		}
		if ( 1 === \preg_match( '#^/.+\.md$#i', $path ) ) {
			return \substr( $path, 0, -3 );
		}

		return null;
	}

	/**
	 * Build the public Markdown URL for one post.
	 */
	public static function url_for_post( object|int $post ): string {
		$post_id = \is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		if ( $post_id < 1 ) {
			return '';
		}

		$canonical = URLManager::rewrite_url( (string) \get_permalink( $post ) );
		if ( '' === $canonical ) {
			return '';
		}

		$parts = \wp_parse_url( $canonical );
		if ( ! \is_array( $parts ) ) {
			return '';
		}
		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			return (string) \add_query_arg( self::QUERY_MARKER, '1', $canonical );
		}

		$path          = isset( $parts['path'] ) && \is_string( $parts['path'] )
			? $parts['path']
			: '/';
		$parts['path'] = self::markdown_path( $path );
		return self::build_url( $parts );
	}

	private static function markdown_path( string $path ): string {
		if ( '/' === $path || '' === $path ) {
			return '/index.md';
		}
		if ( \str_ends_with( $path, '/' ) ) {
			return $path . 'index.md';
		}
		if ( \str_contains( (string) \basename( $path ), '.' ) ) {
			return $path . '.md';
		}
		return $path . '/index.md';
	}

	/**
	 * Resolve a candidate request to a WordPress post ID.
	 *
	 * @param array<string, mixed> $query Query values.
	 */
	public function resolve_post_id( string $path, array $query = array() ): int {
		$marker = $query[ self::QUERY_MARKER ] ?? null;
		if ( \is_scalar( $marker ) && '1' === (string) $marker ) {
			foreach ( array( 'p', 'page_id' ) as $id_key ) {
				$candidate = $query[ $id_key ] ?? null;
				if ( \is_scalar( $candidate ) && \absint( $candidate ) > 0 ) {
					return \absint( $candidate );
				}
			}
		}

		$source = self::source_path( $path );
		if ( null === $source ) {
			return 0;
		}
		if ( '/' === $source ) {
			return 'page' === (string) \get_option( 'show_on_front', 'posts' )
				? \absint( \get_option( 'page_on_front', 0 ) )
				: 0;
		}

		return \function_exists( 'url_to_postid' )
			? \absint( \url_to_postid( \home_url( $source ) ) )
			: 0;
	}

	/**
	 * Generate one bounded, literal Markdown representation.
	 */
	public function get_content( object|int $post ): string {
		$object = \is_object( $post ) ? $post : \get_post( $post );
		if ( ! \is_object( $object ) || (int) ( $object->ID ?? 0 ) < 1 ) {
			return '';
		}

		$output      = $this->content_header( $object );
		$raw_content = isset( $object->post_content ) && \is_scalar( $object->post_content )
			? (string) $object->post_content
			: '';
		if ( \strlen( $raw_content ) > LLMS::OUTPUT_MAX_BYTES ) {
			throw new PublicationSizeLimitException( 'markdown-alternate', LLMS::OUTPUT_MAX_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Protocol error data is encoded before output.
		}
		$text    = ( new VisibleTextExtractor() )->from_post( $object );
		$output .= '' !== $text ? $text . "\n" : "[No visible stored text]\n";
		if ( \strlen( $output ) > LLMS::OUTPUT_MAX_BYTES ) {
			throw new PublicationSizeLimitException( 'markdown-alternate', LLMS::OUTPUT_MAX_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Protocol error data is encoded before output.
		}

		return $output;
	}

	private function content_header( object $post ): string {
		$post_id   = (int) $post->ID;
		$title     = $this->markdown_text( (string) \get_the_title( $post_id ) );
		$canonical = URLManager::rewrite_url( (string) \get_permalink( $post_id ) );
		$language  = TranslationHelper::normalize_hreflang( (string) \get_locale() );
		$modified  = MarkdownResponder::modified_timestamp( $post );
		$output    = '# ' . ( '' !== $title ? $title : __( 'Untitled resource', 'cybermaps' ) ) . "\n\n";
		$output   .= '- Source: ' . $canonical . "\n";
		$output   .= '- Content-Type: ' . \sanitize_key( (string) ( $post->post_type ?? 'post' ) ) . "\n";
		if ( '' !== $language ) {
			$output .= '- Language: ' . $language . "\n";
		}
		if ( null !== $modified ) {
			$output .= '- Last-Modified: ' . \gmdate( 'c', $modified ) . "\n";
		}
		return $output . "\n## Content\n\n";
	}

	/**
	 * Build RFC 8288 relations returned with the Markdown representation.
	 *
	 * @return string[]
	 */
	public function get_markdown_response_links( object|int $post ): array {
		$post_id   = \is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		$canonical = $post_id > 0
			? URLManager::rewrite_url( (string) \get_permalink( $post_id ) )
			: '';
		$llms      = $this->llms_url();
		$links     = array();
		if ( '' !== $canonical ) {
			$links[] = '<' . $canonical . '>; rel="canonical"; type="text/html"';
		}
		if ( '' !== $llms ) {
			$links[] = '<' . $llms . '>; rel="describedby"; type="text/markdown"';
		}

		return $links;
	}

	/**
	 * Return the llms.txt publication that describes the current language scope.
	 */
	public function llms_url(): string {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( ! empty( $settings['enable_multilingual_hub'] ) ) {
			$language = (string) TranslationHelper::get_current_language();
			if ( '' !== $language && TranslationHelper::is_active_language( $language ) ) {
				return URLManager::get_home_url( '/' . \sanitize_key( $language ) . '/llms.txt' );
			}
		}

		return EndpointRegistry::get_instance()->get_url( 'llms' );
	}

	/**
	 * Read a bounded query shape from the request.
	 *
	 * @return array<string, mixed>
	 */
	private function request_query(): array {
		$query = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only public route resolution.
		foreach ( array( self::QUERY_MARKER, 'p', 'page_id' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && \is_scalar( $_GET[ $key ] ) ) {
				$query[ $key ] = \sanitize_text_field( \wp_unslash( (string) $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		return $query;
	}

	/**
	 * Build a URL from wp_parse_url() parts without accepting non-HTTP schemes.
	 *
	 * @param array<string, mixed> $parts Parsed URL parts.
	 */
	private static function build_url( array $parts ): string {
		$scheme = isset( $parts['scheme'] ) && \is_string( $parts['scheme'] )
			? \strtolower( $parts['scheme'] )
			: '';
		$host   = isset( $parts['host'] ) && \is_string( $parts['host'] )
			? $parts['host']
			: '';
		if ( ! \in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return '';
		}

		$url = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$url .= ':' . (int) $parts['port'];
		}
		$url .= isset( $parts['path'] ) && \is_string( $parts['path'] ) ? $parts['path'] : '/';
		if ( isset( $parts['fragment'] ) && \is_string( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$url .= '#' . $parts['fragment'];
		}

		return $url;
	}

	/**
	 * Escape syntax-significant Markdown title characters.
	 */
	private function markdown_text( string $text ): string {
		$text = \preg_replace( '/\s+/u', ' ', \trim( $text ) ) ?? \trim( $text );
		return \str_replace( array( '\\', '[', ']', '*' ), array( '\\\\', '\\[', '\\]', '\\*' ), $text );
	}
}
