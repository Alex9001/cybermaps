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

use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Sitemap\ExternalSitemapValidator;
use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PageOccupancyBuilder;
use Cybermaps\Sitemap\PageOccupancyManifest;

class NetworkRenderer extends BaseRenderer {
	private const SITE_BATCH  = 100;
	private const MAX_ENTRIES = 50000;
	private const MAX_BYTES   = 52428800;

	/**
	 * Render a complete network index of leaf sitemaps, never member indexes.
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

		$budget    = filter_var( apply_filters( 'cybermaps_network_sitemap_time_budget', 1.0 ), FILTER_VALIDATE_FLOAT );
		$deadline  = microtime( true ) + max( 0.05, min( 5.0, false === $budget ? 1.0 : $budget ) );
		$locations = $this->collect_locations( $deadline );
		$writer->startElement( 'sitemapindex' );
		$writer->writeAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
		foreach ( $locations as $loc ) {
			$writer->startElement( 'sitemap' );
			$writer->writeElement( 'loc', $loc );
			$writer->endElement();
		}
		$writer->endElement();
	}

	/** @return string[] Complete, scope-valid leaf URLs or an explicit failure. */
	private function collect_locations( float $deadline ): array {
		$scope     = \Cybermaps\Core\URLManager::get_home_url( '/' );
		$locations = array();
		$bytes     = 256;
		$offset    = 0;
		do {
			$this->check_deadline( $deadline );
			$ids = $this->site_page( $offset );
			foreach ( $ids as $id ) {
				$this->check_deadline( $deadline );
				foreach ( $this->site_locations( (int) $id ) as $loc ) {
					$this->check_deadline( $deadline );
					if ( ! ExternalSitemapValidator::is_in_publication_scope( $loc, $scope ) ) {
						throw new BuildUnavailableException( esc_html__( 'Network sitemap members must share the network publication origin and path scope. No partial network index was produced.', 'cybermaps' ) );
					}
					$bytes += strlen( esc_xml( $loc ) ) + 30;
					if ( count( $locations ) >= self::MAX_ENTRIES || $bytes > self::MAX_BYTES ) {
						throw new BuildUnavailableException( esc_html__( 'The complete network sitemap exceeds the single-index protocol limit. No partial network index was produced.', 'cybermaps' ) );
					}
					$locations[] = $loc;
				}
			}
			$offset    += self::SITE_BATCH;
			$site_count = count( $ids );
		} while ( self::SITE_BATCH === $site_count );
		return $locations;
	}

	/** @return int[] One bounded network page, failing closed on database errors. */
	private function site_page( int $offset ): array {
		global $wpdb;
		$ids = get_sites(
			array(
				'network_id' => (int) get_current_network_id(),
				'fields'     => 'ids',
				'number'     => self::SITE_BATCH,
				'offset'     => $offset,
				'orderby'    => 'id',
				'order'      => 'ASC',
				'public'     => 1,
				'archived'   => 0,
				'spam'       => 0,
				'deleted'    => 0,
			)
		);
		if ( ! is_array( $ids ) || count( $ids ) > self::SITE_BATCH || ! empty( $wpdb->last_error ) ) {
			throw new BuildUnavailableException( esc_html__( 'Network sitemap site inventory could not be read safely. Please retry later.', 'cybermaps' ) );
		}
		return $ids;
	}

	/** @return string[] Completed member inventory; blog context always restored. */
	protected function site_locations( int $blog_id ): array {
		switch_to_blog( $blog_id );
		try {
			if ( ! $this->is_active_member() ) {
				return array();
			}
			$member   = new Orchestrator();
			$manifest = PageOccupancyManifest::load();
			if ( ! PageOccupancyManifest::is_usable( $manifest ) ) {
				( new PageOccupancyBuilder( $member ) )->ensure_scheduled();
				throw new BuildUnavailableException( esc_html__( 'Network sitemap member inventory is still being prepared. Please retry later.', 'cybermaps' ) );
			}
			$settings = $member->get_settings();
			// External URLs have no verified leaf-document contract. Publishing
			// them here could reintroduce an index of indexes.
			if ( ! empty( $settings['external_sitemaps'] ) ) {
				throw new BuildUnavailableException( esc_html__( 'Network sitemap cannot verify configured external sitemap documents as leaf sitemaps. No partial network index was produced.', 'cybermaps' ) );
			}
			$locations = array_column( $member->get_internal_sitemap_entries(), 'loc' );
			if ( ! PageOccupancyManifest::is_usable( $manifest ) ) {
				throw new BuildUnavailableException( esc_html__( 'Network sitemap member inventory changed during selection. Please retry later.', 'cybermaps' ) );
			}
			return $locations;
		} finally {
			restore_current_blog();
		}
	}

	private function is_active_member(): bool {
		$basename = defined( 'CYBERMAPS_PLUGIN_BASENAME' ) ? (string) CYBERMAPS_PLUGIN_BASENAME : 'cybermaps/cybermaps.php';
		return array_key_exists( $basename, (array) get_site_option( 'active_sitewide_plugins', array() ) )
			|| in_array( $basename, (array) get_option( 'active_plugins', array() ), true );
	}

	private function check_deadline( float $deadline ): void {
		if ( microtime( true ) >= $deadline ) {
			throw new BuildUnavailableException( esc_html__( 'Network sitemap selection exceeded its request time budget. No partial network index was produced.', 'cybermaps' ) );
		}
	}
}
