<?php
/**
 * Network Sitemap Renderer
 *
 * @package Cybermaps\Sitemap\Renderer
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap\Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Cybermaps\Sitemap\Orchestrator;

class NetworkRenderer extends BaseRenderer {

	/**
	 * Render Network Sitemap Index.
	 *
	 * @param \Cybermaps\Sitemap\XmlWriter $writer Sitemap XML writer.
	 */
	public function render( $writer ) {
		$options = get_site_option( 'cybermaps_network_settings', array() );
		$options = is_array( $options ) ? $options : array();
		if ( empty( $options['enable_master_index'] ) || ! is_main_site() ) {
			$this->render_empty( $writer );
			return;
		}

		$writer->startElement( 'sitemapindex' );
		$writer->writeAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );

		$network_id    = function_exists( 'get_current_network_id' )
			? (int) get_current_network_id()
			: 0;
		$network_scope = \Cybermaps\Core\URLManager::get_home_url( '/' );
		$blog_ids      = get_sites(
			array(
				'network_id' => $network_id,
				'fields'     => 'ids',
				'number'     => 0,
				'public'     => 1,
				'archived'   => 0,
				'spam'       => 0,
				'deleted'    => 0,
			)
		);

		foreach ( is_array( $blog_ids ) ? $blog_ids : array() as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			try {
				$basename       = defined( 'CYBERMAPS_PLUGIN_BASENAME' )
					? (string) CYBERMAPS_PLUGIN_BASENAME
					: 'cybermaps/cybermaps.php';
				$network_active = array_key_exists( $basename, (array) get_site_option( 'active_sitewide_plugins', array() ) );
				$site_active    = in_array( $basename, (array) get_option( 'active_plugins', array() ), true );
				if ( ! $network_active && ! $site_active ) {
					continue;
				}

				$base = Orchestrator::get_sitemap_base();
				$loc  = \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' );
			} finally {
				restore_current_blog();
			}
			if ( ! \Cybermaps\Sitemap\ExternalSitemapValidator::is_in_publication_scope( $loc, $network_scope ) ) {
				continue;
			}

			$writer->startElement( 'sitemap' );
			$writer->writeElement( 'loc', $loc );
			$writer->endElement();
		}

		$writer->endElement();
	}
}
