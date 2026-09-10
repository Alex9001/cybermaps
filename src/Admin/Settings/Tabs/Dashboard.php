<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs;

use Cybermaps\Admin\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Overview tab — factual capability state and architecture.
 */
class Dashboard implements SettingsTab {

	public function slug(): string {
		return 'dashboard';
	}

	public function label(): string {
		return __( 'Overview', 'cybermaps' );
	}
	public function settings_page(): string {
		return '';
	}


	public function register_settings(): void {
		// Dashboard has no settings — it's read-only.
	}

	public function render(): void {
		$setup_url = add_query_arg(
			array(
				'page' => 'cybermaps-settings',
				'view' => 'setup',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="cm-card cm-setup-launcher cm-mb-25">
			<div>
				<p class="cm-page-eyebrow"><?php esc_html_e( 'Optional configuration assistant', 'cybermaps' ); ?></p>
				<h2><?php esc_html_e( 'Set up Cybermaps with a guided review', 'cybermaps' ); ?></h2>
				<p class="cm-field-help"><?php esc_html_e( 'Answer a short set of questions, review the exact settings Cybermaps recommends, and apply only when you are ready.', 'cybermaps' ); ?></p>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( $setup_url ); ?>">
				<?php esc_html_e( 'Launch Guided Setup', 'cybermaps' ); ?>
			</a>
		</div>
		<div class="cm-card cm-mb-25">
			<div class="section-header">
				<h2><?php esc_html_e( 'Discovery Architecture', 'cybermaps' ); ?></h2>
				<?php echo \Cybermaps\Admin\AccessibleTooltip::get( __( 'Key endpoints your site exposes to search engines and AI agents. Enabled endpoints are shown in full color; disabled ones are dimmed. Click any endpoint to visit it.', 'cybermaps' ), 'tip-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessibleTooltip returns escaped trusted markup. ?>
			</div>
			<?php echo \Cybermaps\Admin\SVGMapper::generate_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="cm-field-help cm-mt-15"><?php esc_html_e( 'This grid summarizes the primary sitemap and discovery publications. The AI Publication Hub controls the AI publication surface; manifest visibility controls decide which enabled publications the AI manifest advertises.', 'cybermaps' ); ?></p>
		</div>

		<div class="cm-card cm-mb-25">
			<div class="section-header">
				<h2><?php esc_html_e( 'Publication State', 'cybermaps' ); ?></h2>
				<?php echo \Cybermaps\Admin\AccessibleTooltip::get( __( 'Literal enabled, disabled, and delivery-scope state. These are not quality or ranking scores.', 'cybermaps' ), 'tip-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessibleTooltip returns escaped trusted markup. ?>
			</div>
			<?php echo \Cybermaps\Admin\DashboardRenderer::render_capability_strip(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
	}
}
