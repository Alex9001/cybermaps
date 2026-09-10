<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitemap URL Listing Page.
 *
 * Displays all generated sitemap files with URL counts and last-modified
 * timestamps so users can verify their sitemaps are working correctly.
 */
class SitemapStatus {

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
		add_action( 'admin_post_cybermaps_verify_sitemap_php_path', array( $this, 'handle_php_path_diagnostic' ) );
	}

	/**
	 * Enqueue assets for the Sitemap Status page.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( '' === $this->page_hook || $this->page_hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cybermaps-command-center', CYBERMAPS_PLUGIN_URL . 'assets/css/admin-command-center.css', array(), CYBERMAPS_VERSION );
		wp_style_add_data( 'cybermaps-command-center', 'rtl', true );
	}

	/**
	 * Add the Sitemap Status admin submenu page.
	 */
	public function add_page(): void {
		$page_hook       = add_submenu_page(
			'cybermaps-settings',
			__( 'Sitemap Status', 'cybermaps' ),
			__( 'Sitemap Status', 'cybermaps' ),
			'manage_options',
			'cybermaps-sitemap-status',
			array( $this, 'render_page' )
		);
		$this->page_hook = is_string( $page_hook ) ? $page_hook : '';
	}

	/**
	 * Render the sitemap URL listing page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cybermaps' ) );
		}

		$settings                    = \Cybermaps\Core\ConfigurationStore::settings();
		$robots_manager              = \Cybermaps\Core\ConfigurationStore::robots();
		$robots_reference_configured = self::robots_sitemap_reference_configured(
			$settings,
			$robots_manager,
			(bool) get_option( 'blog_public', true )
		);
		$hub_enabled                 = ! empty( $settings['enable_discovery_hub'] );
		$indexnow_enabled            = ! empty( $settings['enable_indexnow'] );
		$orchestrator                = new \Cybermaps\Sitemap\Orchestrator();
		$base                        = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$home_url                    = \Cybermaps\Core\URLManager::get_home_url( '/' );
		$sitemaps                    = self::sitemap_inventory( $orchestrator, $home_url, $base );
		$sitemaps                    = self::append_rss_sitemap( $sitemaps, $settings, $home_url );
		$status_context              = self::status_context( $settings );
		$static_mode                 = $status_context['static_mode'];
		$last_success                = $status_context['last_success'];
		$last_attempt                = $status_context['last_attempt'];
		$sync_status                 = $status_context['sync_status'];
		$sync_status_label           = $status_context['sync_status_label'];
		$mode_label                  = $status_context['mode_label'];
		$occupancy_state             = $status_context['occupancy_state'];
		$occupancy_status            = $status_context['occupancy_status'];
		$competing_plugin            = $status_context['competing_plugin'];
		$php_diag                    = $status_context['php_diag'];
		?>
		<div class="wrap cybermaps-wrap">
			<?php
			PageHeader::render(
				__( 'Sitemap Status', 'cybermaps' ),
				__( 'Verify the exact sitemap inventory Cybermaps advertises and how each publication is intended to be served.', 'cybermaps' ),
				array(
					array(
						'label' => $mode_label,
						'tone'  => 'all' === $static_mode ? 'info' : 'neutral',
					),
					array(
						'label' => sprintf(
							/* translators: %s: latest static reconciliation status. */
							__( 'Reconciliation: %s', 'cybermaps' ),
							$sync_status_label
						),
						'tone'  => self::reconciliation_status_tone( $sync_status ),
					),
				),
				array(
					array(
						'label' => __( 'Open sitemap index', 'cybermaps' ),
						'url'   => $home_url . $base . '.xml',
					),
				)
			);
			?>

			<?php self::render_competing_plugin_notice( $competing_plugin ); ?>

			<div class="cm-card-sm cm-mb-20">
				<strong><?php esc_html_e( 'Sitemap page occupancy', 'cybermaps' ); ?></strong>
				<div class="cm-text-sm cm-text-muted" style="margin-top: 6px;">
					<?php
					echo esc_html(
						sprintf(
								/* translators: 1: occupancy status, 2: scanned raw pages, 3: non-empty pages, 4: empty pages, 5: pages not scanned because of the safety ceiling. */
							__( 'Status: %1$s · scanned raw pages: %2$s · non-empty: %3$s · empty: %4$s · unknown: %5$s', 'cybermaps' ),
							self::occupancy_status_label( $occupancy_status ),
							number_format_i18n( (int) ( $occupancy_state['scanned_raw_pages'] ?? 0 ) ),
							number_format_i18n( (int) ( $occupancy_state['non_empty_pages'] ?? 0 ) ),
							number_format_i18n( (int) ( $occupancy_state['empty_pages'] ?? 0 ) ),
							number_format_i18n( (int) ( $occupancy_state['unknown_pages'] ?? 0 ) )
						)
					);
					?>
				</div>
			</div>

			<?php self::render_php_path_diagnostic( $php_diag ); ?>

			<?php self::render_mode_notice( $static_mode, $hub_enabled, $sync_status_label, $last_attempt, $last_success ); ?>

			<?php
			$coverage        = self::item_coverage();
			$total_published = $coverage['total'];
			$included        = $coverage['included'];
			$excluded        = $coverage['excluded'];
			$coverage_pct    = $coverage['percentage'];
			?>
				<div class="cm-kpi-grid cm-kpi-grid-4 cm-mb-20">
				<div class="cm-kpi-card" style="border-left-color: #2271b1;">
					<div class="cm-kpi-value"><?php echo esc_html( number_format_i18n( $total_published ) ); ?></div>
					<div class="cm-kpi-label"><?php esc_html_e( 'Total Published', 'cybermaps' ); ?></div>
				</div>
				<div class="cm-kpi-card" style="border-left-color: #059669;">
					<div class="cm-kpi-value" style="color: #059669;"><?php echo esc_html( number_format_i18n( $included ) ); ?></div>
					<div class="cm-kpi-label"><?php esc_html_e( 'Not Item-Excluded', 'cybermaps' ); ?></div>
				</div>
				<div class="cm-kpi-card" style="border-left-color: #ef4444;">
					<div class="cm-kpi-value" style="color: #ef4444;"><?php echo esc_html( number_format_i18n( $excluded ) ); ?></div>
					<div class="cm-kpi-label"><?php esc_html_e( 'Item-level Exclusions', 'cybermaps' ); ?></div>
				</div>
				<div class="cm-kpi-card" style="border-left-color: <?php echo $coverage_pct >= 80 ? '#059669' : '#f59e0b'; ?>;">
					<div class="cm-kpi-value" style="color: <?php echo $coverage_pct >= 80 ? '#059669' : '#f59e0b'; ?>;"><?php echo esc_html( (string) $coverage_pct ); ?>%</div>
					<div class="cm-kpi-label"><?php esc_html_e( 'Item Inclusion', 'cybermaps' ); ?></div>
					</div>
				</div>
				<p class="description cm-mb-20">
					<?php esc_html_e( 'These four cards measure the explicit per-item sitemap flag. Matrix priorities, global category rules, password protection, and SEO-plugin noindex decisions are reflected in the per-sitemap URL estimates below.', 'cybermaps' ); ?>
				</p>

			<?php self::render_sitemap_table( $sitemaps ); ?>

			<div class="cm-card-sm cm-mt-20">
				<h3><?php esc_html_e( 'Submit to Search Engines', 'cybermaps' ); ?></h3>
				<p class="cm-text-sm">
					<?php esc_html_e( 'Use the services below for manual sitemap submission. Cybermaps reports its current robots.txt and IndexNow configuration separately because either feature can be disabled.', 'cybermaps' ); ?>
				</p>
				<?php self::render_submission_links( $indexnow_enabled, $robots_reference_configured ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function sitemap_inventory(
		\Cybermaps\Sitemap\Orchestrator $orchestrator,
		string $home_url,
		string $base
	): array {
		$per_page        = max( 1, (int) $orchestrator->get_per_page() );
		$provider_totals = array();
		$sitemaps        = array(
			array(
				'url'   => $home_url . $base . '.xml',
				'label' => __( 'Sitemap Index', 'cybermaps' ),
				'type'  => __( 'System: Index', 'cybermaps' ),
			),
		);
		foreach ( $orchestrator->get_internal_sitemap_entries() as $entry ) {
			$provider_id = (string) $entry['provider_id'];
			if ( ! isset( $provider_totals[ $provider_id ] ) ) {
				$provider                        = $orchestrator->get_provider( $provider_id );
				$provider_totals[ $provider_id ] = $provider ? max( 0, (int) $provider->get_count() ) : 0;
			}
			$page       = (int) $entry['page'];
			$total      = $provider_totals[ $provider_id ];
			$labels     = self::sitemap_entry_labels( $entry );
			$sitemaps[] = array(
				'url'   => (string) $entry['loc'],
				'label' => $labels['label'],
				'type'  => $labels['type'],
				'count' => min( $per_page, max( 0, $total - ( ( $page - 1 ) * $per_page ) ) ),
			);
		}
		return $sitemaps;
	}

	/**
	 * @param array<string, mixed> $entry Inventory entry.
	 * @return array{label:string,type:string}
	 */
	private static function sitemap_entry_labels( array $entry ): array {
		$system_labels = array(
			\Cybermaps\Sitemap\ProviderIdentity::NEWS     => array(
				'label' => __( 'Google News Sitemap', 'cybermaps' ),
				'type'  => __( 'System: News', 'cybermaps' ),
			),
			\Cybermaps\Sitemap\ProviderIdentity::MISC     => array(
				'label' => __( 'Miscellaneous Sitemap', 'cybermaps' ),
				'type'  => __( 'System: Miscellaneous', 'cybermaps' ),
			),
			\Cybermaps\Sitemap\ProviderIdentity::AUTHORS  => array(
				'label' => __( 'Authors Sitemap', 'cybermaps' ),
				'type'  => __( 'System: Authors', 'cybermaps' ),
			),
			\Cybermaps\Sitemap\ProviderIdentity::ARCHIVES => array(
				'label' => __( 'Archives Sitemap', 'cybermaps' ),
				'type'  => __( 'System: Archives', 'cybermaps' ),
			),
		);
		$provider_id   = (string) $entry['provider_id'];
		return $system_labels[ $provider_id ] ?? self::custom_sitemap_entry_labels( $entry );
	}

	/**
	 * @param array<string, mixed> $entry Inventory entry.
	 * @return array{label:string,type:string}
	 */
	private static function custom_sitemap_entry_labels( array $entry ): array {
		$provider_kind = (string) $entry['provider_kind'];
		$provider_name = (string) $entry['provider_name'];
		$type_object   = 'post_type' === $provider_kind ? get_post_type_object( $provider_name ) : get_taxonomy( $provider_name );
		$type_label    = is_object( $type_object ) && isset( $type_object->labels->name )
			? (string) $type_object->labels->name
			: $provider_name;
		$label         = sprintf(
			/* translators: 1: post type or taxonomy name, 2: chunk number. */
			__( '%1$s Sitemap (Chunk %2$d)', 'cybermaps' ),
			$type_label,
			(int) $entry['page']
		);
		$type = 'post_type' === $provider_kind
			? sprintf(
				/* translators: %s: WordPress post type name. */
				__( 'Post type: %s', 'cybermaps' ),
				$provider_name
			)
			: sprintf(
				/* translators: %s: WordPress taxonomy name. */
				__( 'Taxonomy: %s', 'cybermaps' ),
				$provider_name
			);
		return array(
			'label' => $label,
			'type'  => $type,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $sitemaps Sitemap rows.
	 * @param array<string, mixed>             $settings Plugin settings.
	 * @return array<int, array<string, mixed>>
	 */
	private static function append_rss_sitemap( array $sitemaps, array $settings, string $home_url ): array {
		if ( empty( $settings['enable_rss_sitemap'] ) ) {
			return $sitemaps;
		}
		$rss_base   = \Cybermaps\Sitemap\Orchestrator::get_rss_sitemap_base();
		$sitemaps[] = array(
			'url'   => $home_url . $rss_base . '.xml',
			'label' => __( 'RSS Sitemap', 'cybermaps' ),
			'type'  => __( 'System: RSS', 'cybermaps' ),
		);
		return $sitemaps;
	}

	/**
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array<string, mixed>
	 */
	private static function status_context( array $settings ): array {
		$static_mode     = \Cybermaps\Discovery\StaticBridge::get_mode( $settings );
		$sync_report     = get_option( 'cybermaps_last_static_sync_report', array() );
		$sync_status     = is_array( $sync_report ) && ! empty( $sync_report['status'] )
			? (string) $sync_report['status']
			: 'not-run';
		$mode_labels     = array(
			'all'        => __( 'Static: all publications', 'cybermaps' ),
			'well_known' => __( 'Mode: compatibility files', 'cybermaps' ),
		);
		$occupancy_state = \get_option( \Cybermaps\Sitemap\PageOccupancyBuilder::STATE_OPTION, array() );
		$php_diag        = \get_transient( 'cybermaps_sitemap_php_path_diagnostic' );
		return array(
			'static_mode'       => $static_mode,
			'last_success'      => (string) get_option( 'cybermaps_last_static_sync', __( 'Never', 'cybermaps' ) ),
			'last_attempt'      => (string) get_option( 'cybermaps_last_static_sync_attempt', __( 'Never', 'cybermaps' ) ),
			'sync_status'       => $sync_status,
			'sync_status_label' => self::reconciliation_status_label( $sync_status ),
			'mode_label'        => $mode_labels[ $static_mode ] ?? __( 'Dynamic-only', 'cybermaps' ),
			'occupancy_state'   => is_array( $occupancy_state ) ? $occupancy_state : array(),
			'occupancy_status'  => self::effective_occupancy_status( is_array( $occupancy_state ) ? $occupancy_state : array() ),
			'competing_plugin'  => CompetingSeoPlugin::detect(),
			'php_diag'          => is_array( $php_diag ) ? $php_diag : null,
		);
	}

	private static function render_competing_plugin_notice( string $plugin ): void {
		if ( '' === $plugin ) {
			return;
		}
		?>
		<div class="cm-card-sm cm-mb-20" style="background: #fef2f2; border-color: #fecaca;">
			<strong><?php esc_html_e( 'Another SEO plugin is active', 'cybermaps' ); ?></strong>
			<div class="cm-text-sm cm-text-muted" style="margin-top: 6px;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: detected SEO plugin name. */
						__( '%s was detected. Verify that only one primary XML sitemap is submitted to search engines.', 'cybermaps' ),
						$plugin
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	/** @param array<string, mixed>|null $diagnostic Diagnostic result. */
	private static function render_php_path_diagnostic( ?array $diagnostic ): void {
		?>
		<div class="cm-card-sm cm-mb-20">
			<strong><?php esc_html_e( 'PHP sitemap path check', 'cybermaps' ); ?></strong>
			<div class="cm-text-sm cm-text-muted" style="margin-top: 6px;">
				<?php if ( null !== $diagnostic ) : ?>
					<?php
					echo esc_html(
						! empty( $diagnostic['success'] )
							? __( 'The dynamic sitemap index responded with the expected Cybermaps marker.', 'cybermaps' )
							: __( 'The dynamic sitemap index did not return the expected Cybermaps marker.', 'cybermaps' )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Run a nonce-protected dynamic-only check when static delivery may bypass PHP.', 'cybermaps' ); ?>
				<?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 8px;">
				<?php wp_nonce_field( 'cybermaps_verify_sitemap_php_path' ); ?>
				<input type="hidden" name="action" value="cybermaps_verify_sitemap_php_path" />
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Verify PHP sitemap path', 'cybermaps' ); ?></button>
			</form>
		</div>
		<?php
	}

	private static function render_mode_notice(
		string $mode,
		bool $hub_enabled,
		string $status_label,
		string $last_attempt,
		string $last_success
	): void {
		if ( 'all' === $mode ) {
			self::render_full_mode_notice( $status_label, $last_attempt, $last_success );
			return;
		}
		if ( 'well_known' === $mode ) {
			self::render_well_known_mode_notice( $hub_enabled );
			return;
		}
		?>
		<div class="cm-card-sm cm-mb-20" style="background: #fffbeb; border-color: #fde68a;">
			<strong><?php esc_html_e( 'Dynamic-only publication mode selected', 'cybermaps' ); ?></strong>
			<div class="cm-text-sm cm-text-muted" style="margin-top: 6px;">
				<?php esc_html_e( 'Cybermaps publishes no files. Sitemap requests use the dynamic WordPress handlers when the web server routes them to PHP.', 'cybermaps' ); ?>
			</div>
		</div>
		<?php
	}

	private static function render_full_mode_notice( string $status, string $attempt, string $success ): void {
		?>
		<div class="cm-card-sm cm-mb-20" style="background: #eff6ff; border-color: #bfdbfe;">
			<strong><?php esc_html_e( 'Full publication mode selected', 'cybermaps' ); ?></strong>
			<div class="cm-text-sm cm-text-muted" style="margin-top: 6px;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: latest reconciliation status, 2: latest attempt, 3: latest complete success. */
						__( 'Latest reconciliation: %1$s · attempted: %2$s · last complete success: %3$s. A published file is only an intended static copy; the public web server decides whether it is actually served.', 'cybermaps' ),
						$status,
						$attempt,
						$success
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	private static function render_well_known_mode_notice( bool $hub_enabled ): void {
		$message = $hub_enabled
			? __( 'Cybermaps does not publish sitemap files in this mode. Sitemap requests use the dynamic WordPress handlers when the web server routes them to PHP; the registered origin-root /.well-known/ compatibility targets are materialized.', 'cybermaps' )
			: __( 'Cybermaps does not publish sitemap files in this mode. Sitemap requests use the dynamic WordPress handlers when the web server routes them to PHP. The Discovery Hub is disabled, so no /.well-known/ compatibility files are materialized.', 'cybermaps' );
		?>
		<div class="cm-card-sm cm-mb-20" style="background: #fffbeb; border-color: #fde68a;">
			<strong><?php esc_html_e( 'Compatibility publication mode selected', 'cybermaps' ); ?></strong>
			<div class="cm-text-sm cm-text-muted" style="margin-top: 6px;"><?php echo esc_html( $message ); ?></div>
		</div>
		<?php
	}

	/** @return array{total:int,included:int,excluded:int,percentage:int} */
	private static function item_coverage(): array {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$post_types = \Cybermaps\Core\PublicationPostTypes::names();
		$total      = 0;
		foreach ( $post_types as $post_type ) {
			$counts = wp_count_posts( $post_type );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		$excluded = self::excluded_item_count( $post_types );
		$included = max( 0, $total - $excluded );
		$percent  = $total > 0 ? (int) round( ( $included / $total ) * 100 ) : 0;
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		return array(
			'total'      => $total,
			'included'   => $included,
			'excluded'   => $excluded,
			'percentage' => $percent,
		);
	}

	/** @param array<int, string> $post_types Public post types. */
	private static function excluded_item_count( array $post_types ): int {
		if ( empty( $post_types ) ) {
			return 0;
		}
		$query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_key'       => '_cybermaps_exclude_sitemap',
				'meta_value'     => '1',
			)
		);
		return max( 0, (int) $query->found_posts );
	}

	/** @param array<int, array<string, mixed>> $sitemaps Sitemap rows. */
	private static function render_sitemap_table( array $sitemaps ): void {
		?>
		<table class="wp-list-table widefat fixed striped cm-sitemap-status-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Cybermaps sitemap publications and estimated URL counts', 'cybermaps' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Sitemap', 'cybermaps' ); ?></th>
				<th scope="col" class="cm-sitemap-status-type"><?php esc_html_e( 'Type', 'cybermaps' ); ?></th>
				<th scope="col" class="cm-sitemap-status-count"><?php esc_html_e( 'Est. URLs', 'cybermaps' ); ?></th>
				<th scope="col" class="cm-sitemap-status-view"><?php esc_html_e( 'View', 'cybermaps' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $sitemaps as $sitemap ) : ?>
					<?php /* translators: %s: sitemap label. */ $open_label = sprintf( __( 'Open %s in a new tab', 'cybermaps' ), $sitemap['label'] ); ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Sitemap', 'cybermaps' ); ?>"><strong><?php echo esc_html( $sitemap['label'] ); ?></strong><br><code style="font-size: 11px;"><?php echo esc_html( $sitemap['url'] ); ?></code></td>
						<td data-label="<?php esc_attr_e( 'Type', 'cybermaps' ); ?>"><span class="cybermaps-badge"><?php echo esc_html( $sitemap['type'] ); ?></span></td>
						<td data-label="<?php esc_attr_e( 'Est. URLs', 'cybermaps' ); ?>"><?php echo isset( $sitemap['count'] ) ? esc_html( (string) $sitemap['count'] ) : '—'; ?></td>
						<td data-label="<?php esc_attr_e( 'View', 'cybermaps' ); ?>"><a href="<?php echo esc_url( $sitemap['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small" aria-label="<?php echo esc_attr( $open_label ); ?>"><span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_submission_links( bool $indexnow_enabled, bool $robots_configured ): void {
		$indexnow_message = $indexnow_enabled
			? __( 'IndexNow is enabled and submits changed page URLs; it does not submit the sitemap itself.', 'cybermaps' )
			: __( 'IndexNow is currently disabled. Submit the sitemap index manually if desired.', 'cybermaps' );
		$robots_message   = $robots_configured
			? __( 'Cybermaps is configured to add the sitemap reference to WordPress’s virtual robots.txt response. A physical server file can override it.', 'cybermaps' )
			: __( 'Cybermaps is not currently configured to add the sitemap reference to virtual robots.txt output.', 'cybermaps' );
		?>
		<ul style="list-style: disc; margin-left: 20px;">
			<li><a href="https://search.google.com/search-console" target="_blank" rel="noopener"><?php esc_html_e( 'Google Search Console', 'cybermaps' ); ?></a> — <?php esc_html_e( 'Submit your sitemap index URL manually.', 'cybermaps' ); ?></li>
			<li><a href="https://www.bing.com/webmasters" target="_blank" rel="noopener"><?php esc_html_e( 'Bing Webmaster Tools', 'cybermaps' ); ?></a> — <?php echo esc_html( $indexnow_message ); ?></li>
			<li><strong>robots.txt</strong> — <?php echo esc_html( $robots_message ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Report the same sitemap-reference decision used by Robots.
	 *
	 * Manual directives and Content-Signal declarations can modify virtual
	 * robots.txt without opting the site into sitemap advertising.
	 *
	 * @param array<string, mixed> $settings Cybermaps settings.
	 * @param array<string, mixed> $manager  Robots-manager settings.
	 */
	public static function robots_sitemap_reference_configured(
		array $settings,
		array $manager,
		bool $site_public
	): bool {
		return $site_public
			&& (
				! empty( $manager['takeover_enabled'] )
				|| ! empty( $settings['inject_robots'] )
			);
	}

	/**
	 * Convert the internal reconciliation state into a translated admin label.
	 */
	private static function reconciliation_status_label( string $status ): string {
		return match ( sanitize_key( $status ) ) {
			'complete' => __( 'Complete', 'cybermaps' ),
			'partial'  => __( 'Partial', 'cybermaps' ),
			'failed'   => __( 'Failed', 'cybermaps' ),
			'error'    => __( 'Error', 'cybermaps' ),
			'busy'     => __( 'Busy', 'cybermaps' ),
			'running'  => __( 'Running', 'cybermaps' ),
			'skipped'  => __( 'Skipped', 'cybermaps' ),
			'not-run'  => __( 'Not run', 'cybermaps' ),
			default    => __( 'Unknown', 'cybermaps' ),
		};
	}

	/**
	 * Map the reconciliation state to a PageHeader badge tone.
	 */
	private static function reconciliation_status_tone( string $status ): string {
		return match ( sanitize_key( $status ) ) {
			'complete'                    => 'good',
			'partial', 'busy', 'running' => 'warning',
			'failed', 'error'            => 'error',
			default                      => 'neutral',
		};
	}

	/**
	 * Detect a competing SEO plugin that may publish its own sitemap.
	 */
	private static function detect_competing_seo_plugin(): string {
		return CompetingSeoPlugin::detect();
	}

	/**
	 * Translate occupancy builder status for admin display.
	 */
	private static function occupancy_status_label( string $status ): string {
		return match ( sanitize_key( $status ) ) {
			'ready'    => __( 'Ready', 'cybermaps' ),
			'building' => __( 'Building', 'cybermaps' ),
			'stale'    => __( 'Stale', 'cybermaps' ),
			'limited'  => __( 'Limited', 'cybermaps' ),
			'failed'   => __( 'Failed', 'cybermaps' ),
			default    => __( 'Unknown', 'cybermaps' ),
		};
	}

	/**
	 * Never present a completed or in-progress result from an obsolete fence as
	 * current after a concurrent inventory invalidation.
	 *
	 * @param array<string, mixed> $state Occupancy builder state.
	 */
	private static function effective_occupancy_status( array $state ): string {
		$status = (string) ( $state['status'] ?? 'unknown' );
		if ( ! in_array( $status, array( 'ready', 'building', 'limited' ), true ) ) {
			return $status;
		}

		$token = is_scalar( $state['token'] ?? null ) ? (string) $state['token'] : '';
		if (
			(int) ( $state['generation'] ?? -1 ) !== \Cybermaps\Sitemap\PageOccupancyManifest::current_generation()
			|| '' === $token
			|| ! hash_equals( \Cybermaps\Sitemap\PageOccupancyManifest::current_token(), $token )
		) {
			return 'stale';
		}

		return $status;
	}

	/**
	 * Run a nonce-protected dynamic sitemap index probe with a diagnostic header.
	 */
	public function handle_php_path_diagnostic(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cybermaps' ) );
		}
		check_admin_referer( 'cybermaps_verify_sitemap_php_path' );

		$base      = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$index_url = \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' );
		$challenge = wp_generate_password( 32, false, false );
		$probe     = self::diagnostic_probe_request( $index_url, $challenge );
		$response  = wp_safe_remote_get( $probe['url'], $probe['args'] );
		$success   = self::diagnostic_response_matches( $response, $challenge );

		set_transient(
			'cybermaps_sitemap_php_path_diagnostic',
			array(
				'success' => $success,
				'time'    => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=cybermaps-sitemap-status' ) );
		exit;
	}

	/**
	 * @return array{url:string,args:array<string,mixed>}
	 */
	private static function diagnostic_probe_request( string $index_url, string $challenge ): array {
		return array(
			'url'  => add_query_arg( 'cybermaps_php_path_probe', $challenge, $index_url ),
			'args' => array(
				'timeout' => 5,
				'headers' => array(
					'Cache-Control'                    => 'no-cache, no-store',
					'Pragma'                           => 'no-cache',
					'X-Cybermaps-Diagnostic-Challenge' => $challenge,
				),
			),
		);
	}

	private static function diagnostic_response_matches( mixed $response, string $challenge ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$marker = trim( (string) wp_remote_retrieve_header( $response, 'X-Cybermaps-Diagnostic-Response' ) );
		return 200 === $code && \hash_equals( $challenge, $marker );
	}
}
