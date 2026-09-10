<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\CrawlerRegistry;
use Cybermaps\Discovery\StaticBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified Discovery Analytics and PHP-observed request log.
 */
final class DiscoveryAnalytics {

	private string $page_hook                       = '';
	private ?CrawlerAnalyticsRepository $repository = null;

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	public function enqueue_scripts( string $hook ): void {
		if ( '' === $this->page_hook || $this->page_hook !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cybermaps-command-center',
			CYBERMAPS_PLUGIN_URL . 'assets/css/admin-command-center.css',
			array(),
			CYBERMAPS_VERSION
		);
		wp_style_add_data( 'cybermaps-command-center', 'rtl', true );
		wp_enqueue_script(
			'cybermaps-discovery-analytics',
			CYBERMAPS_PLUGIN_URL . 'assets/js/discovery-analytics.js',
			array(),
			CYBERMAPS_VERSION,
			true
		);
	}

	public function add_page(): void {
		$page_hook       = add_submenu_page(
			'cybermaps-settings',
			__( 'Discovery Analytics', 'cybermaps' ),
			__( 'Discovery Analytics', 'cybermaps' ),
			'manage_options',
			'cybermaps-discovery-analytics',
			array( $this, 'render_page' )
		);
		$this->page_hook = is_string( $page_hook ) ? $page_hook : '';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'cybermaps' ) );
		}

		$context           = $this->page_context();
		$analytics_enabled = $context['analytics_enabled'];
		$anonymize_ips     = $context['anonymize_ips'];
		$retention_days    = $context['retention_days'];
		$static_mode       = $context['static_mode'];
		$overview          = $context['overview'];
		$activity          = $context['activity'];
		$db_error          = $context['db_error'];
		?>
		<div class="wrap cybermaps-wrap cm-analytics-page">
			<?php self::render_page_header( $analytics_enabled, $static_mode, $retention_days ); ?>

			<nav class="cm-analytics-nav" aria-label="<?php esc_attr_e( 'Analytics sections', 'cybermaps' ); ?>">
				<a href="#overview"><?php esc_html_e( 'Overview', 'cybermaps' ); ?></a>
				<a href="#endpoint-activity"><?php esc_html_e( 'Endpoints', 'cybermaps' ); ?></a>
				<a href="#unidentified-patterns"><?php esc_html_e( 'Unidentified', 'cybermaps' ); ?></a>
				<a href="#content-activity"><?php esc_html_e( 'Content visits', 'cybermaps' ); ?></a>
				<a href="#request-log"><?php esc_html_e( 'Request log', 'cybermaps' ); ?></a>
				<a href="#data-controls"><?php esc_html_e( 'Data controls', 'cybermaps' ); ?></a>
			</nav>

			<div class="cm-coverage-note cm-mb-20">
				<strong><?php esc_html_e( 'Coverage boundary:', 'cybermaps' ); ?></strong>
				<?php esc_html_e( 'Every number below is a request that executed WordPress/PHP. Physical files, web-server rules, CDNs, and full-page caches can serve additional requests that Cybermaps cannot observe. Crawler names come from self-reported User-Agent signatures and are not verified identities.', 'cybermaps' ); ?>
			</div>

			<?php self::render_admin_notices( $db_error ); ?>
			<?php self::render_recording_notice( $analytics_enabled ); ?>

			<section id="overview" class="cm-analytics-section">
				<div class="cm-analytics-section-heading">
					<div>
						<p class="cm-page-eyebrow"><?php esc_html_e( 'Rolling evidence window', 'cybermaps' ); ?></p>
						<h2><?php esc_html_e( '30-day overview', 'cybermaps' ); ?></h2>
					</div>
					<p><?php esc_html_e( 'Counts are limited by the configured retention period when it is shorter than 30 days.', 'cybermaps' ); ?></p>
				</div>
				<?php $this->render_metric_cards( $overview['summary'] ); ?>
			</section>

			<section id="endpoint-activity" class="cm-analytics-section">
				<div class="cm-analytics-section-heading">
					<div>
						<p class="cm-page-eyebrow"><?php esc_html_e( 'Discovery publications', 'cybermaps' ); ?></p>
						<h2><?php esc_html_e( 'Endpoint activity', 'cybermaps' ); ?></h2>
					</div>
					<p><?php esc_html_e( 'Aliases are consolidated by endpoint ID. Internal, browser, automated, crawler-candidate, and unknown requests appear only when they reach PHP.', 'cybermaps' ); ?></p>
				</div>
				<?php $this->render_endpoint_table( $overview['endpoints'] ); ?>
			</section>

			<section id="unidentified-patterns" class="cm-analytics-section">
				<div class="cm-analytics-section-heading">
					<div>
						<p class="cm-page-eyebrow"><?php esc_html_e( 'Unmatched identity evidence', 'cybermaps' ); ?></p>
						<h2><?php esc_html_e( 'Unidentified request patterns', 'cybermaps' ); ?></h2>
					</div>
					<p><?php esc_html_e( 'Patterns group similar stored observations by the locally available evidence. They can reveal repeat clients without claiming to identify their operator.', 'cybermaps' ); ?></p>
				</div>
				<?php $this->render_unknown_client_patterns( self::overview_rows( $overview, 'unknown_clients' ) ); ?>
			</section>

			<section id="content-activity" class="cm-analytics-section">
				<div class="cm-analytics-section-heading">
					<div>
						<p class="cm-page-eyebrow"><?php esc_html_e( 'UA signature claims', 'cybermaps' ); ?></p>
						<h2><?php esc_html_e( 'Crawler content activity', 'cybermaps' ); ?></h2>
					</div>
					<p><?php esc_html_e( 'Ordinary content requests are retained for registered User-Agent matches and conservative crawler candidates. The aggregates below report UA-matched crawlers.', 'cybermaps' ); ?></p>
				</div>
				<?php $this->render_content_activity( $overview ); ?>
			</section>

			<section class="cm-analytics-section">
				<div class="cm-analytics-section-heading">
					<div>
						<p class="cm-page-eyebrow"><?php esc_html_e( 'Request surface', 'cybermaps' ); ?></p>
						<h2><?php esc_html_e( '30-day activity trend', 'cybermaps' ); ?></h2>
					</div>
				</div>
				<?php $this->render_trend( $overview['trend'], $overview['summary'] ); ?>
			</section>

			<section id="request-log" class="cm-analytics-section">
				<?php $this->render_request_log( $activity ); ?>
			</section>

			<section id="data-controls" class="cm-analytics-section">
				<?php $this->render_data_controls( $analytics_enabled, $anonymize_ips, $retention_days ); ?>
			</section>
		</div>
		<?php
	}

	/** @return array<string, mixed> */
	private function page_context(): array {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		return array(
			'analytics_enabled' => ! empty( $settings['enable_analytics'] ),
			'anonymize_ips'     => ! isset( $settings['anonymize_analytics_ips'] ) || ! empty( $settings['anonymize_analytics_ips'] ),
			'retention_days'    => max( 1, min( 365, (int) ( $settings['log_retention_days'] ?? 30 ) ) ),
			'static_mode'       => StaticBridge::get_mode( $settings ),
			'overview'          => $this->get_repository()->get_overview(),
			'activity'          => $this->get_repository()->get_recent_activity(),
			'db_error'          => CrawlerAnalyticsRecorder::get_health_error(),
		);
	}

	private static function render_page_header( bool $enabled, string $static_mode, int $retention_days ): void {
		PageHeader::render(
			__( 'Discovery Analytics', 'cybermaps' ),
			__( 'Review discovery endpoint observations, claimed crawler visits, and unidentified request patterns that reached WordPress/PHP.', 'cybermaps' ),
			array(
				array(
					'label' => $enabled ? __( 'Recording enabled', 'cybermaps' ) : __( 'Recording disabled', 'cybermaps' ),
					'tone'  => $enabled ? 'good' : 'warning',
				),
				array(
					'label' => __( 'Coverage: PHP only', 'cybermaps' ),
					'tone'  => 'info',
				),
				array(
					'label' => sprintf(
						/* translators: %s: Static File Engine mode. */
						__( 'Static mode: %s', 'cybermaps' ),
						self::static_mode_label( $static_mode )
					),
					'tone'  => 'neutral',
				),
				array(
					'label' => sprintf(
						/* translators: %d: analytics retention days. */
						_n( 'Retention: %d day', 'Retention: %d days', $retention_days, 'cybermaps' ),
						$retention_days
					),
					'tone'  => 'neutral',
				),
			),
			array(
				array(
					'label' => __( 'Open publication status', 'cybermaps' ),
					'url'   => admin_url( 'admin.php?page=cybermaps-ai-discovery-status' ),
				),
			)
		);
	}

	private static function render_admin_notices( string $db_error ): void {
		if ( '' !== $db_error ) {
			?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $db_error ); ?></p></div>
			<?php
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result flags from nonce-protected admin actions.
		if ( isset( $_GET['cybermaps_logs_cleared'] ) ) {
			?>
			<div class="notice notice-success inline is-dismissible"><p><?php esc_html_e( 'Analytics history was cleared.', 'cybermaps' ); ?></p></div>
			<?php
		} elseif ( isset( $_GET['cybermaps_logs_error'] ) ) {
			?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Analytics history could not be cleared. Check the database health message and site logs.', 'cybermaps' ); ?></p></div>
			<?php
		}
		// phpcs:enable
		settings_errors();
	}

	private static function render_recording_notice( bool $enabled ): void {
		if ( $enabled ) {
			return;
		}
		?>
		<div class="cm-status-notice-banner">
			<div class="cm-status-notice-icon"><span class="dashicons dashicons-visibility"></span></div>
			<div class="cm-status-notice-content"><div class="cm-status-notice-title"><?php esc_html_e( 'Recording is disabled', 'cybermaps' ); ?></div><div class="cm-status-notice-desc"><?php esc_html_e( 'Existing history remains available, but Cybermaps is not recording new endpoint observations or crawler activity.', 'cybermaps' ); ?></div></div>
			<div class="cm-status-notice-action"><a href="#data-controls" class="cm-status-action-link"><?php esc_html_e( 'Configure recording', 'cybermaps' ); ?></a></div>
		</div>
		<?php
	}

	/** @param array<string, mixed> $overview Analytics overview. @return array<int, array<string, mixed>> */
	private static function overview_rows( array $overview, string $key ): array {
		return is_array( $overview[ $key ] ?? null ) ? $overview[ $key ] : array();
	}

	/**
	 * @param array<string,int> $summary Summary counts.
	 */
	private function render_metric_cards( array $summary ): void {
		$cards = array(
			array(
				'value' => $summary['php_endpoint_requests'] ?? 0,
				'label' => __( 'PHP endpoint observations', 'cybermaps' ),
				'help'  => __( 'Registered endpoint requests retained by Cybermaps, including internal, browser, automated, and unknown requests. Diagnostic probes are excluded.', 'cybermaps' ),
				'tone'  => 'blue',
			),
			array(
				'value' => $summary['claimed_requests'] ?? 0,
				'label' => __( 'Claimed crawler requests', 'cybermaps' ),
				'help'  => __( 'Endpoint and content observations whose self-reported User-Agent matched an explicit registry signature.', 'cybermaps' ),
				'tone'  => 'green',
			),
			array(
				'value' => $summary['internal_requests'] ?? 0,
				'label' => __( 'Internal requests', 'cybermaps' ),
				'help'  => __( 'Requests made by a logged-in WordPress user. The request log records the numeric user ID.', 'cybermaps' ),
				'tone'  => 'amber',
			),
			array(
				'value' => $summary['unregistered_crawler_requests'] ?? 0,
				'label' => __( 'Crawler candidates', 'cybermaps' ),
				'help'  => __( 'Bot-like requests whose User-Agent is not in the bundled crawler registry.', 'cybermaps' ),
				'tone'  => 'purple',
			),
			array(
				'value' => $summary['unknown_requests'] ?? 0,
				'label' => __( 'Automated / unknown', 'cybermaps' ),
				'help'  => __( 'Automated clients, browsers, missing User-Agents, unknown clients, and unclassified legacy endpoint observations.', 'cybermaps' ),
				'tone'  => 'red',
			),
		);
		?>
		<div class="cm-analytics-metrics">
			<?php foreach ( $cards as $card ) : ?>
				<article class="cm-analytics-metric cm-analytics-metric--<?php echo esc_attr( (string) $card['tone'] ); ?>">
					<strong><?php echo esc_html( number_format_i18n( (int) $card['value'] ) ); ?></strong>
					<span><?php echo esc_html( (string) $card['label'] ); ?></span>
					<small><?php echo esc_html( (string) $card['help'] ); ?></small>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $endpoints Endpoint aggregates.
	 */
	private function render_endpoint_table( array $endpoints ): void {
		?>
		<div class="cm-card cm-analytics-table-card">
			<div class="cm-analytics-table-scroll">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Endpoint', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'PHP observations', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'UA signature matched', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'Claimed crawlers', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'Last observed', 'cybermaps' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $endpoints ) ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'No endpoint requests have reached WordPress/PHP in the reporting window.', 'cybermaps' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $endpoints as $endpoint ) : ?>
								<?php $row = self::endpoint_row_view_model( $endpoint ); ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $row['label'] ); ?></strong>
										<br><code><?php echo esc_html( $row['url'] ); ?></code>
									</td>
									<td><?php echo esc_html( number_format_i18n( $row['php_requests'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $row['recognized_requests'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $row['recognized_signatures'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $row['errors'] ) ); ?></td>
									<td><?php echo esc_html( self::format_site_time( $row['last_observed'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint aggregate. @return array<string, mixed> */
	private static function endpoint_row_view_model( array $endpoint ): array {
		$endpoint_id = (string) ( $endpoint['endpoint_id'] ?? '' );
		return array(
			'label'                 => '' !== $endpoint_id ? $endpoint_id : __( 'Unclassified endpoint', 'cybermaps' ),
			'url'                   => (string) ( $endpoint['url'] ?? '' ),
			'php_requests'          => (int) ( $endpoint['php_requests'] ?? 0 ),
			'recognized_requests'   => (int) ( $endpoint['recognized_requests'] ?? 0 ),
			'recognized_signatures' => (int) ( $endpoint['recognized_signatures'] ?? 0 ),
			'errors'                => (int) ( $endpoint['errors'] ?? 0 ),
			'last_observed'         => (string) ( $endpoint['last_observed'] ?? '' ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $patterns Unidentified client aggregates.
	 */
	private function render_unknown_client_patterns( array $patterns ): void {
		?>
		<div class="cm-card cm-analytics-table-card">
			<div class="cm-analytics-table-scroll">
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Client pattern', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'Stored evidence', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'Activity', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'First seen', 'cybermaps' ); ?></th>
							<th><?php esc_html_e( 'Last seen', 'cybermaps' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $patterns ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No unidentified request patterns are available in the reporting window.', 'cybermaps' ); ?></td></tr>
						<?php else : ?>
							<?php
							foreach ( $patterns as $pattern ) :
								$this->render_unknown_pattern_row( self::unknown_pattern_view_model( $pattern ) );
							endforeach;
							?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/** @param array<string, mixed> $pattern Unidentified aggregate. @return array<string, mixed> */
	private static function unknown_pattern_view_model( array $pattern ): array {
		return array_merge(
			self::unknown_identity_view_model( $pattern ),
			self::unknown_evidence_view_model( $pattern ),
			self::unknown_activity_view_model( $pattern ),
			self::unknown_time_view_model( $pattern )
		);
	}

	/** @param array<string, mixed> $pattern Unidentified aggregate. @return array<string, mixed> */
	private static function unknown_identity_view_model( array $pattern ): array {
		$status = self::identity_status( $pattern );
		$label  = trim( (string) ( $pattern['label'] ?? $pattern['bot'] ?? '' ) );
		return array(
			'identity_status' => $status,
			'display_label'   => '' !== $label ? $label : self::identity_status_label( $status ),
			'user_agent'      => trim( (string) ( $pattern['user_agent'] ?? '' ) ),
		);
	}

	/** @param array<string, mixed> $pattern Unidentified aggregate. @return array<string, string> */
	private static function unknown_evidence_view_model( array $pattern ): array {
		return array(
			'requester_key'  => trim( (string) ( $pattern['requester_key'] ?? '' ) ),
			'ip_address'     => trim( (string) ( $pattern['ip_address'] ?? '' ) ),
			'ip_source'      => trim( (string) ( $pattern['ip_source'] ?? '' ) ),
			'ip_storage'     => trim( (string) ( $pattern['ip_storage'] ?? '' ) ),
			'request_method' => trim( (string) ( $pattern['request_method'] ?? '' ) ),
			'accept_type'    => trim( (string) ( $pattern['accept_type'] ?? '' ) ),
		);
	}

	/** @param array<string, mixed> $pattern Unidentified aggregate. @return array<string, int> */
	private static function unknown_activity_view_model( array $pattern ): array {
		return array(
			'request_count'     => (int) ( $pattern['request_count'] ?? $pattern['requests'] ?? 0 ),
			'endpoint_requests' => (int) ( $pattern['endpoint_requests'] ?? 0 ),
			'endpoint_count'    => (int) ( $pattern['endpoint_count'] ?? $pattern['distinct_endpoints'] ?? 0 ),
		);
	}

	/** @param array<string, mixed> $pattern Unidentified aggregate. @return array<string, string> */
	private static function unknown_time_view_model( array $pattern ): array {
		return array(
			'first_seen' => (string) ( $pattern['first_seen'] ?? $pattern['first_observed'] ?? '' ),
			'last_seen'  => (string) ( $pattern['last_seen'] ?? $pattern['last_observed'] ?? '' ),
		);
	}

	/** @param array<string, mixed> $row Unidentified row view model. */
	private function render_unknown_pattern_row( array $row ): void {
		?>
		<tr>
			<?php self::render_unknown_client_cell( $row ); ?>
			<?php self::render_unknown_evidence_cell( $row ); ?>
			<?php self::render_unknown_activity_cell( $row ); ?>
			<td><?php echo esc_html( self::format_site_time( $row['first_seen'] ) ); ?></td>
			<td><?php echo esc_html( self::format_site_time( $row['last_seen'] ) ); ?></td>
		</tr>
		<?php
	}

	/** @param array<string, mixed> $row Unidentified row view model. */
	private static function render_unknown_client_cell( array $row ): void {
		?>
		<td>
			<strong><?php echo esc_html( $row['display_label'] ); ?></strong><br>
			<span class="cybermaps-badge cm-recognition-badge cm-recognition-badge--<?php echo esc_attr( self::identity_badge_tone( $row['identity_status'] ) ); ?>"><?php echo esc_html( self::identity_status_label( $row['identity_status'] ) ); ?></span><br>
			<?php
			if ( '' !== $row['user_agent'] ) :
				?>
				<code><?php echo esc_html( $row['user_agent'] ); ?></code>
				<?php
else :
	?>
				<small><?php esc_html_e( 'No stored User-Agent evidence', 'cybermaps' ); ?></small><?php endif; ?>
		</td>
		<?php
	}

	/** @param array<string, mixed> $row Unidentified row view model. */
	private static function render_unknown_evidence_cell( array $row ): void {
		?>
		<td>
			<?php
			if ( '' !== $row['requester_key'] ) :
				?>
				<code><?php echo esc_html( sprintf( /* translators: %s: site-specific pseudonymous requester key. */ __( 'Requester: %s', 'cybermaps' ), $row['requester_key'] ) ); ?></code><br><?php endif; ?>
			<?php
			if ( '' !== $row['ip_address'] ) :
				?>
				<code><?php echo esc_html( self::format_ip_evidence( $row['ip_address'], $row['ip_source'], $row['ip_storage'] ) ); ?></code><br><?php endif; ?>
			<?php
			if ( '' !== $row['request_method'] || '' !== $row['accept_type'] ) :
				?>
				<code><?php echo esc_html( self::format_transport_evidence( $row['request_method'], $row['accept_type'] ) ); ?></code><?php endif; ?>
			<?php
			if ( '' === $row['requester_key'] && '' === $row['ip_address'] && '' === $row['request_method'] && '' === $row['accept_type'] ) :
				?>
				<small><?php esc_html_e( 'Evidence unavailable for this legacy pattern', 'cybermaps' ); ?></small><?php endif; ?>
		</td>
		<?php
	}

	/** @param array<string, mixed> $row Unidentified row view model. */
	private static function render_unknown_activity_cell( array $row ): void {
		?>
		<td>
			<strong><?php printf( /* translators: %s: request count. */ esc_html( _n( '%s request', '%s requests', $row['request_count'], 'cybermaps' ) ), esc_html( number_format_i18n( $row['request_count'] ) ) ); ?></strong><br>
			<small><?php printf( /* translators: 1: endpoint request count, 2: distinct endpoint count. */ esc_html__( '%1$s endpoint observations · %2$s distinct endpoints', 'cybermaps' ), esc_html( number_format_i18n( $row['endpoint_requests'] ) ), esc_html( number_format_i18n( $row['endpoint_count'] ) ) ); ?></small>
		</td>
		<?php
	}

	/**
	 * @param array<string,mixed> $overview Complete analytics overview.
	 */
	private function render_content_activity( array $overview ): void {
		$content    = self::overview_rows( $overview, 'content' );
		$crawlers   = self::overview_rows( $overview, 'crawlers' );
		$categories = self::overview_rows( $overview, 'categories' );
		$labels     = CrawlerRegistry::get_categories();
		?>
		<div class="cm-analytics-grid">
			<?php self::render_content_paths( $content ); ?>
			<?php self::render_crawler_signatures( $crawlers, $labels ); ?>
		</div>
		<?php self::render_category_activity( $categories, $labels ); ?>
		<?php
	}

	/** @param array<int, array<string, mixed>> $content Content rows. */
	private static function render_content_paths( array $content ): void {
		?>
		<div class="cm-card cm-analytics-table-card">
			<h3><?php esc_html_e( 'Top content paths', 'cybermaps' ); ?></h3>
			<div class="cm-analytics-table-scroll"><table class="wp-list-table widefat striped">
				<thead><tr><th><?php esc_html_e( 'Path', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Visits', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Claimed crawlers', 'cybermaps' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $content ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No UA-matched crawler content visits in the reporting window.', 'cybermaps' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $content as $row ) :
						?>
						<tr><td><code><?php echo esc_html( (string) ( $row['url'] ?? '' ) ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) ( $row['requests'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) ( $row['recognized_signatures'] ?? 0 ) ) ); ?></td></tr><?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table></div>
		</div>
		<?php
	}

	/** @param array<int, array<string, mixed>> $crawlers Crawler rows. @param array<string, string> $labels Category labels. */
	private static function render_crawler_signatures( array $crawlers, array $labels ): void {
		?>
		<div class="cm-card cm-analytics-table-card">
			<h3><?php esc_html_e( 'Claimed crawler signatures', 'cybermaps' ); ?></h3>
			<div class="cm-analytics-table-scroll"><table class="wp-list-table widefat striped">
				<thead><tr><th><?php esc_html_e( 'Claimed crawler', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Endpoints', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Content', 'cybermaps' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $crawlers ) ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No claimed crawler signatures in the reporting window.', 'cybermaps' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $crawlers as $row ) :
						?>
						<?php $category = (string) ( $row['category'] ?? '' ); ?><tr><td><strong><?php echo esc_html( (string) ( $row['bot'] ?? '' ) ); ?></strong><br><span class="cybermaps-badge cm-badge-<?php echo esc_attr( $category ); ?>"><?php echo esc_html( (string) ( $labels[ $category ] ?? $category ) ); ?></span></td><td><?php echo esc_html( number_format_i18n( (int) ( $row['endpoint_requests'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) ( $row['page_requests'] ?? 0 ) ) ); ?></td></tr><?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table></div>
		</div>
		<?php
	}

	/** @param array<int, array<string, mixed>> $categories Category rows. @param array<string, string> $labels Category labels. */
	private static function render_category_activity( array $categories, array $labels ): void {
		?>
		<div class="cm-card cm-analytics-category-card">
			<h3><?php esc_html_e( 'Claimed activity by category', 'cybermaps' ); ?></h3>
			<div class="cm-analytics-category-list">
			<?php if ( empty( $categories ) ) : ?>
				<p><?php esc_html_e( 'No UA-matched crawler activity in the reporting window.', 'cybermaps' ); ?></p>
			<?php else : ?>
				<?php
				foreach ( $categories as $row ) :
					?>
					<?php $category = (string) ( $row['category'] ?? '' ); ?><div><span class="cybermaps-badge cm-badge-<?php echo esc_attr( $category ); ?>"><?php echo esc_html( (string) ( $labels[ $category ] ?? $category ) ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) ( $row['requests'] ?? 0 ) ) ); ?></strong><small><?php printf( /* translators: 1: endpoint requests, 2: content-page requests. */ esc_html__( 'Endpoint: %1$s · content: %2$s', 'cybermaps' ), esc_html( number_format_i18n( (int) ( $row['endpoint_requests'] ?? 0 ) ) ), esc_html( number_format_i18n( (int) ( $row['page_requests'] ?? 0 ) ) ) ); ?></small></div><?php endforeach; ?>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $trend   Daily rows.
	 * @param array<string,int>              $summary Summary totals.
	 */
	private function render_trend( array $trend, array $summary ): void {
		$has_activity = (int) ( $summary['php_endpoint_requests'] ?? 0 ) > 0
			|| (int) ( $summary['recognized_page_requests'] ?? 0 ) > 0;
		?>
		<div class="cm-card cm-analytics-table-card">
			<?php if ( ! $has_activity ) : ?>
				<p><?php esc_html_e( 'No PHP-observed activity in the reporting window.', 'cybermaps' ); ?></p>
			<?php else : ?>
				<div class="cm-analytics-table-scroll cm-analytics-trend-scroll">
					<table class="wp-list-table widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Day', 'cybermaps' ); ?></th>
								<th><?php esc_html_e( 'PHP endpoints', 'cybermaps' ); ?></th>
								<th><?php esc_html_e( 'UA-matched endpoints', 'cybermaps' ); ?></th>
								<th><?php esc_html_e( 'UA-matched content', 'cybermaps' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $trend as $row ) : ?>
								<tr>
									<td><?php echo esc_html( (string) ( $row['day'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) ( $row['php_endpoint_requests'] ?? 0 ) ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) ( $row['recognized_endpoint_requests'] ?? 0 ) ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) ( $row['recognized_page_requests'] ?? 0 ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $activity Recent observations.
	 */
	private function render_request_log( array $activity ): void {
		$labels = CrawlerRegistry::get_categories();
		?>
		<div class="cm-analytics-section-heading cm-analytics-log-heading">
			<div>
				<p class="cm-page-eyebrow"><?php esc_html_e( 'Retained evidence', 'cybermaps' ); ?></p>
				<h2><?php esc_html_e( 'Recent request log', 'cybermaps' ); ?></h2>
				<p><?php esc_html_e( 'The stream balances recent endpoint observations with retained claimed and crawler-candidate content visits.', 'cybermaps' ); ?></p>
			</div>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cybermaps_export_logs' ), 'cybermaps_export_logs' ) ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Export full retained history', 'cybermaps' ); ?>
			</a>
		</div>

		<div class="cm-analytics-filters">
			<div class="cm-filter-group" role="group" aria-label="<?php esc_attr_e( 'Filter by request type', 'cybermaps' ); ?>">
				<span><?php esc_html_e( 'Request type', 'cybermaps' ); ?></span>
				<button type="button" class="button is-active" data-cm-filter-kind="all" aria-pressed="true"><?php esc_html_e( 'All', 'cybermaps' ); ?></button>
				<button type="button" class="button" data-cm-filter-kind="endpoint" aria-pressed="false"><?php esc_html_e( 'Endpoints', 'cybermaps' ); ?></button>
				<button type="button" class="button" data-cm-filter-kind="page" aria-pressed="false"><?php esc_html_e( 'Content', 'cybermaps' ); ?></button>
			</div>
			<div class="cm-filter-group" role="group" aria-label="<?php esc_attr_e( 'Filter by identity evidence', 'cybermaps' ); ?>">
				<span><?php esc_html_e( 'Identity evidence', 'cybermaps' ); ?></span>
				<button type="button" class="button is-active" data-cm-filter-identity="all" aria-pressed="true"><?php esc_html_e( 'All', 'cybermaps' ); ?></button>
				<button type="button" class="button" data-cm-filter-identity="claimed" aria-pressed="false"><?php esc_html_e( 'Claimed', 'cybermaps' ); ?></button>
				<button type="button" class="button" data-cm-filter-identity="candidate" aria-pressed="false"><?php esc_html_e( 'Crawler candidates', 'cybermaps' ); ?></button>
				<button type="button" class="button" data-cm-filter-identity="internal" aria-pressed="false"><?php esc_html_e( 'Internal', 'cybermaps' ); ?></button>
				<button type="button" class="button" data-cm-filter-identity="unidentified" aria-pressed="false"><?php esc_html_e( 'Automated / unknown', 'cybermaps' ); ?></button>
				<button type="button" class="button" data-cm-filter-identity="legacy" aria-pressed="false"><?php esc_html_e( 'Legacy', 'cybermaps' ); ?></button>
			</div>
		</div>

		<div class="cm-card cm-request-log-card">
			<?php if ( empty( $activity ) ) : ?>
				<p class="cm-analytics-empty"><?php esc_html_e( 'No PHP-observed endpoint or retained crawler requests are stored yet.', 'cybermaps' ); ?></p>
				<?php else : ?>
					<div class="cm-activity-feed" id="cm-analytics-feed">
						<?php
						foreach ( $activity as $row ) :
							$this->render_request_row( self::request_row_view_model( $row ), $labels );
						endforeach;
						?>
				</div>
				<p id="cm-analytics-feed-empty" class="cm-analytics-empty" hidden><?php esc_html_e( 'No stored requests match the selected filters.', 'cybermaps' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string, mixed> $row Request evidence row. @return array<string, mixed> */
	private static function request_row_view_model( array $row ): array {
		return array_merge(
			self::request_transport_view_model( $row ),
			self::request_identity_view_model( $row )
		);
	}

	/** @param array<string, mixed> $row Request evidence row. @return array<string, mixed> */
	private static function request_transport_view_model( array $row ): array {
		$kind = 'endpoint' === (string) ( $row['request_kind'] ?? '' ) ? 'endpoint' : 'page';
		return array(
			'kind'           => $kind,
			'url'            => (string) ( $row['url'] ?? '' ),
			'endpoint_id'    => (string) ( $row['endpoint_id'] ?? '' ),
			'time'           => (string) ( $row['time'] ?? '' ),
			'status'         => (int) ( $row['response_status'] ?? 200 ),
			'request_method' => trim( (string) ( $row['request_method'] ?? '' ) ),
			'accept_type'    => trim( (string) ( $row['accept_type'] ?? '' ) ),
		);
	}

	/** @param array<string, mixed> $row Request evidence row. @return array<string, mixed> */
	private static function request_identity_view_model( array $row ): array {
		$status = self::identity_status( $row );
		return array(
			'category'        => (string) ( $row['category'] ?? '' ),
			'identity_status' => $status,
			'identity_group'  => self::identity_filter_group( $status ),
			'display_name'    => self::request_display_name( $row, $status ),
			'crawler_id'      => trim( (string) ( $row['crawler_id'] ?? '' ) ),
			'requester_key'   => trim( (string) ( $row['requester_key'] ?? '' ) ),
			'wp_user_id'      => (int) ( $row['wp_user_id'] ?? 0 ),
			'ip_address'      => trim( (string) ( $row['ip_address'] ?? '' ) ),
			'ip_source'       => trim( (string) ( $row['ip_source'] ?? '' ) ),
			'ip_storage'      => trim( (string) ( $row['ip_storage'] ?? '' ) ),
		);
	}

	/** @param array<string, mixed> $row Request row view model. @param array<string, string> $labels Category labels. */
	private function render_request_row( array $row, array $labels ): void {
		?>
		<article class="cm-feed-item" data-cm-request-kind="<?php echo esc_attr( $row['kind'] ); ?>" data-cm-identity-group="<?php echo esc_attr( $row['identity_group'] ); ?>" data-cm-identity-status="<?php echo esc_attr( $row['identity_status'] ); ?>">
			<div class="cm-feed-marker"></div>
			<div class="cm-feed-content">
				<div class="cm-feed-header"><strong><?php echo esc_html( $row['display_name'] ); ?></strong><time class="cm-feed-time"><?php echo esc_html( self::format_site_time( $row['time'] ) ); ?></time></div>
				<?php self::render_request_badges( $row, $labels ); ?>
				<?php self::render_request_target( $row ); ?>
				<?php self::render_request_status( $row ); ?>
				<?php self::render_request_identity_evidence( $row ); ?>
				<?php self::render_requester_evidence( $row['requester_key'] ); ?>
				<?php self::render_request_ip_evidence( $row ); ?>
			</div>
		</article>
		<?php
	}

	/** @param array<string, mixed> $row Request row view model. @param array<string, string> $labels Category labels. */
	private static function render_request_badges( array $row, array $labels ): void {
		$kind_label = 'endpoint' === $row['kind'] ? esc_html__( 'endpoint', 'cybermaps' ) : esc_html__( 'content', 'cybermaps' );
		?>
		<div class="cm-feed-badges">
			<span class="cybermaps-badge cm-badge-<?php echo esc_attr( $row['category'] ); ?>"><?php echo esc_html( self::category_label( $row['category'], $labels ) ); ?></span>
			<span class="cybermaps-badge cm-request-kind-badge cm-request-kind-badge--<?php echo esc_attr( $row['kind'] ); ?>"><?php echo $kind_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- translated and escaped above. ?></span>
			<span class="cybermaps-badge cm-recognition-badge cm-recognition-badge--<?php echo esc_attr( self::identity_badge_tone( $row['identity_status'] ) ); ?>"><?php echo esc_html( self::identity_status_label( $row['identity_status'] ) ); ?></span>
		</div>
		<?php
	}

	/** @param array<string, mixed> $row Request row view model. */
	private static function render_request_target( array $row ): void {
		?>
		<div class="cm-feed-meta"><code><?php echo esc_html( $row['url'] ); ?></code>
		<?php
		if ( 'endpoint' === $row['kind'] && '' !== $row['endpoint_id'] ) :
			?>
			<span aria-hidden="true">·</span><code><?php echo esc_html( $row['endpoint_id'] ); ?></code><?php endif; ?></div>
		<?php
	}

	/** @param array<string, mixed> $row Request row view model. */
	private static function render_request_status( array $row ): void {
		?>
		<div class="cm-feed-meta">
			<code><?php echo esc_html( sprintf( /* translators: %d: HTTP response status code. */ __( 'HTTP %d · PHP', 'cybermaps' ), $row['status'] ) ); ?></code>
			<?php
			if ( '' !== $row['request_method'] || '' !== $row['accept_type'] ) :
				?>
				<code><?php echo esc_html( self::format_transport_evidence( $row['request_method'], $row['accept_type'] ) ); ?></code><?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string, mixed> $row Request row view model. */
	private static function render_request_identity_evidence( array $row ): void {
		if ( '' === $row['crawler_id'] && $row['wp_user_id'] < 1 ) {
			return;
		}
		?>
		<div class="cm-feed-meta">
			<?php
			if ( '' !== $row['crawler_id'] ) :
				?>
				<code><?php echo esc_html( sprintf( /* translators: %s: stable crawler registry identifier. */ __( 'Crawler ID: %s', 'cybermaps' ), $row['crawler_id'] ) ); ?></code><?php endif; ?>
			<?php
			if ( $row['wp_user_id'] > 0 ) :
				?>
				<code><?php echo esc_html( sprintf( /* translators: %d: numeric WordPress user ID. */ __( 'WP user: #%d', 'cybermaps' ), $row['wp_user_id'] ) ); ?></code><?php endif; ?>
		</div>
		<?php
	}

	private static function render_requester_evidence( string $requester_key ): void {
		if ( '' === $requester_key ) {
			return;
		}
		?>
		<div class="cm-feed-meta"><code><?php echo esc_html( sprintf( /* translators: %s: site-specific pseudonymous requester key. */ __( 'Requester: %s', 'cybermaps' ), $requester_key ) ); ?></code></div>
		<?php
	}

	/** @param array<string, mixed> $row Request row view model. */
	private static function render_request_ip_evidence( array $row ): void {
		if ( '' === $row['ip_address'] ) {
			return;
		}
		?>
		<div class="cm-feed-meta"><code><?php echo esc_html( self::format_ip_evidence( $row['ip_address'], $row['ip_source'], $row['ip_storage'] ) ); ?></code></div>
		<?php
	}

	private function render_data_controls( bool $enabled, bool $anonymize_ips, int $retention_days ): void {
		?>
		<div class="cm-analytics-section-heading">
			<div>
				<p class="cm-page-eyebrow"><?php esc_html_e( 'Site-local data', 'cybermaps' ); ?></p>
				<h2><?php esc_html_e( 'Recording and retention', 'cybermaps' ); ?></h2>
			</div>
			<p><?php esc_html_e( 'Analytics records remain in this WordPress database and are not sent to the Cybermaps developer.', 'cybermaps' ); ?></p>
		</div>

		<div class="cm-analytics-controls-grid">
			<form method="post" action="options.php" class="cm-card cm-analytics-settings-form">
				<?php settings_fields( \Cybermaps\Admin\Settings\SettingsRegistrar::ANALYTICS_OPTIONS_GROUP ); ?>
				<input type="hidden" name="cybermaps_active_tab" value="analytics">

				<label class="cm-toggle-wrapper" for="enable_analytics">
					<input
						type="checkbox"
						id="enable_analytics"
						name="cybermaps_settings[enable_analytics]"
						value="1"
						class="cm-toggle-input"
						<?php checked( $enabled ); ?>
					>
					<span class="cm-toggle-switch"></span>
					<span class="cm-toggle-label"><?php esc_html_e( 'Record PHP-observed crawler analytics', 'cybermaps' ); ?></span>
				</label>
				<p class="description"><?php esc_html_e( 'Records registered endpoint requests that reach PHP, plus UA-matched and conservative crawler-candidate visits to ordinary content. Cybermaps diagnostic probes are excluded. Disabling recording does not delete existing history.', 'cybermaps' ); ?></p>

				<label class="cm-toggle-wrapper" for="anonymize_analytics_ips">
					<input
						type="checkbox"
						id="anonymize_analytics_ips"
						name="cybermaps_settings[anonymize_analytics_ips]"
						value="1"
						class="cm-toggle-input"
						<?php checked( $anonymize_ips ); ?>
					>
					<span class="cm-toggle-switch"></span>
					<span class="cm-toggle-label"><?php esc_html_e( 'Anonymize stored IP addresses', 'cybermaps' ); ?></span>
				</label>
				<p class="description"><?php esc_html_e( 'Enabled by default. IPv4 addresses are reduced to their /24 network and IPv6 addresses to /64. Turn this off to store full resolved IP addresses locally. This choice affects only future records; existing history is not changed.', 'cybermaps' ); ?></p>

				<label for="log_retention_days"><strong><?php esc_html_e( 'Retention period', 'cybermaps' ); ?></strong></label>
				<div class="cm-retention-control">
					<input
						type="number"
						id="log_retention_days"
						name="cybermaps_settings[log_retention_days]"
						value="<?php echo esc_attr( (string) $retention_days ); ?>"
						min="1"
						max="365"
					>
					<span><?php esc_html_e( 'days', 'cybermaps' ); ?></span>
				</div>
				<p class="description"><?php esc_html_e( 'The daily cleanup job removes older rows. Reports can show no more history than this setting retains.', 'cybermaps' ); ?></p>

				<?php submit_button( __( 'Save analytics settings', 'cybermaps' ), 'primary', 'submit', false ); ?>
			</form>

			<div class="cm-card cm-analytics-danger-zone">
				<h3><?php esc_html_e( 'Stored history', 'cybermaps' ); ?></h3>
				<p><?php esc_html_e( 'Export the complete retained dataset before clearing it if you need an offline record.', 'cybermaps' ); ?></p>
				<div class="cm-analytics-control-actions">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cybermaps_export_logs' ), 'cybermaps_export_logs' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Export CSV', 'cybermaps' ); ?>
					</a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cm-confirm-form="<?php esc_attr_e( 'Clear all retained Cybermaps analytics history? This cannot be undone.', 'cybermaps' ); ?>">
						<input type="hidden" name="action" value="cybermaps_clear_logs">
						<?php wp_nonce_field( 'cybermaps_clear_logs', 'cybermaps_clear_logs_nonce' ); ?>
						<button type="submit" class="button cm-btn-destructive"><?php esc_html_e( 'Clear history', 'cybermaps' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Normalize identity evidence from current and legacy analytics rows.
	 *
	 * @param array<string,mixed> $row Stored request or aggregate row.
	 */
	private static function identity_status( array $row ): string {
		$status   = (string) ( $row['identity_status'] ?? '' );
		$category = (string) ( $row['category'] ?? '' );

		if ( 'authenticated' === $category ) {
			return 'legacy';
		}

		return in_array(
			$status,
			array(
				'claimed',
				'internal',
				'unregistered-bot',
				'automated-client',
				'browser',
				'no-user-agent',
				'unknown',
				'legacy',
			),
			true
		) ? $status : 'legacy';
	}

	private static function identity_status_label( string $status ): string {
		return match ( $status ) {
			'claimed'          => __( 'Claimed (UA signature matched)', 'cybermaps' ),
			'internal'         => __( 'Internal', 'cybermaps' ),
			'unregistered-bot' => __( 'Crawler candidate', 'cybermaps' ),
			'automated-client' => __( 'Automated client', 'cybermaps' ),
			'browser'          => __( 'Browser / manual', 'cybermaps' ),
			'no-user-agent'    => __( 'No User-Agent', 'cybermaps' ),
			'unknown'          => __( 'Unknown client', 'cybermaps' ),
			default            => __( 'Legacy record', 'cybermaps' ),
		};
	}

	private static function identity_filter_group( string $status ): string {
		return match ( $status ) {
			'claimed'          => 'claimed',
			'internal'         => 'internal',
			'unregistered-bot' => 'candidate',
			'legacy'           => 'legacy',
			default            => 'unidentified',
		};
	}

	private static function identity_badge_tone( string $status ): string {
		return 'claimed' === $status ? 'recognized' : 'unrecognized';
	}

	/**
	 * @param array<string,mixed> $row Stored request row.
	 */
	private static function request_display_name( array $row, string $identity_status ): string {
		$name     = trim( (string) ( $row['bot'] ?? '' ) );
		$category = (string) ( $row['category'] ?? '' );

		if ( 'internal' === $identity_status ) {
			return '' !== $name ? $name : __( 'Logged-in site user', 'cybermaps' );
		}

		if (
			'legacy' === $identity_status
			&& (
				'authenticated' === $category
				|| 'authenticated request' === strtolower( $name )
			)
		) {
			return __( 'Legacy internal request', 'cybermaps' );
		}

		return '' !== $name ? $name : self::identity_status_label( $identity_status );
	}

	/**
	 * @param array<string,string> $registry_labels Crawler category labels.
	 */
	private static function category_label( string $category, array $registry_labels ): string {
		if ( isset( $registry_labels[ $category ] ) ) {
			return (string) $registry_labels[ $category ];
		}

		return match ( $category ) {
			'internal'         => __( 'Internal', 'cybermaps' ),
			'unregistered-bot' => __( 'Crawler candidate', 'cybermaps' ),
			'unrecognized'     => __( 'Unidentified client', 'cybermaps' ),
			'authenticated'    => __( 'Legacy internal', 'cybermaps' ),
			''                 => __( 'Unclassified', 'cybermaps' ),
			default            => ucwords( str_replace( '-', ' ', $category ) ),
		};
	}

	private static function format_transport_evidence( string $request_method, string $accept_type ): string {
		$evidence = array();

		if ( '' !== $request_method ) {
			$evidence[] = $request_method;
		}
		if ( '' !== $accept_type ) {
			$evidence[] = sprintf(
				/* translators: %s: coarse accepted-media category such as json or html. */
				__( 'Accept: %s', 'cybermaps' ),
				$accept_type
			);
		}

		return implode( ' · ', $evidence );
	}

	private static function format_ip_evidence( string $ip_address, string $ip_source, string $ip_storage ): string {
		$address_label = match ( $ip_storage ) {
			'anonymized' => __( 'Network', 'cybermaps' ),
			'full'       => __( 'IP', 'cybermaps' ),
			'legacy'     => __( 'Stored address', 'cybermaps' ),
			default      => __( 'Address', 'cybermaps' ),
		};

		return sprintf(
			/* translators: 1: address type, 2: stored address, 3: IP resolution source, 4: storage mode. */
			__( '%1$s: %2$s · Source: %3$s · Storage: %4$s', 'cybermaps' ),
			$address_label,
			$ip_address,
			self::evidence_slug_label( $ip_source ),
			self::evidence_slug_label( $ip_storage )
		);
	}

	private static function evidence_slug_label( string $value ): string {
		if ( '' === $value || 'none' === $value ) {
			return __( 'None', 'cybermaps' );
		}

		return match ( $value ) {
			'cloudflare'    => 'Cloudflare',
			'direct'        => __( 'Direct', 'cybermaps' ),
			'trusted-proxy' => __( 'Trusted proxy', 'cybermaps' ),
			'anonymized'    => __( 'Anonymized', 'cybermaps' ),
			'full'          => __( 'Full', 'cybermaps' ),
			'legacy'        => __( 'Legacy', 'cybermaps' ),
			default         => ucwords( str_replace( array( '-', '_' ), ' ', $value ) ),
		};
	}

	private function get_repository(): CrawlerAnalyticsRepository {
		if ( null === $this->repository ) {
			$this->repository = new CrawlerAnalyticsRepository();
		}

		return $this->repository;
	}

	private static function format_site_time( string $mysql_time ): string {
		if ( '' === $mysql_time ) {
			return '—';
		}

		$format = (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' );
		return mysql2date( $format, $mysql_time, false );
	}

	private static function static_mode_label( string $mode ): string {
		return match ( $mode ) {
			'all'        => __( 'Full publication cache', 'cybermaps' ),
			'well_known' => __( 'Compatibility files', 'cybermaps' ),
			default      => __( 'Dynamic only', 'cybermaps' ),
		};
	}
}
