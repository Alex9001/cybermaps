<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dedicated, optional workspace for the Guided Setup client. */
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
			<div class="cm-setup-wizard-header">
				<div>
					<p class="cm-page-eyebrow"><?php esc_html_e( 'Optional configuration assistant', 'cybermaps' ); ?></p>
					<h1><?php esc_html_e( 'Guided Setup', 'cybermaps' ); ?></h1>
					<p><?php esc_html_e( 'Answer a short set of questions, inspect every proposed change, and apply only when you are ready.', 'cybermaps' ); ?></p>
				</div>
				<a class="button button-secondary" href="<?php echo esc_url( $overview_url ); ?>">
					<?php esc_html_e( 'Back to Overview', 'cybermaps' ); ?>
				</a>
			</div>
			<div id="cybermaps-setup-wizard-root" data-overview-url="<?php echo esc_url( $overview_url ); ?>">
				<p><?php esc_html_e( 'Loading Guided Setup…', 'cybermaps' ); ?></p>
			</div>
			<noscript>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Guided Setup requires JavaScript. You can configure every Cybermaps setting from its normal workspace.', 'cybermaps' ); ?></p></div>
			</noscript>
		</div>
		<?php
	}
}
