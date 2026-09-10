<?php
/**
 * Index Sitemap Renderer
 *
 * @package Cybermaps\Sitemap\Renderer
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap\Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Cybermaps\Sitemap\ExternalSitemapValidator;

class IndexRenderer extends BaseRenderer {

	/**
	 * Render Sitemap Index.
	 *
	 * @param \Cybermaps\Sitemap\XmlWriter $writer Sitemap XML writer.
	 */
	public function render( $writer ) {
		$writer->startElement( 'sitemapindex' );
		$writer->writeAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );

		$settings = $this->orchestrator->get_settings();
		foreach ( $this->orchestrator->get_internal_sitemap_entries() as $entry ) {
			$writer->startElement( 'sitemap' );
			$writer->writeElement( 'loc', $entry['loc'] );
			if ( '' !== $entry['lastmod'] ) {
				$writer->writeElement( 'lastmod', $entry['lastmod'] );
			}
			$writer->endElement();
		}

		// Include structurally valid external sitemap URLs without blocking on HTTP.
		$external_sitemaps = is_scalar( $settings['external_sitemaps'] ?? null )
			? (string) $settings['external_sitemaps']
			: '';
		if ( '' !== $external_sitemaps ) {
			$urls = array_filter(
				explode(
					"\n",
					ExternalSitemapValidator::filter_textarea( $external_sitemaps )
				)
			);
			foreach ( $urls as $url ) {
				$writer->startElement( 'sitemap' );
				$writer->writeElement( 'loc', $url );
				$writer->endElement();
			}
		}

		$writer->endElement();
	}
}
