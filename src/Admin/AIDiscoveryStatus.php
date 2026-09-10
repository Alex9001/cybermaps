<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Discovery Status Page.
 *
 * Displays intended publication state separately from observed public HTTP
 * delivery. An on-disk file alone is never treated as proof of endpoint health.
 */
class AIDiscoveryStatus {

	/**
	 * Hook suffix returned by WordPress for this submenu page.
	 */
	private string $page_hook = '';

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue assets for the AI Discovery Status page.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( '' === $this->page_hook || $this->page_hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cybermaps-command-center', CYBERMAPS_PLUGIN_URL . 'assets/css/admin-command-center.css', array(), CYBERMAPS_VERSION );
		wp_style_add_data( 'cybermaps-command-center', 'rtl', true );
	}

	/**
	 * Add the AI Discovery Status admin submenu page.
	 */
	public function add_page(): void {
		$page_hook       = add_submenu_page(
			'cybermaps-settings',
			__( 'AI Discovery Status', 'cybermaps' ),
			__( 'AI Discovery Status', 'cybermaps' ),
			'manage_options',
			'cybermaps-ai-discovery-status',
			array( $this, 'render_page' )
		);
		$this->page_hook = is_string( $page_hook ) ? $page_hook : '';
	}

	/**
	 * Render the AI discovery publication status page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cybermaps' ) );
		}

		$status_data  = ( new DiscoveryStatus() )->get_status_data( self::force_refresh_requested() );
		$page_context = self::page_context( $status_data );
		$hub_enabled  = $page_context['hub_enabled'];
		$static_mode  = $page_context['static_mode'];
		$endpoints    = $page_context['endpoints'];
		$notices      = $page_context['notices'];
		$observations = $page_context['observations'];
		$missing      = $page_context['missing'];
		$static_count = $page_context['static_count'];
		$checked_at   = $page_context['checked_at'];
		$refresh_url  = $page_context['refresh_url'];
		$negotiation  = $page_context['markdown_negotiation'];
		$routing      = $page_context['well_known_routing'];
		?>
		<div class="wrap cybermaps-wrap">
			<?php self::render_page_header( $static_mode, $checked_at, $refresh_url, count( $endpoints ) ); ?>

			<?php self::render_hub_summary( $hub_enabled, $status_data, $static_mode, $static_count ); ?>
			<?php self::render_markdown_negotiation( $negotiation ); ?>
			<?php self::render_well_known_routing( $routing ); ?>

			<?php self::render_publication_warnings( $hub_enabled, $missing, $status_data ); ?>
			<?php self::render_notices( $notices ); ?>

			<?php self::render_endpoint_table( $endpoints, $observations ); ?>
			<?php DeploymentGuidance::render( $endpoints ); ?>

			<div class="cm-flex cm-gap-20 cm-mt-20">
				<div class="cm-card-sm" style="flex:1;">
					<h3><?php esc_html_e( 'How to read this page', 'cybermaps' ); ?></h3>
					<ul style="list-style:disc;margin-left:20px;">
						<li><?php esc_html_e( 'Intended describes the selected publication mode; it does not prove how the web server answered.', 'cybermaps' ); ?></li>
						<li><?php esc_html_e( 'On disk confirms only that a local static copy exists.', 'cybermaps' ); ?></li>
						<li><?php esc_html_e( 'Observed is dynamic when the Cybermaps response marker is present, static when the public body exactly matches an owned file, and otherwise unverified.', 'cybermaps' ); ?></li>
						<li><?php esc_html_e( 'Body validation checks the public status, expected media type, and a nonempty parseable body.', 'cybermaps' ); ?></li>
						<li><?php esc_html_e( 'Header conformance is separate: it checks only the additional headers a web server or CDN must attach to static copies.', 'cybermaps' ); ?></li>
					</ul>
				</div>
				<div class="cm-card-sm" style="flex:1;">
					<h3><?php esc_html_e( 'Static server requirements', 'cybermaps' ); ?></h3>
					<ul style="list-style:disc;margin-left:20px;">
						<li><?php esc_html_e( 'Allow the selected extension-bearing JSON files to be served with their declared JSON media types.', 'cybermaps' ); ?></li>
						<li><?php esc_html_e( 'For static copies, configure Cache-Control, CORS, Content-Digest calculated from the deployed body, and opt-in Content-Usage where enabled.', 'cybermaps' ); ?></li>
						<li><?php esc_html_e( 'Cybermaps keeps its custom discovery documents at ordinary root paths, avoiding unregistered .well-known aliases.', 'cybermaps' ); ?></li>
						<li><?php esc_html_e( 'Canonical well-known fallback bodies remain available when the server bypasses WordPress. Debugging separates body availability from media-type, CORS, cache, and digest conformance.', 'cybermaps' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	private static function force_refresh_requested(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified immediately when the refresh flag is present.
		$requested = isset( $_GET['cybermaps_refresh'] )
			&& is_scalar( $_GET['cybermaps_refresh'] )
			&& '1' === sanitize_text_field( wp_unslash( (string) $_GET['cybermaps_refresh'] ) );
		// phpcs:enable
		if ( ! $requested ) {
			return false;
		}
		check_admin_referer( 'cybermaps_refresh_discovery_status' );
		return true;
	}

	/**
	 * @param array<string, mixed> $status_data Health result.
	 * @return array<string, mixed>
	 */
	private static function page_context( array $status_data ): array {
		$endpoints = is_array( $status_data['endpoints'] ?? null ) ? $status_data['endpoints'] : array();
		usort( $endpoints, array( self::class, 'compare_endpoints' ) );
		$counts = self::static_publication_counts( $endpoints );
		return array(
			'hub_enabled'          => ! empty( $status_data['hub_enabled'] ),
			'static_mode'          => (string) ( $status_data['static_mode'] ?? 'off' ),
			'endpoints'            => $endpoints,
			'notices'              => self::status_array( $status_data, 'notices' ),
			'observations'         => CrawlerAnalyticsRecorder::get_endpoint_observations(),
			'missing'              => $counts['missing'],
			'static_count'         => $counts['static'],
			'checked_at'           => (string) ( $status_data['checked_at'] ?? '' ),
			'refresh_url'          => wp_nonce_url(
				add_query_arg(
					array(
						'page'              => 'cybermaps-ai-discovery-status',
						'cybermaps_refresh' => '1',
					),
					admin_url( 'admin.php' )
				),
				'cybermaps_refresh_discovery_status'
			),
			'markdown_negotiation' => self::status_array( $status_data, 'markdown_negotiation' ),
			'well_known_routing'   => self::status_array( $status_data, 'well_known_routing' ),
		);
	}

	/** @return array<string,mixed> */
	private static function status_array( array $status_data, string $key ): array {
		$value = $status_data[ $key ] ?? array();
		return is_array( $value ) ? $value : array();
	}

	/** @param array<string, mixed> $left Left endpoint. @param array<string, mixed> $right Right endpoint. */
	private static function compare_endpoints( array $left, array $right ): int {
		$order       = array(
			'essential'    => 0,
			'experimental' => 1,
			'legacy'       => 2,
		);
		$left_group  = (string) ( $left['group'] ?? 'essential' );
		$right_group = (string) ( $right['group'] ?? 'essential' );
		$group_order = ( $order[ $left_group ] ?? 99 ) <=> ( $order[ $right_group ] ?? 99 );
		return 0 !== $group_order
			? $group_order
			: strcasecmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) );
	}

	/** @param array<int, array<string, mixed>> $endpoints Endpoint rows. @return array{static:int,missing:int} */
	private static function static_publication_counts( array $endpoints ): array {
		$counts = array(
			'static'  => 0,
			'missing' => 0,
		);
		foreach ( $endpoints as $endpoint ) {
			if ( 'static' !== ( $endpoint['intended_delivery'] ?? '' ) ) {
				continue;
			}
			++$counts['static'];
			if ( false === ( $endpoint['on_disk'] ?? null ) ) {
				++$counts['missing'];
			}
		}
		return $counts;
	}

	private static function render_page_header( string $mode, string $checked_at, string $refresh_url, int $count ): void {
		$checked_label = '' !== $checked_at
			? sprintf(
				/* translators: %s: UTC validation timestamp. */
				__( 'Checked: %s UTC', 'cybermaps' ),
				gmdate( 'Y-m-d H:i', strtotime( $checked_at ) )
			)
			: __( 'Not checked', 'cybermaps' );
		PageHeader::render(
			__( 'AI Discovery Status', 'cybermaps' ),
			sprintf(
				/* translators: %d: number of registered public discovery paths. */
				__( 'Validate delivery, media type, and parseable output for all %d registered discovery paths.', 'cybermaps' ),
				$count
			),
			array(
				array(
					'label' => self::mode_label( $mode ),
					'tone'  => 'all' === $mode ? 'info' : 'neutral',
				),
				array(
					'label' => $checked_label,
					'tone'  => 'neutral',
				),
			),
			array(
				array(
					'label'   => __( 'Refresh validation', 'cybermaps' ),
					'url'     => $refresh_url,
					'primary' => true,
				),
			)
		);
	}

	/** @param array<string, mixed> $status_data Health result. */
	private static function render_hub_summary( bool $enabled, array $status_data, string $mode, int $static_count ): void {
		if ( ! $enabled ) {
			?>
			<div class="cm-card-sm cm-mb-20" style="background:#fffbeb;border-color:#fde68a;">
				<strong><?php esc_html_e( 'AI Publication Hub is disabled.', 'cybermaps' ); ?></strong>
				<?php esc_html_e( 'Enable it in Settings → AI Publishing to activate these publications.', 'cybermaps' ); ?>
			</div>
			<?php
			return;
		}
		?>
		<div class="cm-flex cm-gap-20 cm-mb-20" style="flex-wrap:wrap;">
			<?php self::render_count_card( __( 'Validated responses', 'cybermaps' ), (int) ( $status_data['active_count'] ?? 0 ) ); ?>
			<?php self::render_count_card( __( 'Confirmed errors', 'cybermaps' ), (int) ( $status_data['error_count'] ?? 0 ) ); ?>
			<?php self::render_count_card( __( 'Unverified checks', 'cybermaps' ), (int) ( $status_data['unverified_count'] ?? 0 ) ); ?>
			<div class="cm-card-sm" style="min-width:180px;flex:1;">
				<div class="cm-text-xs cm-text-muted"><?php esc_html_e( 'Publication intent', 'cybermaps' ); ?></div>
				<strong style="font-size:18px;"><?php echo esc_html( self::mode_label( $mode ) ); ?></strong>
				<div class="cm-text-xs cm-text-muted"><?php echo esc_html( sprintf( /* translators: %d: number of paths intended for static publication. */ __( '%d paths intended static', 'cybermaps' ), $static_count ) ); ?></div>
				<div class="cm-text-xs cm-text-muted"><?php echo esc_html( sprintf( /* translators: %s: latest static reconciliation status. */ __( 'Reconciliation: %s', 'cybermaps' ), self::sync_status_label( (string) ( $status_data['sync_status'] ?? 'pending' ) ) ) ); ?></div>
			</div>
		</div>
		<?php
	}

	private static function render_count_card( string $label, int $count ): void {
		?>
		<div class="cm-card-sm" style="min-width:180px;flex:1;">
			<div class="cm-text-xs cm-text-muted"><?php echo esc_html( $label ); ?></div>
			<strong style="font-size:22px;"><?php echo esc_html( (string) $count ); ?></strong>
		</div>
		<?php
	}

	/** @param array<string, mixed> $status_data Health result. */
	private static function render_publication_warnings( bool $enabled, int $missing, array $status_data ): void {
		self::render_missing_files_warning( $enabled, $missing );
		self::render_sync_warning( $enabled, $status_data );
	}

	private static function render_missing_files_warning( bool $enabled, int $missing ): void {
		if ( ! $enabled || $missing < 1 ) {
			return;
		}
		?>
		<div class="cm-card-sm cm-mb-20" style="background:#fef2f2;border-color:#fecaca;">
			<strong><?php echo esc_html( sprintf( /* translators: %d: number of intended static files missing from disk. */ _n( '%d intended static file is missing.', '%d intended static files are missing.', $missing, 'cybermaps' ), $missing ) ); ?></strong>
			<p style="margin:8px 0 0;"><?php esc_html_e( 'This is a publication-state warning. The HTTP result below determines whether a dynamic fallback still makes the public URL usable.', 'cybermaps' ); ?></p>
		</div>
		<?php
	}

	/** @param array<string, mixed> $status_data Health result. */
	private static function render_sync_warning( bool $enabled, array $status_data ): void {
		$status = (string) ( $status_data['sync_status'] ?? 'disabled' );
		if ( ! $enabled || in_array( $status, array( 'complete', 'dynamic' ), true ) ) {
			return;
		}
		?>
		<div class="cm-card-sm cm-mb-20" style="background:#fffbeb;border-color:#fde68a;">
			<strong><?php echo esc_html( sprintf( /* translators: %s: structured static reconciliation state. */ __( 'Static publication reconciliation: %s.', 'cybermaps' ), self::sync_status_label( $status ) ) ); ?></strong>
			<p style="margin:8px 0 0;"><?php echo esc_html( self::sync_status_message( $status, $status_data ) ); ?></p>
		</div>
		<?php
	}

	/** @param array<int, array<string, mixed>> $notices Environment notices. */
	private static function render_notices( array $notices ): void {
		foreach ( $notices as $notice ) {
			$style = 'warning' === (string) ( $notice['level'] ?? 'info' )
				? 'background:#fffbeb;border-color:#fde68a;'
				: 'background:#eff6ff;border-color:#bfdbfe;';
			?>
			<div class="cm-card-sm cm-mb-20" style="<?php echo esc_attr( $style ); ?>"><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></div>
			<?php
		}
	}

	/** @param array<string,mixed> $status Negotiation status. */
	private static function render_markdown_negotiation( array $status ): void {
		$state    = (string) ( $status['status'] ?? 'disabled' );
		$provider = (string) ( $status['provider'] ?? 'disabled' );
		$labels   = array(
			'healthy'    => __( 'Healthy', 'cybermaps' ),
			'error'      => __( 'Needs attention', 'cybermaps' ),
			'unverified' => __( 'Unverified', 'cybermaps' ),
			'disabled'   => __( 'Disabled', 'cybermaps' ),
		);
		?>
		<div class="cm-card-sm cm-mb-20">
			<h3><?php esc_html_e( 'Markdown for Agents', 'cybermaps' ); ?></h3>
			<p><strong><?php echo esc_html( $labels[ $state ] ?? ucfirst( $state ) ); ?></strong>
			<?php if ( ! in_array( $provider, array( '', 'disabled', 'unknown' ), true ) ) : ?>
				· <?php echo esc_html( 'origin' === $provider ? __( 'Cybermaps origin', 'cybermaps' ) : __( 'CDN or edge', 'cybermaps' ) ); ?>
			<?php endif; ?>
			</p>
			<p><?php echo esc_html( (string) ( $status['message'] ?? '' ) ); ?></p>
			<?php
			if ( ! empty( $status['url'] ) ) :
				?>
				<code><?php echo esc_html( (string) $status['url'] ); ?></code><?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string,mixed> $status Managed well-known routing status. */
	private static function render_well_known_routing( array $status ): void {
		$state        = (string) ( $status['status'] ?? 'pending' );
		$verification = is_array( $status['verification'] ?? null ) ? $status['verification'] : array();
		$labels       = array(
			'installed'          => __( 'Installed', 'cybermaps' ),
			'unchanged'          => __( 'Installed', 'cybermaps' ),
			'not_required'       => __( 'Not required', 'cybermaps' ),
			'dynamic_only'       => __( 'Dynamic only', 'cybermaps' ),
			'unsupported_server' => __( 'Server-managed', 'cybermaps' ),
			'conflict'           => __( 'Needs attention', 'cybermaps' ),
			'write_failed'       => __( 'Needs attention', 'cybermaps' ),
			'pending'            => __( 'Pending', 'cybermaps' ),
		);
		?>
		<div class="cm-card-sm cm-mb-20">
			<h3><?php esc_html_e( 'Automatic well-known routing', 'cybermaps' ); ?></h3>
			<p><strong><?php echo esc_html( $labels[ $state ] ?? ucfirst( str_replace( '_', ' ', $state ) ) ); ?></strong></p>
			<p><?php echo esc_html( (string) ( $status['message'] ?? __( 'Cybermaps will reconcile compatible origin routing automatically.', 'cybermaps' ) ) ); ?></p>
			<?php if ( ! empty( $verification['message'] ) ) : ?>
				<p class="cm-text-sm cm-text-muted"><?php echo esc_html( (string) $verification['message'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<int, array<string, mixed>> $endpoints Endpoint rows. @param array<string, array<string, mixed>> $observations Request observations. */
	private static function render_endpoint_table( array $endpoints, array $observations ): void {
		?>
		<table class="wp-list-table widefat cm-discovery-status-table">
			<thead><tr>
				<th><?php esc_html_e( 'Publication', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Description', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Expected type', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Intended', 'cybermaps' ); ?></th><th><?php esc_html_e( 'On disk', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Observed', 'cybermaps' ); ?></th><th><?php esc_html_e( 'PHP observation', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Availability & body', 'cybermaps' ); ?></th><th><?php esc_html_e( 'Protocol conformance', 'cybermaps' ); ?></th><th><?php esc_html_e( 'View', 'cybermaps' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			$current_group = '';
			foreach ( $endpoints as $endpoint ) {
				$group = (string) ( $endpoint['group'] ?? 'essential' );
				if ( $group !== $current_group ) {
					$current_group = $group;
					?>
					<tr class="cm-discovery-status-group-row"><th colspan="10" scope="colgroup"><?php echo esc_html( self::group_label( $group ) ); ?></th></tr>
					<?php
				}
				$endpoint_id = sanitize_key( (string) ( $endpoint['endpoint_id'] ?? '' ) );
				self::render_endpoint_row( $endpoint, $observations[ $endpoint_id ] ?? array() );
			}
			?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. @param array<string, mixed> $observation Request observation. */
	private static function render_endpoint_row( array $endpoint, array $observation ): void {
		$intended = (string) ( $endpoint['intended_delivery'] ?? 'dynamic' );
		?>
		<tr class="cm-discovery-status-endpoint-row">
		<?php
		self::render_publication_cell( $endpoint );
		?>
		<td class="cm-text-sm cm-discovery-status-field--description"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'Description', 'cybermaps' ); ?></span><?php echo esc_html( (string) ( $endpoint['description'] ?? '' ) ); ?></td>
		<?php
		self::render_type_cell( $endpoint );
		self::render_intended_cell( $endpoint, $intended );
		self::render_disk_cell( $endpoint, $intended );
		self::render_observed_cell( $endpoint );
		self::render_php_observation_cell( $intended, $observation );
		self::render_body_validation_cell( $endpoint );
		self::render_header_validation_cell( $endpoint );
		self::render_view_cell( $endpoint, $intended );
		?>
		</tr>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. */
	private static function render_publication_cell( array $endpoint ): void {
		?>
		<td class="cm-discovery-status-publication cm-discovery-status-field--publication">
			<span class="cm-discovery-status-field-label"><?php esc_html_e( 'Publication', 'cybermaps' ); ?></span><strong><?php echo esc_html( (string) ( $endpoint['label'] ?? '' ) ); ?></strong><br><code style="font-size:11px;"><?php echo esc_html( (string) ( $endpoint['path'] ?? '' ) ); ?></code>
			<?php
			if ( ! empty( $endpoint['spec'] ) ) :
				?>
				<br><span class="cm-text-xs cm-text-muted"><?php echo esc_html( (string) $endpoint['spec'] ); ?></span><?php endif; ?>
			<br><span class="cm-text-xs cm-text-muted"><?php echo esc_html( sprintf( /* translators: 1: publication maturity, 2: adoption evidence, 3: delivery class. */ __( '%1$s · %2$s · %3$s', 'cybermaps' ), self::maturity_label( (string) ( $endpoint['maturity'] ?? '' ) ), self::adoption_label( (string) ( $endpoint['adoption'] ?? '' ) ), self::delivery_class_label( (string) ( $endpoint['delivery_class'] ?? '' ) ) ) ); ?></span>
		</td>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. */
	private static function render_type_cell( array $endpoint ): void {
		$observed = (string) ( $endpoint['content_type'] ?? '' );
		?>
		<td class="cm-discovery-status-type cm-discovery-status-field--type"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'Expected type', 'cybermaps' ); ?></span><code style="font-size:11px;"><?php echo esc_html( (string) ( $endpoint['type'] ?? '' ) ); ?></code>
		<?php
		if ( '' !== $observed && false === ( $endpoint['content_type_valid'] ?? null ) ) :
			?>
			<br><span style="color:#b91c1c;font-size:11px;"><?php echo esc_html( $observed ); ?></span><?php endif; ?></td>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. */
	private static function render_intended_cell( array $endpoint, string $intended ): void {
		?>
		<td class="cm-discovery-status-field--intended"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'Intended', 'cybermaps' ); ?></span>
		<?php
		if ( 'disabled' === $intended ) {
			?>
			<span class="cm-text-muted"><?php esc_html_e( 'Disabled', 'cybermaps' ); ?></span>
			<?php
		} elseif ( 'static' === $intended ) {
			?>
			<span style="color:#0369a1;"><?php esc_html_e( 'Static copy', 'cybermaps' ); ?></span><br><span class="cm-text-xs cm-text-muted"><?php echo esc_html( (string) ( $endpoint['static_bucket'] ?? '' ) ); ?></span>
			<?php
		} else {
			?>
			<span class="cm-text-muted"><?php esc_html_e( 'Dynamic', 'cybermaps' ); ?></span>
			<?php
		}
		?>
		</td>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. */
	private static function render_disk_cell( array $endpoint, string $intended ): void {
		$on_disk = $endpoint['on_disk'] ?? null;
		?>
		<td class="cm-discovery-status-field--disk"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'On disk', 'cybermaps' ); ?></span>
		<?php
		if ( 'dynamic' === $intended && true !== $on_disk ) {
			?>
			<span class="cm-text-muted"><?php esc_html_e( 'Not required', 'cybermaps' ); ?></span>
			<?php
		} elseif ( empty( $endpoint['static_file'] ) ) {
			?>
			<span class="cm-text-muted">—</span>
			<?php
		} elseif ( true === $on_disk ) {
			?>
			<span style="color:#047857;">&#10003; <?php esc_html_e( 'Present', 'cybermaps' ); ?></span>
			<?php
		} elseif ( false === $on_disk ) {
			$style = 'static' === $intended ? 'color:#b91c1c;' : 'color:#64748b;';
			?>
			<span style="<?php echo esc_attr( $style ); ?>">&#10007; <?php esc_html_e( 'Absent', 'cybermaps' ); ?></span>
			<?php
		} else {
			?>
			<span style="color:#a16207;"><?php esc_html_e( 'Unverified', 'cybermaps' ); ?></span>
			<?php
		}
		?>
		</td>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. */
	private static function render_observed_cell( array $endpoint ): void {
		$delivery = (string) ( $endpoint['delivery'] ?? 'unverified' );
		?>
		<td class="cm-discovery-status-field--observed"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'Observed', 'cybermaps' ); ?></span><span style="<?php echo esc_attr( self::delivery_style( $delivery ) ); ?>"><?php echo esc_html( self::delivery_label( $delivery ) ); ?></span></td>
		<?php
	}

	/** @param array<string, mixed> $observation Request observation. */
	private static function render_php_observation_cell( string $intended, array $observation ): void {
		$last_php = ! empty( $observation['last_php'] )
			? sprintf( /* translators: %s: database timestamp. */ __( 'Last PHP: %s', 'cybermaps' ), (string) $observation['last_php'] )
			: __( 'No PHP-observed request', 'cybermaps' );
		?>
		<td class="cm-discovery-status-field--php"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'PHP observation', 'cybermaps' ); ?></span>
		<?php
		if ( 'disabled' === $intended ) {
			?>
			<strong><?php esc_html_e( 'Not published', 'cybermaps' ); ?></strong>
			<?php
		} elseif ( 'static' === $intended ) {
			?>
			<strong><?php esc_html_e( 'Not observable when static', 'cybermaps' ); ?></strong>
			<?php
		} else {
			?>
			<strong><?php esc_html_e( 'Observable when PHP runs', 'cybermaps' ); ?></strong>
			<?php
		}
		?>
		<div class="cm-text-xs cm-text-muted" style="margin-top:4px;"><?php echo esc_html( sprintf( /* translators: 1: all PHP endpoint observations, 2: observations with a matching crawler User-Agent signature. */ __( '%1$d PHP · %2$d UA matched', 'cybermaps' ), (int) ( $observation['php_requests'] ?? 0 ), (int) ( $observation['recognized_requests'] ?? 0 ) ) ); ?></div><div class="cm-text-xs cm-text-muted"><?php echo esc_html( $last_php ); ?></div></td>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. */
	private static function render_body_validation_cell( array $endpoint ): void {
		$availability = (string) ( $endpoint['availability_status'] ?? 'unverified' );
		$body         = (string) ( $endpoint['body_status'] ?? 'unverified' );
		?>
		<td class="cm-discovery-status-field--http"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'Availability & body', 'cybermaps' ); ?></span><span style="<?php echo esc_attr( self::status_style( $availability ) ); ?>font-size:11px;padding:2px 8px;border-radius:3px;display:inline-block;"><?php echo esc_html( self::availability_label( $availability ) ); ?>
		<?php
		if ( ! empty( $endpoint['code'] ) ) :
			?>
			<?php echo esc_html( ' · HTTP ' . (string) $endpoint['code'] ); ?><?php endif; ?></span><br><span style="<?php echo esc_attr( self::status_style( $body ) ); ?>font-size:11px;padding:2px 8px;border-radius:3px;display:inline-block;margin-top:4px;"><?php echo esc_html( self::body_status_label( $body ) ); ?></span>
			<?php
			if ( ! empty( $endpoint['message'] ) ) :
				?>
			<div class="cm-text-xs cm-text-muted" style="margin-top:5px;"><?php echo esc_html( (string) $endpoint['message'] ); ?></div><?php endif; ?></td>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. */
	private static function render_header_validation_cell( array $endpoint ): void {
		$status = (string) ( $endpoint['conformance_status'] ?? 'unverified' );
		?>
		<td class="cm-discovery-status-field--headers"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'Protocol conformance', 'cybermaps' ); ?></span><span style="<?php echo esc_attr( self::status_style( $status ) ); ?>font-size:11px;padding:2px 8px;border-radius:3px;display:inline-block;"><?php echo esc_html( self::conformance_status_label( $status ) ); ?></span>
		<?php
		if ( ! empty( $endpoint['header_message'] ) ) :
			?>
			<div class="cm-text-xs cm-text-muted" style="margin-top:5px;"><strong><?php esc_html_e( 'Delivery policy:', 'cybermaps' ); ?></strong> <?php echo esc_html( (string) $endpoint['header_message'] ); ?></div><?php endif; ?>
		<?php if ( 'error' === ( $endpoint['header_status'] ?? '' ) ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybermaps-settings&tab=advanced#cybermaps-local-delivery' ) ); ?>"><?php esc_html_e( 'Configure local delivery', 'cybermaps' ); ?></a>
		<?php endif; ?></td>
		<?php
	}

	/** @param array<string, mixed> $endpoint Endpoint row. */
	private static function render_view_cell( array $endpoint, string $intended ): void {
		?>
		<td class="cm-discovery-status-field--view"><span class="cm-discovery-status-field-label"><?php esc_html_e( 'View', 'cybermaps' ); ?></span>
		<?php
		if ( 'disabled' === $intended ) :
			?>
			<span class="cm-text-muted">—</span>
			<?php
else :
	?>
			<a href="<?php echo esc_url( (string) ( $endpoint['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small"><span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span><span class="screen-reader-text"><?php esc_html_e( 'Open publication', 'cybermaps' ); ?></span></a><?php endif; ?></td>
		<?php
	}

	private static function mode_label( string $mode ): string {
		return match ( $mode ) {
			'all' => __( 'Publish all eligible copies', 'cybermaps' ),
			'well_known' => __( 'Publish core discovery files', 'cybermaps' ),
			default => __( 'Dynamic-only delivery', 'cybermaps' ),
		};
	}

	private static function sync_status_label( string $status ): string {
		$labels = array(
			'complete'       => __( 'Complete', 'cybermaps' ),
			'partial'        => __( 'Partial', 'cybermaps' ),
			'failed'         => __( 'Failed', 'cybermaps' ),
			'busy'           => __( 'Busy', 'cybermaps' ),
			'skipped'        => __( 'Skipped', 'cybermaps' ),
			'missing'        => __( 'Files missing', 'cybermaps' ),
			'stale'          => __( 'Reconciliation required', 'cybermaps' ),
			'schedule_error' => __( 'Scheduling failed', 'cybermaps' ),
			'dynamic'        => __( 'Not applicable', 'cybermaps' ),
			'disabled'       => __( 'Disabled', 'cybermaps' ),
		);
		return $labels[ $status ] ?? __( 'Pending', 'cybermaps' );
	}

	/**
	 * Explain a structured reconciliation state without claiming that an old
	 * absence of write errors means current files are synchronized.
	 *
	 * @param array<string, mixed> $status_data Health result.
	 */
	private static function sync_status_message( string $status, array $status_data ): string {
		$attempt_message = self::sync_attempt_message( $status_data );
		$messages        = array(
			'partial'        => $attempt_message,
			'failed'         => $attempt_message,
			'schedule_error' => self::schedule_error_message( $status_data ),
			'missing'        => __( 'At least one path selected for static publication has no resolved file on disk. Its public URL may still work through the dynamic fallback.', 'cybermaps' ),
			'stale'          => __( 'Settings or publication mode changed after the latest stored report. The queued reconciliation must complete before physical files are current.', 'cybermaps' ),
			'busy'           => __( 'Another generated-file operation held the publication lock. Cybermaps will retry.', 'cybermaps' ),
			'skipped'        => __( 'The latest reconciliation was intentionally skipped; inspect the stored report for its reason.', 'cybermaps' ),
		);
		if ( isset( $messages[ $status ] ) ) {
			return $messages[ $status ];
		}
		return __( 'No completed reconciliation report is recorded for the selected publication intent yet.', 'cybermaps' );
	}

	/** @param array<string, mixed> $status_data Health result. */
	private static function sync_attempt_message( array $status_data ): string {
		$report = is_array( $status_data['sync_report'] ?? null ) ? $status_data['sync_report'] : array();
		$counts = is_array( $report['counts'] ?? null ) ? $report['counts'] : array();
		return sprintf(
			/* translators: 1: conflict count, 2: failure count, 3: retained-file count. */
			__( 'Latest attempt — conflicts: %1$d; failures: %2$d; retained files: %3$d. Public HTTP validation remains authoritative for availability.', 'cybermaps' ),
			(int) ( $counts['conflicted'] ?? 0 ),
			(int) ( $counts['failed'] ?? 0 ),
			(int) ( $counts['retained'] ?? 0 )
		);
	}

	/** @param array<string, mixed> $status_data Health result. */
	private static function schedule_error_message( array $status_data ): string {
		return (string) (
			$status_data['schedule_error']['message']
			?? __( 'WordPress could not schedule the background static reconciliation.', 'cybermaps' )
		);
	}

	private static function status_label( string $status ): string {
		return match ( $status ) {
			'healthy' => __( 'Validated', 'cybermaps' ),
			'error' => __( 'Error', 'cybermaps' ),
			'disabled' => __( 'Disabled', 'cybermaps' ),
			default => __( 'Unverified', 'cybermaps' ),
		};
	}

	private static function header_status_label( string $status ): string {
		return match ( $status ) {
			'healthy' => __( 'Conformant', 'cybermaps' ),
			'error' => __( 'Missing or mismatched', 'cybermaps' ),
			'disabled', 'not_applicable' => __( 'Not required', 'cybermaps' ),
			default => __( 'Unverified', 'cybermaps' ),
		};
	}

	private static function availability_label( string $status ): string {
		return match ( $status ) {
			'pass' => __( 'Available', 'cybermaps' ),
			'error' => __( 'Unavailable', 'cybermaps' ),
			default => __( 'Unverified', 'cybermaps' ),
		};
	}

	private static function body_status_label( string $status ): string {
		return match ( $status ) {
			'pass' => __( 'Body valid', 'cybermaps' ),
			'error' => __( 'Body invalid', 'cybermaps' ),
			default => __( 'Body unverified', 'cybermaps' ),
		};
	}

	private static function conformance_status_label( string $status ): string {
		return match ( $status ) {
			'pass' => __( 'Conformant', 'cybermaps' ),
			'error' => __( 'Not conformant', 'cybermaps' ),
			default => __( 'Unverified', 'cybermaps' ),
		};
	}

	private static function status_style( string $status ): string {
		return match ( $status ) {
			'healthy', 'pass' => 'background:#ecfdf5;color:#047857;',
			'error' => 'background:#fef2f2;color:#b91c1c;',
			'disabled' => 'background:#f1f5f9;color:#64748b;',
			default => 'background:#fffbeb;color:#a16207;',
		};
	}

	private static function delivery_label( string $delivery ): string {
		return match ( $delivery ) {
			'dynamic' => __( 'Dynamic', 'cybermaps' ),
			'static' => __( 'Static', 'cybermaps' ),
			'disabled' => __( 'Disabled', 'cybermaps' ),
			default => __( 'Unverified', 'cybermaps' ),
		};
	}

	private static function delivery_style( string $delivery ): string {
		return match ( $delivery ) {
			'dynamic' => 'color:#6d28d9;',
			'static' => 'color:#0369a1;',
			'disabled' => 'color:#64748b;',
			default => 'color:#a16207;',
		};
	}

	private static function group_label( string $group ): string {
		return match ( $group ) {
			'experimental' => __( 'Experimental, opt-in publications', 'cybermaps' ),
			'legacy' => __( 'Legacy compatibility publications', 'cybermaps' ),
			default => __( 'Essential publications', 'cybermaps' ),
		};
	}

	private static function maturity_label( string $maturity ): string {
		return match ( $maturity ) {
			'formal-standard' => __( 'standards-track specification', 'cybermaps' ),
			'formal-draft' => __( 'formal draft', 'cybermaps' ),
			'community-convention' => __( 'community convention', 'cybermaps' ),
			'experimental-proposal' => __( 'experimental proposal', 'cybermaps' ),
			'legacy' => __( 'legacy', 'cybermaps' ),
			default => __( 'vendor extension', 'cybermaps' ),
		};
	}

	private static function adoption_label( string $adoption ): string {
		return match ( $adoption ) {
			'provider-documented' => __( 'provider documented', 'cybermaps' ),
			'known-consumer' => __( 'known consumer', 'cybermaps' ),
			'independent-producers' => __( 'independent producers', 'cybermaps' ),
			default => __( 'reference only', 'cybermaps' ),
		};
	}

	private static function delivery_class_label( string $delivery ): string {
		return 'rest-api' === $delivery
			? __( 'REST API', 'cybermaps' )
			: __( 'fixed path', 'cybermaps' );
	}
}
