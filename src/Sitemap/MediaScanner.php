<?php
/**
 * Media Discovery Scanner
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MediaScanner {
	private const AUDIT_GENERATION_OPTION = 'cybermaps_media_audit_generation';
	private const AUDIT_GENERATION_META   = '_cybermaps_media_audit_generation';
	private const AUDIT_MODE_META         = '_cybermaps_media_audit_mode';

	/**
	 * Runtime publication bounds for one post's saved media inventory.
	 *
	 * The stored post meta is mutable outside this scanner, so every consumer
	 * applies these limits again before rendering. A smaller video ceiling also
	 * prevents a malformed legacy value from injecting an excessive number of
	 * VideoObject scripts into one singular page.
	 */
	public const MAX_MEDIA_ITEMS_PER_POST       = 100;
	public const MAX_VIDEO_ITEMS_PER_POST       = 25;
	private const MAX_MEDIA_CANDIDATES_PER_POST = 500;
	private const MAX_CONTENT_SCAN_BYTES        = 2097152;

	/**
	 * Scan for standard media (Featured Image and Attached Media).
	 *
	 * @param int $post_id Post ID to scan.
	 * @return array List of media items.
	 */
	public function scan_standard( $post_id ) {
		$media = array();
		$post  = get_post( $post_id );
		if ( ! is_object( $post ) ) {
			return array();
		}

		// 1. Featured Image
		$thumbnail_id = get_post_meta( $post_id, '_thumbnail_id', true );
		if ( $thumbnail_id ) {
			$img_url = wp_get_attachment_url( $thumbnail_id );
			if ( $img_url ) {
				$img_url = self::normalize_media_url( (string) $img_url );
				if ( '' !== $img_url ) {
					$media[ $img_url ] = array(
						'url'             => $img_url,
						'title'           => get_the_title( $thumbnail_id ),
						'type'            => 'image',
						'visual_weight'   => 1.0, // Publisher hint: featured media first.
						'multimodal_desc' => $this->generate_multimodal_desc( 'featured_image', $post, $thumbnail_id ),
					);
				}
			}
		}

		// 2. Attached Media
		$attachments = get_children(
			array(
				'post_parent'    => $post_id,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'numberposts'    => 20,
			)
		);

		if ( $attachments ) {
			foreach ( $attachments as $attachment ) {
				$img_url = wp_get_attachment_url( $attachment->ID );
				if ( $img_url ) {
					$img_url = self::normalize_media_url( (string) $img_url );
					if ( '' === $img_url ) {
						continue;
					}
					if ( ! isset( $media[ $img_url ] ) ) {
						$media[ $img_url ] = array(
							'url'             => $img_url,
							'title'           => $attachment->post_title,
							'type'            => 'image',
							'visual_weight'   => 0.7,
							'multimodal_desc' => $this->generate_multimodal_desc( 'attachment', $post, $attachment->ID ),
						);
					}
				}
			}
		}

		return self::limit_audit_items( array_values( $media ) );
	}

	/**
	 * Scan for advanced media (Regex parsing of content).
	 *
	 * @param int $post_id Post ID to scan.
	 * @return array List of media items.
	 */
	public function scan_advanced( $post_id ) {
		$media_map = array();

		foreach ( $this->scan_standard( $post_id ) as $item ) {
			$media_map[ $item['url'] ] = $item;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array_values( $media_map );
		}

		$content = substr(
			(string) $post->post_content,
			0,
			self::MAX_CONTENT_SCAN_BYTES
		);

		// 3. Parse inline images.
		$this->add_inline_images( $media_map, $content, $post );

		// 4. Parse native video sources.
		$this->add_native_videos(
			$media_map,
			$content,
			'/<video[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i',
			$post
		);
		$this->add_native_videos(
			$media_map,
			$content,
			'~<video\b(?:(?!</video>).)*?<source[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>(?:(?!</video>).)*?</video>~is',
			$post
		);

		// 5. YouTube embeds.
		$this->add_youtube_videos( $media_map, $content, $post );

		// 6. Vimeo embeds.
		$this->add_vimeo_videos( $media_map, $content, $post );

		return self::limit_audit_items( array_values( $media_map ) );
	}

	/**
	 * Add inline image tags to a media inventory.
	 *
	 * @param array<string,array<string,mixed>> $media_map Media keyed by URL.
	 * @param object                            $post      Parent post.
	 */
	private function add_inline_images( array &$media_map, string $content, object $post ): void {
		$matches = self::bounded_content_matches(
			'/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i',
			$content,
			self::MAX_MEDIA_ITEMS_PER_POST
		);
		foreach ( $matches as $match ) {
			$url = self::normalize_media_url( (string) ( $match[1] ?? '' ) );
			if ( '' === $url || isset( $media_map[ $url ] ) ) {
				continue;
			}

			$tag   = (string) ( $match[0] ?? '' );
			$title = '';
			if ( preg_match( '/alt=[\'"]([^\'"]+)[\'"]/i', $tag, $alt_match ) ) {
				$title = $alt_match[1];
			} elseif ( preg_match( '/title=[\'"]([^\'"]+)[\'"]/i', $tag, $title_match ) ) {
				$title = $title_match[1];
			}

			$media_map[ $url ] = array(
				'url'             => $url,
				'title'           => $title,
				'type'            => 'image',
				'visual_weight'   => 0.5,
				'multimodal_desc' => $this->generate_multimodal_desc( 'inline_image', $post, 0, $title ),
			);
		}
	}

	/**
	 * Add direct video and nested source matches to a media inventory.
	 *
	 * @param array<string,array<string,mixed>> $media_map Media keyed by URL.
	 * @param object                            $post      Parent post.
	 */
	private function add_native_videos(
		array &$media_map,
		string $content,
		string $pattern,
		object $post
	): void {
		$matches = self::bounded_content_matches(
			$pattern,
			$content,
			self::MAX_MEDIA_ITEMS_PER_POST
		);
		foreach ( $matches as $match ) {
			$url = self::normalize_media_url( (string) ( $match[1] ?? '' ) );
			if ( '' === $url || isset( $media_map[ $url ] ) ) {
				continue;
			}

			$media_map[ $url ] = array(
				'url'             => $url,
				'title'           => $post->post_title,
				'thumbnail_loc'   => self::extract_video_poster( (string) ( $match[0] ?? '' ) ),
				'type'            => 'video',
				'video_url_type'  => 'content',
				'visual_weight'   => 1.0,
				'multimodal_desc' => $this->generate_multimodal_desc( 'video', $post ),
			);
		}
	}

	/**
	 * Add YouTube embeds to a media inventory.
	 *
	 * @param array<string,array<string,mixed>> $media_map Media keyed by URL.
	 * @param object                            $post      Parent post.
	 */
	private function add_youtube_videos( array &$media_map, string $content, object $post ): void {
		$matches = self::bounded_content_matches(
			'~(?<![a-z0-9.-])(?:(?:https?:)?//)?(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?[^"\'\s<>]*?\bv=|(?:v|e|embed|shorts|live)/)|youtu\.be/)([a-z0-9_-]{11})(?=$|[?&#/"\';<>\s])~i',
			$content,
			self::MAX_MEDIA_ITEMS_PER_POST
		);
		foreach ( $matches as $match ) {
			$video_id = (string) ( $match[1] ?? '' );
			$url      = self::normalize_media_url( 'https://www.youtube.com/embed/' . $video_id );
			if ( '' === $url || isset( $media_map[ $url ] ) ) {
				continue;
			}

			$media_map[ $url ] = array(
				'url'             => $url,
				'title'           => __( 'YouTube Video', 'cybermaps' ),
				'thumbnail_loc'   => "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg",
				'type'            => 'video',
				'video_url_type'  => 'player',
				'visual_weight'   => 1.0,
				'multimodal_desc' => $this->generate_multimodal_desc( 'youtube', $post ),
			);
		}
	}

	/**
	 * Add Vimeo embeds to a media inventory.
	 *
	 * @param array<string,array<string,mixed>> $media_map Media keyed by URL.
	 * @param object                            $post      Parent post.
	 */
	private function add_vimeo_videos( array &$media_map, string $content, object $post ): void {
		$matches = self::bounded_content_matches(
			'~(?<![a-z0-9.-])(?:(?:https?:)?//)?(?:www\.)?(?:player\.)?vimeo\.com/(?:channels/(?:[\w-]+/)?|groups/(?:[\w-]+/)?|album/(?:\d+/)?video/|video/)?(\d+)(?=$|[/?#"\';<>\s])~i',
			$content,
			self::MAX_MEDIA_ITEMS_PER_POST
		);
		foreach ( $matches as $match ) {
			$video_id = (string) ( $match[1] ?? '' );
			$url      = self::normalize_media_url( 'https://player.vimeo.com/video/' . $video_id );
			if ( '' === $url || isset( $media_map[ $url ] ) ) {
				continue;
			}

			$media_map[ $url ] = array(
				'url'             => $url,
				'title'           => __( 'Vimeo Video', 'cybermaps' ),
				'thumbnail_loc'   => '',
				'type'            => 'video',
				'video_url_type'  => 'player',
				'visual_weight'   => 1.0,
				'multimodal_desc' => $this->generate_multimodal_desc( 'vimeo', $post ),
			);
		}
	}

	/**
	 * Collect a bounded number of non-overlapping regex matches.
	 *
	 * preg_match_all() allocates every match before callers can slice the result.
	 * Advancing one match at a time keeps Advanced mode's work proportional to
	 * the publication budget even when stored markup contains thousands of tags.
	 *
	 * @return array<int,array<int,string>>
	 */
	private static function bounded_content_matches(
		string $pattern,
		string $content,
		int $limit
	): array {
		$limit        = max( 0, $limit );
		$length       = strlen( $content );
		$offset       = 0;
		$results      = array();
		$result_count = 0;

		while ( $result_count < $limit && $offset < $length ) {
			$matched = preg_match(
				$pattern,
				$content,
				$match,
				PREG_OFFSET_CAPTURE,
				$offset
			);
			if ( 1 !== $matched || ! isset( $match[0][0], $match[0][1] ) ) {
				break;
			}

			$row = array();
			foreach ( $match as $key => $capture ) {
				if ( is_int( $key ) && is_array( $capture ) && isset( $capture[0] ) ) {
					$row[ $key ] = (string) $capture[0];
				}
			}
			$results[] = $row;
			++$result_count;

			$match_start  = max( 0, (int) $match[0][1] );
			$match_length = strlen( (string) $match[0][0] );
			$offset       = max( $offset + 1, $match_start + max( 1, $match_length ) );
		}

		return $results;
	}

	/**
	 * Return only audit rows produced for the active scanner generation and mode.
	 *
	 * Rows created before provenance markers existed intentionally fail closed.
	 * A mode change invalidates every row in O(1) by advancing one site option;
	 * posts become publishable again after their next save or explicit rescan.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function current_audit_items( int $post_id, mixed $intensity ): array {
		$mode = self::normalize_intensity( $intensity );
		if ( $post_id < 1 || 'none' === $mode ) {
			return array();
		}

		$stored_mode       = get_post_meta( $post_id, self::AUDIT_MODE_META, true );
		$stored_generation = get_post_meta(
			$post_id,
			self::AUDIT_GENERATION_META,
			true
		);
		if (
			! is_scalar( $stored_mode )
			|| $mode !== (string) $stored_mode
			|| ! is_scalar( $stored_generation )
			|| self::get_audit_generation() !== (int) $stored_generation
		) {
			return array();
		}

		return self::limit_audit_items(
			get_post_meta( $post_id, '_cybermaps_media_audit', true )
		);
	}

	/**
	 * Invalidate every saved audit without an unbounded postmeta deletion.
	 */
	public static function invalidate_audits(): int {
		$current = self::get_audit_generation();
		$next    = $current < PHP_INT_MAX ? $current + 1 : 1;
		update_option( self::AUDIT_GENERATION_OPTION, $next );
		return $next;
	}

	/**
	 * Normalize a stored scanner mode and fail closed for malformed values.
	 */
	public static function normalize_intensity( mixed $intensity ): string {
		if ( ! is_scalar( $intensity ) ) {
			return 'none';
		}

		$mode = sanitize_key( (string) $intensity );
		return in_array( $mode, array( 'none', 'standard', 'advanced' ), true )
			? $mode
			: 'none';
	}

	/**
	 * Normalize an untrusted saved media-audit value to the publication budget.
	 *
	 * Only the first bounded candidate window is inspected. Within it, at most
	 * 25 validly shaped videos are retained and images fill the remaining
	 * overall budget. Relative order remains stable inside the selected set.
	 *
	 * @param mixed $media Saved or freshly scanned media rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function limit_audit_items( mixed $media ): array {
		if ( ! is_array( $media ) ) {
			return array();
		}

		$raw_candidates                       = array_slice(
			array_values( $media ),
			0,
			self::MAX_MEDIA_CANDIDATES_PER_POST
		);
		list( $candidates, $images, $videos ) = self::normalized_candidates( $raw_candidates );
		return self::select_audit_items( $candidates, $images, $videos );
	}

	private static function normalized_candidates( array $raw_candidates ): array {
		$candidates = array();
		$images     = 0;
		$videos     = 0;
		foreach ( $raw_candidates as $item ) {
			$item = self::normalize_audit_candidate( $item );
			if ( null === $item ) {
				continue;
			}
			$candidates[] = $item;
			if ( 'video' === $item['type'] ) {
				++$videos;
			} else {
				++$images;
			}
		}
		return array( $candidates, $images, $videos );
	}

	private static function select_audit_items( array $candidates, int $images, int $videos ): array {
		$video_budget    = min( self::MAX_VIDEO_ITEMS_PER_POST, $videos );
		$image_budget    = min( $images, self::MAX_MEDIA_ITEMS_PER_POST - $video_budget );
		$selected        = array();
		$selected_images = 0;
		$selected_videos = 0;
		foreach ( $candidates as $item ) {
			if ( 'video' === $item['type'] && $selected_videos < $video_budget ) {
				$selected[] = $item;
				++$selected_videos;
			} elseif ( 'image' === $item['type'] && $selected_images < $image_budget ) {
				$selected[] = $item;
				++$selected_images;
			}
			if ( count( $selected ) >= self::MAX_MEDIA_ITEMS_PER_POST ) {
				break;
			}
		}
		return $selected;
	}

	/**
	 * Normalize one mutable postmeta row before any public consumer sees it.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function normalize_audit_candidate( mixed $item ): ?array {
		if ( ! is_array( $item ) || ! is_scalar( $item['type'] ?? null ) ) {
			return null;
		}

		$type = sanitize_key( (string) $item['type'] );
		$url  = is_scalar( $item['url'] ?? null )
			? self::normalize_media_url( (string) $item['url'] )
			: '';
		if ( ! in_array( $type, array( 'image', 'video' ), true ) || '' === $url ) {
			return null;
		}

		$item['type'] = $type;
		$item['url']  = $url;
		return self::normalize_audit_fields( $item );
	}

	private static function normalize_audit_fields( array $item ): array {
		foreach ( array( 'title', 'multimodal_desc' ) as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$item[ $key ] = is_scalar( $item[ $key ] )
					? sanitize_text_field( (string) $item[ $key ] )
					: '';
			}
		}
		$item = self::normalize_thumbnail( $item );
		$item = self::normalize_video_type( $item );
		return self::normalize_visual_weight( $item );
	}

	private static function normalize_thumbnail( array $item ): array {
		if ( array_key_exists( 'thumbnail_loc', $item ) ) {
			$item['thumbnail_loc'] = is_scalar( $item['thumbnail_loc'] )
				? self::normalize_media_url( (string) $item['thumbnail_loc'] )
				: '';
		}
		return $item;
	}

	private static function normalize_video_type( array $item ): array {
		if ( array_key_exists( 'video_url_type', $item ) ) {
			$type                   = is_scalar( $item['video_url_type'] )
				? sanitize_key( (string) $item['video_url_type'] )
				: '';
			$item['video_url_type'] = in_array( $type, array( 'content', 'player' ), true )
				? $type
				: '';
		}
		return $item;
	}

	private static function normalize_visual_weight( array $item ): array {
		if ( ! array_key_exists( 'visual_weight', $item ) ) {
			return $item;
		}
		if ( is_scalar( $item['visual_weight'] ) && is_numeric( $item['visual_weight'] ) ) {
			$item['visual_weight'] = min( 1.0, max( 0.0, (float) $item['visual_weight'] ) );
		} else {
			unset( $item['visual_weight'] );
		}
		return $item;
	}

	/**
	 * Resolve a video row to a direct-content or embeddable-player URL.
	 *
	 * New scanner rows carry `video_url_type`. Legacy YouTube and Vimeo rows did
	 * not, so their provider URLs are recognized and normalized to canonical
	 * embed players instead of being mislabeled as downloadable video content.
	 *
	 * @param array<string,mixed> $video Saved media-audit row.
	 * @return array{url:string,type:'content'|'player'} Empty URL when invalid.
	 */
	public static function resolve_video_publication( array $video ): array {
		$url = is_scalar( $video['url'] ?? null )
			? self::normalize_media_url( (string) $video['url'] )
			: '';
		if ( '' === $url ) {
			return array(
				'url'  => '',
				'type' => 'content',
			);
		}

		$stored_type = is_scalar( $video['video_url_type'] ?? null )
			? sanitize_key( (string) $video['video_url_type'] )
			: '';
		$youtube_id  = self::youtube_video_id( $url );
		if ( '' !== $youtube_id ) {
			return array(
				'url'  => 'https://www.youtube.com/embed/' . $youtube_id,
				'type' => 'player',
			);
		}
		$vimeo_id = self::vimeo_video_id( $url );
		if ( '' !== $vimeo_id ) {
			return array(
				'url'  => 'https://player.vimeo.com/video/' . $vimeo_id,
				'type' => 'player',
			);
		}
		if ( 'content' === $stored_type ) {
			return array(
				'url'  => $url,
				'type' => 'content',
			);
		}

		return array(
			'url'  => $url,
			'type' => 'player' === $stored_type ? 'player' : 'content',
		);
	}

	private static function youtube_video_id( string $url ): string {
		$pattern = '~^https?://(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?[^#]*?\bv=|(?:v|e|embed|shorts|live)/)|youtu\.be/)([a-z0-9_-]{11})(?:[/?&#]|$)~i';
		return 1 === preg_match( $pattern, $url, $matches )
			? (string) $matches[1]
			: '';
	}

	private static function vimeo_video_id( string $url ): string {
		$host = strtolower( (string) \wp_parse_url( $url, PHP_URL_HOST ) );
		if (
			'vimeo.com' !== $host
			&& 'www.vimeo.com' !== $host
			&& 'player.vimeo.com' !== $host
		) {
			return '';
		}

		return 1 === preg_match( '~/([1-9][0-9]*)(?:[/?#]|$)~', $url, $matches )
			? (string) $matches[1]
			: '';
	}

	/**
	 * Extract a literal poster URL from a video element.
	 *
	 * A generic site icon is not a video thumbnail. Direct videos without an
	 * actual poster remain available to Cybermaps' generic media inventory but
	 * are omitted from protocols that require a representative thumbnail.
	 */
	private static function extract_video_poster( string $markup ): string {
		if (
			1 !== preg_match(
				'/<video\b[^>]*\bposter=[\'"]([^\'"]+)[\'"]/i',
				$markup,
				$matches
			)
		) {
			return '';
		}

		return self::normalize_media_url( (string) $matches[1] );
	}

	/**
	 * Build a literal media description from stored context.
	 *
	 * @param string   $source   Media source.
	 * @param \WP_Post $post     Parent post.
	 * @param int      $media_id Attachment ID (if applicable).
	 * @param string   $hint     Manual hint (like alt text).
	 * @return string
	 */
	private function generate_multimodal_desc( $source, $post, $media_id = 0, $hint = '' ) {
		$title       = sanitize_text_field( (string) $post->post_title );
		$stored_hint = $this->media_hint( (int) $media_id, $hint );
		if ( in_array( $source, array( 'video', 'youtube', 'vimeo' ), true ) ) {
			return "Video embedded in \"{$title}\".";
		}
		$prefix = 'featured_image' === $source
			? 'Featured image for'
			: ( 'inline_image' === $source ? 'Image embedded in' : 'Attached media for' );
		return '' !== $stored_hint
			? "{$prefix} \"{$title}\": {$stored_hint}."
			: "{$prefix} \"{$title}\".";
	}

	private function media_hint( int $media_id, mixed $hint ): string {
		$stored_hint = sanitize_text_field( (string) $hint );
		if ( $media_id < 1 ) {
			return $stored_hint;
		}
		$alt = sanitize_text_field( (string) get_post_meta( $media_id, '_wp_attachment_image_alt', true ) );
		return '' !== $alt
			? $alt
			: ( '' !== $stored_hint ? $stored_hint : sanitize_text_field( (string) get_the_title( $media_id ) ) );
	}

	/**
	 * Run audit and save results to post meta.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $intensity Intensity level (none, standard, advanced).
	 * @return array Audited media.
	 */
	public function run_audit( $post_id, $intensity = 'standard' ) {
		$post_id   = (int) $post_id;
		$intensity = self::normalize_intensity( $intensity );
		if ( $post_id < 1 ) {
			return array();
		}

		if ( 'none' === $intensity ) {
			delete_post_meta( $post_id, self::AUDIT_GENERATION_META );
			delete_post_meta( $post_id, self::AUDIT_MODE_META );
			delete_post_meta( $post_id, '_cybermaps_media_audit' );
			return array();
		}

		$generation = self::get_audit_generation();
		delete_post_meta( $post_id, self::AUDIT_GENERATION_META );
		$media = ( 'advanced' === $intensity ) ? $this->scan_advanced( $post_id ) : $this->scan_standard( $post_id );
		$media = self::limit_audit_items( $media );

		update_post_meta( $post_id, '_cybermaps_media_audit', $media );
		update_post_meta( $post_id, self::AUDIT_MODE_META, $intensity );
		update_post_meta( $post_id, self::AUDIT_GENERATION_META, $generation );
		return $media;
	}

	/**
	 * Current site-wide media-audit generation.
	 */
	public static function get_audit_generation(): int {
		$generation = get_option( self::AUDIT_GENERATION_OPTION, 1 );
		return is_scalar( $generation ) && (int) $generation > 0
			? (int) $generation
			: 1;
	}

	/**
	 * Ensure URL is absolute.
	 *
	 * @param string $url URL to normalize.
	 * @return string
	 */
	public static function normalize_media_url( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url ) {
			return '';
		}
		if ( 1 === preg_match( '~^https?://~i', $url ) ) {
			return \Cybermaps\Core\URLManager::sanitize_http_url( $url );
		}
		if ( str_starts_with( $url, '//' ) ) {
			return \Cybermaps\Core\URLManager::sanitize_http_url(
				( is_ssl() ? 'https:' : 'http:' ) . $url
			);
		}
		if (
			1 === preg_match(
				'~^(?:www\.)?(?:youtube(?:-nocookie)?\.com|youtu\.be|(?:player\.)?vimeo\.com)/~i',
				$url
			)
		) {
			return \Cybermaps\Core\URLManager::sanitize_http_url( 'https://' . $url );
		}
		if ( 1 === preg_match( '~^[a-z][a-z0-9+.-]*:~i', $url ) ) {
			return '';
		}
		if ( str_starts_with( $url, '/' ) ) {
			return \Cybermaps\Core\URLManager::sanitize_http_url(
				\Cybermaps\Core\URLManager::get_home_url( $url )
			);
		}

		return \Cybermaps\Core\URLManager::sanitize_http_url(
			\Cybermaps\Core\URLManager::get_home_url( '/' . ltrim( $url, '/' ) )
		);
	}
}
