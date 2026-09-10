<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs;

use Cybermaps\Admin\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XML Sitemaps tab — publication strategy, XML formats, exclusions, and redirects.
 */
class Sitemaps implements SettingsTab {

	public function slug(): string {
		return 'sitemaps';
	}

	public function label(): string {
		return __( 'XML Sitemaps', 'cybermaps' );
	}
	public function settings_page(): string {
		return 'cybermaps-sitemaps';
	}

	public function register_settings(): void {}

	public function render(): void {
		?>
		<div class="cm-section-desc">
			<?php esc_html_e( 'Configure the content, media, language, delivery, and notification settings used by Cybermaps sitemap publications.', 'cybermaps' ); ?>
		</div>
		<section id="cybermaps_optimization_section" class="cybermaps-panel-card cm-sitemap-panel">
			<h2><span class="dashicons dashicons-chart-area" aria-hidden="true"></span><?php esc_html_e( 'Content Discovery Strategy', 'cybermaps' ); ?></h2>
			<?php \Cybermaps\Admin\Settings\Tabs\Sitemaps\SitemapsSections::optimization_section_callback(); ?>
		</section>
		<section id="cybermaps_scope_section" class="cybermaps-panel-card cm-sitemap-panel">
			<h2><span class="dashicons dashicons-filter" aria-hidden="true"></span><?php esc_html_e( 'Content Scope', 'cybermaps' ); ?></h2>
			<?php \Cybermaps\Admin\Settings\Tabs\Sitemaps\SitemapsSections::scope_section_callback(); ?>
		</section>
		<section id="cybermaps_general_section" class="cybermaps-panel-card cm-sitemap-panel">
			<h2><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php esc_html_e( 'Sitemap Paths & Delivery', 'cybermaps' ); ?></h2>
			<?php \Cybermaps\Admin\Settings\Tabs\Sitemaps\SitemapsSections::general_section_callback(); ?>
		</section>
		<section id="cybermaps_media_section" class="cybermaps-panel-card cm-sitemap-panel">
			<h2><span class="dashicons dashicons-admin-media" aria-hidden="true"></span><?php esc_html_e( 'Media Discovery', 'cybermaps' ); ?></h2>
			<?php \Cybermaps\Admin\Settings\Tabs\Sitemaps\SitemapsSections::media_section_callback(); ?>
		</section>
		<section id="cybermaps_indexing_section" class="cybermaps-panel-card cm-sitemap-panel">
			<h2><span class="dashicons dashicons-rss" aria-hidden="true"></span><?php esc_html_e( 'News, Feeds & Notifications', 'cybermaps' ); ?></h2>
			<?php \Cybermaps\Admin\Settings\Tabs\Sitemaps\SitemapsSections::indexing_section_callback(); ?>
		</section>
		<section id="cybermaps_international_section" class="cybermaps-panel-card cm-sitemap-panel">
			<h2><span class="dashicons dashicons-translation" aria-hidden="true"></span><?php esc_html_e( 'Language & Translation', 'cybermaps' ); ?></h2>
			<?php \Cybermaps\Admin\Settings\Tabs\Sitemaps\SitemapsSections::international_section_callback(); ?>
		</section>
		<?php
	}
}
