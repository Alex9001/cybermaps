<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs;

use Cybermaps\Admin\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Identity implements SettingsTab {

	public function slug(): string {
		return 'schema';
	}

	public function label(): string {
		return __( 'Schema', 'cybermaps' );
	}
	public function settings_page(): string {
		return 'cybermaps-schema';
	}


	public function register_settings(): void {}

	public function render(): void {
		?>
		<div class="cm-schema-workspace">
			<?php \Cybermaps\Admin\Settings\Tabs\Identity\IdentityFields::identity_section_callback(); ?>
			<?php \Cybermaps\Admin\Settings\Tabs\Identity\IdentityFields::render_identity_builder(); ?>
		</div>
		<?php
	}
}
