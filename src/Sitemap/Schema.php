<?php
declare(strict_types=1);
namespace Cybermaps\Sitemap;

use Cybermaps\SEO\PublicationEligibility;
use Cybermaps\SEO\SeoContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schema {
	/**
	 * Register hooks for schema injection.
	 */
	public function register_hooks() {
		add_action( 'wp_head', array( $this, 'inject_identity_schema' ) );
		add_action( 'wp_head', array( $this, 'inject_video_schema' ) );
	}

	/**
	 * Inject JSON-LD Identity schema based on the Identity Hub settings.
	 */
	public function inject_identity_schema() {
		if ( ! is_front_page() ) {
			return;
		}

		if ( ! ( new PublicationEligibility() )->decide( SeoContext::home(), PublicationEligibility::SCHEMA )->indexable ) {
			return;
		}

		$data = \Cybermaps\Core\ConfigurationStore::identity();
		$name = is_scalar( $data['name'] ?? null )
			? sanitize_text_field( (string) $data['name'] )
			: '';
		if ( '' === $name ) {
			return;
		}
		$data['name'] = $name;

		$schema = array( '@context' => 'https://schema.org' )
			+ \Cybermaps\Core\IdentityEntityBuilder::build( $data );

		wp_print_inline_script_tag(
			(string) wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			array( 'type' => 'application/ld+json' )
		);
	}

	/**
	 * Inject JSON-LD VideoObject schema if enabled and videos are found.
	 */
	public function inject_video_schema() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id      = (int) get_the_ID();
		$current_post = get_post( $post_id );
		if ( ! is_object( $current_post ) || ! ( new PublicationEligibility() )->post( $current_post, PublicationEligibility::SCHEMA )->indexable ) {
			return;
		}

		$settings         = \Cybermaps\Core\ConfigurationStore::settings();
		$video_enabled    = ! array_key_exists( 'enable_video_schema', $settings )
			|| ( is_scalar( $settings['enable_video_schema'] ) && '1' === (string) $settings['enable_video_schema'] );
		$stored_intensity = $settings['media_discovery_intensity'] ?? 'none';
		$media_intensity  = is_scalar( $stored_intensity )
			? sanitize_key( (string) $stored_intensity )
			: 'none';
		if ( ! $video_enabled || ! in_array( $media_intensity, array( 'standard', 'advanced' ), true ) ) {
			return;
		}

		$media  = MediaScanner::current_audit_items( $post_id, $media_intensity );
		$videos = array_filter(
			$media,
			function ( $item ) {
				return is_array( $item ) && isset( $item['type'] ) && 'video' === $item['type'];
			}
		);

		if ( empty( $videos ) ) {
			return;
		}

		$post_title  = (string) get_the_title( $post_id );
		$upload_date = (string) get_the_date( 'c', $post_id );
		$permalink   = (string) get_permalink( $post_id );
		foreach ( $videos as $vid ) {
			$schema = $this->build_video_schema(
				is_array( $vid ) ? $vid : array(),
				$post_id,
				$post_title,
				$upload_date,
				$permalink
			);
			if ( null === $schema ) {
				continue;
			}

			wp_print_inline_script_tag(
				(string) wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				array( 'type' => 'application/ld+json' )
			);
		}
	}

	/**
	 * Build a VideoObject only when all required public fields are usable.
	 *
	 * @param array<string, mixed> $video       Stored media-audit row.
	 * @param int                  $post_id     Owning post ID.
	 * @param string               $post_title  Owning post title.
	 * @param string               $upload_date ISO 8601 publication date.
	 * @param string               $permalink   Owning post permalink.
	 * @return array<string, string>|null
	 */
	private function build_video_schema(
		array $video,
		int $post_id,
		string $post_title,
		string $upload_date,
		string $permalink
	): ?array {
		$video_publication = MediaScanner::resolve_video_publication( $video );
		$thumbnail         = is_scalar( $video['thumbnail_loc'] ?? null )
			? (string) $video['thumbnail_loc']
			: '';
		$publication_url   = $this->normalize_public_url(
			(string) \Cybermaps\Core\URLManager::rewrite_url(
				$video_publication['url']
			)
		);
		$thumbnail_url     = $this->normalize_public_url(
			(string) \Cybermaps\Core\URLManager::rewrite_url(
				MediaScanner::normalize_media_url( $thumbnail )
			)
		);
		$permalink         = $this->normalize_public_url(
			(string) \Cybermaps\Core\URLManager::rewrite_url( $permalink )
		);
		$name              = is_scalar( $video['title'] ?? null )
			? trim( sanitize_text_field( (string) $video['title'] ) )
			: '';
		$name              = '' !== $name ? $name : trim( $post_title );
		$upload_date       = trim( $upload_date );

		if (
			$post_id < 1
			|| '' === $name
			|| '' === $upload_date
			|| '' === $publication_url
			|| '' === $thumbnail_url
			|| '' === $permalink
		) {
			return null;
		}

		$schema = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'VideoObject',
			'name'         => $name,
			'description'  => $name,
			'thumbnailUrl' => $thumbnail_url,
			'uploadDate'   => $upload_date,
			'@id'          => $permalink . '#video-' . md5( $publication_url ),
		);
		$schema[ 'player' === $video_publication['type'] ? 'embedUrl' : 'contentUrl' ] = $publication_url;
		return $schema;
	}

	/**
	 * Normalize an absolute HTTP(S) URL without performing a network request.
	 */
	private function normalize_public_url( string $candidate ): string {
		return \Cybermaps\Core\URLManager::sanitize_http_url( $candidate );
	}
}
