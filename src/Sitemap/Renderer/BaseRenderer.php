<?php
/**
 * Base Sitemap Renderer
 *
 * @package Cybermaps\Sitemap\Renderer
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap\Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Cybermaps\Sitemap\Orchestrator;

abstract class BaseRenderer {

	/**
	 * Orchestrator instance.
	 *
	 * @var Orchestrator
	 */
	protected $orchestrator;

	/**
	 * BaseRenderer constructor.
	 *
	 * @param Orchestrator $orchestrator Orchestrator instance.
	 */
	public function __construct( Orchestrator $orchestrator ) {
		$this->orchestrator = $orchestrator;
	}

	/**
	 * Render empty urlset.
	 *
	 * @param \Cybermaps\Sitemap\XmlWriter $writer Sitemap XML writer.
	 */
	public function render_empty( $writer, bool $not_found = true ) {
		if ( $not_found ) {
			status_header( 404 );
		}
		$writer->startElement( 'urlset' );
		$writer->writeAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
		$writer->endElement();
	}
}
