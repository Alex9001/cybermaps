<?php
/**
 * Sitemap Renderer
 *
 * @package Cybermaps\Sitemap\Renderer
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap\Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Cybermaps\Sitemap\ProviderInterface;
use Cybermaps\Sitemap\ProviderIdentity;

class SitemapRenderer extends BaseRenderer {
	private const MAX_VIDEO_DESCRIPTION_LENGTH = 2048;

	/**
	 * Render a specific sitemap.
	 *
	 * @param \Cybermaps\Sitemap\XmlWriter $writer   Sitemap XML writer.
	 * @param ProviderInterface $provider Provider instance.
	 * @param string            $type     Sitemap type.
	 * @param int                $page     Page number.
	 * @param array|null         $preloaded_urls Optional request-local URL list.
	 */
	public function render( $writer, $provider, $type, $page, ?array $preloaded_urls = null ) {
		$urls = null === $preloaded_urls ? $provider->get_urls( $page ) : $preloaded_urls;
		if ( empty( $urls ) ) {
			$this->render_empty( $writer, ProviderIdentity::NEWS !== $type );
			return;
		}
		$this->start_urlset( $writer, $type );
		foreach ( $urls as $url ) {
			$this->render_url( $writer, $url, $type );
		}
		$writer->endElement();
	}

	private function start_urlset( $writer, string $type ): void {
		$writer->startElement( 'urlset' );
		$writer->writeAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
		$writer->writeAttribute( 'xmlns:xhtml', 'http://www.w3.org/1999/xhtml' );
		$settings = $this->orchestrator->get_settings();
		if ( 'none' !== ( $settings['media_discovery_intensity'] ?? 'none' ) ) {
			$writer->writeAttribute( 'xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1' );
			$writer->writeAttribute( 'xmlns:video', 'http://www.google.com/schemas/sitemap-video/1.1' );
		}
		if ( ProviderIdentity::NEWS === $type ) {
			$writer->writeAttribute( 'xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9' );
		}
	}

	private function render_url( $writer, mixed $url, string $type ): void {
		if ( ! is_array( $url ) ) {
			return;
		}
		$location = \Cybermaps\Core\URLManager::sanitize_http_url( $url['loc'] ?? '' );
		if ( '' === $location ) {
			return;
		}
		$writer->startElement( 'url' );
		$writer->writeElement( 'loc', $location );
		$this->write_url_metadata( $writer, $url );
		$this->write_alternates( $writer, $url );
		$this->write_news( $writer, $url, $type );
		$this->write_images( $writer, $url );
		$this->write_videos( $writer, $url );
		$writer->endElement();
	}

	private function write_url_metadata( $writer, array $url ): void {
		$lastmod = self::scalar_text( $url['lastmod'] ?? '' );
		if ( '' !== $lastmod ) {
			$writer->writeElement( 'lastmod', $lastmod );
		}
		$changefreq = is_scalar( $url['changefreq'] ?? null ) ? sanitize_key( (string) $url['changefreq'] ) : '';
		if ( in_array( $changefreq, array( 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' ), true ) ) {
			$writer->writeElement( 'changefreq', $changefreq );
		}
		if ( is_scalar( $url['priority'] ?? null ) && is_numeric( $url['priority'] ) ) {
			$writer->writeElement(
				'priority',
				number_format( min( 1.0, max( 0.0, (float) $url['priority'] ) ), 1 )
			);
		}
	}

	private function write_alternates( $writer, array $url ): void {
		$alternates = is_array( $url['alternates'] ?? null ) ? $url['alternates'] : array();
		foreach ( $alternates as $lang => $href ) {
			$language = \Cybermaps\Core\TranslationHelper::normalize_hreflang( $lang );
			$href     = \Cybermaps\Core\URLManager::sanitize_http_url( $href );
			if ( '' === $language || '' === $href ) {
				continue;
			}
			$writer->startElement( 'xhtml:link' );
			$writer->writeAttribute( 'rel', 'alternate' );
			$writer->writeAttribute( 'hreflang', $language );
			$writer->writeAttribute( 'href', $href );
			$writer->endElement();
		}
	}

	private function write_news( $writer, array $url, string $type ): void {
		$news        = is_array( $url['news'] ?? null ) ? $url['news'] : array();
		$publication = is_array( $news['publication'] ?? null ) ? $news['publication'] : array();
		$name        = self::scalar_text( $publication['name'] ?? '' );
		$language    = \Cybermaps\Core\TranslationHelper::normalize_hreflang( $publication['language'] ?? '' );
		$date        = self::scalar_text( $news['publication_date'] ?? '' );
		$title       = self::scalar_text( $news['title'] ?? '' );
		if ( ProviderIdentity::NEWS !== $type || '' === $name || '' === $language || '' === $date || '' === $title ) {
			return;
		}
		$writer->startElement( 'news:news' );
		$writer->startElement( 'news:publication' );
		$writer->writeElement( 'news:name', $name );
		$writer->writeElement( 'news:language', $language );
		$writer->endElement();
		$writer->writeElement( 'news:publication_date', $date );
		$writer->writeElement( 'news:title', $title );
		$writer->endElement();
	}

	private function write_images( $writer, array $url ): void {
		$images = is_array( $url['images'] ?? null ) ? $url['images'] : array();
		foreach ( $images as $image ) {
			$image_url = is_array( $image )
				? \Cybermaps\Core\URLManager::sanitize_http_url( $image['url'] ?? '' )
				: '';
			if ( '' === $image_url ) {
				continue;
			}
			$writer->startElement( 'image:image' );
			$writer->writeElement( 'image:loc', $image_url );
			$writer->endElement();
		}
	}

	private function write_videos( $writer, array $url ): void {
		$videos = is_array( $url['videos'] ?? null ) ? $url['videos'] : array();
		foreach ( $videos as $video ) {
			$details = is_array( $video ) ? $this->video_details( $video ) : null;
			if ( null === $details ) {
				continue;
			}
			$writer->startElement( 'video:video' );
			$writer->writeElement( 'video:thumbnail_loc', $details['thumbnail'] );
			$writer->writeElement( 'video:title', $details['title'] );
			$writer->writeElement( 'video:description', $details['description'] );
			$writer->writeElement( $details['element'], $details['url'] );
			$writer->endElement();
		}
	}

	private function video_details( array $video ): ?array {
		$thumbnail   = \Cybermaps\Core\URLManager::sanitize_http_url( $video['thumbnail_loc'] ?? '' );
		$locations   = $this->video_locations( $video );
		$title       = self::scalar_text( $video['title'] ?? '' );
		$description = \Cybermaps\Discovery\PublicationConstraints::bounded_text(
			self::scalar_text( $video['description'] ?? $title ),
			self::MAX_VIDEO_DESCRIPTION_LENGTH
		);
		if (
			'' === $thumbnail
			|| ( '' === $locations['content'] && '' === $locations['player'] )
			|| '' === $title
			|| '' === $description
		) {
			return null;
		}

		return array(
			'thumbnail'   => $thumbnail,
			'title'       => $title,
			'description' => $description,
			'element'     => '' !== $locations['content'] ? 'video:content_loc' : 'video:player_loc',
			'url'         => '' !== $locations['content'] ? $locations['content'] : $locations['player'],
		);
	}

	/**
	 * @return array{content:string,player:string}
	 */
	private function video_locations( array $video ): array {
		$content = \Cybermaps\Core\URLManager::sanitize_http_url( $video['content_loc'] ?? '' );
		$player  = \Cybermaps\Core\URLManager::sanitize_http_url( $video['player_loc'] ?? '' );
		if ( '' !== $content || '' !== $player || empty( $video['url'] ) ) {
			return array(
				'content' => $content,
				'player'  => $player,
			);
		}

		$legacy = \Cybermaps\Sitemap\MediaScanner::resolve_video_publication( $video );
		if ( 'player' === $legacy['type'] ) {
			$player = \Cybermaps\Core\URLManager::sanitize_http_url( $legacy['url'] );
		} else {
			$content = \Cybermaps\Core\URLManager::sanitize_http_url( $legacy['url'] );
		}

		return array(
			'content' => $content,
			'player'  => $player,
		);
	}

	private static function scalar_text( mixed $value ): string {
		return is_scalar( $value )
			? trim( (string) $value )
			: '';
	}
}
