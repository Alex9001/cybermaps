<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs\Identity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IdentityFields {

	public static function render_identity_builder() {
		$identity_hub = new \Cybermaps\Admin\IdentityHub();
		$identity_hub->render_identity_builder();
	}
	public static function identity_section_callback() {
		echo '<div class="section-header cm-schema-header"><div>';
		echo '<h2>' . esc_html__( 'Schema & Site Identity', 'cybermaps' ) . '</h2>';
		echo '<p>' . esc_html__( 'Define the organization, local business, or person represented by this site. Cybermaps uses it for homepage JSON-LD and its Knowledge Graph.', 'cybermaps' ) . '</p>';
		echo '</div></div>';
	}
}
