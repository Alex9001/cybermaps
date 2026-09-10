<?php
/**
 * Post Type Sitemap Provider
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostTypeProvider extends BaseProvider {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	private $post_type;

	/**
	 * PostTypeProvider constructor.
	 *
	 * @param string $post_type Post type slug.
	 */
	public function __construct( string $post_type ) {
		$this->post_type = $post_type;
	}

	/**
	 * Get URLs for a specific page.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public function get_urls( int $page ): array {
		$settings = $this->get_settings();
		$per_page = $this->get_per_page();
		$posts    = $this->get_eligible_content()->get_post_page( $this->post_type, $page, $per_page );

		if ( empty( $posts ) ) {
			return array();
		}

		$urls          = array();
		$base_priority = $this->get_calculated_priority(
			ProviderIdentity::post_type( $this->post_type )
		);

		foreach ( $posts as $p ) {
			$urls[] = $this->build_url( $p, $settings, $base_priority );
		}

		return $urls;
	}

	/**
	 * Build one post sitemap entry.
	 *
	 * @param object               $post Post object.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>
	 */
	private function build_url( object $post, array $settings, float $base_priority ): array {
		$priority = $this->get_post_priority( (int) $post->ID, $base_priority );

		list( $images, $videos ) = $this->get_media( $post, $settings );

		$stored_changefreq = get_post_meta( $post->ID, '_cybermaps_sitemap_changefreq', true );
		$changefreq        = is_scalar( $stored_changefreq ) && '' !== (string) $stored_changefreq
			? (string) $stored_changefreq
			: 'weekly';

		return array(
			'loc'        => \Cybermaps\Core\URLManager::rewrite_url( get_permalink( $post->ID ) ),
			'lastmod'    => gmdate( 'c', strtotime( $post->post_modified_gmt ) ),
			'priority'   => $priority,
			'images'     => $images,
			'videos'     => $videos,
			'alternates' => $this->get_alternates( $post->ID, 'post' ),
			'changefreq' => $changefreq,
		);
	}

	/**
	 * Apply a post-level priority override.
	 */
	private function get_post_priority( int $post_id, float $base_priority ): float {
		$manual_priority = get_post_meta( $post_id, '_cybermaps_sitemap_priority', true );
		if ( ! empty( $manual_priority ) && (float) $manual_priority > 0 ) {
			return min( 1.0, max( 0.0, (float) $manual_priority ) );
		}
		return $base_priority;
	}

	/**
	 * Collect media entries for one post.
	 *
	 * @param object               $post Post object.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array{0:array<int, array<string, string>>,1:array<int, array<string, string>>}
	 */
	private function get_media( object $post, array $settings ): array {
		$images    = array();
		$videos    = array();
		$intensity = $settings['media_discovery_intensity'] ?? 'none';
		if ( 'none' === MediaScanner::normalize_intensity( $intensity ) ) {
			return array( $images, $videos );
		}

		$media = MediaScanner::current_audit_items( (int) $post->ID, $intensity );
		if ( empty( $media ) ) {
			return array( $images, $videos );
		}

		foreach ( $media as $item ) {
			$this->append_media_item( $item, $post, $images, $videos );
		}

		return array( $images, $videos );
	}

	/**
	 * Append one valid media audit item to its output collection.
	 *
	 * @param array<int, array<string, string>> $images Image entries.
	 * @param array<int, array<string, string>> $videos Video entries.
	 */
	private function append_media_item( mixed $item, object $post, array &$images, array &$videos ): void {
		if ( ! is_array( $item ) ) {
			return;
		}
		$media_url = MediaScanner::normalize_media_url( (string) ( $item['url'] ?? '' ) );
		if ( '' === $media_url ) {
			return;
		}

		$type = (string) ( $item['type'] ?? '' );
		if ( 'image' === $type ) {
			$images[] = $this->build_image( $item, $media_url );
			return;
		}
		if ( 'video' !== $type ) {
			return;
		}

		$video = $this->build_video( $item, $post );
		if ( null !== $video ) {
			$videos[] = $video;
		}
	}

	/**
	 * Build one image entry.
	 *
	 * @param array<string, mixed> $item Media item.
	 * @return array{url:string,title:string}
	 */
	private function build_image( array $item, string $media_url ): array {
		return array(
			'url'   => \Cybermaps\Core\URLManager::rewrite_media_url( $media_url ),
			'title' => (string) ( $item['title'] ?? '' ),
		);
	}

	/**
	 * Build one valid video entry.
	 *
	 * @param array<string, mixed> $item Media item.
	 * @return array<string, string>|null
	 */
	private function build_video( array $item, object $post ): ?array {
		$publication = MediaScanner::resolve_video_publication( $item );
		if ( '' === $publication['url'] ) {
			return null;
		}

		$thumbnail_url = MediaScanner::normalize_media_url( (string) ( $item['thumbnail_loc'] ?? '' ) );
		$title         = trim( (string) ( $item['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = trim( (string) ( $post->post_title ?? '' ) );
		}
		$description = trim( (string) ( $item['multimodal_desc'] ?? '' ) );
		if ( '' === $description ) {
			$description = $title;
		}

		$thumbnail_url   = \Cybermaps\Core\URLManager::rewrite_media_url( $thumbnail_url );
		$publication_url = $this->rewrite_video_url( $publication );
		if ( ! $this->is_complete_video( $thumbnail_url, $publication_url, $title, $description ) ) {
			return null;
		}

		$video = array(
			'title'         => $title,
			'description'   => $description,
			'thumbnail_loc' => $thumbnail_url,
		);

		$location_key = 'player' === $publication['type'] ? 'player_loc' : 'content_loc';

		$video[ $location_key ] = $publication_url;
		return $video;
	}

	/**
	 * Rewrite a video publication URL according to its type.
	 *
	 * @param array{type:string,url:string} $publication Publication data.
	 */
	private function rewrite_video_url( array $publication ): string {
		return 'player' === $publication['type']
			? \Cybermaps\Core\URLManager::rewrite_url( $publication['url'] )
			: \Cybermaps\Core\URLManager::rewrite_media_url( $publication['url'] );
	}

	/**
	 * Whether a video has the required public fields.
	 */
	private function is_complete_video( string $thumbnail_url, string $publication_url, string $title, string $description ): bool {
		return '' !== \Cybermaps\Core\URLManager::sanitize_http_url( $thumbnail_url )
			&& '' !== \Cybermaps\Core\URLManager::sanitize_http_url( $publication_url )
			&& '' !== $title
			&& '' !== $description;
	}

	/**
	 * Get total count of items.
	 *
	 * @return int
	 */
	public function get_count(): int {
		$count = $this->get_eligible_content()->get_post_count( $this->post_type );
		if ( $count > 0 && $count <= $this->get_per_page() ) {
			return count(
				$this->get_eligible_content()->get_post_page(
					$this->post_type,
					1,
					$this->get_per_page()
				)
			);
		}
		return $count;
	}

	/**
	 * Get last modification date.
	 *
	 * @return string
	 */
	public function get_lastmod(): string {
		return $this->get_eligible_content()->get_post_lastmod( $this->post_type );
	}
}
