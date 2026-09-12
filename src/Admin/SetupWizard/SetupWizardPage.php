<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dedicated, optional workspace for the Quick Setup client. */
final class SetupWizardPage {
	public function render(): void {
		$overview_url = add_query_arg(
			array(
				'page' => 'cybermaps-settings',
				'tab'  => 'dashboard',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap cybermaps-setup-wizard-page">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Cybermaps Quick Setup', 'cybermaps' ); ?></h1>
			<div class="cm-setup-wizard-header">
				<div class="cm-setup-wizard-brand">
					<span><?php esc_html_e( 'Cybermaps Quick Setup', 'cybermaps' ); ?></span>
				</div>
				<a class="button-link" data-quick-setup-exit href="<?php echo esc_url( $overview_url ); ?>">
					<?php esc_html_e( 'Skip for now', 'cybermaps' ); ?>
				</a>
			</div>
			<div id="cybermaps-setup-wizard-root" data-overview-url="<?php echo esc_url( $overview_url ); ?>">
				<p><?php esc_html_e( 'Getting your site ready…', 'cybermaps' ); ?></p>
			</div>
			<noscript>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Quick Setup requires JavaScript. You can configure every Cybermaps setting from its normal workspace.', 'cybermaps' ); ?></p></div>
			</noscript>
		</div>
		<?php
	}
}
