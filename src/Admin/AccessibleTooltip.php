<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders accessible informational toggletips across supported WordPress versions.
 */
final class AccessibleTooltip {
	/**
	 * Return a keyboard-accessible help trigger and dismissible information panel.
	 */
	public static function get( string $content, string $position = '' ): string {
		$classes = array( 'cybermaps-help-tip' );
		if ( in_array( $position, array( 'tip-left', 'tip-right' ), true ) ) {
			$classes[] = $position;
		}

		if ( function_exists( 'wp_get_toggletip' ) ) {
			return wp_get_toggletip(
				$content,
				array(
					'label'       => __( 'Help', 'cybermaps' ),
					'close_label' => __( 'Close', 'cybermaps' ),
					'icon'        => 'dashicons-editor-help',
					'class'       => implode( ' ', $classes ),
				)
			);
		}

		return self::get_legacy( $content, $classes );
	}

	/**
	 * Render the WordPress 7.0 fallback without defining a global Core polyfill.
	 *
	 * @param string[] $classes Wrapper classes.
	 */
	private static function get_legacy( string $content, array $classes ): string {
		$id          = wp_unique_id( 'cybermaps-toggletip-' );
		$label       = __( 'Help', 'cybermaps' );
		$close_label = __( 'Close', 'cybermaps' );
		$classes[]   = 'cybermaps-legacy-toggletip';

		return '<span class="' . esc_attr( implode( ' ', $classes ) ) . '">'
			. '<button type="button" class="cybermaps-legacy-toggletip__toggle" aria-haspopup="dialog" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '" aria-label="' . esc_attr( $label ) . '">'
			. '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span></button>'
			. '<span id="' . esc_attr( $id ) . '" class="cybermaps-legacy-toggletip__bubble" role="dialog" aria-label="' . esc_attr( $label ) . '" tabindex="-1" hidden>'
			. '<span id="' . esc_attr( $id ) . '-text" class="cybermaps-legacy-toggletip__text">' . esc_html( $content ) . '</span>'
			. '<button type="button" class="cybermaps-legacy-toggletip__close" aria-label="' . esc_attr( $close_label ) . '">'
			. '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></span></span>';
	}
}
