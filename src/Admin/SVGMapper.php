<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SVGMapper {

	public static function generate_html(): string {
		$settings  = \Cybermaps\Core\ConfigurationStore::settings();
		$hub       = ! empty( $settings['enable_discovery_hub'] );
		$domain    = str_replace(
			array( 'http://', 'https://' ),
			'',
			\Cybermaps\Core\URLManager::get_home_url()
		);
		$base      = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$news_base = \Cybermaps\Sitemap\Orchestrator::get_news_sitemap_base();
		$rss_base  = \Cybermaps\Sitemap\Orchestrator::get_rss_sitemap_base();
		$has_news  = ! empty( $settings['enable_google_news'] );
		$has_rss   = ! empty( $settings['enable_rss_sitemap'] );

		$cache_key = 'cybermaps_arch_v7_' . md5(
			(string) wp_json_encode(
				array(
					'settings'  => $settings,
					'locale'    => get_locale(),
					'domain'    => $domain,
					'base'      => $base,
					'news_base' => $news_base,
					'rss_base'  => $rss_base,
				)
			)
		);
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$groups = array(
			array(
				'label'  => __( 'Search Engines', 'cybermaps' ),
				'color'  => '#2563eb',
				'always' => true,
				'items'  => array(
					array(
						'path'   => '/' . $base . '.xml',
						'label'  => __( 'Sitemap Index', 'cybermaps' ),
						'active' => true,
					),
					array(
						'path'   => '/' . $news_base . '.xml',
						'label'  => __( 'Google News', 'cybermaps' ),
						'active' => $has_news,
					),
				),
			),
			array(
				'label'  => __( 'RSS Feed', 'cybermaps' ),
				'color'  => '#f59e0b',
				'always' => false,
				'items'  => array(
					array(
						'path'   => '/' . $rss_base . '.xml',
						'label'  => __( 'RSS Sitemap', 'cybermaps' ),
						'active' => $has_rss,
					),
				),
			),
			array(
				'label'  => __( 'AI Content', 'cybermaps' ),
				'color'  => '#7c3aed',
				'always' => false,
				'items'  => array(
					array(
						'path'   => '/ai-discovery.json',
						'label'  => __( 'ADP 3.0 Manifest', 'cybermaps' ),
						'active' => $hub,
					),
					array(
						'path'   => '/ai.json',
						'label'  => __( 'Cybermaps Manifest', 'cybermaps' ),
						'active' => $hub,
					),
					array(
						'path'   => '/llms.txt',
						'label'  => __( 'LLMS Compact', 'cybermaps' ),
						'active' => $hub,
					),
					array(
						'path'   => '/llms-full.txt',
						'label'  => __( 'LLMS Full', 'cybermaps' ),
						'active' => $hub && ! empty( $settings['enable_llms_full'] ),
					),
					array(
						'path'   => '/llms-tldr.txt',
						'label'  => __( 'Budgeted Briefing', 'cybermaps' ),
						'active' => $hub && ! empty( $settings['enable_llms_tldr'] ),
					),
					array(
						'path'   => '/feed.json',
						'label'  => __( 'JSON Feed', 'cybermaps' ),
						'active' => $hub,
					),
				),
			),
			array(
				'label'  => __( 'AI Structured Data', 'cybermaps' ),
				'color'  => '#059669',
				'always' => false,
				'items'  => array(
					array(
						'path'   => '/knowledge-graph.json',
						'label'  => __( 'Knowledge Graph', 'cybermaps' ),
						'active' => $hub,
					),
					array(
						'path'   => '/ai-sitemap.xml',
						'label'  => __( 'AI Sitemap', 'cybermaps' ),
						'active' => $hub,
					),
				),
			),
			array(
				'label'  => __( 'Web API Discovery', 'cybermaps' ),
				'color'  => '#dc2626',
				'always' => false,
				'items'  => array(
					array(
						'path'   => '/.well-known/api-catalog',
						'label'  => __( 'API Catalog', 'cybermaps' ),
						'active' => $hub,
					),
				),
			),
			array(
				'label'  => __( 'AI Policy', 'cybermaps' ),
				'color'  => '#0891b2',
				'always' => false,
				'items'  => array(
					array(
						'path'   => '/ai-usage.json',
						'label'  => __( 'Usage Policy', 'cybermaps' ),
						'active' => $hub,
					),
					array(
						'path'   => '/ai-actions.json',
						'label'  => __( 'AI Actions', 'cybermaps' ),
						'active' => $hub,
					),
					array(
						'path'   => \Cybermaps\Discovery\Capabilities::CANONICAL_PATH,
						'label'  => __( 'Site Guide', 'cybermaps' ),
						'active' => $hub,
					),
				),
			),
		);

		$output  = '<div class="cm-discovery-architecture">';
		$output .= '<div class="cm-arch-header">';
		$output .= '<span class="cm-arch-domain">' . esc_html( $domain ) . '</span>';
		$output .= '<span class="cm-arch-arrow">→</span>';
		$output .= '<strong>robots.txt</strong>';
		$output .= '</div>';

		$output .= '<div class="cm-arch-grid">';
		foreach ( $groups as $group ) {
			$ga      = $group['always'] || (bool) array_filter(
				$group['items'],
				static fn( array $item ): bool => ! empty( $item['active'] )
			);
			$output .= '<div class="cm-arch-group' . ( $ga ? '' : ' cm-arch-group-inactive' ) . '">';
			$output .= '<div class="cm-arch-group-head" style="background:' . ( $ga ? $group['color'] : '#94a3b8' ) . '">';
			$output .= '<span class="cm-arch-group-dot"></span>' . esc_html( $group['label'] );
			$output .= '</div><div class="cm-arch-items">';
			foreach ( $group['items'] as $item ) {
				$ia      = $item['active'];
				$output .= '<a href="' . esc_url( \Cybermaps\Core\URLManager::get_home_url( $item['path'] ) ) . '" target="_blank" rel="noopener noreferrer" class="cm-arch-item' . ( $ia ? ' cm-arch-item-active' : ' cm-arch-item-off' ) . '">';
				$output .= '<span class="cm-arch-item-dot" style="background:' . ( $ia ? '#10b981' : '#cbd5e1' ) . '"></span>';
				$output .= '<code>' . esc_html( $item['path'] ) . '</code>';
				$output .= '<span class="cm-arch-item-label">' . esc_html( $item['label'] ) . '</span>';
				$output .= '</a>';
			}
			$output .= '</div></div>';
		}
		$output .= '</div>';

		$output .= '<div class="cm-arch-legend">';
		$output .= '<span class="cm-arch-item-dot" style="background:#10b981"></span> ' . esc_html__( 'Active', 'cybermaps' );
		$output .= '<span class="cm-arch-item-dot" style="background:#cbd5e1; margin-left:16px;"></span> ' . esc_html__( 'Disabled', 'cybermaps' );
		$output .= '</div></div>';

		\Cybermaps\Core\CacheManager::set( $cache_key, $output, 24 * HOUR_IN_SECONDS, 'admin' );

		return $output;
	}
}
