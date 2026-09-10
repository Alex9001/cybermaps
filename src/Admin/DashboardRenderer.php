<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders factual Core capability state without synthetic quality scores.
 */
final class DashboardRenderer {
	public static function render_capability_strip(): string {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$mode     = \Cybermaps\Discovery\StaticBridge::get_mode( $settings );
		$hub      = ! empty( $settings['enable_discovery_hub'] );
		$full     = ! empty( $settings['enable_llms_full'] );
		$tldr     = ! empty( $settings['enable_llms_tldr'] );

		return self::render_capability_items( self::capability_items( $mode, $hub, $full, $tldr ) );
	}

	/**
	 * @return array<int,array{label:string,active:bool,status:string,detail:string}> Capability items.
	 */
	private static function capability_items( string $mode, bool $hub, bool $full, bool $tldr ): array {
		$static_state = self::static_publication_state( $mode, $hub );
		$full_state   = self::publication_state(
			$hub,
			$full,
			__( 'Published', 'cybermaps' ),
			__( 'Off', 'cybermaps' )
		);
		$tldr_state   = self::publication_state(
			$hub,
			$tldr,
			__( 'Experimental / published', 'cybermaps' ),
			__( 'Experimental / off', 'cybermaps' )
		);

		return array(
			array(
				'label'  => __( 'XML sitemaps', 'cybermaps' ),
				'active' => true,
				'status' => __( 'Available', 'cybermaps' ),
				'detail' => __( 'Index and eligible child sitemaps are registered.', 'cybermaps' ),
			),
			array(
				'label'  => __( 'Static publication', 'cybermaps' ),
				'active' => $static_state['active'],
				'status' => $static_state['status'],
				'detail' => __( 'Effective physical-publication state. Full scope can publish sitemap files independently; compatibility files require the AI Publication Hub.', 'cybermaps' ),
			),
			array(
				'label'  => __( 'AI publishing', 'cybermaps' ),
				'active' => $hub,
				'status' => $hub ? __( 'Enabled', 'cybermaps' ) : __( 'Disabled', 'cybermaps' ),
				'detail' => __( 'Master state of the public AI discovery surface.', 'cybermaps' ),
			),
			array(
				'label'  => __( 'Complete content file', 'cybermaps' ),
				'active' => $full_state['active'],
				'status' => $full_state['status'],
				'detail' => __( 'Opt-in, complete-or-fail llms-full.txt literal content publication (4 MiB safety ceiling).', 'cybermaps' ),
			),
			array(
				'label'  => __( 'Budgeted briefing', 'cybermaps' ),
				'active' => $tldr_state['active'],
				'status' => $tldr_state['status'],
				'detail' => __( 'Cybermaps experimental briefing; no automatic consumers are documented.', 'cybermaps' ),
			),
		);
	}

	/**
	 * @return array{active:bool,status:string} Static-publication state.
	 */
	private static function static_publication_state( string $mode, bool $hub ): array {
		return array(
			'active' => 'all' === $mode || ( $hub && 'well_known' === $mode ),
			'status' => match ( $mode ) {
				'all'        => __( 'Full scope selected', 'cybermaps' ),
				'well_known' => $hub ? __( '.well-known active', 'cybermaps' ) : __( 'Configured; hub disabled', 'cybermaps' ),
				default      => __( 'Dynamic only', 'cybermaps' ),
			},
		);
	}

	/**
	 * @return array{active:bool,status:string} Publication state.
	 */
	private static function publication_state(
		bool $hub,
		bool $enabled,
		string $published_status,
		string $off_status
	): array {
		return array(
			'active' => $hub && $enabled,
			'status' => $enabled
				? ( $hub ? $published_status : __( 'Configured; hub disabled', 'cybermaps' ) )
				: $off_status,
		);
	}

	/**
	 * @param array<int,array{label:string,active:bool,status:string,detail:string}> $items Capability items.
	 */
	private static function render_capability_items( array $items ): string {
		$output = '<div class="cm-capability-strip">';
		foreach ( $items as $item ) {
			$output .= sprintf(
				'<div class="cm-cap-chip %1$s" data-cm-tooltip="%2$s"><div class="cm-cap-meta"><span class="cm-cap-primary-label">%3$s</span><span class="cm-cap-status %1$s">%4$s</span></div></div>',
				esc_attr( $item['active'] ? 'active' : 'inactive' ),
				esc_attr( $item['detail'] ),
				esc_html( $item['label'] ),
				esc_html( $item['status'] )
			);
		}
		return $output . '</div>';
	}
}
