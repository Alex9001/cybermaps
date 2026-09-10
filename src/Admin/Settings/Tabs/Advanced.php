<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs;

use Cybermaps\Admin\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Advanced implements SettingsTab {

	public function slug(): string {
		return 'advanced';
	}

	public function label(): string {
		return __( 'Advanced', 'cybermaps' );
	}
	public function settings_page(): string {
		return 'cybermaps-advanced';
	}


	public function register_settings(): void {
		add_settings_section(
			'cybermaps_headless_section',
			__( 'Headless & API Integration', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields::class, 'headless_section_callback' ),
			'cybermaps-advanced'
		);

		add_settings_field(
			'frontend_base_url',
			__( 'Frontend Base URL', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-advanced',
			'cybermaps_headless_section',
			array(
				'label_for'   => 'frontend_base_url',
				'type'        => 'url',
				'placeholder' => 'https://frontend.example.com',
				'description' => __( 'The base URL of your headless frontend. Used for URL rewriting in publications. If IndexNow is enabled, the frontend must also proxy or publish the generated /{key}.txt verification path on that same host.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'cdn_base_url',
			__( 'Sitemap Media CDN Base URL', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-advanced',
			'cybermaps_headless_section',
			array(
				'label_for'   => 'cdn_base_url',
				'type'        => 'url',
				'placeholder' => 'https://cdn.example.com',
				'description' => __( 'Optional replacement origin for same-site image and video URLs written into XML sitemap entries.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'cdn_enabled',
			__( 'Rewrite Sitemap Media URLs', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_checkbox_field' ),
			'cybermaps-advanced',
			'cybermaps_headless_section',
			array(
				'label_for'   => 'cdn_enabled',
				'description' => __( 'Rewrite same-site media URLs in XML sitemap output to the CDN base URL. This does not move or serve XSLT, CSS, discovery endpoints, or generated static files.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'api_secret',
			__( 'API Secret', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_api_secret_field' ),
			'cybermaps-advanced',
			'cybermaps_headless_section',
			array( 'label_for' => 'api_secret' )
		);

		add_settings_field(
			'api_catalog',
			__( 'Linkset API Catalog', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_api_catalog' ),
			'cybermaps-advanced',
			'cybermaps_headless_section'
		);

		add_settings_section(
			'cybermaps_optimization_section',
			__( 'Delivery & Performance', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields::class, 'optimization_section_callback' ),
			'cybermaps-advanced'
		);

		add_settings_field(
			'enable_litespeed_cache_integration',
			__( 'LiteSpeed Cache Integration', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-advanced',
			'cybermaps_optimization_section',
			array(
				'label_for'   => 'enable_litespeed_cache_integration',
				'description' => __( 'Enabled by default when LiteSpeed Cache is available. Cybermaps emits publication tags, sends targeted invalidations, and marks negotiated responses no-cache through official LiteSpeed hooks. Disable only to prevent Cybermaps from calling those hooks.', 'cybermaps' ),
				'default'     => '1',
			)
		);

		add_settings_field(
			'enable_apcu_l1_cache',
			__( 'APCu Request-local Cache', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-advanced',
			'cybermaps_optimization_section',
			array(
				'label_for'   => 'enable_apcu_l1_cache',
				'description' => __( 'Enabled by default when APCu is available. Cybermaps stores only bounded derived sitemap, discovery, and chunk values in disposable worker-local memory. Durable queues, locks, and ownership records never use APCu.', 'cybermaps' ),
				'default'     => '1',
			)
		);

		add_settings_field(
			'edge_optimization_tools',
			__( 'Edge Optimization Tools', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields::class, 'render_edge_optimization_field' ),
			'cybermaps-advanced',
			'cybermaps_optimization_section'
		);

		add_settings_section(
			'cybermaps_maintenance_section',
			__( 'Maintenance & Cleanup', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields::class, 'maintenance_section_callback' ),
			'cybermaps-advanced'
		);
		add_settings_section(
			'cybermaps_proxy_section',
			__( 'Trusted Proxy Handling', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields::class, 'proxy_section_callback' ),
			'cybermaps-advanced'
		);

		add_settings_field(
			'trusted_proxy_header',
			__( 'Trusted Proxy Header', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_select_field' ),
			'cybermaps-advanced',
			'cybermaps_proxy_section',
			array(
				'label_for'   => 'trusted_proxy_header',
				'description' => __( 'Trust a single forwarding header only when WordPress is behind a reverse proxy whose IP ranges you explicitly control.', 'cybermaps' ),
				'default'     => 'off',
				'options'     => array(
					'off'             => __( 'Disabled', 'cybermaps' ),
					'forwarded'       => __( 'Forwarded', 'cybermaps' ),
					'x_forwarded_for' => __( 'X-Forwarded-For', 'cybermaps' ),
					'x_real_ip'       => __( 'X-Real-IP', 'cybermaps' ),
				),
			)
		);

		add_settings_field(
			'trusted_proxy_cidrs',
			__( 'Trusted Proxy CIDRs', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_textarea_field' ),
			'cybermaps-advanced',
			'cybermaps_proxy_section',
			array(
				'label_for'   => 'trusted_proxy_cidrs',
				'placeholder' => "10.0.0.0/8\n2001:db8::/32",
				'maxlength'   => 4096,
				'description' => __( 'Enter up to 64 IPv4 or IPv6 CIDRs, one per line. Entries outside these ranges are never trusted for client-IP forwarding.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'nuclear_purge',
			__( 'Static File Cleanup', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields::class, 'render_nuclear_purge_field' ),
			'cybermaps-advanced',
			'cybermaps_maintenance_section'
		);

		add_settings_field(
			'delete_data_on_uninstall',
			__( 'Uninstall Cleanup', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-advanced',
			'cybermaps_maintenance_section',
			array(
				'label_for'   => 'delete_data_on_uninstall',
				'description' => __( 'If enabled, uninstall deletes Core settings and data. It also removes unchanged generated files that Cybermaps can verify it owns; edited or pre-existing files are retained.', 'cybermaps' ),
				'default'     => '0',
			)
		);

		add_settings_section(
			'cybermaps_exchange_section',
			__( 'Backup & Migration', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields::class, 'exchange_section_callback' ),
			'cybermaps-advanced'
		);

		add_settings_field(
			'config_exchange',
			__( 'Configuration Exchange', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields::class, 'render_exchange_fields' ),
			'cybermaps-advanced',
			'cybermaps_exchange_section'
		);
	}

	public function render(): void {
		?>
		<div class="cm-section-desc">
			<strong><?php esc_html_e( 'Advanced configuration', 'cybermaps' ); ?></strong> &mdash; <?php esc_html_e( 'Delivery optimization, headless URLs, API secrets, trusted proxies, maintenance tools, and configuration import/export.', 'cybermaps' ); ?>
		</div>
		<?php do_settings_sections( $this->settings_page() ); ?>
		<?php
	}
}
