<?php
declare(strict_types=1);

namespace Cybermaps\Admin\Settings\Tabs;

use Cybermaps\Admin\Settings\SettingsTab;
use Cybermaps\Audit\AuditExporter;
use Cybermaps\Audit\AuditRunRepository;
use Cybermaps\Audit\ContentAuditService;
use Cybermaps\Audit\ReportPresentation;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evidence-first reports, exports, and client presentation controls.
 */
final class ContentReview implements SettingsTab {
	public function slug(): string {
		return 'review';
	}

	public function label(): string {
		return __( 'Reports', 'cybermaps' );
	}

	public function settings_page(): string {
		return 'cybermaps-content-review';
	}

	public function register_settings(): void {
		add_settings_section(
			'cybermaps_content_review_policy',
			__( 'Content Measurement Policy', 'cybermaps' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Rules are explicit and saved with each report. They apply to public, search-indexable content and turn literal measurements into a practical professional review queue. Save policy changes before running a new report.', 'cybermaps' ) . '</p>';
			},
			$this->settings_page()
		);

		$fields = array(
			'audit_post_min_words'    => array(
				__( 'Post minimum words', 'cybermaps' ),
				'300',
				__( 'Minimum literal visible word count for posts.', 'cybermaps' ),
				1,
				10000,
			),
			'audit_post_max_age_days' => array(
				__( 'Post review interval', 'cybermaps' ),
				'365',
				__( 'Flag posts whose stored modified date is older than this many days. Use 0 to disable.', 'cybermaps' ),
				0,
				36500,
			),
			'audit_page_min_words'    => array(
				__( 'Page minimum words', 'cybermaps' ),
				'150',
				__( 'Minimum literal visible word count for pages.', 'cybermaps' ),
				1,
				10000,
			),
			'audit_page_max_age_days' => array(
				__( 'Page review interval', 'cybermaps' ),
				'0',
				__( 'Pages have no age finding by default. Enter days to enable one.', 'cybermaps' ),
				0,
				36500,
			),
		);
		foreach ( $fields as $key => $field ) {
			add_settings_field(
				$key,
				$field[0],
				array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
				$this->settings_page(),
				'cybermaps_content_review_policy',
				array(
					'label_for'   => $key,
					'default'     => $field[1],
					'placeholder' => $field[1],
					'description' => $field[2],
					'type'        => 'number',
					'min'         => $field[3],
					'max'         => $field[4],
					'step'        => 1,
				)
			);
		}

		add_settings_field(
			'audit_post_require_media',
			__( 'Post media review', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			$this->settings_page(),
			'cybermaps_content_review_policy',
			array(
				'label_for'   => 'audit_post_require_media',
				'label'       => __( 'Review posts without an image or media block', 'cybermaps' ),
				'description' => __( 'Enabled by default. A finding recommends judgment; it does not require decorative media.', 'cybermaps' ),
				'default'     => '1',
			)
		);
		add_settings_field(
			'audit_page_require_media',
			__( 'Page media review', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			$this->settings_page(),
			'cybermaps_content_review_policy',
			array(
				'label_for'   => 'audit_page_require_media',
				'label'       => __( 'Review pages without an image or media block', 'cybermaps' ),
				'description' => __( 'Disabled by default because many professional pages are intentionally text-led.', 'cybermaps' ),
				'default'     => '0',
			)
		);

		add_settings_section(
			'cybermaps_content_review_presentation',
			__( 'Client Report Presentation', 'cybermaps' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Optional presentation details brand the printable deliverable without changing the saved measurements.', 'cybermaps' ) . '</p>';
			},
			$this->settings_page()
		);
		foreach (
			array(
				'agency_name'        => array(
					__( 'Prepared by', 'cybermaps' ),
					__( 'Example Studio', 'cybermaps' ),
					__( 'Optional professional or agency name.', 'cybermaps' ),
				),
				'agency_url'         => array(
					__( 'Prepared-by URL', 'cybermaps' ),
					'https://example.com',
					__( 'Optional link used with the prepared-by name.', 'cybermaps' ),
				),
				'site_name_override' => array(
					__( 'Client site name', 'cybermaps' ),
					get_bloginfo( 'name' ),
					__( 'Optional client-facing display name.', 'cybermaps' ),
				),
			) as $key => $field
		) {
			add_settings_field(
				$key,
				$field[0],
				array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
				$this->settings_page(),
				'cybermaps_content_review_presentation',
				array(
					'label_for'   => $key,
					'type'        => 'agency_url' === $key ? 'url' : 'text',
					'placeholder' => $field[1],
					'description' => $field[2],
				)
			);
		}
		add_settings_field(
			'agency_logo',
			__( 'Prepared-by logo', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_media_upload_field' ),
			$this->settings_page(),
			'cybermaps_content_review_presentation',
			array(
				'label_for'   => 'agency_logo',
				'description' => __( 'Optional logo displayed in printable reports.', 'cybermaps' ),
			)
		);
		add_settings_field(
			'report_theme',
			__( 'Report theme', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_select_field' ),
			$this->settings_page(),
			'cybermaps_content_review_presentation',
			array(
				'label_for'   => 'report_theme',
				'description' => __( 'Visual theme used by printable content and discovery reports.', 'cybermaps' ),
				'default'     => 'swiss',
				'options'     => ReportPresentation::themes(),
			)
		);
	}

	public function render(): void {
		$state              = self::load_report_state();
		$run                = $state['run'];
		$history            = $state['history'];
		$report_load_error  = $state['error'];
		$catalog            = AuditExporter::finding_catalog();
		$counts             = self::finding_counts( $run, $catalog );
		$discovery_html_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cybermaps_export_discovery_report&format=html' ),
			'cybermaps_reports'
		);
		$discovery_json_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cybermaps_export_discovery_report&format=json' ),
			'cybermaps_reports'
		);
		?>
		<div class="cybermaps-section cm-reports-hub">
			<?php
			self::render_report_notices( $report_load_error );
			self::render_reports_heading();
			self::render_feature_cards( $run, $discovery_html_url, $discovery_json_url );
			self::render_report_state( $run, $history, $report_load_error, $catalog, $counts );
			self::render_settings_panels( $this->settings_page() );
			?>
		</div>
		<?php
	}

	/**
	 * Load a selected or latest completed report and its bounded history.
	 *
	 * @return array{run:?array,history:array<int,array<string,mixed>>,error:string}
	 */
	private static function load_report_state(): array {
		$repository       = new AuditRunRepository();
		$requested_run_id = self::requested_run_id();
		$run              = null;
		$history          = array();
		$error            = '';

		try {
			$run_id = $repository->latest_completed_run_id();
			if ( $requested_run_id > 0 ) {
				$run_id = $requested_run_id;
			}
			$run = self::load_display_run( $run_id );
			if ( null === $run && $requested_run_id > 0 ) {
				$run = self::load_display_run( $repository->latest_completed_run_id() );
			}
			$history = $repository->completed_runs( 10 );
		} catch ( Throwable ) {
			$error = __( 'Saved content reports could not be loaded from the database. The report tables may need repair.', 'cybermaps' );
		}

		return array(
			'run'     => $run,
			'history' => $history,
			'error'   => $error,
		);
	}

	private static function requested_run_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only selection of an already completed report.
		if ( ! isset( $_GET['cybermaps_run_id'] ) || ! is_scalar( $_GET['cybermaps_run_id'] ) ) {
			return 0;
		}
		$value = absint( wp_unslash( (string) $_GET['cybermaps_run_id'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $value;
	}

	/** @return array<string,mixed>|null */
	private static function load_display_run( int $run_id ): ?array {
		if ( $run_id < 1 ) {
			return null;
		}

		return ( new ContentAuditService() )->get_run_for_display( $run_id );
	}

	/**
	 * @param array<string,mixed>|null              $run     Selected report.
	 * @param array<string,array<string,mixed>>     $catalog Finding catalog.
	 * @return array<string,int>
	 */
	private static function finding_counts( ?array $run, array $catalog ): array {
		$counts = array_fill_keys( array_keys( $catalog ), 0 );
		if ( null === $run ) {
			return $counts;
		}
		$stored_counts = $run['finding_counts'] ?? array();
		foreach ( is_array( $stored_counts ) ? $stored_counts : array() as $key => $count ) {
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] = max( 0, (int) $count );
			}
		}

		return $counts;
	}

	private static function render_report_notices( string $report_load_error ): void {
		if ( '' !== $report_load_error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $report_load_error ) . '</p></div>';
		}
		if ( self::report_deleted_notice_requested() ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The selected content report was deleted.', 'cybermaps' ) . '</p></div>';
		}
		if ( self::report_busy_notice_requested() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Another Content Intelligence Report is already running. Wait for it to finish before starting another.', 'cybermaps' ) . '</p></div>';
		}
	}

	private static function report_deleted_notice_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice after a nonce-protected action.
		$value = isset( $_GET['cybermaps_report_deleted'] ) && is_scalar( $_GET['cybermaps_report_deleted'] )
			? absint( wp_unslash( (string) $_GET['cybermaps_report_deleted'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the protected action.
		return $value > 0;
	}

	private static function report_busy_notice_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice after a nonce-protected run request.
		$value = isset( $_GET['cybermaps_report_busy'] ) && is_scalar( $_GET['cybermaps_report_busy'] )
			? sanitize_text_field( wp_unslash( (string) $_GET['cybermaps_report_busy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after the protected action.
		return '1' === $value;
	}

	private static function render_reports_heading(): void {
		?>
		<div class="cm-reports-heading">
			<p class="cm-page-eyebrow"><?php esc_html_e( 'Professional deliverables', 'cybermaps' ); ?></p>
			<h2><?php esc_html_e( 'Reports', 'cybermaps' ); ?></h2>
			<p><?php esc_html_e( 'Create client-ready measurement reports, focused action lists, and a validated inventory of your AI discovery publications.', 'cybermaps' ); ?></p>
		</div>
		<?php
	}

	/** @param array<string,mixed>|null $run Selected report. */
	private static function render_feature_cards( ?array $run, string $discovery_html_url, string $discovery_json_url ): void {
		?>
		<div class="cm-report-feature-grid">
			<section class="cm-report-feature cm-report-feature--content">
				<div class="cm-report-feature-icon"><span class="dashicons dashicons-analytics"></span></div>
				<div class="cm-report-feature-copy">
					<span class="cm-report-kicker"><?php esc_html_e( 'Measured content intelligence', 'cybermaps' ); ?></span>
					<h3><?php esc_html_e( 'Content Intelligence Report', 'cybermaps' ); ?></h3>
					<p><?php esc_html_e( 'Measure public search-indexable content, save the policy and measurements, compare against the prior report, and export a branded resolution plan.', 'cybermaps' ); ?></p>
				</div>
				<div class="cm-report-feature-actions">
					<input type="hidden" name="action" value="cybermaps_run_content_audit" form="cybermaps-run-content-audit-form">
					<input type="hidden" name="cybermaps_content_audit_nonce" value="<?php echo esc_attr( wp_create_nonce( 'cybermaps_content_audit' ) ); ?>" form="cybermaps-run-content-audit-form">
					<button type="submit" form="cybermaps-run-content-audit-form" class="button button-primary"><?php esc_html_e( 'Run new report', 'cybermaps' ); ?></button>
					<?php if ( null !== $run ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( self::export_url( (int) $run['id'], 'html' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View report', 'cybermaps' ); ?></a>
					<?php endif; ?>
				</div>
			</section>

			<section class="cm-report-feature cm-report-feature--discovery">
				<div class="cm-report-feature-icon"><span class="dashicons dashicons-networking"></span></div>
				<div class="cm-report-feature-copy">
					<span class="cm-report-kicker"><?php esc_html_e( 'Live validation', 'cybermaps' ); ?></span>
					<h3><?php esc_html_e( 'AI Discovery Publication Report', 'cybermaps' ); ?></h3>
					<p><?php esc_html_e( 'Generate a point-in-time, client-facing inventory of discovery publications, intended delivery, media types, and public HTTP validation.', 'cybermaps' ); ?></p>
				</div>
				<div class="cm-report-feature-actions">
					<a class="button button-primary" href="<?php echo esc_url( $discovery_html_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Generate report', 'cybermaps' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( $discovery_json_url ); ?>"><?php esc_html_e( 'Export JSON', 'cybermaps' ); ?></a>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed>|null          $run     Selected report.
	 * @param array<int,array<string,mixed>>    $history Completed report history.
	 * @param array<string,array<string,mixed>> $catalog Finding catalog.
	 * @param array<string,int>                 $counts  Finding counts.
	 */
	private static function render_report_state( ?array $run, array $history, string $report_load_error, array $catalog, array $counts ): void {
		if ( null !== $run ) {
			self::render_saved_report( $run, $history, $catalog, $counts );
			return;
		}
		if ( '' === $report_load_error ) {
			self::render_empty_report_state();
		}
	}

	/**
	 * @param array<string,mixed>               $run     Selected report.
	 * @param array<int,array<string,mixed>>    $history Completed report history.
	 * @param array<string,array<string,mixed>> $catalog Finding catalog.
	 * @param array<string,int>                 $counts  Finding counts.
	 */
	private static function render_saved_report( array $run, array $history, array $catalog, array $counts ): void {
		self::render_saved_report_header( $run );
		self::render_saved_report_metrics( $run );
		self::render_action_reports( $run, $catalog, $counts );
		self::render_findings_preview( $run, $catalog );
		self::render_report_history( $history, (int) $run['id'] );
	}

	/** @param array<string,mixed> $run Selected report. */
	private static function render_saved_report_header( array $run ): void {
		$run_id = (int) $run['id'];
		?>
		<div class="cm-report-run-header">
			<div>
				<span class="cm-report-kicker"><?php esc_html_e( 'Saved report', 'cybermaps' ); ?></span>
				<h3>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: saved report ID. */
							__( 'Report #%d', 'cybermaps' ),
							$run_id
						)
					);
					?>
				</h3>
				<p><?php echo esc_html( (string) self::report_value( $run, 'completed_gmt' ) ); ?> GMT</p>
			</div>
			<div class="cm-report-export-actions">
				<a class="button button-primary" href="<?php echo esc_url( self::export_url( $run_id, 'html' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Printable report', 'cybermaps' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( self::export_url( $run_id, 'csv' ) ); ?>"><?php esc_html_e( 'CSV', 'cybermaps' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( self::export_url( $run_id, 'json' ) ); ?>"><?php esc_html_e( 'JSON', 'cybermaps' ); ?></a>
				<?php if ( empty( $run['is_baseline'] ) ) : ?>
					<input type="hidden" name="action" value="cybermaps_delete_content_audit" form="cybermaps-delete-content-audit-form">
					<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run_id ); ?>" form="cybermaps-delete-content-audit-form">
					<input type="hidden" name="cybermaps_delete_nonce" value="<?php echo esc_attr( wp_create_nonce( 'cybermaps_delete_content_audit_' . $run_id ) ); ?>" form="cybermaps-delete-content-audit-form">
					<button type="submit" form="cybermaps-delete-content-audit-form" class="button button-link-delete" data-cybermaps-confirm="<?php echo esc_attr( __( 'Delete this saved report? This cannot be undone.', 'cybermaps' ) ); ?>"><?php esc_html_e( 'Delete report', 'cybermaps' ); ?></button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** @param array<string,mixed> $run Selected report. */
	private static function render_saved_report_metrics( array $run ): void {
		$diff = self::report_array_value( $run, 'diff' );
		?>
		<div class="cm-report-metrics">
			<?php
			self::metric( __( 'Resources reviewed', 'cybermaps' ), (int) $run['resource_count'], 'neutral' );
			self::metric( __( 'Current findings', 'cybermaps' ), (int) $run['finding_count'], 'warning' );
			if ( (int) self::report_value( $diff, 'baseline_run_id', 0 ) > 0 ) {
				self::metric( __( 'Added', 'cybermaps' ), (int) self::report_value( $diff, 'added_count', 0 ), 'error' );
				self::metric( __( 'Resolved', 'cybermaps' ), (int) self::report_value( $diff, 'resolved_count', 0 ), 'good' );
				self::metric( __( 'Persisting', 'cybermaps' ), (int) self::report_value( $diff, 'persisting_count', 0 ), 'neutral' );
			} else {
				self::metric( __( 'Comparison', 'cybermaps' ), __( 'Baseline', 'cybermaps' ), 'good' );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed>               $run     Selected report.
	 * @param array<string,array<string,mixed>> $catalog Finding catalog.
	 * @param array<string,int>                 $counts  Finding counts.
	 */
	private static function render_action_reports( array $run, array $catalog, array $counts ): void {
		?>
		<section class="cm-report-section">
			<div class="cm-report-section-heading">
				<div>
					<span class="cm-report-kicker"><?php esc_html_e( 'Focused deliverables', 'cybermaps' ); ?></span>
					<h3><?php esc_html_e( 'Action reports', 'cybermaps' ); ?></h3>
				</div>
				<p><?php esc_html_e( 'Export a client-ready list for one review category from the saved report data.', 'cybermaps' ); ?></p>
			</div>
			<div class="cm-action-items-grid">
				<?php foreach ( $catalog as $key => $meta ) : ?>
					<article class="cm-issue-card cm-report-issue-card">
						<div class="cm-issue-header">
							<div class="cm-issue-icon"><span class="dashicons <?php echo esc_attr( self::finding_icon( $key ) ); ?>"></span></div>
							<span class="cm-priority-badge <?php echo 'thin_content' === $key ? 'high' : 'medium'; ?>"><?php esc_html_e( 'Review', 'cybermaps' ); ?></span>
						</div>
						<div class="cm-issue-body">
							<div class="cm-issue-count"><?php echo esc_html( (string) $counts[ $key ] ); ?></div>
							<div class="cm-issue-label"><?php echo esc_html( $meta['label'] ); ?></div>
							<p class="cm-issue-desc"><?php echo esc_html( $meta['description'] ); ?></p>
						</div>
						<div class="cm-issue-footer">
							<a class="button button-secondary" href="<?php echo esc_url( self::export_url( (int) $run['id'], 'html', $key ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'HTML report', 'cybermaps' ); ?></a>
							<a class="button button-secondary" href="<?php echo esc_url( self::export_url( (int) $run['id'], 'csv', $key ) ); ?>"><?php esc_html_e( 'CSV', 'cybermaps' ); ?></a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<string,mixed>               $run     Selected report.
	 * @param array<string,array<string,mixed>> $catalog Finding catalog.
	 */
	private static function render_findings_preview( array $run, array $catalog ): void {
		$findings = self::report_array_value( $run, 'findings' );
		?>
		<section class="cm-report-section">
			<div class="cm-report-section-heading">
				<div>
					<span class="cm-report-kicker"><?php esc_html_e( 'Report preview', 'cybermaps' ); ?></span>
					<h3><?php esc_html_e( 'Current findings', 'cybermaps' ); ?></h3>
				</div>
				<p><?php esc_html_e( 'The printable report includes recommendations and the measurement summary for every finding.', 'cybermaps' ); ?></p>
			</div>
			<table class="widefat cm-report-findings-table">
				<thead><tr><th><?php esc_html_e( 'Resource', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Finding', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Measurement', 'cybermaps' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $findings, 0, 25 ) as $finding ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) $finding['url'] ); ?>"><?php echo esc_html( (string) $finding['title'] ); ?></a></td>
						<td><span class="cm-report-finding-badge"><?php echo esc_html( self::finding_label( $finding, $catalog ) ); ?></span></td>
						<td><?php echo esc_html( (string) $finding['summary'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $findings ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'This run recorded no policy findings.', 'cybermaps' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<?php if ( (int) $run['finding_count'] > count( $findings ) ) : ?>
				<p class="description"><?php esc_html_e( 'Showing the first 25 findings. Export the report for the complete inventory.', 'cybermaps' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<string,mixed>               $finding Finding row.
	 * @param array<string,array<string,mixed>> $catalog Finding catalog.
	 */
	private static function finding_label( array $finding, array $catalog ): string {
		$key = (string) self::report_value( $finding, 'finding_key' );
		return isset( $catalog[ $key ]['label'] ) ? (string) $catalog[ $key ]['label'] : $key;
	}

	/** @param array<int,array<string,mixed>> $history Completed report history. */
	private static function render_report_history( array $history, int $current_run_id ): void {
		if ( count( $history ) <= 1 ) {
			return;
		}
		?>
		<section class="cm-report-section">
			<div class="cm-report-section-heading">
				<div>
					<span class="cm-report-kicker"><?php esc_html_e( 'Report history', 'cybermaps' ); ?></span>
					<h3><?php esc_html_e( 'Other saved reports', 'cybermaps' ); ?></h3>
				</div>
				<p><?php esc_html_e( 'Open or export another saved report.', 'cybermaps' ); ?></p>
			</div>
			<table class="widefat cm-report-history-table">
				<thead><tr><th><?php esc_html_e( 'Run', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Completed', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Resources', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Findings', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Exports', 'cybermaps' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $history as $historical_run ) : ?>
					<?php $historical_id = (int) self::report_value( $historical_run, 'id', 0 ); ?>
					<?php if ( $historical_id === $current_run_id ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<tr>
						<td><a href="<?php echo esc_url( self::report_url( $historical_id ) ); ?>">#<?php echo esc_html( (string) $historical_id ); ?></a></td>
						<td><?php echo esc_html( (string) self::report_value( $historical_run, 'completed_gmt' ) ); ?> GMT</td>
						<td><?php echo esc_html( (string) self::report_value( $historical_run, 'resource_count', 0 ) ); ?></td>
						<td><?php echo esc_html( (string) self::report_value( $historical_run, 'finding_count', 0 ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( self::export_url( $historical_id, 'html' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'HTML', 'cybermaps' ); ?></a>
							<span aria-hidden="true"> · </span>
							<a href="<?php echo esc_url( self::export_url( $historical_id, 'csv' ) ); ?>"><?php esc_html_e( 'CSV', 'cybermaps' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private static function render_empty_report_state(): void {
		?>
		<div class="cm-report-empty">
			<span class="dashicons dashicons-media-document"></span>
			<div>
				<h3><?php esc_html_e( 'No content report exists yet', 'cybermaps' ); ?></h3>
				<p><?php esc_html_e( 'Run the first report to establish a baseline. Later reports show exactly which findings were added, resolved, or persisted.', 'cybermaps' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function render_settings_panels( string $settings_page ): void {
		?>
		<div class="cm-report-settings-grid">
			<section class="cybermaps-panel-card cm-report-settings-card">
				<h3><span class="dashicons dashicons-filter"></span> <?php esc_html_e( 'Content measurement policy', 'cybermaps' ); ?></h3>
				<p class="description"><?php esc_html_e( 'These explicit rules are saved with every report.', 'cybermaps' ); ?></p>
				<table class="form-table">
					<?php do_settings_fields( $settings_page, 'cybermaps_content_review_policy' ); ?>
				</table>
			</section>
			<section class="cybermaps-panel-card cm-report-settings-card">
				<h3><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Client presentation', 'cybermaps' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Brand and theme printable deliverables generated from the saved measurements.', 'cybermaps' ); ?></p>
				<table class="form-table">
					<?php do_settings_fields( $settings_page, 'cybermaps_content_review_presentation' ); ?>
				</table>
			</section>
		</div>
		<?php
	}

	/** @param array<string,mixed> $row Report row. */
	private static function report_value( array $row, string $key, mixed $fallback = '' ): mixed {
		return isset( $row[ $key ] ) ? $row[ $key ] : $fallback;
	}

	/**
	 * @param array<string,mixed> $row Report row.
	 * @return array<int|string,mixed>
	 */
	private static function report_array_value( array $row, string $key ): array {
		$value = self::report_value( $row, $key, array() );
		return is_array( $value ) ? $value : array();
	}

	private static function export_url( int $run_id, string $format, string $finding = '' ): string {
		$args = array(
			'action' => 'cybermaps_export_content_audit',
			'run_id' => $run_id,
			'format' => $format,
		);
		if ( '' !== $finding ) {
			$args['finding'] = $finding;
		}
		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			'cybermaps_content_audit'
		);
	}

	private static function report_url( int $run_id ): string {
		return add_query_arg(
			array(
				'page'             => 'cybermaps-settings',
				'tab'              => 'review',
				'cybermaps_run_id' => $run_id,
			),
			admin_url( 'admin.php' )
		);
	}

	private static function metric( string $label, int|string $value, string $tone ): void {
		?>
		<div class="cm-report-metric cm-report-metric--<?php echo esc_attr( $tone ); ?>">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( (string) $value ); ?></strong>
		</div>
		<?php
	}

	private static function finding_icon( string $finding_key ): string {
		return match ( $finding_key ) {
			'thin_content' => 'dashicons-editor-paragraph',
			'stale_content' => 'dashicons-calendar-alt',
			default => 'dashicons-format-image',
		};
	}
}
