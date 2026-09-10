<?php
/**
 * Cybermaps Debugging admin page.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\DiagnosticLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Displays the detected stack and Cybermaps optimization utilization. */
final class SystemStatus {
	private string $page_hook = '';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cybermaps_debug_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_cybermaps_debug_clear', array( $this, 'ajax_clear' ) );
		add_action( 'wp_ajax_cybermaps_debug_bundle', array( $this, 'ajax_bundle' ) );
		add_action( 'wp_ajax_cybermaps_debug_verification', array( $this, 'ajax_record_verification' ) );
	}

	public function add_page(): void {
		$hook            = add_submenu_page(
			'cybermaps-settings',
			__( 'Debugging', 'cybermaps' ),
			__( 'Debugging', 'cybermaps' ),
			'manage_options',
			'cybermaps-system-status',
			array( $this, 'render_page' )
		);
		$this->page_hook = is_string( $hook ) ? $hook : '';
	}

	public function enqueue_assets( string $hook ): void {
		if ( '' === $this->page_hook || $hook !== $this->page_hook ) {
			return;
		}
		wp_enqueue_style( 'cybermaps-command-center', CYBERMAPS_PLUGIN_URL . 'assets/css/admin-command-center.css', array(), CYBERMAPS_VERSION );
		wp_enqueue_style( 'cybermaps-system-status', CYBERMAPS_PLUGIN_URL . 'assets/css/system-status.css', array( 'cybermaps-command-center' ), CYBERMAPS_VERSION );
		wp_style_add_data( 'cybermaps-command-center', 'rtl', true );
		wp_enqueue_script( 'cybermaps-system-status', CYBERMAPS_PLUGIN_URL . 'assets/js/system-status.js', array(), CYBERMAPS_VERSION, true );
		wp_localize_script(
			'cybermaps-system-status',
			'cybermapsSystemStatus',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( EdgeOptimizationController::nonce_action() ),
				'debugNonce' => wp_create_nonce( 'cybermaps_debugging' ),
				'debugState' => DiagnosticLogger::state_summary(),
				'report'     => SystemStatusCollector::diagnostic_report(),
				'strings'    => array(
					'checking'         => __( 'Running public delivery checks…', 'cybermaps' ),
					'failed'           => __( 'The delivery check did not complete.', 'cybermaps' ),
					'copied'           => __( 'GitHub support bundle copied.', 'cybermaps' ),
					'copyFailed'       => __( 'Copy failed. Download the support bundle instead.', 'cybermaps' ),
					'cleared'          => __( 'Diagnostic events cleared.', 'cybermaps' ),
					'confirmClear'     => __( 'Permanently clear all collected Cybermaps diagnostic events?', 'cybermaps' ),
					'debugEnabled'     => __( 'Diagnostic logging enabled.', 'cybermaps' ),
					'debugDisabled'    => __( 'Diagnostic logging disabled. Existing events remain available until cleared or expired.', 'cybermaps' ),
					'debugActiveUntil' => __( 'Active until', 'cybermaps' ),
					'debugOff'         => __( 'Off', 'cybermaps' ),
					'eventsRetained'   => __( 'events retained for up to seven days', 'cybermaps' ),
					'noEvents'         => __( 'No diagnostic events have been collected.', 'cybermaps' ),
				),
			)
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cybermaps' ) );
		}
		$status  = SystemStatusCollector::collect();
		$summary = $status['summary'];
		?>
		<div class="wrap cybermaps-wrap cm-system-status">
			<?php
			PageHeader::render(
				__( 'Debugging', 'cybermaps' ),
				__( 'Collect bounded Cybermaps diagnostics and inspect current system and optimization status.', 'cybermaps' ),
				array(
					array(
						'label' => sprintf( /* translators: %d: active optimization count. */ __( '%d active', 'cybermaps' ), (int) $summary['active'] ),
						'tone'  => 'good',
					),
					array(
						'label' => sprintf( /* translators: %d: available optimization count. */ __( '%d available', 'cybermaps' ), (int) $summary['available'] ),
						'tone'  => (int) $summary['available'] > 0 ? 'warning' : 'neutral',
					),
					array(
						'label' => sprintf( /* translators: %d: attention item count. */ __( '%d need attention', 'cybermaps' ), (int) $summary['attention'] ),
						'tone'  => (int) $summary['attention'] > 0 ? 'warning' : 'neutral',
					),
				),
				array(
					array(
						'label' => __( 'Optimization controls', 'cybermaps' ),
						'url'   => add_query_arg(
							array(
								'page' => 'cybermaps-settings',
								'tab'  => 'advanced',
							),
							admin_url( 'admin.php' )
						) . '#cybermaps-edge-optimization',
					),
				)
			);
			?>
			<?php $this->render_debugging_card(); ?>
			<div class="cm-status-actions cm-mb-20">
				<button type="button" class="button" id="cybermaps-status-refresh"><?php esc_html_e( 'Refresh local status', 'cybermaps' ); ?></button>
				<button type="button" class="button button-primary" id="cybermaps-status-verify"><?php esc_html_e( 'Run public delivery checks', 'cybermaps' ); ?></button>
				<button type="button" class="button" id="cybermaps-status-copy"><?php esc_html_e( 'Copy GitHub support bundle', 'cybermaps' ); ?></button>
				<span id="cybermaps-status-feedback" class="cm-status-feedback" role="status" aria-live="polite"></span>
			</div>

			<div id="cybermaps-public-check-results" class="cm-mb-20" hidden></div>

			<?php foreach ( $status['sections'] as $section ) : ?>
				<section class="cm-card-sm cm-mb-20">
					<h2><?php echo esc_html( (string) $section['label'] ); ?></h2>
					<div class="cm-status-table-wrap">
						<table class="widefat striped cm-status-table">
							<thead><tr>
								<th><?php esc_html_e( 'Component', 'cybermaps' ); ?></th>
								<th><?php esc_html_e( 'Availability', 'cybermaps' ); ?></th>
								<th><?php esc_html_e( 'Cybermaps usage', 'cybermaps' ); ?></th>
								<th><?php esc_html_e( 'Evidence and next step', 'cybermaps' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( (array) $section['items'] as $item ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( (string) $item['label'] ); ?></th>
									<td><span class="cm-status-pill cm-status-<?php echo esc_attr( (string) $item['state'] ); ?>"><?php echo esc_html( self::state_label( (string) $item['state'] ) ); ?></span><br><?php echo esc_html( (string) $item['availability'] ); ?></td>
									<td><?php echo esc_html( (string) $item['usage'] ); ?></td>
									<td><?php echo esc_html( (string) $item['detail'] ); ?>
									<?php
									if ( '' !== (string) $item['action_url'] ) :
										?>
										<a href="<?php echo esc_url( (string) $item['action_url'] ); ?>"><?php esc_html_e( 'Configure', 'cybermaps' ); ?></a><?php endif; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Detection is evidence-based and may be inconclusive when a proxy hides the origin stack. The copied report intentionally omits secrets and deployment-specific paths.', 'cybermaps' ); ?></p>
		</div>
		<?php
	}

	/** Render opt-in debugging controls before the system inventory. */
	private function render_debugging_card(): void {
		$state = DiagnosticLogger::state_summary();
		?>
		<section class="cm-card-sm cm-mb-20 cybermaps-debug-card" aria-labelledby="cybermaps-debug-title">
			<h2 id="cybermaps-debug-title"><?php esc_html_e( 'Cybermaps diagnostic logging', 'cybermaps' ); ?></h2>
			<p><?php esc_html_e( 'Temporarily records bounded Cybermaps operations and failures. It omits credentials, cookies, request bodies, crawler analytics, IP and email addresses, full URLs, and absolute filesystem paths.', 'cybermaps' ); ?></p>
			<div class="cybermaps-debug-controls">
				<label class="cybermaps-debug-toggle"><input type="checkbox" id="cybermaps-debug-enabled" <?php checked( ! empty( $state['enabled'] ) ); ?> /> <strong><?php esc_html_e( 'Enable diagnostic logging', 'cybermaps' ); ?></strong></label>
				<label for="cybermaps-debug-duration"><?php esc_html_e( 'Automatically stop after', 'cybermaps' ); ?></label>
				<select id="cybermaps-debug-duration">
					<option value="3600"><?php esc_html_e( '1 hour', 'cybermaps' ); ?></option>
					<option value="14400"><?php esc_html_e( '4 hours', 'cybermaps' ); ?></option>
					<option value="86400" selected><?php esc_html_e( '24 hours', 'cybermaps' ); ?></option>
				</select>
			</div>
			<p id="cybermaps-debug-state" class="description" aria-live="polite"></p>
			<div class="cybermaps-debug-actions">
				<button type="button" class="button" id="cybermaps-debug-copy"><?php esc_html_e( 'Copy GitHub support bundle', 'cybermaps' ); ?></button>
				<button type="button" class="button" id="cybermaps-debug-download"><?php esc_html_e( 'Download support bundle', 'cybermaps' ); ?></button>
				<button type="button" class="button button-link-delete" id="cybermaps-debug-clear"><?php esc_html_e( 'Clear diagnostic log', 'cybermaps' ); ?></button>
			</div>
			<p id="cybermaps-debug-feedback" class="description" aria-live="polite"></p>
			<details class="cybermaps-debug-events">
				<summary><?php esc_html_e( 'Recent diagnostic events', 'cybermaps' ); ?> (<span id="cybermaps-debug-count"><?php echo esc_html( (string) $state['entry_count'] ); ?></span>)</summary>
				<div id="cybermaps-debug-log"><p class="description"><?php esc_html_e( 'Use Copy or Download to load the current redacted events.', 'cybermaps' ); ?></p></div>
			</details>
		</section>
		<?php
	}

	/** Enable or disable time-bounded diagnostic collection. */
	public function ajax_toggle(): void {
		check_ajax_referer( 'cybermaps_debugging', 'nonce' );
		$this->authorize_debug_request();
		$enabled  = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		$duration = isset( $_POST['duration'] ) ? absint( wp_unslash( $_POST['duration'] ) ) : DAY_IN_SECONDS;
		$state    = $enabled ? DiagnosticLogger::enable( $duration ) : DiagnosticLogger::disable();
		wp_send_json_success( array( 'state' => $state ) );
	}

	/** Delete retained diagnostic events. */
	public function ajax_clear(): void {
		check_ajax_referer( 'cybermaps_debugging', 'nonce' );
		$this->authorize_debug_request();
		wp_send_json_success( array( 'state' => DiagnosticLogger::clear() ) );
	}

	/** Return a fresh, bounded support bundle. */
	public function ajax_bundle(): void {
		check_ajax_referer( 'cybermaps_debugging', 'nonce' );
		$this->authorize_debug_request();
		wp_send_json_success(
			array( 'bundle' => DiagnosticLogger::support_bundle( SystemStatusCollector::diagnostic_report() ) )
		);
	}

	/** Record only aggregate delivery-check evidence from the Debugging page. */
	public function ajax_record_verification(): void {
		check_ajax_referer( 'cybermaps_debugging', 'nonce' );
		$this->authorize_debug_request();
		$total  = isset( $_POST['total'] ) ? absint( wp_unslash( $_POST['total'] ) ) : 0;
		$passed = isset( $_POST['passed'] ) ? absint( wp_unslash( $_POST['passed'] ) ) : 0;
		DiagnosticLogger::log(
			'delivery.verification.completed',
			array(
				'total'             => $total,
				'passed'            => min( $passed, $total ),
				'resource_failures' => max( 0, $total - $passed ),
			),
			$passed >= $total ? 'info' : 'warning'
		);
		wp_send_json_success( array( 'recorded' => DiagnosticLogger::is_enabled() ) );
	}

	/** Authorize a Debugging AJAX mutation or export. */
	private function authorize_debug_request(): void {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'POST requests are required.', 'cybermaps' ) ), 405 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage Cybermaps debugging.', 'cybermaps' ) ), 403 );
		}
	}

	private static function state_label( string $state ): string {
		return match ( $state ) {
			'active'         => __( 'Active', 'cybermaps' ),
			'available'      => __( 'Available but unused', 'cybermaps' ),
			'attention'      => __( 'Needs attention', 'cybermaps' ),
			'unavailable'    => __( 'Unavailable', 'cybermaps' ),
			'not_applicable' => __( 'Not applicable', 'cybermaps' ),
			default          => __( 'Unknown', 'cybermaps' ),
		};
	}
}
