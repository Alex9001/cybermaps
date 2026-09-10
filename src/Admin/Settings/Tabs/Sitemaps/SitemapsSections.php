<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs\Sitemaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SitemapsSections {

	public static function optimization_section_callback() {
		self::render_competing_seo_warning();
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Set a practical baseline for every public content group.', 'cybermaps' ) . '</strong> ' . esc_html__( 'Choose a starting profile, then override only the rows that should publish or be described differently.', 'cybermaps' ) . '</div>';
		self::render_discovery_center();
	}

	/**
	 * Warn when another SEO plugin may publish a competing XML sitemap.
	 */
	private static function render_competing_seo_warning(): void {
		$competing_plugin = \Cybermaps\Admin\CompetingSeoPlugin::detect();
		if ( '' === $competing_plugin ) {
			return;
		}

		echo '<div class="cm-card-sm cm-mb-20" style="background: #fef2f2; border-color: #fecaca;">';
		echo '<strong>' . esc_html__( 'Another SEO plugin is active', 'cybermaps' ) . '</strong>';
		echo '<div class="cm-text-sm cm-text-muted" style="margin-top: 6px;">';
		echo esc_html(
			sprintf(
				/* translators: %s: detected SEO plugin name. */
				__( '%s was detected. Verify that only one primary XML sitemap is submitted to search engines.', 'cybermaps' ),
				$competing_plugin
			)
		);
		echo '</div></div>';
	}

	/**
	 * Render an accessible, keyboard-focusable explanation trigger.
	 */
	private static function help_tip( string $tip, string $position = '' ): string {
		return \Cybermaps\Admin\AccessibleTooltip::get( $tip, $position );
	}

	/**
	 * Allowed markup for the accessible help-tip trigger.
	 *
	 * A dedicated allowlist preserves the native WordPress popover contract
	 * while keeping each rendered helper on a recognized KSES path.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function help_tip_allowed_html(): array {
		return array(
			'span'   => array(
				'class'       => true,
				'id'          => true,
				'popover'     => true,
				'role'        => true,
				'tabindex'    => true,
				'autofocus'   => true,
				'aria-label'  => true,
				'aria-hidden' => true,
			),
			'button' => array(
				'type'                => true,
				'class'               => true,
				'aria-label'          => true,
				'aria-haspopup'       => true,
				'popovertarget'       => true,
				'popovertargetaction' => true,
			),
		);
	}

	/**
	 * Content inclusion, exclusion, and additional-URL controls.
	 */
	public static function scope_section_callback(): void {
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Define the URLs Cybermaps may list.', 'cybermaps' ) . '</strong> ' . wp_kses_post( __( 'These controls change Cybermaps sitemap membership. They do not add <code>noindex</code>, block a URL, or remove it from a search index.', 'cybermaps' ) ) . '</div>';
		$options = \Cybermaps\Core\ConfigurationStore::settings();

		echo '<div class="cm-sitemap-settings-stack">';
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-filter" aria-hidden="true"></span> ' . esc_html__( 'Homepage & Archives', 'cybermaps' ) . '</h3>';

		$include_homepage = isset( $options['include_homepage'] ) && '1' === (string) $options['include_homepage'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[include_homepage]" value="1" class="cm-toggle-input" ' . checked( $include_homepage, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Include Homepage in the Misc Sitemap', 'cybermaps' ) . '</span></label>';

		$include_authors = isset( $options['include_authors'] ) && '1' === (string) $options['include_authors'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[include_authors]" value="1" class="cm-toggle-input" ' . checked( $include_authors, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Include Author Archives', 'cybermaps' ) . '</span></label>';

		$include_archives = isset( $options['include_archives'] ) && '1' === (string) $options['include_archives'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[include_archives]" value="1" class="cm-toggle-input" ' . checked( $include_archives, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Include Date Archives', 'cybermaps' ) . '</span></label>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Date archives follow WordPress Core post-archive behavior.', 'cybermaps' ) . '</p>';

		$include_empty_terms = isset( $options['include_empty_terms'] ) && '1' === (string) $options['include_empty_terms'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[include_empty_terms]" value="1" class="cm-toggle-input" ' . checked( $include_empty_terms, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Include Empty Term Archives', 'cybermaps' ) . '</span></label>';
		echo '</div>';

		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span> ' . esc_html__( 'XML Sitemap Exclusions', 'cybermaps' ) . '</h3>';
		$exclude_posts = isset( $options['exclude_post_ids'] ) ? $options['exclude_post_ids'] : '';
		echo '<label class="cm-sitemap-control" for="exclude_post_ids"><strong>' . esc_html__( 'Post IDs', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'Comma-separated numeric post IDs to omit from standard XML sitemap children.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</label>';
		echo '<input id="exclude_post_ids" class="regular-text" type="text" name="cybermaps_settings[exclude_post_ids]" value="' . esc_attr( $exclude_posts ) . '" placeholder="' . esc_attr__( 'e.g. 12, 45, 89', 'cybermaps' ) . '">';

		$exclude_cats = isset( $options['exclude_categories'] ) ? $options['exclude_categories'] : '';
		echo '<label class="cm-sitemap-control" for="exclude_categories"><strong>' . esc_html__( 'Category Slugs', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'Comma-separated category slugs. Posts assigned to these categories are omitted from standard XML sitemap children.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</label>';
		echo '<input id="exclude_categories" class="regular-text" type="text" name="cybermaps_settings[exclude_categories]" value="' . esc_attr( $exclude_cats ) . '" placeholder="' . esc_attr__( 'e.g. news, archive', 'cybermaps' ) . '">';
		echo '</div>';

		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> ' . esc_html__( 'Additional URLs', 'cybermaps' ) . '</h3>';
		$external_pages = isset( $options['external_pages'] ) ? $options['external_pages'] : '';
		echo '<label class="cm-sitemap-control" for="external_pages"><strong>' . esc_html__( 'External Pages', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'Add public non-WordPress pages to the Misc Sitemap. Enter one absolute HTTP or HTTPS URL per line.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</label>';
		echo '<textarea id="external_pages" class="large-text" dir="ltr" name="cybermaps_settings[external_pages]" rows="3" placeholder="https://example.com/custom-landing-page">' . esc_textarea( $external_pages ) . '</textarea>';
		echo '</div></div>';
	}

	/**
	 * Core sitemap path, response, redirect, and last-modified controls.
	 */
	public static function general_section_callback(): void {
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Control sitemap addresses and delivery.', 'cybermaps' ) . '</strong> ' . esc_html__( 'Configure the main XML path, redirects, response caching, and last-modified signals in one place.', 'cybermaps' ) . '</div>';
		$options      = \Cybermaps\Core\ConfigurationStore::settings();
		$sitemap_base = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$news_base    = \Cybermaps\Sitemap\Orchestrator::get_news_sitemap_base();

		echo '<div class="cm-sitemap-settings-stack">';
		self::render_general_paths( $options, $sitemap_base );
		self::render_general_routing( $options, $sitemap_base, $news_base );
		self::render_general_dynamic_delivery( $options );
		self::render_general_static_publication();
		self::render_general_last_modified( $options );
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_general_paths( array $options, string $sitemap_base ): void {
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> ' . esc_html__( 'Paths & Sitemap Index', 'cybermaps' ) . '</h3>';
		echo '<label class="cm-sitemap-control" for="sitemap_url_base"><strong>' . esc_html__( 'Sitemap URL Base', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'Path relative to the site root. Cybermaps accepts a clean slug or an .xml filename and normalizes the public route.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</label>';
		echo '<input id="sitemap_url_base" class="regular-text" dir="ltr" type="text" name="cybermaps_settings[sitemap_url_base]" value="' . esc_attr( $sitemap_base ) . '" placeholder="sitemap.xml">';

		$external_sitemaps = isset( $options['external_sitemaps'] ) ? $options['external_sitemaps'] : '';
		echo '<label class="cm-sitemap-control" for="external_sitemaps"><strong>' . esc_html__( 'External Sitemap References', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'Add structurally validated external references to the Cybermaps sitemap index. Enter one unique absolute HTTP or HTTPS .xml URL per line.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</label>';
		echo '<textarea id="external_sitemaps" class="large-text" dir="ltr" name="cybermaps_settings[external_sitemaps]" rows="3" placeholder="https://example.com/other-sitemap.xml">' . esc_textarea( $external_sitemaps ) . '</textarea>';
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_general_routing( array $options, string $sitemap_base, string $news_base ): void {
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-randomize" aria-hidden="true"></span> ' . esc_html__( 'Traffic Routing', 'cybermaps' ) . '</h3>';
		self::render_wordpress_core_redirect( $options );
		self::render_default_sitemap_redirect( $options, $sitemap_base );
		self::render_news_sitemap_redirect( $options, $news_base );
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_wordpress_core_redirect( array $options ): void {
		$redirect_wp_sitemap = ! array_key_exists( 'redirect_wp_sitemap', $options ) || '1' === (string) $options['redirect_wp_sitemap'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[redirect_wp_sitemap]" value="1" class="cm-toggle-input" ' . checked( $redirect_wp_sitemap, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Redirect WordPress Core Sitemaps', 'cybermaps' ) . '</span></label>';
		echo '<p class="cybermaps-desc">' . wp_kses_post( __( 'Redirects <code>/wp-sitemap.xml</code> and its child routes to the Cybermaps sitemap.', 'cybermaps' ) ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_default_sitemap_redirect( array $options, string $sitemap_base ): void {
		$redirect_default  = isset( $options['redirect_default_sitemap'] ) && '1' === (string) $options['redirect_default_sitemap'];
		$default_redundant = in_array( $sitemap_base, array( 'sitemap.xml', 'sitemap' ), true );
		echo '<label class="cm-toggle-wrapper' . ( $default_redundant ? ' is-disabled' : '' ) . '">';
		echo '<input type="checkbox" name="cybermaps_settings[redirect_default_sitemap]" value="1" class="cm-toggle-input" ' . checked( $redirect_default, true, false ) . ( $default_redundant ? ' disabled' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . wp_kses_post( __( 'Redirect <code>/sitemap.xml</code>', 'cybermaps' ) ) . '</span></label>';
		if ( $default_redundant ) {
			echo '<input type="hidden" name="cybermaps_settings[redirect_default_sitemap]" value="' . esc_attr( $redirect_default ? '1' : '0' ) . '">';
		}
		echo '<p class="cybermaps-desc' . ( $default_redundant ? ' cm-sitemap-redundant' : '' ) . '">' . ( $default_redundant ? esc_html__( 'Already active because the Sitemap URL Base uses this path.', 'cybermaps' ) : wp_kses_post( __( 'Redirects the conventional <code>/sitemap.xml</code> path to your custom sitemap path.', 'cybermaps' ) ) ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_news_sitemap_redirect( array $options, string $news_base ): void {
		$redirect_news  = isset( $options['redirect_news_sitemap'] ) && '1' === (string) $options['redirect_news_sitemap'];
		$news_redundant = 'sitemap-news' === $news_base;
		echo '<label class="cm-toggle-wrapper' . ( $news_redundant ? ' is-disabled' : '' ) . '">';
		echo '<input type="checkbox" name="cybermaps_settings[redirect_news_sitemap]" value="1" class="cm-toggle-input" ' . checked( $redirect_news, true, false ) . ( $news_redundant ? ' disabled' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . wp_kses_post( __( 'Redirect <code>/sitemap-news.xml</code>', 'cybermaps' ) ) . '</span></label>';
		if ( $news_redundant ) {
				echo '<input type="hidden" name="cybermaps_settings[redirect_news_sitemap]" value="' . esc_attr( $redirect_news ? '1' : '0' ) . '">';
		}
		echo '<p class="cybermaps-desc' . ( $news_redundant ? ' cm-sitemap-redundant' : '' ) . '">' . ( $news_redundant ? esc_html__( 'Already active because the News Sitemap URL Base uses this path.', 'cybermaps' ) : wp_kses_post( __( 'Redirects the conventional <code>/sitemap-news.xml</code> path to your custom News sitemap.', 'cybermaps' ) ) ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_general_dynamic_delivery( array $options ): void {
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-performance" aria-hidden="true"></span> ' . esc_html__( 'Dynamic Delivery', 'cybermaps' ) . '</h3>';
		$inject_robots = isset( $options['inject_robots'] ) && '1' === (string) $options['inject_robots'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[inject_robots]" value="1" class="cm-toggle-input" ' . checked( $inject_robots, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Advertise the Sitemap in Virtual robots.txt', 'cybermaps' ) . '</span></label>';

		$enable_caching = isset( $options['enable_caching'] ) && '1' === (string) $options['enable_caching'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[enable_caching]" value="1" class="cm-toggle-input" ' . checked( $enable_caching, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Cache Dynamically Generated Sitemap Responses', 'cybermaps' ) . '</span></label>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Reuses generated responses until relevant content or settings invalidate them.', 'cybermaps' ) . '</p>';
		echo '<p class="cybermaps-desc cm-sitemap-analytics-link">';
		printf(
			/* translators: %s: Discovery Analytics administration URL. */
			wp_kses_post( __( 'Crawler recording and retention are managed in <a href="%s">Discovery Analytics</a>.', 'cybermaps' ) ),
			esc_url( admin_url( 'admin.php?page=cybermaps-discovery-analytics#data-controls' ) )
		);
		echo '</p></div>';
	}

	private static function render_general_static_publication(): void {
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-database" aria-hidden="true"></span> ' . esc_html__( 'Static Publication', 'cybermaps' ) . '</h3>';
		echo '<label class="cm-sitemap-control" for="static_engine_mode"><strong>' . esc_html__( 'Static File Engine', 'cybermaps' ) . '</strong></label>';
		\Cybermaps\Admin\Settings\Fields\FieldRenderer::render_static_engine_mode(
			array(
				'label_for'   => 'static_engine_mode',
				'description' => __( '<strong>Core discovery files (default)</strong> writes small root and canonical <code>/.well-known/</code> resources so nginx and similar origins that bypass WordPress can still serve their bodies. Dynamic routing remains preferred because only PHP can guarantee protocol-specific headers; the status page reports body availability separately from header conformance. <strong>Full publication cache</strong> also writes eligible sitemap and discovery caches. Static-file requests bypass PHP and cannot appear in crawler analytics.', 'cybermaps' ),
			)
		);
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_general_last_modified( array $options ): void {
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-update" aria-hidden="true"></span> ' . esc_html__( 'Last-Modified Signals', 'cybermaps' ) . '</h3>';
		$update_comment_post = isset( $options['update_comment_post'] ) && '1' === (string) $options['update_comment_post'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[update_comment_post]" value="1" class="cm-toggle-input" ' . checked( $update_comment_post, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Update Post Modified Time for New Comments', 'cybermaps' ) . '</span></label>';

		$update_comment_page = isset( $options['update_comment_page'] ) && '1' === (string) $options['update_comment_page'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[update_comment_page]" value="1" class="cm-toggle-input" ' . checked( $update_comment_page, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Update Page Modified Time for New Comments', 'cybermaps' ) . '</span></label>';
		echo '</div>';
	}

	/**
	 * Media discovery, metadata, and maintenance controls.
	 */
	public static function media_section_callback(): void {
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Publish richer image and video discovery data.', 'cybermaps' ) . '</strong> ' . esc_html__( 'Choose how media is found, whether Cybermaps AI hints are emitted, and when existing content should be rescanned.', 'cybermaps' ) . '</div>';
		$options   = \Cybermaps\Core\ConfigurationStore::settings();
		$intensity = isset( $options['media_discovery_intensity'] ) ? (string) $options['media_discovery_intensity'] : 'none';

		echo '<div class="cm-sitemap-settings-stack"><div class="cm-sitemap-settings-group">';
		echo '<label class="cm-sitemap-control" for="media_discovery_intensity"><strong>' . esc_html__( 'Media Discovery Depth', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'Standard uses featured images and attached media. Advanced also parses stored post content for embedded images and videos.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</label>';
		echo '<select id="media_discovery_intensity" name="cybermaps_settings[media_discovery_intensity]" class="regular-text">';
		echo '<option value="none" ' . selected( $intensity, 'none', false ) . '>' . esc_html__( 'Off — no media sitemaps', 'cybermaps' ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<option value="standard" ' . selected( $intensity, 'standard', false ) . '>' . esc_html__( 'Standard — featured and attached media', 'cybermaps' ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<option value="advanced" ' . selected( $intensity, 'advanced', false ) . '>' . esc_html__( 'Advanced — include embedded media', 'cybermaps' ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</select>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Advanced discovery runs when an eligible item is saved. Use the rescan below to refresh existing content.', 'cybermaps' ) . '</p>';

		$enable_multimodal = ! isset( $options['enable_multimodal_discovery'] ) || '1' === (string) $options['enable_multimodal_discovery'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[enable_multimodal_discovery]" value="1" class="cm-toggle-input" ' . checked( $enable_multimodal, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Publish Cybermaps Media Hints in the AI Sitemap', 'cybermaps' ) . '</span></label>';
		echo '<p class="cybermaps-desc">' . wp_kses_post( __( 'Adds Cybermaps-defined <code>ai:visual_weight</code> and <code>ai:multimodal_desc</code> metadata. These are vendor fields, not a third-party protocol guarantee.', 'cybermaps' ) ) . '</p>';
		echo '</div>';

		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-video-alt3" aria-hidden="true"></span> ' . esc_html__( 'On-Page Video Markup', 'cybermaps' ) . '</h3>';
		\Cybermaps\Admin\Settings\Fields\FieldRenderer::render_video_schema_toggle();
		echo '</div>';

		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-update" aria-hidden="true"></span> ' . esc_html__( 'Existing Content', 'cybermaps' ) . '</h3>';
		echo '<div class="cybermaps-field-group cm-media-rescan">';
		echo '<strong>' . esc_html__( 'Bulk Media Rescan', 'cybermaps' ) . '</strong>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Refresh saved media observations for every currently included published item using the selected discovery depth.', 'cybermaps' ) . '</p>';
		echo '<button type="button" id="cybermaps-start-sync" class="button button-secondary">' . esc_html__( 'Rescan Media Now', 'cybermaps' ) . '</button>';
		echo '<div id="cybermaps-sync-progress-wrapper" class="cm-media-rescan-progress" style="display:none;">';
		echo '<div class="cm-media-rescan-track"><div id="cybermaps-sync-progress-bar" role="progressbar" aria-label="' . esc_attr__( 'Media rescan progress', 'cybermaps' ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>';
		echo '<span id="cybermaps-sync-progress-text" aria-live="polite"></span></div>';
		echo '<div id="cybermaps-sync-status" class="notice inline" role="status" aria-live="polite" style="display:none;"><p></p></div>';
		echo '</div></div></div>';
	}
	public static function render_discovery_center() {
		list( $discovery_data, $discovery_json ) = self::discovery_center_state();

		$auditor            = new \Cybermaps\Admin\DiscoveryAuditor();
		$archetype          = ! empty( $discovery_data['archetype'] ) ? $discovery_data['archetype'] : 'medium-business';
		$archetype_defaults = $auditor->get_archetype_defaults( $archetype );
		$archetype_intents  = $auditor->get_archetype_intents( $archetype );
		$overrides          = (array) ( $discovery_data['overrides'] ?? array() );
		$intents            = (array) ( $discovery_data['type_intents'] ?? array() );
		$disabled           = (array) ( $discovery_data['disabled'] ?? array() );
		$custom_row_keys    = array_unique(
			array_merge( array_keys( $overrides ), array_keys( $intents ), array_keys( $disabled ) )
		);
		$custom_row_count   = count( $custom_row_keys );
		?>
		<div id="cybermaps-content-discovery-strategy" class="cm-discovery-toolbar">
			<div class="cm-discovery-profile-control">
				<label class="cm-discovery-toolbar-label" for="cybermaps-archetype-selector">
					<?php esc_html_e( 'Starting profile', 'cybermaps' ); ?>
					<?php echo self::help_tip( __( 'Choose the publishing model closest to this site. A profile supplies baseline Publish, intent, and weight values. Changing it preserves rows you customized.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</label>
				<select id="cybermaps-archetype-selector" class="cybermaps-archetype-select">
					<?php foreach ( $auditor->get_all_archetypes() as $key => $lbl ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $archetype, $key ); ?>><?php echo esc_html( $lbl ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="button" id="cybermaps-reset-blueprint" class="button button-secondary"><?php esc_html_e( 'Reset row overrides', 'cybermaps' ); ?></button>
			<span class="cm-discovery-toolbar-action">
				<button type="button" id="cybermaps-resync-blueprint" class="button button-secondary" data-scanning-label="<?php esc_attr_e( 'Analyzing…', 'cybermaps' ); ?>" data-error-label="<?php esc_attr_e( 'Cybermaps could not analyze the site. Your current settings were not changed.', 'cybermaps' ); ?>"><?php esc_html_e( 'Suggest from site structure', 'cybermaps' ); ?></button>
				<?php echo self::help_tip( __( 'Uses public content types and published-item counts to suggest a profile. It does not read page content, classify the business, or change anything until you apply the suggestion.', 'cybermaps' ), 'tip-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		</div>
		<p id="cybermaps-blueprint-status" class="cm-discovery-profile-status" role="status" aria-live="polite">
			<?php
			if ( 0 === $custom_row_count ) {
				esc_html_e( 'Every row currently uses the selected profile baseline.', 'cybermaps' );
			} else {
				printf(
					/* translators: %d: number of customized content-group rows. */
					esc_html( _n( '%d custom row is active.', '%d custom rows are active.', $custom_row_count, 'cybermaps' ) ),
					(int) $custom_row_count
				);
			}
			?>
		</p>
		<div id="cybermaps-blueprint-suggestion" class="cm-discovery-suggestion" hidden>
			<div>
				<strong id="cybermaps-blueprint-suggestion-title"></strong>
				<span id="cybermaps-blueprint-suggestion-evidence"></span>
			</div>
			<div class="cm-discovery-suggestion-actions">
				<button type="button" id="cybermaps-apply-blueprint" class="button button-primary"><?php esc_html_e( 'Apply suggestion and reset rows', 'cybermaps' ); ?></button>
				<button type="button" id="cybermaps-dismiss-blueprint" class="button button-link"><?php esc_html_e( 'Dismiss', 'cybermaps' ); ?></button>
			</div>
		</div>
		<div id="cybermaps-blueprint-error" class="notice notice-error inline" role="alert" style="display:none;margin:0 0 16px;padding:8px 12px;"></div>

		<div class="cm-matrix-table" role="table" aria-label="<?php esc_attr_e( 'Content discovery publication defaults', 'cybermaps' ); ?>">
			<div class="cm-matrix-head" role="row">
				<span class="cm-matrix-col-status" role="columnheader"><?php esc_html_e( 'Publish', 'cybermaps' ); ?> <?php echo self::help_tip( __( 'Makes this group eligible for Cybermaps XML, RSS, HTML, and AI discovery outputs. Turning it off does not add noindex or hide the content.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="cm-matrix-col-label" role="columnheader"><?php esc_html_e( 'Content group', 'cybermaps' ); ?> <?php echo self::help_tip( __( 'Post types contain individual entries. Taxonomies—including Post Formats—represent archive pages that group entries.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="cm-matrix-col-intent" role="columnheader"><?php esc_html_e( 'Discovery intent', 'cybermaps' ); ?> <?php echo self::help_tip( __( 'Informational content primarily explains or answers. Commercial content supports a purchase, booking, contact, registration, download, or another conversion.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="cm-matrix-col-priority" role="columnheader"><?php esc_html_e( 'Publication weight', 'cybermaps' ); ?> <?php echo self::help_tip( __( 'A relative 0.1–1.0 hint published in XML and AI output. Higher-weight groups appear earlier in the sitemap index; this is not a search ranking score or crawl guarantee.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="cm-matrix-col-source" role="columnheader"><?php esc_html_e( 'Source', 'cybermaps' ); ?> <?php echo self::help_tip( __( 'Baseline means the selected profile supplies the row. Custom means at least one value differs and can be reset independently.', 'cybermaps' ), 'tip-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</div>
			<?php
			self::render_discovery_matrix_rows(
				$archetype_defaults,
				$archetype_intents,
				$overrides,
				$intents,
				$disabled
			);
			?>
		</div>
		<div class="cm-discovery-save-row">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save content strategy', 'cybermaps' ); ?></button>
			<span><?php esc_html_e( 'Saves this strategy together with any other changes on the XML Sitemaps tab.', 'cybermaps' ); ?></span>
		</div>

			<input
				type="hidden"
				id="cybermaps_discovery_center"
				name="cybermaps_discovery_center"
				value="<?php echo esc_attr( $discovery_json ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'cybermaps_discovery_center' ) ); ?>"
				data-archetypes="<?php echo esc_attr( wp_json_encode( $auditor->get_all_defaults() ) ); ?>"
				data-off-label="<?php esc_attr_e( 'Off', 'cybermaps' ); ?>"
				data-custom-zero-label="<?php esc_attr_e( 'Every row uses the selected profile baseline. Choose Save content strategy to publish this change.', 'cybermaps' ); ?>"
				data-custom-one-label="<?php esc_attr_e( '1 custom row is active. Choose Save content strategy to publish it.', 'cybermaps' ); ?>"
				data-custom-many-label="<?php /* translators: %s: number of customized content-group rows. */ esc_attr_e( '%s custom rows are active. Choose Save content strategy to publish them.', 'cybermaps' ); ?>"
				data-profile-zero-label="<?php /* translators: %s: site profile label. */ esc_attr_e( 'The %s baseline is loaded with no custom rows. Review it, then choose Save content strategy.', 'cybermaps' ); ?>"
				data-profile-one-label="<?php /* translators: %s: site profile label. */ esc_attr_e( 'The %s baseline is loaded and 1 custom row was preserved. Review it, then choose Save content strategy.', 'cybermaps' ); ?>"
				data-profile-many-label="<?php /* translators: 1: site profile label, 2: number of preserved customized rows. */ esc_attr_e( 'The %1$s baseline is loaded and %2$s custom rows were preserved. Review them, then choose Save content strategy.', 'cybermaps' ); ?>"
				data-reset-label="<?php /* translators: %s: site profile label. */ esc_attr_e( 'All rows now use the %s profile baseline. Choose Save content strategy to publish them.', 'cybermaps' ); ?>"
				data-suggestion-title="<?php /* translators: %s: suggested site profile label. */ esc_attr_e( 'Suggested profile: %s', 'cybermaps' ); ?>"
				data-suggestion-evidence-zero="<?php esc_attr_e( 'No published items were found in the analyzed content groups.', 'cybermaps' ); ?>"
				data-suggestion-evidence-one="<?php esc_attr_e( 'Based on 1 published item and the available site-structure signals.', 'cybermaps' ); ?>"
				data-suggestion-evidence-many="<?php /* translators: %s: number of published items found during site analysis. */ esc_attr_e( 'Based on %s published items and the available site-structure signals.', 'cybermaps' ); ?>"
				data-suggestion-reset-warning="<?php esc_attr_e( 'Applying this suggestion will replace every custom row in this form.', 'cybermaps' ); ?>"
				data-suggestion-applied-label="<?php /* translators: %s: suggested site profile label. */ esc_attr_e( 'The suggested %s profile is loaded and all prior row customizations were reset. Review the rows, then choose Save content strategy.', 'cybermaps' ); ?>"
				data-suggestion-dismissed-label="<?php esc_attr_e( 'Site analysis suggestion dismissed. Current settings were not changed.', 'cybermaps' ); ?>"
				data-profile-source-label="<?php esc_attr_e( 'Baseline', 'cybermaps' ); ?>"
				data-custom-source-label="<?php esc_attr_e( 'Custom', 'cybermaps' ); ?>"
			>
			<noscript>
				<p class="notice notice-warning inline"><strong><?php esc_html_e( 'JavaScript is required to edit and serialize the Content Discovery Strategy controls.', 'cybermaps' ); ?></strong></p>
			</noscript>
		<?php
	}

	/**
	 * @return array{0:array<string,mixed>,1:string}
	 */
	private static function discovery_center_state(): array {
		$discovery_raw  = get_option( 'cybermaps_discovery_center', '' );
		$discovery_data = is_array( $discovery_raw )
			? $discovery_raw
			: json_decode( is_string( $discovery_raw ) ? $discovery_raw : '', true );
		if ( ! is_array( $discovery_data ) ) {
			$discovery_data = array(
				'archetype'    => 'medium-business',
				'overrides'    => array(),
				'type_intents' => array(),
			);
		}
		$discovery_json = wp_json_encode( $discovery_data );
		$discovery_json = is_string( $discovery_json ) ? $discovery_json : '{}';
		$canonical_json = \Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer::sanitize(
			$discovery_json
		);
		$canonical_data = is_string( $canonical_json )
			? json_decode( $canonical_json, true )
			: null;
		if ( is_array( $canonical_data ) ) {
			$discovery_data = $canonical_data;
			$discovery_json = $canonical_json;
		}

		return array( $discovery_data, $discovery_json );
	}

	/**
	 * @param array<string,mixed> $archetype_defaults Profile priority defaults.
	 * @param array<string,mixed> $archetype_intents  Profile intent defaults.
	 * @param array<string,mixed> $overrides          Saved priority overrides.
	 * @param array<string,mixed> $intents            Saved intent overrides.
	 * @param array<string,mixed> $disabled           Saved disabled rows.
	 */
	private static function render_discovery_matrix_rows(
		array $archetype_defaults,
		array $archetype_intents,
		array $overrides,
		array $intents,
		array $disabled
	): void {
		$last_kind = '';
		foreach ( self::discovery_content_groups() as $type_entry ) {
			$kind = (string) $type_entry['kind'];
			if ( $kind !== $last_kind ) {
				$last_kind = $kind;
				self::render_discovery_kind_heading( $kind );
			}
			$row = self::discovery_matrix_row(
				$type_entry,
				$archetype_defaults,
				$archetype_intents,
				$overrides,
				$intents,
				$disabled
			);
			self::render_discovery_matrix_row( $row );
		}
	}

	/**
	 * @return array<int,array{identity:string,kind:string,name:string,object:object}>
	 */
	private static function discovery_content_groups(): array {
		$all_types = array();
		foreach ( \Cybermaps\Core\PublicationPostTypes::objects() as $type => $type_obj ) {
			$all_types[] = array(
				'identity' => \Cybermaps\Sitemap\ProviderIdentity::post_type( (string) $type ),
				'kind'     => 'post_type',
				'name'     => (string) $type,
				'object'   => $type_obj,
			);
		}
		foreach ( (array) get_taxonomies( array( 'public' => true ), 'objects' ) as $type => $type_obj ) {
			if ( ! is_object( $type_obj ) ) {
				$type     = is_scalar( $type_obj ) ? (string) $type_obj : (string) $type;
				$type_obj = get_taxonomy( $type );
			} else {
				$type = isset( $type_obj->name ) ? (string) $type_obj->name : (string) $type;
			}
			if ( ! is_object( $type_obj ) || empty( $type_obj->public ) ) {
				continue;
			}
			$all_types[] = array(
				'identity' => \Cybermaps\Sitemap\ProviderIdentity::taxonomy( $type ),
				'kind'     => 'taxonomy',
				'name'     => $type,
				'object'   => $type_obj,
			);
		}

		return $all_types;
	}

	private static function render_discovery_kind_heading( string $kind ): void {
		$group_label = 'post_type' === $kind
			? __( 'Post types — individual content', 'cybermaps' )
			: __( 'Taxonomies — archive groupings', 'cybermaps' );
		?>
		<div class="cm-matrix-kind-heading" role="row">
			<span role="cell" aria-colspan="5"><?php echo esc_html( $group_label ); ?></span>
		</div>
		<?php
	}

	/**
	 * @param array{identity:string,kind:string,name:string,object:object} $type_entry Content-group entry.
	 * @param array<string,mixed> $archetype_defaults Profile priority defaults.
	 * @param array<string,mixed> $archetype_intents  Profile intent defaults.
	 * @param array<string,mixed> $overrides          Saved priority overrides.
	 * @param array<string,mixed> $intents            Saved intent overrides.
	 * @param array<string,mixed> $disabled           Saved disabled rows.
	 * @return array<string,mixed>
	 */
	private static function discovery_matrix_row(
		array $type_entry,
		array $archetype_defaults,
		array $archetype_intents,
		array $overrides,
		array $intents,
		array $disabled
	): array {
		$type          = (string) $type_entry['name'];
		$matrix_key    = (string) $type_entry['identity'];
		$kind          = (string) $type_entry['kind'];
		$val           = self::discovery_priority( $matrix_key, $type, $overrides, $archetype_defaults );
		$intent        = self::discovery_intent( $matrix_key, $type, $intents, $archetype_intents );
		$is_disabled   = self::discovery_row_disabled( $matrix_key, $type, $disabled );
		$off           = $is_disabled || $val <= 0;
		$profile_value = (float) ( $archetype_defaults[ $type ] ?? 0.5 );
		$remembered    = $val > 0 ? $val : ( $profile_value > 0 ? $profile_value : 0.5 );
		$is_custom     = self::has_discovery_value( $matrix_key, $type, $overrides )
			|| self::has_discovery_value( $matrix_key, $type, $intents )
			|| $is_disabled;

		return array_merge(
			self::discovery_group_labels( $type_entry ),
			array(
				'type'        => $type,
				'matrix_key'  => $matrix_key,
				'control_key' => sanitize_key( $matrix_key ),
				'val'         => $val,
				'intent'      => $intent,
				'off'         => $off,
				'remembered'  => $remembered,
				'is_custom'   => $is_custom,
			)
		);
	}

	/**
	 * @param array<string,mixed> $overrides          Saved priority overrides.
	 * @param array<string,mixed> $archetype_defaults Profile priority defaults.
	 */
	private static function discovery_priority(
		string $matrix_key,
		string $type,
		array $overrides,
		array $archetype_defaults
	): float {
		if ( array_key_exists( $matrix_key, $overrides ) ) {
			return (float) $overrides[ $matrix_key ];
		}
		if ( array_key_exists( $type, $overrides ) ) {
			return (float) $overrides[ $type ];
		}
		return (float) ( $archetype_defaults[ $type ] ?? 0.5 );
	}

	/**
	 * @param array<string,mixed> $intents           Saved intent overrides.
	 * @param array<string,mixed> $archetype_intents Profile intent defaults.
	 */
	private static function discovery_intent(
		string $matrix_key,
		string $type,
		array $intents,
		array $archetype_intents
	): string {
		if ( array_key_exists( $matrix_key, $intents ) ) {
			return (string) $intents[ $matrix_key ];
		}
		if ( array_key_exists( $type, $intents ) ) {
			return (string) $intents[ $type ];
		}
		return (string) ( $archetype_intents[ $type ] ?? 'informational' );
	}

	/**
	 * @param array<string,mixed> $values Saved row values.
	 */
	private static function has_discovery_value( string $matrix_key, string $type, array $values ): bool {
		return array_key_exists( $matrix_key, $values ) || array_key_exists( $type, $values );
	}

	/**
	 * @param array<string,mixed> $disabled Saved disabled rows.
	 */
	private static function discovery_row_disabled( string $matrix_key, string $type, array $disabled ): bool {
		return ! empty( $disabled[ $matrix_key ] ) || ! empty( $disabled[ $type ] );
	}

	/**
	 * @param array{identity:string,kind:string,name:string,object:object} $type_entry Content-group entry.
	 * @return array<string,string>
	 */
	private static function discovery_group_labels( array $type_entry ): array {
		$type_obj = $type_entry['object'];
		$type     = (string) $type_entry['name'];
		$kind     = (string) $type_entry['kind'];
		$label    = isset( $type_obj->label )
			? (string) $type_obj->label
			: (string) ( $type_obj->labels->name ?? $type );
		if ( 'taxonomy' === $kind && 'post_format' === $type ) {
			$label = __( 'Post Formats', 'cybermaps' );
		}
		$kind_label = 'post_type' === $kind
			? __( 'Post type', 'cybermaps' )
			: __( 'Taxonomy', 'cybermaps' );
		if ( 'post_type' === $kind ) {
			/* translators: %s: public post type label. */
			$group_description = sprintf( __( 'Individual %s entries', 'cybermaps' ), $label );
		} elseif ( 'post_format' === $type ) {
			$group_description = __( 'Archive pages for image, video, quote, link, and other WordPress post formats', 'cybermaps' );
		} else {
			/* translators: %s: public taxonomy label. */
			$group_description = sprintf( __( '%s archive pages', 'cybermaps' ), $label );
		}

		return array(
			'label'             => $label,
			'kind_label'        => $kind_label,
			'group_description' => $group_description,
			/* translators: 1: content label, 2: resource kind (post type or taxonomy). */
			'include_label'     => sprintf( __( 'Publish %1$s %2$s through eligible Cybermaps outputs', 'cybermaps' ), $label, $kind_label ),
			/* translators: 1: content label, 2: resource kind (post type or taxonomy). */
			'priority_label'    => sprintf( __( '%1$s %2$s publication weight', 'cybermaps' ), $label, $kind_label ),
			/* translators: 1: content label, 2: resource kind (post type or taxonomy). */
			'intent_label'      => sprintf( __( '%1$s %2$s discovery intent', 'cybermaps' ), $label, $kind_label ),
			/* translators: 1: content label, 2: resource kind (post type or taxonomy). */
			'reset_label'       => sprintf( __( 'Reset custom settings for %1$s %2$s', 'cybermaps' ), $label, $kind_label ),
		);
	}

	/**
	 * @param array<string,mixed> $row Render-ready content-group row.
	 */
	private static function render_discovery_matrix_row( array $row ): void {
		?>
		<div class="cm-matrix-row<?php echo $row['off'] ? ' is-disabled' : ''; ?>" role="row" data-type="<?php echo esc_attr( $row['matrix_key'] ); ?>" data-base-type="<?php echo esc_attr( $row['type'] ); ?>" data-last-value="<?php echo esc_attr( (string) $row['remembered'] ); ?>">
			<span class="cm-matrix-col-status" role="cell">
				<label class="cm-toggle-wrapper small">
					<input type="checkbox" id="cybermaps-matrix-status-<?php echo esc_attr( $row['control_key'] ); ?>" class="cm-toggle-input cm-matrix-off-toggle" aria-label="<?php echo esc_attr( $row['include_label'] ); ?>" <?php checked( ! $row['off'] ); ?>>
					<span class="cm-toggle-switch"></span>
				</label>
			</span>
			<span class="cm-matrix-col-label" role="cell">
				<strong><?php echo esc_html( $row['label'] ); ?></strong>
				<span class="cm-matrix-group-description"><?php echo esc_html( $row['group_description'] ); ?></span>
				<code class="cm-matrix-type-key"><?php echo esc_html( $row['kind_label'] . ' · ' . $row['type'] ); ?></code>
			</span>
			<span class="cm-matrix-col-intent" role="cell" data-label="<?php esc_attr_e( 'Discovery intent', 'cybermaps' ); ?>">
				<select class="cm-intent-select" aria-label="<?php echo esc_attr( $row['intent_label'] ); ?>"<?php echo $row['off'] ? ' disabled' : ''; ?>>
					<option value="informational" <?php selected( $row['intent'], 'informational' ); ?>><?php esc_html_e( 'Informational', 'cybermaps' ); ?></option>
					<option value="transactional" <?php selected( $row['intent'], 'transactional' ); ?>><?php esc_html_e( 'Commercial', 'cybermaps' ); ?></option>
				</select>
			</span>
			<span class="cm-matrix-col-priority" role="cell" data-label="<?php esc_attr_e( 'Publication weight', 'cybermaps' ); ?>">
				<input type="range" id="cybermaps-matrix-priority-<?php echo esc_attr( $row['control_key'] ); ?>" class="cm-matrix-slider" aria-label="<?php echo esc_attr( $row['priority_label'] ); ?>" aria-valuemin="0.1" aria-valuemax="1.0" aria-valuenow="<?php echo esc_attr( number_format( $row['remembered'], 1, '.', '' ) ); ?>" aria-valuetext="<?php echo $row['off'] ? esc_attr__( 'Off', 'cybermaps' ) : esc_attr( number_format( $row['remembered'], 1, '.', '' ) ); ?>" min="0.1" max="1.0" value="<?php echo esc_attr( number_format( $row['remembered'], 1, '.', '' ) ); ?>" step="0.1"<?php echo $row['off'] ? ' disabled' : ''; ?>>
				<output class="cm-matrix-slider-val" for="cybermaps-matrix-priority-<?php echo esc_attr( $row['control_key'] ); ?>"><?php echo $row['off'] ? esc_html__( 'Off', 'cybermaps' ) : esc_html( number_format( $row['val'], 1 ) ); ?></output>
			</span>
			<span class="cm-matrix-col-source" role="cell" data-label="<?php esc_attr_e( 'Source', 'cybermaps' ); ?>">
				<span class="cm-matrix-source-badge <?php echo $row['is_custom'] ? 'is-custom' : 'is-profile'; ?>"><?php echo $row['is_custom'] ? esc_html__( 'Custom', 'cybermaps' ) : esc_html__( 'Baseline', 'cybermaps' ); ?></span>
				<button type="button" class="button-link cm-matrix-reset-row" aria-label="<?php echo esc_attr( $row['reset_label'] ); ?>"<?php echo $row['is_custom'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Reset', 'cybermaps' ); ?></button>
			</span>
		</div>
		<?php
	}

	public static function indexing_section_callback(): void {
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Publish specialized discovery formats and notify compatible services.', 'cybermaps' ) . '</strong> ' . esc_html__( 'Google News and RSS create additional sitemap feeds. IndexNow and WebSub send update notifications; they do not guarantee crawling or indexing.', 'cybermaps' ) . '</div>';
		$options      = \Cybermaps\Core\ConfigurationStore::settings();
		$sitemap_base = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$news_base    = \Cybermaps\Sitemap\Orchestrator::get_news_sitemap_base();

		echo '<div class="cm-sitemap-settings-stack">';
		self::render_indexing_news( $options, $sitemap_base, $news_base );
		self::render_indexing_rss( $options );
		self::render_indexing_notifications( $options );
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_indexing_news(
		array $options,
		string $sitemap_base,
		string $news_base
	): void {
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-media-document" aria-hidden="true"></span> ' . esc_html__( 'Google News Sitemap', 'cybermaps' ) . '</h3>';
		$enable_news = isset( $options['enable_google_news'] ) && '1' === (string) $options['enable_google_news'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[enable_google_news]" value="1" class="cm-toggle-input" ' . checked( $enable_news, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Publish the Google News Sitemap', 'cybermaps' ) . '</span></label>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Lists eligible articles from the last 48 hours using the Google News XML extension.', 'cybermaps' ) . '</p>';

		echo '<div class="cm-sitemap-dependent-fields">';
		echo '<label class="cm-sitemap-control" for="news_sitemap_url_base"><strong>' . esc_html__( 'News Sitemap URL Base', 'cybermaps' ) . '</strong></label>';
		echo '<input id="news_sitemap_url_base" class="regular-text" dir="ltr" type="text" name="cybermaps_settings[news_sitemap_url_base]" value="' . esc_attr( $news_base ) . '" placeholder="' . esc_attr( $sitemap_base . '-news' ) . '">';

		$news_publication_name = isset( $options['news_publication_name'] ) ? $options['news_publication_name'] : get_bloginfo( 'name' );
		echo '<label class="cm-sitemap-control" for="news_publication_name"><strong>' . esc_html__( 'Publication Name', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'Use the exact publication name shown in Google News Publisher Center.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</label>';
		echo '<input id="news_publication_name" class="regular-text" type="text" name="cybermaps_settings[news_publication_name]" value="' . esc_attr( $news_publication_name ) . '" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '">';
		echo '</div></div>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_indexing_rss( array $options ): void {
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-rss" aria-hidden="true"></span> ' . esc_html__( 'RSS 2.0 Sitemap', 'cybermaps' ) . '</h3>';
		$enable_rss = ! empty( $options['enable_rss_sitemap'] );
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[enable_rss_sitemap]" value="1" class="cm-toggle-input" ' . checked( $enable_rss, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Publish the RSS Sitemap', 'cybermaps' ) . '</span></label>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Publishes a bounded recent-content RSS feed for crawler and feed-based discovery.', 'cybermaps' ) . '</p>';

		$rss_base = \Cybermaps\Sitemap\Orchestrator::get_rss_sitemap_base();
		echo '<div class="cm-sitemap-dependent-fields">';
		echo '<label class="cm-sitemap-control" for="rss_sitemap_url_base"><strong>' . esc_html__( 'RSS Sitemap URL Base', 'cybermaps' ) . '</strong></label>';
		echo '<input id="rss_sitemap_url_base" class="regular-text" dir="ltr" type="text" name="cybermaps_settings[rss_sitemap_url_base]" value="' . esc_attr( $rss_base ) . '" placeholder="sitemap-rss">';

		$rss_limit = isset( $options['rss_sitemap_limit'] ) ? (int) $options['rss_sitemap_limit'] : 100;
		echo '<label class="cm-sitemap-control" for="rss_sitemap_limit"><strong>' . esc_html__( 'Maximum Items', 'cybermaps' ) . '</strong></label>';
		echo '<input id="rss_sitemap_limit" type="number" name="cybermaps_settings[rss_sitemap_limit]" value="' . esc_attr( (string) $rss_limit ) . '" min="1" max="1000" class="small-text">';

		$rss_types = isset( $options['rss_sitemap_types'] ) ? (array) $options['rss_sitemap_types'] : array( 'post' );
		echo '<fieldset class="cm-sitemap-checkboxes"><legend><strong>' . esc_html__( 'Included Post Types', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'This RSS-only selection narrows the post types allowed by the global Content Discovery Strategy.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</legend>';
		foreach ( \Cybermaps\Core\PublicationPostTypes::objects() as $post_type ) {
			echo '<label class="cm-checkbox-wrapper">';
			echo '<input type="checkbox" name="cybermaps_settings[rss_sitemap_types][]" value="' . esc_attr( $post_type->name ) . '" ' . checked( in_array( $post_type->name, $rss_types, true ), true, false ) . '> ' . esc_html( $post_type->label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</label>';
		}
		echo '</fieldset></div></div>';
	}

	/**
	 * @param array<string,mixed> $options Settings.
	 */
	private static function render_indexing_notifications( array $options ): void {
		echo '<div class="cm-sitemap-settings-group">';
		echo '<h3 class="cybermaps-settings-subheading"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> ' . esc_html__( 'Update Notifications', 'cybermaps' ) . '</h3>';
		$enable_indexnow = isset( $options['enable_indexnow'] ) && '1' === (string) $options['enable_indexnow'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[enable_indexnow]" value="1" class="cm-toggle-input" ' . checked( $enable_indexnow, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Send IndexNow URL Updates', 'cybermaps' ) . '</span></label>';
		echo '<p class="cybermaps-desc">' . wp_kses_post( __( 'Submits changed public URLs and a site-specific key to the IndexNow API. Headless sites must expose the generated <code>/{key}.txt</code> path on the configured frontend host.', 'cybermaps' ) ) . '</p>';

		$frontend_base = \Cybermaps\Core\URLManager::normalize_configured_base_url(
			(string) ( $options['frontend_base_url'] ?? '' )
		);
		if ( '' !== $frontend_base ) {
			$verify = get_transient( 'cybermaps_indexnow_remote_verify' );
			$verify = is_array( $verify ) ? $verify : null;
			echo '<div class="cm-sitemap-dependent-fields">';
			if ( null !== $verify ) {
				echo '<p class="cybermaps-desc">' . esc_html( (string) ( $verify['message'] ?? '' ) ) . '</p>';
			}
			echo '<button type="submit" form="cybermaps-verify-indexnow-key-form" class="button button-secondary">' . esc_html__( 'Verify public IndexNow key URL', 'cybermaps' ) . '</button>';
			echo '</div>';
		}

		$enable_websub = isset( $options['enable_websub'] ) && '1' === (string) $options['enable_websub'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[enable_websub]" value="1" class="cm-toggle-input" ' . checked( $enable_websub, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Send WebSub Feed Updates', 'cybermaps' ) . '</span></label>';
		echo '<p class="cybermaps-desc">' . wp_kses_post( __( 'Uses <code>/feed.json</code> as the canonical topic and notifies configured hubs when eligible content changes. AI Publishing must also be enabled.', 'cybermaps' ) ) . '</p>';

		$websub_hubs = isset( $options['websub_hubs'] ) ? $options['websub_hubs'] : "https://pubsubhubbub.appspot.com/\nhttps://pubsubhubbub.superfeedr.com/";
		echo '<div class="cm-sitemap-dependent-fields">';
		echo '<label class="cm-sitemap-control" for="websub_hubs"><strong>' . esc_html__( 'WebSub Hubs', 'cybermaps' ) . '</strong></label>';
		echo '<textarea id="websub_hubs" class="large-text code" dir="ltr" name="cybermaps_settings[websub_hubs]" rows="3" placeholder="https://hub.example.com/">' . esc_textarea( $websub_hubs ) . '</textarea>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Enter one absolute HTTPS hub URL per line. Two established public hubs are supplied by default.', 'cybermaps' ) . '</p>';
		echo '</div></div>';
	}

	public static function international_section_callback(): void {
		echo '<div class="cm-section-prose"><strong>' . esc_html__( 'Keep language signals together.', 'cybermaps' ) . '</strong> ' . esc_html__( 'Set the primary hreflang language and optionally publish relationships supplied by a supported translation plugin.', 'cybermaps' ) . '</div>';
		$options = \Cybermaps\Core\ConfigurationStore::settings();

		echo '<div class="cm-sitemap-settings-stack"><div class="cm-sitemap-settings-group">';
		$site_language = isset( $options['site_language'] ) ? $options['site_language'] : get_bloginfo( 'language' );
		echo '<label class="cm-sitemap-control" for="site_language"><strong>' . esc_html__( 'Primary Language (hreflang)', 'cybermaps' ) . '</strong> ' . wp_kses( self::help_tip( __( 'Use a valid language code such as en, en-US, or fr-FR. Cybermaps uses it for sitemap hreflang output.', 'cybermaps' ) ), self::help_tip_allowed_html() ) . '</label>';
		echo '<input id="site_language" class="regular-text" dir="ltr" type="text" name="cybermaps_settings[site_language]" value="' . esc_attr( $site_language ) . '" placeholder="' . esc_attr__( 'e.g. en-US', 'cybermaps' ) . '">';

		$translation_enabled = isset( $options['enable_translation_integrations'] ) && '1' === (string) $options['enable_translation_integrations'];
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[enable_translation_integrations]" value="1" class="cm-toggle-input" ' . checked( $translation_enabled, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span><span class="cm-toggle-label">' . esc_html__( 'Use WPML or Polylang Translation Relationships', 'cybermaps' ) . '</span></label>';

		if ( \Cybermaps\Core\Plugin::is_translation_environment() ) {
			echo '<p class="cm-sitemap-integration-status is-detected"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Compatible translation environment detected. Enable the option to add its language relationships to sitemap output.', 'cybermaps' ) . '</p>';
		} else {
			echo '<p class="cm-sitemap-integration-status"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span>' . esc_html__( 'No supported translation plugin was detected. The option can remain off on a single-language site.', 'cybermaps' ) . '</p>';
		}

		echo '<p class="cybermaps-desc">' . esc_html__( 'WordPress multisite sitemap indexing is configured separately by a network administrator in Network Admin.', 'cybermaps' ) . '</p>';
		echo '</div></div>';
	}
}
