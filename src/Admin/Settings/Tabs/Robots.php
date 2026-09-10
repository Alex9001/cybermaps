<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs;

use Cybermaps\Admin\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Robots implements SettingsTab {

	public function slug(): string {
		return 'robots';
	}

	public function label(): string {
		return __( 'Robots', 'cybermaps' );
	}
	public function settings_page(): string {
		return 'cybermaps-robots';
	}


	public function register_settings(): void {}

	public function render(): void {
		?>
		<div class="cm-robots-workspace">
			<?php \Cybermaps\Admin\Settings\Tabs\Robots\RobotsFields::robots_section_callback(); ?>

			<section class="cm-settings-card cm-robots-takeover" aria-labelledby="cybermaps-robots-takeover-heading">
				<h3 id="cybermaps-robots-takeover-heading"><?php esc_html_e( 'Robots.txt Publishing', 'cybermaps' ); ?></h3>
				<?php \Cybermaps\Admin\Settings\Tabs\Robots\RobotsFields::render_robots_takeover_field(); ?>
			</section>

			<section class="cm-robots-panel" aria-labelledby="cybermaps-crawler-matrix-heading">
				<div class="section-header">
					<div>
						<h3 id="cybermaps-crawler-matrix-heading"><?php esc_html_e( 'Crawler Policies', 'cybermaps' ); ?></h3>
						<p><?php esc_html_e( 'Use a category row to update every crawler currently listed in that group, then expand it only when one crawler needs an exception.', 'cybermaps' ); ?></p>
					</div>
				</div>
				<?php \Cybermaps\Admin\Settings\Tabs\Robots\RobotsFields::render_crawler_matrix(); ?>
			</section>

			<section class="cm-robots-panel" aria-labelledby="cybermaps-content-signals-heading">
				<div class="section-header">
					<div>
						<h3 id="cybermaps-content-signals-heading"><?php esc_html_e( 'Content-Use Preferences', 'cybermaps' ); ?></h3>
					</div>
				</div>
				<?php \Cybermaps\Admin\Settings\Tabs\Robots\RobotsFields::render_content_signals_field(); ?>
				<?php \Cybermaps\Admin\Settings\Tabs\Robots\RobotsFields::render_content_usage_field(); ?>
			</section>
		</div>
		<?php
	}
}
