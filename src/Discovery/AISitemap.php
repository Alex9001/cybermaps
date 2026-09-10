<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\URLManager;
use Cybermaps\Sitemap\MediaScanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-Sitemap Handler
 *
 * Handles requests for /ai-sitemap.xml.
 */
class AISitemap {
	/**
	 * Shared post-selection policy.
	 */
	private AIContentSelector $selector;

	public function __construct( ?AIContentSelector $selector = null ) {
		$this->selector = $selector ?? new AIContentSelector();
	}

	/**
	 * Handle requests for /ai-sitemap.xml.
	 *
	 * @return void
	 */
	public function handle(): void {
		$path = (string) \Cybermaps\Core\URLManager::get_request_path();
		if ( '/ai-sitemap.xml' !== $path ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return;
		}

		$output = (string) $this->get_content();

		Integrity::send_headers( $output );
		header( 'Content-Type: application/xml; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
		exit;
	}

	/**
	 * Get the vendor-defined AI XML inventory.
	 *
	 * @return string
	 */
	public function get_content(): string {
		return (string) $this->generate_sitemap();
	}

	/**
	 * Generate the vendor-defined AI XML inventory.
	 *
	 * @return string
	 */
	private function generate_sitemap(): string {
		$settings     = \Cybermaps\Core\ConfigurationStore::settings();
		$custom_links = array_slice(
			isset( $settings['ai_sitemap_custom_links'] ) && is_array( $settings['ai_sitemap_custom_links'] )
				? $settings['ai_sitemap_custom_links']
				: array(),
			0,
			PublicationConstraints::CUSTOM_LINKS_MAX
		);

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:ai="https://cybermaps.dev/schemas/ai/1.0">';

		foreach ( $this->selector->get_posts() as $post ) {
			$xml .= $this->post_xml( $post, $settings );
		}

		$xml .= self::custom_links_xml( $custom_links );
		$xml .= '</urlset>';

		return $xml;
	}

	/**
	 * Build one selected post entry.
	 *
	 * @param mixed                $post Selected post value.
	 * @param array<string, mixed> $settings General settings.
	 */
	private function post_xml( mixed $post, array $settings ): string {
		if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_type ) ) {
			return '';
		}

		$post_type = (string) $post->post_type;
		$weight    = $this->selector->get_weight( $post_type );
		$ai_meta   = \Cybermaps\Discovery\AIMetadata::calculate( (int) $post->ID );
		$intent    = (string) \Cybermaps\Discovery\IntentEngine::calculate( $post->ID, 'post', $post_type );
		$xml       = '<url>';
		$xml      .= '<loc>' . esc_xml( (string) \Cybermaps\Core\URLManager::rewrite_url( (string) get_permalink( $post->ID ) ) ) . '</loc>';
		$xml      .= '<lastmod>' . esc_xml( (string) get_the_modified_date( 'c', $post->ID ) ) . '</lastmod>';
		$xml      .= '<priority>' . number_format( $weight, 1 ) . '</priority>';
		$xml      .= '<ai:weight>' . number_format( $weight, 1 ) . '</ai:weight>';
		$xml      .= '<ai:intent>' . esc_xml( $intent ) . '</ai:intent>';
		$xml      .= '<ai:content_type>' . esc_xml( (string) ( $ai_meta['content_type'] ?? '' ) ) . '</ai:content_type>';
		$xml      .= '<ai:length_band>' . esc_xml( (string) ( $ai_meta['length_band'] ?? '' ) ) . '</ai:length_band>';
		$xml      .= '<ai:freshness>' . esc_xml( (string) ( $ai_meta['freshness'] ?? '' ) ) . '</ai:freshness>';
		if ( self::content_hints_enabled( $settings ) && ! empty( $ai_meta['snippet'] ) ) {
			$xml .= '<ai:snippet>' . esc_xml( (string) $ai_meta['snippet'] ) . '</ai:snippet>';
		}
		if ( ! empty( $settings['enable_rag_chunks'] ) ) {
			$xml .= '<ai:chunks_url>' . esc_xml( (string) \Cybermaps\Core\URLManager::get_chunk_url( $post->ID ) ) . '</ai:chunks_url>';
		}
		$xml .= self::media_xml( (int) $post->ID, $settings );
		$xml .= '</url>';

		return $xml;
	}

	/**
	 * Preserve the default-on, scalar-only content-hints setting semantics.
	 *
	 * @param array<string, mixed> $settings General settings.
	 */
	private static function content_hints_enabled( array $settings ): bool {
		return ! isset( $settings['enable_content_hints'] )
			|| (
				is_scalar( $settings['enable_content_hints'] )
				&& '1' === (string) $settings['enable_content_hints']
			);
	}

	/**
	 * Build the optional media fragments for one post.
	 *
	 * @param array<string, mixed> $settings General settings.
	 */
	private static function media_xml( int $post_id, array $settings ): string {
		$enable_multimodal = ! isset( $settings['enable_multimodal_discovery'] )
			|| '1' === $settings['enable_multimodal_discovery'];
		if ( ! $enable_multimodal || empty( $settings['media_discovery_intensity'] ) || 'none' === $settings['media_discovery_intensity'] ) {
			return '';
		}

		$media = MediaScanner::current_audit_items( $post_id, $settings['media_discovery_intensity'] );
		$xml   = '';
		foreach ( $media as $item ) {
			$xml .= self::media_item_xml( $item );
		}

		return $xml;
	}

	/**
	 * Build one valid image or video fragment.
	 */
	private static function media_item_xml( mixed $item ): string {
		if ( ! is_array( $item ) || empty( $item['url'] ) ) {
			return '';
		}

		$media_type = is_scalar( $item['type'] ?? null ) ? (string) $item['type'] : '';
		if ( ! in_array( $media_type, array( 'image', 'video' ), true ) ) {
			return '';
		}

		$raw_media_url = is_scalar( $item['url'] ) ? (string) $item['url'] : '';
		if ( 'video' === $media_type ) {
			$video_publication = MediaScanner::resolve_video_publication( $item );
			$raw_media_url     = $video_publication['url'];
		}
		$media_url = self::public_media_url( $raw_media_url );
		if ( '' === $media_url ) {
			return '';
		}

		$tag_name = 'video' === $media_type ? 'ai:video' : 'ai:image';
		$xml      = '<' . $tag_name . '>';
		$xml     .= '<ai:loc>' . esc_xml( $media_url ) . '</ai:loc>';
		$xml     .= self::media_details_xml( $item );
		$xml     .= '</' . $tag_name . '>';

		return $xml;
	}

	/**
	 * Build optional media child elements in schema order.
	 *
	 * @param array<string, mixed> $item Media audit item.
	 */
	private static function media_details_xml( array $item ): string {
		$xml = '';
		if ( is_scalar( $item['title'] ?? null ) && '' !== (string) $item['title'] ) {
			$xml .= '<ai:title>' . esc_xml( (string) $item['title'] ) . '</ai:title>';
		}
		if ( isset( $item['visual_weight'] ) ) {
			$xml .= '<ai:visual_weight>' . number_format( floatval( $item['visual_weight'] ), 1 ) . '</ai:visual_weight>';
		}
		if ( is_scalar( $item['multimodal_desc'] ?? null ) && '' !== (string) $item['multimodal_desc'] ) {
			$xml .= '<ai:multimodal_desc>' . esc_xml( (string) $item['multimodal_desc'] ) . '</ai:multimodal_desc>';
		}

		return $xml;
	}

	/**
	 * Build bounded custom-link entries.
	 *
	 * @param array<int, mixed> $custom_links Stored custom links.
	 */
	private static function custom_links_xml( array $custom_links ): string {
		$xml = '';
		foreach ( $custom_links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url = self::public_http_url(
				is_scalar( $link['url'] ?? null ) ? (string) $link['url'] : ''
			);
			if ( '' === $url ) {
				continue;
			}
			$stored_priority = $link['priority'] ?? 0.5;
			$priority        = is_scalar( $stored_priority ) && is_numeric( $stored_priority )
				? max( 0.1, min( 1.0, (float) $stored_priority ) )
				: 0.5;

			$xml .= '<url>';
			$xml .= '<loc>' . esc_xml( $url ) . '</loc>';
			$xml .= '<priority>' . number_format( $priority, 1 ) . '</priority>';
			$xml .= '<ai:weight>' . number_format( $priority, 1 ) . '</ai:weight>';
			$xml .= '</url>';
		}

		return $xml;
	}

	/**
	 * Normalize a stored media URL and apply the configured public CDN mapping.
	 */
	private static function public_media_url( string $url ): string {
		$url = MediaScanner::normalize_media_url( $url );
		if ( '' === $url ) {
			return '';
		}

		return self::public_http_url( (string) URLManager::rewrite_media_url( $url ) );
	}

	/**
	 * Accept only absolute, credential-free HTTP(S) publication URLs.
	 */
	private static function public_http_url( string $url ): string {
		return URLManager::sanitize_http_url( $url );
	}
}
