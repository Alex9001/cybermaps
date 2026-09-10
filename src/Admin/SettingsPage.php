<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

use Cybermaps\Admin\Settings\SettingsTab;
use Cybermaps\Admin\Settings\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin router for the Settings page. Maintains a registry of tab classes
 * and delegates rendering to them. Each tab is a self-contained class
 * implementing SettingsTab — self-documenting, independently testable.
 *
 * Tab classes live in src/Admin/Settings/Tabs/.
 * The interface lives in src/Admin/Settings/SettingsTab.php.
 */
class SettingsPage {

	/** @var array<string, SettingsTab> */
	private array $tabs = array();

	public function __construct() {
		$this->tabs = array(
			'dashboard' => new Tabs\Dashboard(),
			'sitemaps'  => new Tabs\Sitemaps(),
			'shortcode' => new Tabs\Shortcode(),
			'ai'        => new Tabs\Discovery(),
			'schema'    => new Tabs\Identity(),
			'review'    => new Tabs\ContentReview(),
			'advanced'  => new Tabs\Advanced(),
		);
	}

	/**
	 * Get all registered tabs.
	 *
	 * @return array<string, SettingsTab>
	 */
	public function get_tabs(): array {
		return $this->tabs;
	}

	/**
	 * Admin menu callback (static for Settings API registration).
	 */
	public static function render_admin_page(): void {
		( new self() )->render();
	}

	/**
	 * Render the settings page shell with tab navigation and active tab content.
	 */
	public function render(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin workspace selection.
		$view = isset( $_GET['view'] ) && is_scalar( $_GET['view'] )
			? sanitize_key( wp_unslash( (string) $_GET['view'] ) )
			: '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'setup' === $view ) {
			( new \Cybermaps\Admin\SetupWizard\SetupWizardPage() )->render();
			return;
		}
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin workspace selection.
		$active_tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] )
			? sanitize_key( wp_unslash( (string) $_GET['tab'] ) )
			: 'dashboard';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $this->tabs[ $active_tab ] ) ) {
			$active_tab = 'dashboard';
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result from a nonce-protected admin action.
		$regeneration_notice = self::get_regeneration_notice( $_GET );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result from a nonce-protected admin action.
		$intent_recovery_notice = self::get_intent_recovery_notice( $_GET );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cybermaps', 'cybermaps' ); ?></h1>
			<?php settings_errors(); ?>
			<?php self::render_notices( $regeneration_notice, $intent_recovery_notice ); ?>

			<div class="cybermaps-command-center">
				<div class="cybermaps-main-content">
					<nav class="cybermaps-react-shell" aria-label="<?php esc_attr_e( 'Cybermaps settings sections', 'cybermaps' ); ?>">
						<div class="cybermaps-tab-panel">
							<div class="components-tab-panel__tabs">
								<?php foreach ( $this->tabs as $slug => $tab ) : ?>
									<a
										id="cybermaps-tab-button-<?php echo esc_attr( $slug ); ?>"
										class="components-tab-panel__tabs-item<?php echo $slug === $active_tab ? ' active-tab' : ''; ?>"
										href="
										<?php
										echo esc_url(
											add_query_arg(
												array(
													'page' => 'cybermaps-settings',
													'tab'  => $slug,
												),
												admin_url( 'admin.php' )
											)
										);
										?>
										"
										<?php if ( $slug === $active_tab ) : ?>
											aria-current="page"
										<?php endif; ?>
									><?php echo esc_html( $tab->label() ); ?></a>
								<?php endforeach; ?>
							</div>
						</div>
					</nav>

					<form method="post" action="options.php" id="cybermaps-settings-form">
						<?php settings_fields( 'cybermaps_options_group' ); ?>
						<input type="hidden" name="cybermaps_active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />

						<div
							id="cybermaps-tab-<?php echo esc_attr( $active_tab ); ?>"
							class="cybermaps-tab-content active"
							aria-labelledby="cybermaps-tab-button-<?php echo esc_attr( $active_tab ); ?>"
						>
							<?php $this->tabs[ $active_tab ]->render(); ?>
						</div>

						<div class="cybermaps-submit-wrapper" style="margin-top: 20px;<?php echo 'dashboard' === $active_tab ? ' display:none;' : ''; ?>">
							<?php submit_button(); ?>
						</div>
						<input type="hidden" id="cybermaps-identity-json-payload" disabled />
						<input type="hidden" id="cybermaps-robots-json-payload" disabled />
						<?php
						/*
						 * Keep this as the final successful control in the
						 * form. If PHP's max_input_vars truncates a large AI
						 * workspace, the sanitizer can detect the missing
						 * marker and preserve every stored option instead of
						 * accepting a partial nested payload.
						 */
						?>
						<input type="hidden" name="cybermaps_form_complete" value="1" />
					</form>
					<form
						id="cybermaps-verify-indexnow-key-form"
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					>
						<?php wp_nonce_field( 'cybermaps_verify_indexnow_key' ); ?>
						<input type="hidden" name="action" value="cybermaps_verify_indexnow_key" />
					</form>

					<?php
					/*
					 * Keep Reports actions associated with separate forms so
					 * pressing Enter in an ordinary settings field cannot run
					 * or delete a report instead of saving settings.
					 */
					?>
					<form
						id="cybermaps-run-content-audit-form"
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					></form>
					<form
						id="cybermaps-delete-content-audit-form"
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					></form>
				</div>

				<div class="cybermaps-sidebar">
					<?php $this->render_sidebar( $active_tab ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render bounded action-result notices above the settings workspace.
	 *
	 * @param array{type:string,message:string}|null $regeneration Regeneration result.
	 * @param array{type:string,message:string}|null $recovery     Intent recovery result.
	 */
	private static function render_notices( ?array $regeneration, ?array $recovery ): void {
		foreach ( array( $regeneration, $recovery ) as $notice ) {
			if ( null === $notice ) {
				continue;
			}
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php
		}
	}

	/**
	 * Build a truthful notice from the bounded StaticBridge redirect payload.
	 *
	 * The regeneration action always refreshes the sitemap cache and rewrite
	 * rules before asking the Static File Engine to reconcile its publications.
	 * Static completion must therefore be reported separately instead of
	 * treating every redirect as an unconditional success.
	 *
	 * @param array<string, mixed> $query Admin request query values.
	 * @return array{type:string,message:string}|null
	 */
	public static function get_regeneration_notice( array $query ): ?array {
		if ( ! array_key_exists( 'cybermaps_regenerated', $query ) ) {
			return null;
		}

		$status  = self::get_notice_query_key( $query, 'cybermaps_regeneration_status' );
		$mode    = self::get_notice_query_key( $query, 'cybermaps_regeneration_mode' );
		$success = '1' === self::get_notice_query_value( $query, 'cybermaps_regeneration_success' );

		$counts = array();
		foreach ( array( 'desired', 'written', 'unchanged', 'conflicted', 'failed', 'skipped', 'deleted', 'retained' ) as $count_key ) {
			$counts[ $count_key ] = absint( self::get_notice_query_value( $query, 'cybermaps_regeneration_' . $count_key ) );
		}

		$counts_summary = sprintf(
			/* translators: 1: desired files, 2: written files, 3: unchanged files, 4: conflicted files, 5: failed files, 6: skipped files, 7: deleted files, 8: retained files. */
			__( 'Static-file counts — desired: %1$d; written: %2$d; unchanged: %3$d; conflicted: %4$d; failed: %5$d; skipped: %6$d; deleted: %7$d; retained: %8$d.', 'cybermaps' ),
			$counts['desired'],
			$counts['written'],
			$counts['unchanged'],
			$counts['conflicted'],
			$counts['failed'],
			$counts['skipped'],
			$counts['deleted'],
			$counts['retained']
		);

		if ( 'complete' === $status && $success ) {
			return self::complete_regeneration_notice( $mode, $counts_summary );
		}

		if ( 'partial' === $status ) {
			return array(
				'type'    => 'warning',
				'message' => __( 'Sitemap cache and rewrite rules were refreshed, but static publication completed only partially. Review the Static File Engine status before relying on generated files.', 'cybermaps' ) . ' ' . $counts_summary,
			);
		}

		if ( 'pending' === $status ) {
			return array(
				'type'    => 'info',
				'message' => __( 'Sitemap cache and rewrite rules were refreshed. Static publication is in progress, and the remaining work was queued for background continuation.', 'cybermaps' ) . ' ' . $counts_summary,
			);
		}

		if ( 'failed' === $status || ( 'complete' === $status && ! $success ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Sitemap cache and rewrite rules were refreshed, but static publication failed. Review the Static File Engine status for details.', 'cybermaps' ) . ' ' . $counts_summary,
			);
		}

		if ( 'busy' === $status ) {
			return array(
				'type'    => 'warning',
				'message' => __( 'Sitemap cache and rewrite rules were refreshed, but another generated-file operation is already running. Cybermaps requested a background retry; review the Static File Engine status to confirm scheduling.', 'cybermaps' ) . ' ' . $counts_summary,
			);
		}

		if ( 'skipped' === $status && $success ) {
			return array(
				'type'    => 'info',
				'message' => __( 'Sitemap cache and rewrite rules were refreshed. Static publication was intentionally skipped; dynamic delivery remains active for this installation.', 'cybermaps' ) . ' ' . $counts_summary,
			);
		}

		if ( 'skipped' === $status ) {
			return array(
				'type'    => 'warning',
				'message' => __( 'Sitemap cache and rewrite rules were refreshed, but static publication was skipped without a successful result. Review the Static File Engine status for details.', 'cybermaps' ) . ' ' . $counts_summary,
			);
		}

		return array(
			'type'    => 'warning',
			'message' => __( 'Sitemap cache and rewrite rules were refreshed, but no valid static publication result was returned. Review the Static File Engine status before relying on generated files.', 'cybermaps' ),
		);
	}

	/**
	 * Distinguish successful dynamic reconciliation from static publication.
	 *
	 * @return array{type:string,message:string}
	 */
	private static function complete_regeneration_notice( string $mode, string $counts_summary ): array {
		$message = 'off' === $mode
			? __( 'Sitemap cache and rewrite rules were refreshed. Dynamic delivery is active and owned static files were reconciled.', 'cybermaps' )
			: __( 'Sitemap cache and rewrite rules were refreshed, and static publication completed.', 'cybermaps' );

		return array(
			'type'    => 'success',
			'message' => $message . ' ' . $counts_summary,
		);
	}

	/**
	 * Build a bounded notice from the confirmed static-intent recovery redirect.
	 *
	 * @param array<string, mixed> $query Admin request query values.
	 * @return array{type:string,message:string}|null
	 */
	public static function get_intent_recovery_notice( array $query ): ?array {
		if ( ! array_key_exists( 'cybermaps_intent_resolution', $query ) ) {
			return null;
		}

		$success = '1' === self::get_notice_query_value( $query, 'cybermaps_intent_resolution_success' );
		$code    = self::get_notice_query_key( $query, 'cybermaps_intent_resolution_code' );
		if ( $success && 'resolved' === $code ) {
			return array(
				'type'    => 'success',
				'message' => __( 'The pending static operation was reconciled. Regenerate publications and review the Static File Engine status.', 'cybermaps' ),
			);
		}

		$messages = array(
			'confirmation_required' => __( 'Static recovery was not authorized because the exact worker-quiescence confirmation was not supplied.', 'cybermaps' ),
			'intent_changed'        => __( 'Static recovery stopped because the pending operation changed. Reload the page before trying again.', 'cybermaps' ),
			'intent_malformed'      => __( 'The pending static operation is malformed and was preserved for support review.', 'cybermaps' ),
			'recovery_busy'         => __( 'Static recovery could not acquire the operation fence. Confirm every worker is stopped, wait for the lease to expire, and try again.', 'cybermaps' ),
			'recovery_incomplete'   => __( 'Static recovery could not prove a safe terminal state. The journal and current file were preserved for review.', 'cybermaps' ),
		);

		return array(
			'type'    => 'error',
			'message' => $messages[ $code ] ?? __( 'Static recovery did not complete. The journal was preserved for review.', 'cybermaps' ),
		);
	}

	/**
	 * Get a scalar query value without trusting request types.
	 *
	 * @param array<string, mixed> $query Admin request query values.
	 */
	private static function get_notice_query_value( array $query, string $key ): string {
		if ( ! isset( $query[ $key ] ) || ! is_scalar( $query[ $key ] ) ) {
			return '';
		}

		return (string) wp_unslash( (string) $query[ $key ] );
	}

	/**
	 * Get a sanitized key-like query value.
	 *
	 * @param array<string, mixed> $query Admin request query values.
	 */
	private static function get_notice_query_key( array $query, string $key ): string {
		return sanitize_key( self::get_notice_query_value( $query, $key ) );
	}

	/**
	 * Render the sidebar with actions, live sitemaps, and discovery links.
	 */
	private function render_sidebar( string $active_tab ): void {
		$options                 = \Cybermaps\Core\ConfigurationStore::settings();
		$base                    = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$news_base               = \Cybermaps\Sitemap\Orchestrator::get_news_sitemap_base();
		$enable_ai               = ! empty( $options['enable_discovery_hub'] );
		$enable_llms_full        = ! empty( $options['enable_llms_full'] );
		$enable_llms_tldr        = ! empty( $options['enable_llms_tldr'] );
		$enable_news             = ! empty( $options['enable_google_news'] );
		$enable_rss              = ! empty( $options['enable_rss_sitemap'] );
		$rss_base                = \Cybermaps\Sitemap\Orchestrator::get_rss_sitemap_base();
		$registry                = \Cybermaps\Core\EndpointRegistry::get_instance();
		$sitemap_url             = \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' );
		$news_url                = \Cybermaps\Core\URLManager::get_home_url( '/' . $news_base . '.xml' );
		$rss_url                 = \Cybermaps\Core\URLManager::get_home_url( '/' . $rss_base . '.xml' );
		$rest_search_example_url = add_query_arg(
			array(
				'q'     => 'site',
				'limit' => 10,
			),
			$registry->get_url( 'rest_search' )
		);
		$intent_recovery_status  = \Cybermaps\Discovery\StaticBridge::get_instance()->get_pending_intent_recovery_status();
		?>
		<div id="side-sortables" class="meta-box-sortables ui-sortable">
			<?php $this->render_static_intent_recovery( $intent_recovery_status, $active_tab ); ?>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Actions', 'cybermaps' ); ?></span></h2>
				<div class="inside">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cybermaps_regenerate_sitemaps">
						<input type="hidden" name="cybermaps_return_tab" value="<?php echo esc_attr( $active_tab ); ?>">
						<?php wp_nonce_field( 'cybermaps_regenerate', 'cybermaps_regenerate_nonce' ); ?>
						<button type="submit" class="button button-primary" style="width:100%;justify-content:center;box-sizing:border-box;text-align:center;">
							<?php esc_html_e( 'Regenerate Publications', 'cybermaps' ); ?>
						</button>
					</form>
				</div>
			</div>

			<?php $this->render_beta_feedback_sidebar(); ?>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Sitemaps', 'cybermaps' ); ?></span></h2>
				<div class="inside">
					<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-media-spreadsheet"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'Sitemap Index', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<?php if ( $enable_news ) : ?>
					<a href="<?php echo esc_url( $news_url ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-media-document"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'Google News', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<?php endif; ?>
					<?php if ( $enable_rss ) : ?>
					<a href="<?php echo esc_url( $rss_url ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-rss"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'RSS Sitemap', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $enable_ai ) : ?>
			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'LLMS Endpoints', 'cybermaps' ); ?></span></h2>
				<div class="inside">
					<a href="<?php echo esc_url( $registry->get_url( 'llms' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-text-page"></span>
						<span class="cm-sidebar-link-label">llms.txt</span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<?php if ( $enable_llms_full ) : ?>
					<a href="<?php echo esc_url( $registry->get_url( 'llms_full' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-text-page"></span>
						<span class="cm-sidebar-link-label">llms-full.txt</span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<?php endif; ?>
					<?php if ( $enable_llms_tldr ) : ?>
					<a href="<?php echo esc_url( $registry->get_url( 'llms_tldr' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-text-page"></span>
						<span class="cm-sidebar-link-label">llms-tldr.txt</span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<?php endif; ?>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'AI Discovery', 'cybermaps' ); ?></span></h2>
				<div class="inside">
					<a href="<?php echo esc_url( $registry->get_url( 'adp_discovery' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-rest-api"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'ADP 3.0 Manifest', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="<?php echo esc_url( $registry->get_url( 'manifest' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-rest-api"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'Cybermaps Manifest', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="<?php echo esc_url( $registry->get_url( 'ai_sitemap' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-networking"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'AI Sitemap', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="<?php echo esc_url( $registry->get_url( 'knowledge_graph' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-chart-area"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'Knowledge Graph', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="<?php echo esc_url( $registry->get_url( 'feed' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-rss"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'JSON Feed', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Machine-readable interfaces', 'cybermaps' ); ?></span></h2>
				<div class="inside">
					<a href="<?php echo esc_url( $registry->get_url( 'usage_policy' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-shield"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'Usage Policy', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="<?php echo esc_url( $registry->get_url( 'actions' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-admin-tools"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'AI Actions', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="<?php echo esc_url( $registry->get_url( 'skill' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-welcome-learn-more"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'Site Guide', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="<?php echo esc_url( $registry->get_url( 'api_catalog' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-list-view"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'API Catalog', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="<?php echo esc_url( $rest_search_example_url ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-search"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'REST Search example', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
				</div>
			</div>
			<?php endif; ?>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Resources', 'cybermaps' ); ?></span></h2>
				<div class="inside">
					<a href="https://cybermaps.dev" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-admin-home"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'Plugin Homepage', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
					<a href="https://cybermaps.dev/docs" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
						<span class="dashicons dashicons-book"></span>
						<span class="cm-sidebar-link-label"><?php esc_html_e( 'Documentation', 'cybermaps' ); ?></span>
						<span class="dashicons dashicons-external cm-sidebar-ext-icon"></span>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/** Render open-beta feedback links and the diagnostic collection shortcut. */
	private function render_beta_feedback_sidebar(): void {
		?>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Open beta feedback', 'cybermaps' ); ?></span></h2>
			<div class="inside">
				<p class="description"><?php esc_html_e( 'Help improve Cybermaps. Report a problem or suggest an improvement on GitHub.', 'cybermaps' ); ?></p>
				<a href="<?php echo esc_url( 'https://github.com/Alex9001/cybermaps/issues/new?template=bug_report.yml' ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
					<span class="dashicons dashicons-flag" aria-hidden="true"></span>
					<span class="cm-sidebar-link-label"><?php esc_html_e( 'Report an issue', 'cybermaps' ); ?></span>
					<span class="dashicons dashicons-external cm-sidebar-ext-icon" aria-hidden="true"></span>
				</a>
				<a href="<?php echo esc_url( 'https://github.com/Alex9001/cybermaps/issues/new?template=feature_request.yml' ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<span class="cm-sidebar-link-label"><?php esc_html_e( 'Suggest an improvement', 'cybermaps' ); ?></span>
					<span class="dashicons dashicons-external cm-sidebar-ext-icon" aria-hidden="true"></span>
				</a>
				<a href="<?php echo esc_url( 'https://github.com/Alex9001/cybermaps/issues' ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<span class="cm-sidebar-link-label"><?php esc_html_e( 'Browse existing issues', 'cybermaps' ); ?></span>
					<span class="dashicons dashicons-external cm-sidebar-ext-icon" aria-hidden="true"></span>
				</a>
				<p class="description"><?php esc_html_e( 'Before reporting, open Cybermaps → Debugging and choose Copy GitHub support bundle. Include it in your report after reviewing it for private information.', 'cybermaps' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybermaps-system-status' ) ); ?>" target="_blank" rel="noopener noreferrer" class="cm-sidebar-link">
					<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
					<span class="cm-sidebar-link-label"><?php esc_html_e( 'Open Debugging', 'cybermaps' ); ?></span>
					<span class="dashicons dashicons-external cm-sidebar-ext-icon" aria-hidden="true"></span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the exceptional operator recovery control only while a journal is
	 * pending or malformed. Normal settings-page layout is unchanged.
	 *
	 * @param array<string,mixed> $status Body-free intent status.
	 */
	private function render_static_intent_recovery( array $status, string $active_tab ): void {
		$state = (string) ( $status['status'] ?? 'none' );
		if ( 'none' === $state ) {
			return;
		}
		?>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Static Recovery Required', 'cybermaps' ); ?></span></h2>
			<div class="inside">
				<p class="cm-desc-warn"><?php echo esc_html( (string) ( $status['warning'] ?? '' ) ); ?></p>
				<?php if ( 'pending' === $state ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: static operation type, 2: relative publication path. */
								__( 'Pending %1$s operation for %2$s.', 'cybermaps' ),
								(string) ( $status['type'] ?? '' ),
								(string) ( $status['path'] ?? '' )
							)
						);
						?>
					</p>
					<p><?php esc_html_e( 'Stop or quiesce every Cybermaps cron, web, and CLI worker first. This confirmation is manual because WordPress has no portable atomic transaction spanning the database and filesystem.', 'cybermaps' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cybermaps_resolve_static_intent">
						<input type="hidden" name="cybermaps_return_tab" value="<?php echo esc_attr( $active_tab ); ?>">
						<input type="hidden" name="cybermaps_static_intent_id" value="<?php echo esc_attr( (string) ( $status['intent_id'] ?? '' ) ); ?>">
						<input type="hidden" name="cybermaps_static_intent_nonce" value="<?php echo esc_attr( (string) ( $status['nonce'] ?? '' ) ); ?>">
						<label for="cybermaps-static-intent-confirmation"><strong><?php esc_html_e( 'Type this exact confirmation:', 'cybermaps' ); ?></strong></label>
						<code><?php echo esc_html( (string) ( $status['confirmation'] ?? '' ) ); ?></code>
						<input id="cybermaps-static-intent-confirmation" class="regular-text" type="text" name="cybermaps_static_intent_confirmation" required autocomplete="off">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Resolve Pending Static Operation', 'cybermaps' ); ?></button>
					</form>
				<?php else : ?>
					<p><?php esc_html_e( 'The stored recovery journal is malformed. Cybermaps will not delete it or mutate generated files automatically. Preserve a database backup and inspect the cybermaps_static_write_intent option before taking manual action.', 'cybermaps' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
