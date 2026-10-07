<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\ReadOnlyRequest;
use Cybermaps\Core\URLManager;
use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves Markdown at canonical public URLs through HTTP content negotiation.
 */
final class MarkdownNegotiation {
	public function register_hooks(): void {
		// WordPress canonical redirects run at priority 10 and remain authoritative.
		add_action( 'template_redirect', array( $this, 'handle' ), 20 );
	}

	public function handle(): void {
		if ( ! ReadOnlyRequest::is_allowed( ReadOnlyRequest::method() ) || ! $this->is_supported_view() ) {
			return;
		}
		$prefers_markdown = AcceptNegotiator::prefers_markdown( $this->accept_header() );
		$generation       = \Cybermaps\Core\CacheManager::get_generation( 'discovery', true );
		try {
			self::require_current_generation( $generation );
			$settings = \Cybermaps\Core\ConfigurationStore::publication_settings();
			\Cybermaps\Core\ConfigurationStore::publication_discovery();
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			if ( ! $prefers_markdown ) {
				return;
			}
			PublicationRequestGuard::serve_unavailable( $error );
		}
		if ( ! self::is_enabled( $settings ) ) {
			return;
		}

		MarkdownResponder::add_vary_accept();
		if ( ! $prefers_markdown ) {
			return;
		}

		PublicationRequestGuard::enforce_active_route();
		\Cybermaps\Integration\EdgeCache\LiteSpeedAdapter::mark_negotiated_request_nocache();
		$responder = new MarkdownResponder();
		try {
			$representation = $this->representation( $settings );
			self::require_current_generation( $generation );
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			PublicationRequestGuard::serve_unavailable( $error );
		} catch ( PublicationSizeLimitException $error ) {
			$responder->send_size_limit_error( $error, true );
		}
		if ( null === $representation ) {
			return;
		}

		$responder->send(
			$representation['content'],
			$representation['modified'],
			$representation['links'],
			true,
			$generation
		);
	}

	/** Reject observed privacy movement before conditional headers or body delivery. */
	private static function require_current_generation( int $generation ): void {
		if ( $generation < 0 || \Cybermaps\Core\CacheManager::get_generation( 'discovery', true ) !== $generation ) {
			throw new \Cybermaps\Core\BuildUnavailableException( esc_html__( 'Cybermaps content changed during Markdown publication. Please retry shortly.', 'cybermaps' ) );
		}
	}

	/**
	 * @param array<string,mixed>|null $settings Settings snapshot.
	 */
	public static function is_enabled( ?array $settings = null ): bool {
		$settings = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
		return ! empty( $settings['enable_discovery_hub'] ) && ! empty( $settings['enable_markdown_negotiation'] );
	}

	private function is_supported_view(): bool {
		if ( is_admin() || $this->is_excluded_view() ) {
			return false;
		}
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			return true;
		}

		foreach ( array( 'is_front_page', 'is_home', 'is_post_type_archive', 'is_tax', 'is_category', 'is_tag', 'is_author', 'is_date' ) as $predicate ) {
			if ( function_exists( $predicate ) && $predicate() ) {
				return true;
			}
		}
		return false;
	}

	private function is_excluded_view(): bool {
		foreach ( array( 'is_feed', 'is_search', 'is_404', 'is_preview', 'is_embed', 'is_trackback' ) as $predicate ) {
			if ( function_exists( $predicate ) && $predicate() ) {
				return true;
			}
		}
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return array{content:string,modified:?int,links:string[]}|null
	 */
	private function representation( array $settings ): ?array {
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			return $this->singular_representation( $settings );
		}
		return $this->collection_representation( $settings );
	}

	/**
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return array{content:string,modified:?int,links:string[]}|null
	 */
	private function singular_representation( array $settings ): ?array {
		$post = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! is_object( $post ) || ! ( new PublicationEligibility( null, $settings ) )->post( $post, PublicationEligibility::AI )->indexable ) {
			return null;
		}

		$alternate = new MarkdownAlternate();
		return array(
			'content'  => $alternate->get_content( $post ),
			// Locale and public URL settings also contribute to the representation.
			'modified' => null,
			'links'    => $alternate->get_markdown_response_links( $post ),
		);
	}

	/**
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return array{content:string,modified:null,links:string[]}
	 */
	private function collection_representation( array $settings ): array {
		global $wp_query;
		$posts = is_object( $wp_query ) && is_array( $wp_query->posts ?? null ) ? $wp_query->posts : array();
		$page  = max( 1, (int) get_query_var( 'paged', 1 ), (int) get_query_var( 'page', 1 ) );
		$max   = is_object( $wp_query ) ? max( 1, (int) ( $wp_query->max_num_pages ?? 1 ) ) : 1;
		$title = function_exists( 'wp_get_document_title' ) ? (string) wp_get_document_title() : (string) get_bloginfo( 'name' );

		return array(
			'content'  => ( new MarkdownCollection() )->render(
				$title,
				$this->current_public_url(),
				$posts,
				$page,
				$this->page_link( 'get_previous_posts_page_link' ),
				$this->page_link( 'get_next_posts_page_link', $max ),
				$settings
			),
			'modified' => null,
			'links'    => array( '<' . $this->current_public_url() . '>; rel="canonical"; type="text/html"' ),
		);
	}

	private function current_public_url(): string {
		$path = (string) URLManager::get_request_path();
		$url  = URLManager::get_home_url( '/' === $path ? '/' : $path );
		if ( '/' !== $path ) {
			return $url;
		}
		$query = array();
		foreach ( array( 'cat', 'category_name', 'tag', 'tag_id', 'author', 'author_name', 'year', 'monthnum', 'day', 'm', 'post_type', 'taxonomy', 'term', 'paged', 'page' ) as $key ) {
			$value = get_query_var( $key, '' );
			if ( is_scalar( $value ) && '' !== (string) $value && '0' !== (string) $value ) {
				$query[ $key ] = PublicationConstraints::bounded_text( (string) $value, 200 );
			}
		}
		return empty( $query ) ? $url : add_query_arg( $query, $url );
	}

	private function page_link( string $callback, int $maximum = 0 ): string {
		if ( ! function_exists( $callback ) ) {
			return '';
		}
		$url = 0 < $maximum ? $callback( $maximum ) : $callback();
		return is_string( $url ) ? URLManager::rewrite_url( $url ) : '';
	}

	private function accept_header(): string {
		return isset( $_SERVER['HTTP_ACCEPT'] ) && is_scalar( $_SERVER['HTTP_ACCEPT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) )
			: '';
	}
}
