<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs\Robots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RobotsFields {

	public static function robots_section_callback() {
		echo '<p class="cm-robots-summary">';
		echo esc_html__( 'Control WordPress robots.txt rules, discovery-manifest targeting, endpoint rate limits, and machine-readable content preferences.', 'cybermaps' );
		echo \Cybermaps\Admin\AccessibleTooltip::get( __( 'Robots rules and content-use declarations communicate publisher preferences. They are not authentication or access control, and each crawler decides whether to honor them.', 'cybermaps' ), 'tip-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessibleTooltip returns escaped trusted markup.
		echo '</p>';
	}
	public static function render_crawler_matrix() {
		$manager_options = \Cybermaps\Core\ConfigurationStore::robots();
		$overrides       = isset( $manager_options['overrides'] ) ? $manager_options['overrides'] : array();
		$manual          = isset( $manager_options['manual_directives'] ) ? $manager_options['manual_directives'] : '';

		$categories       = \Cybermaps\Core\CrawlerRegistry::get_policy_categories();
		$bots             = \Cybermaps\Core\CrawlerRegistry::get_policy_bots();
		$takeover_enabled = ! empty( $manager_options['takeover_enabled'] );

		echo '<p class="notice notice-info inline" style="margin:0 0 16px;padding:10px 12px;">';
		if ( $takeover_enabled ) {
			echo esc_html__( 'Robots takeover is enabled: the Robots Rule column is applied to WordPress’s virtual robots.txt output. Manifest targets remain non-binding discovery declarations.', 'cybermaps' );
		} else {
			echo esc_html__( 'Robots takeover is disabled: Robots Rule selections may be saved, but they are not applied until takeover is enabled. Manifest targets can still be published as non-binding discovery declarations.', 'cybermaps' );
		}
		echo '</p>';

		echo '<input type="hidden" class="cybermaps-reset-overrides" name="cybermaps_robots_manager[reset_overrides]" value="0">';

		echo '<div class="cybermaps-matrix-quick-select" style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">';
		echo '<button type="button" class="button cybermaps-bulk-action" data-action="allow-all" disabled>' . esc_html__( 'Allow All Crawlers', 'cybermaps' ) . '</button>';
		echo '<button type="button" class="button cybermaps-bulk-action" data-action="full-ai" disabled>' . esc_html__( 'Allow + Target AI Crawlers', 'cybermaps' ) . '</button>';
		echo '<button type="button" class="button cybermaps-bulk-action" data-action="clear-ai-targets" disabled>' . esc_html__( 'Clear AI Manifest Targets', 'cybermaps' ) . '</button>';
		echo '<button type="button" class="button cybermaps-bulk-action" data-action="reset" disabled>' . esc_html__( 'Reset to Registry Defaults', 'cybermaps' ) . '</button>';
		echo '</div>';
		echo '<noscript><p class="notice notice-warning inline">' . esc_html__( 'Bulk and category shortcuts require JavaScript. The individual crawler controls below remain available.', 'cybermaps' ) . '</p></noscript>';

		$robots_rule_label     = __( 'Robots Rule', 'cybermaps' );
		$manifest_target_label = __( 'Manifest Target', 'cybermaps' );
		$rpm_limit_label       = __( 'RPM Limit', 'cybermaps' );

		echo '<div class="cybermaps-crawler-matrix-wrapper">';
		echo '<table class="widefat striped cybermaps-crawler-matrix">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Crawler robots, discovery targeting, and endpoint request limits', 'cybermaps' ) . '</caption>';
		echo '<thead>';
		echo '<tr>';
		echo '<th scope="col" class="cybermaps-crawler-name-column">' . esc_html__( 'Bot / Crawler', 'cybermaps' ) . '</th>';
		echo '<th scope="col">' . esc_html( $robots_rule_label ) . '</th>';
		echo '<th scope="col">' . esc_html( $manifest_target_label ) . '</th>';
		echo '<th scope="col">' . esc_html( $rpm_limit_label ) . ' ' . \Cybermaps\Admin\AccessibleTooltip::get( __( 'Requests per minute for Cybermaps discovery endpoints. A value of 0 uses the built-in limit for the endpoint tier. Exceeding the limit returns HTTP 429 with Retry-After.', 'cybermaps' ), 'tip-left' ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The dynamic label and AccessibleTooltip markup are escaped.
		echo '</tr>';
		echo '</thead>';
		self::render_categories(
			$categories,
			$bots,
			$overrides,
			$robots_rule_label,
			$manifest_target_label,
			$rpm_limit_label
		);
		echo '</table>';
		echo '</div>';

		echo '<div style="margin-top: 20px;">';
		echo '<label for="cybermaps-manual-directives"><strong>' . esc_html__( 'Manual Directives:', 'cybermaps' ) . '</strong></label><br>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Add custom User-agent or Disallow/Allow rules to Cybermaps robots.txt output. Review complete group syntax carefully; malformed directives can change how later rules are interpreted.', 'cybermaps' ) . '</p>';
		echo '<textarea id="cybermaps-manual-directives" name="cybermaps_robots_manager[manual_directives]" rows="5" maxlength="' . esc_attr( (string) \Cybermaps\Discovery\Robots::MAX_MANUAL_DIRECTIVES_BYTES ) . '" style="width: 100%; font-family: monospace;" placeholder="User-agent: *' . "\n" . 'Disallow: /private/">' . esc_textarea( $manual ) . '</textarea>';
		echo '</div>';

		wp_add_inline_script(
			'cybermaps-command-center',
			'jQuery(document).ready(function($) {
    var $matrix = $(".cybermaps-crawler-matrix");
    $matrix.addClass("is-collapsible");
    $matrix.find(".cybermaps-category-disclosure")
        .attr("aria-expanded", "false")
        .removeAttr("hidden");
    $matrix.find(".cybermaps-master-toggle").prop("disabled", false);
    $matrix.closest(".cm-robots-panel").find(".cybermaps-bulk-action").prop("disabled", false);

    function syncMasterToggle(master) {
        var $master  = $(master);
        var type     = $master.data("type");
        var cat      = $master.data("cat");
        var selector = type === "robots" ? ".bot-robots-toggle" : ".bot-llm-toggle";
        var $children = $("tr[data-cat=\"" + cat + "\"] " + selector);
        var checkedCount = $children.filter(":checked").length;

        $master.prop("checked", $children.length > 0 && checkedCount === $children.length);
        $master.prop("indeterminate", checkedCount > 0 && checkedCount < $children.length);
    }

    function syncMasterToggles() {
        $(".cybermaps-master-toggle").each(function() {
            syncMasterToggle(this);
        });
    }

    $(".cybermaps-category-disclosure").on("click", function() {
        var $button  = $(this);
        var expanded = $button.attr("aria-expanded") === "true";
        var $group   = $button.closest(".cybermaps-crawler-category-group").next(".cybermaps-crawler-category-details");
        var label    = expanded ? $button.data("expand-label") : $button.data("collapse-label");
        var aria     = expanded ? $button.data("open-aria") : $button.data("close-aria");

        $button.attr("aria-expanded", expanded ? "false" : "true");
        $button.attr("aria-label", aria);
        $button.find(".cybermaps-crawler-category-action").text(label);
        $group.find(".cybermaps-crawler-detail-row").toggleClass("is-expanded", ! expanded);
    });

    $(".cybermaps-master-toggle").on("change", function() {
        var type     = $(this).data("type");
        var cat      = $(this).data("cat");
        var checked  = $(this).prop("checked");
        var selector = type === "robots" ? ".bot-robots-toggle" : ".bot-llm-toggle";
        $("tr[data-cat=\"" + cat + "\"] " + selector).prop("checked", checked);
        $(".cybermaps-reset-overrides").val("0");
        syncMasterToggle(this);
    });

    $(".cybermaps-bulk-action").on("click", function() {
        var action = $(this).data("action");
        $(".cybermaps-reset-overrides").val("0");
        switch (action) {
            case "allow-all":
                $(".bot-robots-toggle").prop("checked", true);
                break;
            case "full-ai":
                $("tr[data-cat=\"ai-training\"] .bot-robots-toggle, tr[data-cat=\"ai-search\"] .bot-robots-toggle, tr[data-cat=\"ai-user\"] .bot-robots-toggle, tr[data-cat=\"ai-training\"] .bot-llm-toggle, tr[data-cat=\"ai-search\"] .bot-llm-toggle, tr[data-cat=\"ai-user\"] .bot-llm-toggle").prop("checked", true);
                break;
            case "clear-ai-targets":
                $(".bot-llm-toggle").prop("checked", false);
                break;
            case "reset":
                $(".bot-robots-toggle, .bot-llm-toggle").each(function() {
                    $(this).prop("checked", $(this).data("default") == "1");
                });
                $(".bot-rpm-limit").val("0");
                $(".cybermaps-master-toggle").prop("checked", false);
                $(".cybermaps-reset-overrides").val("1");
                break;
            default:
                break;
        }
        syncMasterToggles();
    });

    $(".bot-robots-toggle, .bot-llm-toggle").on("change", function() {
        $(".cybermaps-reset-overrides").val("0");
        syncMasterToggles();
    });

    $(".bot-rpm-limit").on("change input", function() {
        $(".cybermaps-reset-overrides").val("0");
    });

    syncMasterToggles();
});'
		);
	}

	/**
	 * Render every non-empty crawler policy category.
	 *
	 * @param array<string,string> $categories            Category labels.
	 * @param array<string,object> $bots                  Registered crawler metadata.
	 * @param array<string,mixed>  $overrides             Saved crawler overrides.
	 * @param string               $robots_rule_label     Robots column label.
	 * @param string               $manifest_target_label Manifest column label.
	 * @param string               $rpm_limit_label       Rate-limit column label.
	 */
	private static function render_categories( array $categories, array $bots, array $overrides, string $robots_rule_label, string $manifest_target_label, string $rpm_limit_label ): void {
		foreach ( $categories as $cat_id => $cat_label ) {
			self::render_category(
				(string) $cat_id,
				(string) $cat_label,
				$bots,
				$overrides,
				$robots_rule_label,
				$manifest_target_label,
				$rpm_limit_label
			);
		}
	}

	/**
	 * Render one category heading and its crawler controls.
	 *
	 * @param string               $cat_id                Category identifier.
	 * @param string               $cat_label             Category label.
	 * @param array<string,object> $bots                  Registered crawler metadata.
	 * @param array<string,mixed>  $overrides             Saved crawler overrides.
	 * @param string               $robots_rule_label     Robots column label.
	 * @param string               $manifest_target_label Manifest column label.
	 * @param string               $rpm_limit_label       Rate-limit column label.
	 */
	private static function render_category( string $cat_id, string $cat_label, array $bots, array $overrides, string $robots_rule_label, string $manifest_target_label, string $rpm_limit_label ): void {
		$category_bot_count = self::category_bot_count( $bots, $cat_id );
		if ( 0 === $category_bot_count ) {
			return;
		}

		$category_group_id  = 'cybermaps-crawler-category-' . sanitize_key( $cat_id );
		$category_ui_labels = self::category_ui_labels( $cat_label, $category_bot_count );
		self::render_category_heading(
			$cat_id,
			$cat_label,
			$category_group_id,
			$category_ui_labels,
			$robots_rule_label,
			$manifest_target_label,
			$rpm_limit_label
		);
		echo '<tbody id="' . esc_attr( $category_group_id ) . '" class="cybermaps-crawler-category-details" data-cat="' . esc_attr( $cat_id ) . '">';
		self::render_category_bots( $cat_id, $bots, $overrides, $robots_rule_label, $manifest_target_label, $rpm_limit_label );
		echo '</tbody>';
	}

	/**
	 * Count crawler entries assigned to a category.
	 *
	 * @param array<string,object> $bots   Registered crawler metadata.
	 * @param string               $cat_id Category identifier.
	 */
	private static function category_bot_count( array $bots, string $cat_id ): int {
		$count = 0;
		foreach ( $bots as $bot_meta ) {
			if ( $bot_meta->category->value === $cat_id ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Build translated labels used by a category disclosure.
	 *
	 * @return array{count:string,show:string,hide:string}
	 */
	private static function category_ui_labels( string $cat_label, int $category_bot_count ): array {
		return array(
			'count' => sprintf(
				/* translators: %d: number of crawlers in a category. */
				_n( '%d crawler', '%d crawlers', $category_bot_count, 'cybermaps' ),
				$category_bot_count
			),
			'show'  => sprintf(
				/* translators: %s: crawler category name. */
				__( 'Show individual crawler settings for %s', 'cybermaps' ),
				$cat_label
			),
			'hide'  => sprintf(
				/* translators: %s: crawler category name. */
				__( 'Hide individual crawler settings for %s', 'cybermaps' ),
				$cat_label
			),
		);
	}

	/**
	 * Render a crawler category heading row.
	 *
	 * @param array{count:string,show:string,hide:string} $ui_labels Disclosure labels.
	 */
	private static function render_category_heading( string $cat_id, string $cat_label, string $category_group_id, array $ui_labels, string $robots_rule_label, string $manifest_target_label, string $rpm_limit_label ): void {
		$is_ai_category = \Cybermaps\Core\CrawlerRegistry::category_supports_manifest_target( $cat_id );

		echo '<tbody class="cybermaps-crawler-category-group" data-cat="' . esc_attr( $cat_id ) . '">';
		echo '<tr class="cybermaps-matrix-category" data-cat="' . esc_attr( $cat_id ) . '">';
		echo '<td><div class="cybermaps-crawler-category-heading">';
		echo '<span class="cybermaps-crawler-category-name">' . esc_html( $cat_label ) . '</span>';
		echo '<span class="cybermaps-crawler-category-count">' . esc_html( $ui_labels['count'] ) . '</span>';
		echo '<button type="button" class="cybermaps-category-disclosure" hidden aria-expanded="true" aria-controls="' . esc_attr( $category_group_id ) . '" data-expand-label="' . esc_attr__( 'Customize', 'cybermaps' ) . '" data-collapse-label="' . esc_attr__( 'Collapse', 'cybermaps' ) . '" data-open-aria="' . esc_attr( $ui_labels['show'] ) . '" data-close-aria="' . esc_attr( $ui_labels['hide'] ) . '" aria-label="' . esc_attr( $ui_labels['show'] ) . '">';
		echo '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>';
		echo '<span class="cybermaps-crawler-category-action">' . esc_html__( 'Customize', 'cybermaps' ) . '</span>';
		echo '</button>';
		echo \Cybermaps\Admin\AccessibleTooltip::get( self::get_category_tooltip( $cat_id ), 'tip-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessibleTooltip returns escaped trusted markup.
		echo '</div></td>';
		echo '<td data-label="' . esc_attr( $robots_rule_label ) . '">';
		echo '<label class="cm-checkbox-wrapper" style="margin: 0; display: inline-flex;">';
		echo '<input type="checkbox" class="cybermaps-master-toggle" data-type="robots" data-cat="' . esc_attr( $cat_id ) . '" disabled aria-label="' . esc_attr( self::category_toggle_label( 'robots', $cat_label ) ) . '">';
		echo '</label></td>';
		echo '<td data-label="' . esc_attr( $manifest_target_label ) . '">';
		if ( $is_ai_category ) {
			echo '<label class="cm-checkbox-wrapper" style="margin: 0; display: inline-flex;">';
			echo '<input type="checkbox" class="cybermaps-master-toggle" data-type="llm" data-cat="' . esc_attr( $cat_id ) . '" disabled aria-label="' . esc_attr( self::category_toggle_label( 'manifest', $cat_label ) ) . '">';
			echo '</label>';
		}
		echo '</td>';
		echo '<td data-label="' . esc_attr( $rpm_limit_label ) . '"></td>'; // Request-rate spacer.
		echo '</tr>';
		echo '</tbody>';
	}

	private static function category_toggle_label( string $type, string $cat_label ): string {
		if ( 'robots' === $type ) {
			/* translators: %s: crawler category name. */
			$template = __( 'Toggle all robots rules in %s', 'cybermaps' );
		} else {
			/* translators: %s: crawler category name. */
			$template = __( 'Toggle all manifest targets in %s', 'cybermaps' );
		}

		return sprintf(
			/* translators: %s: crawler category name. */
			$template,
			$cat_label
		);
	}

	/**
	 * Render the crawler rows assigned to one category.
	 *
	 * @param array<string,object> $bots      Registered crawler metadata.
	 * @param array<string,mixed>  $overrides Saved crawler overrides.
	 */
	private static function render_category_bots( string $cat_id, array $bots, array $overrides, string $robots_rule_label, string $manifest_target_label, string $rpm_limit_label ): void {
		foreach ( $bots as $bot_id => $bot_meta ) {
			if ( $bot_meta->category->value !== $cat_id ) {
				continue;
			}

			self::render_bot_row( (string) $bot_id, $bot_meta, $cat_id, $overrides, $robots_rule_label, $manifest_target_label, $rpm_limit_label );
		}
	}

	/**
	 * Render controls for one crawler.
	 *
	 * @param object              $bot_meta  Registered crawler metadata.
	 * @param array<string,mixed> $overrides Saved crawler overrides.
	 */
	private static function render_bot_row( string $bot_id, object $bot_meta, string $cat_id, array $overrides, string $robots_rule_label, string $manifest_target_label, string $rpm_limit_label ): void {
		$bot_override    = isset( $overrides[ $bot_id ] ) && is_array( $overrides[ $bot_id ] ) ? $overrides[ $bot_id ] : array();
		$robots_checked  = $bot_override['robots'] ?? $bot_meta->default['robots'];
		$llm_checked     = $bot_override['llm'] ?? $bot_meta->default['llm'];
		$tpm_val         = isset( $bot_override['tpm'] ) ? (int) $bot_override['tpm'] : 0;
		$rpm_id          = 'cybermaps-bot-rpm-' . sanitize_key( $bot_id );
		$is_ai_bot       = \Cybermaps\Core\CrawlerRegistry::supports_manifest_target( $bot_meta );
		$robots_aria     = self::bot_control_label( 'robots', (string) $bot_meta->name );
		$manifest_aria   = self::bot_control_label( $is_ai_bot ? 'manifest' : 'unavailable', (string) $bot_meta->name );
		$rate_limit_aria = self::bot_control_label( 'rate', (string) $bot_meta->name );

		echo '<tr class="cybermaps-crawler-detail-row" data-bot="' . esc_attr( $bot_id ) . '" data-cat="' . esc_attr( $cat_id ) . '">';
		echo '<td>';
		echo '<strong>' . esc_html( $bot_meta->name ) . '</strong><br>';
		echo '<span class="cybermaps-desc" style="font-size: 11px;">' . esc_html( $bot_meta->desc ) . '</span><br>';
		echo '<span class="cm-text-xs cm-text-muted">' . esc_html__( 'Robots token:', 'cybermaps' ) . ' <code>' . esc_html( $bot_meta->ua ) . '</code></span>';
		if ( ! $bot_meta->recognizes_requests ) {
			echo ' <span class="cm-scope-badge cm-scope-all">' . esc_html__( 'policy only', 'cybermaps' ) . '</span>';
		}
		echo '</td>';
		echo '<td data-label="' . esc_attr( $robots_rule_label ) . '">';
		echo '<label class="cm-checkbox-wrapper" style="margin: 0; display: inline-flex;">';
		echo '<input type="checkbox" name="cybermaps_robots_manager[overrides][' . esc_attr( $bot_id ) . '][robots]" value="1" class="bot-robots-toggle" data-default="' . ( $bot_meta->default['robots'] ? '1' : '0' ) . '" aria-label="' . esc_attr( $robots_aria ) . '" ' . checked( $robots_checked, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</label>';
		echo '</td>';
		self::render_manifest_control( $bot_id, $bot_meta, $is_ai_bot, $llm_checked, $manifest_target_label, $manifest_aria );
		echo '<td data-label="' . esc_attr( $rpm_limit_label ) . '">';
		echo '<label class="screen-reader-text" for="' . esc_attr( $rpm_id ) . '">' . esc_html( $rate_limit_aria ) . '</label>';
		echo '<input id="' . esc_attr( $rpm_id ) . '" type="number" name="cybermaps_robots_manager[overrides][' . esc_attr( $bot_id ) . '][tpm]" value="' . esc_attr( $tpm_val ) . '" min="0" max="10000" step="1" class="bot-rpm-limit" data-default="0" style="width: 70px; font-size: 11px;" placeholder="' . esc_attr__( 'Default', 'cybermaps' ) . '">';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Render the manifest-target control or its unavailable state.
	 *
	 * @param object $bot_meta Registered crawler metadata.
	 * @param mixed  $checked  Current checked state.
	 */
	private static function render_manifest_control( string $bot_id, object $bot_meta, bool $is_ai_bot, $checked, string $manifest_target_label, string $aria_label ): void {
		echo '<td data-label="' . esc_attr( $manifest_target_label ) . '">';
		if ( $is_ai_bot ) {
			echo '<label class="cm-checkbox-wrapper" style="margin: 0; display: inline-flex;">';
			echo '<input type="checkbox" name="cybermaps_robots_manager[overrides][' . esc_attr( $bot_id ) . '][llm]" value="1" class="bot-llm-toggle" data-default="' . ( $bot_meta->default['llm'] ? '1' : '0' ) . '" aria-label="' . esc_attr( $aria_label ) . '" ' . checked( $checked, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</label>';
		} else {
			echo '<label class="cm-checkbox-wrapper" style="margin: 0; display: inline-flex; opacity: 0.3;">';
			echo '<input type="checkbox" disabled aria-label="' . esc_attr( $aria_label ) . '">';
			echo '</label>';
			echo \Cybermaps\Admin\AccessibleTooltip::get( __( 'This setting controls Cybermaps publication hints for crawler entries classified as AI-related; it is separate from robots.txt enforcement.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessibleTooltip returns escaped trusted markup.
		}
		echo '</td>';
	}

	private static function bot_control_label( string $type, string $bot_name ): string {
		$templates = array(
			/* translators: %s: crawler name. */
			'robots'      => __( 'Robots rule for %s', 'cybermaps' ),
			/* translators: %s: crawler name. */
			'manifest'    => __( 'Manifest target for %s', 'cybermaps' ),
			/* translators: %s: crawler name. */
			'unavailable' => __( 'Manifest targeting is unavailable for %s', 'cybermaps' ),
			/* translators: %s: crawler name. */
			'rate'        => __( 'Requests per minute limit for %s', 'cybermaps' ),
		);

		return sprintf(
			/* translators: %s: crawler name. */
			$templates[ $type ],
			$bot_name
		);
	}
	private static function get_category_tooltip( string $cat_id ): string {
		$tips = array(
			'search-engine'    => __( 'Traditional search crawler signatures. Robots rules are voluntary and do not guarantee inclusion or exclusion from a search index.', 'cybermaps' ),
			'ai-search'        => __( 'Crawler signatures associated with AI-assisted search products. Each provider decides how it interprets robots rules and separates product purposes.', 'cybermaps' ),
			'ai-user'          => __( 'On-demand fetchers that retrieve a page in response to a user action. They are distinct from scheduled indexing and model-development crawlers, and some providers may not apply robots.txt rules to these requests.', 'cybermaps' ),
			'ai-training'      => __( 'Crawler signatures associated with model-development purposes. A robots rule communicates a preference; it is not technical or legal enforcement.', 'cybermaps' ),
			'data-crawler'     => __( 'Crawlers that build archives, indexes, monitoring datasets, or structured data products. Their output may have downstream uses that differ from the crawler operator’s stated purpose.', 'cybermaps' ),
			'social'           => __( 'Social preview crawler signatures. Blocking a crawler may affect link previews, depending on the platform and its current behavior.', 'cybermaps' ),
			'seo-tool'         => __( 'Third-party SEO and research crawler signatures. Each service decides whether and how it honors the published rule.', 'cybermaps' ),
			'diagnostic'       => __( 'Infrastructure and monitoring crawler signatures. Blocking may affect a configured third-party service, but outcomes are provider-specific.', 'cybermaps' ),
			'unregistered-bot' => __( 'Requests without a signature in the bundled registry. This category cannot identify the operator, intent, or safety of the requester.', 'cybermaps' ),
			'other'            => __( 'Miscellaneous crawlers that do not fit the categories above. Toggle the category to allow or block them as a group.', 'cybermaps' ),
		);
		return $tips[ $cat_id ] ?? '';
	}
	public static function render_content_signals_field() {
		$manager_options = \Cybermaps\Core\ConfigurationStore::robots();
		$signals         = isset( $manager_options['content_signals'] ) ? (array) $manager_options['content_signals'] : array();

		$available_signals = array(
			'ai-train' => array(
				'label' => __( 'AI Training', 'cybermaps' ),
				'desc'  => __( 'Publisher preference for use in model training or fine-tuning.', 'cybermaps' ),
				'allow' => __( 'Publishes an affirmative preference. It does not prove that a provider collected or used the content.', 'cybermaps' ),
				'deny'  => __( 'Publishes a negative preference. It does not technically prevent collection or create enforcement.', 'cybermaps' ),
			),
			'search'   => array(
				'label' => __( 'Search Indexing', 'cybermaps' ),
				'desc'  => __( 'Publisher preference for search indexing and excerpt use by clients that recognize this field.', 'cybermaps' ),
				'allow' => __( 'Publishes an affirmative preference; appearance in any product remains the provider’s decision.', 'cybermaps' ),
				'deny'  => __( 'Publishes a negative preference; it does not guarantee removal from an index or affect clients that ignore the field.', 'cybermaps' ),
			),
			'ai-input' => array(
				'label' => __( 'AI Input / RAG', 'cybermaps' ),
				'desc'  => __( 'Publisher preference for retrieval or context-window use by clients that recognize this field.', 'cybermaps' ),
				'allow' => __( 'Publishes an affirmative preference. It does not establish ingestion, retrieval, citation, or answer behavior.', 'cybermaps' ),
				'deny'  => __( 'Publishes a negative preference. It does not technically prevent retrieval or use by clients that ignore it.', 'cybermaps' ),
			),
		);

		?>
		<div class="cm-section-prose" style="margin-bottom:16px;">
			<strong><?php esc_html_e( 'Machine-readable content preferences.', 'cybermaps' ); ?></strong>
			<?php echo wp_kses_post( __( 'Cybermaps publishes selected preferences as <code>Content-Signal</code> directives in its virtual <code>robots.txt</code> additions. They do not enforce access or use, and each crawler decides whether it recognizes or follows them.', 'cybermaps' ) ); ?>
		</div>

		<div class="cm-settings-card">
		<?php
		foreach ( $available_signals as $key => $meta ) :
			$value     = isset( $signals[ $key ] ) ? $signals[ $key ] : '';
			$select_id = 'cybermaps-content-signal-' . sanitize_key( (string) $key );
			?>
			<div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #f0f0f1;">
				<div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
					<label for="<?php echo esc_attr( $select_id ); ?>"><strong style="font-size:14px;"><?php echo esc_html( $meta['label'] ); ?></strong></label>
					<select id="<?php echo esc_attr( $select_id ); ?>" name="cybermaps_robots_manager[content_signals][<?php echo esc_attr( $key ); ?>]" style="width:90px;">
						<option value="" <?php selected( $value, '' ); ?>><?php esc_html_e( 'Not published', 'cybermaps' ); ?></option>
						<option value="yes" <?php selected( $value, 'yes' ); ?>><?php esc_html_e( 'Allow', 'cybermaps' ); ?></option>
						<option value="no" <?php selected( $value, 'no' ); ?>><?php esc_html_e( 'Deny', 'cybermaps' ); ?></option>
					</select>
				</div>
				<p class="cm-text-sm cm-text-muted" style="margin:0 0 10px 0;"><?php echo esc_html( $meta['desc'] ); ?></p>
				<div class="cm-desc-pros-cons">
					<div class="cm-desc-pros"><?php echo esc_html( $meta['allow'] ); ?></div>
					<div class="cm-desc-cons"><?php echo esc_html( $meta['deny'] ); ?></div>
				</div>
			</div>
		<?php endforeach; ?>
			<p class="cm-text-xs cm-text-muted" style="margin:0;"><?php esc_html_e( 'These are publisher preference declarations, not technical or legal enforcement. Field support and interpretation are client-specific.', 'cybermaps' ); ?></p>
		</div>
		<?php
	}
	public static function render_robots_takeover_field() {
		$options = \Cybermaps\Core\ConfigurationStore::robots();
		$checked = isset( $options['takeover_enabled'] ) && $options['takeover_enabled'] ? 'checked' : '';
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_robots_manager[takeover_enabled]" value="1" class="cm-toggle-input" ' . checked( $checked, 'checked', false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span>';
		echo '<span class="cm-toggle-label">' . esc_html__( 'Enable Cybermaps Robots.txt Takeover', 'cybermaps' ) . '</span>';
		echo '</label>';
		echo '<p class="cybermaps-desc">' . esc_html__( 'When enabled, Cybermaps replaces WordPress’s virtual robots.txt response and applies the matrix Robots Rule column. WordPress search visibility remains authoritative: a private site continues to publish a blanket disallow. A physical robots.txt served directly by the web server remains outside WordPress and must be configured separately.', 'cybermaps' ) . '</p>';
	}

	public static function render_content_usage_field(): void {
		$options   = \Cybermaps\Core\ConfigurationStore::robots();
		$enabled   = ! empty( $options['content_usage_enabled'] );
		$overrides = isset( $options['content_usage_overrides'] ) && is_array( $options['content_usage_overrides'] )
			? $options['content_usage_overrides']
			: array();
		$rows      = array();
		foreach ( $overrides as $path => $preferences ) {
			if ( ! is_array( $preferences ) ) {
				continue;
			}
			$rows[] = array( (string) $path, $preferences['ai-train'] ?? '', $preferences['search'] ?? '' );
		}
		$rows[] = array( '', '', '' );

		echo '<div class="cm-settings-card" style="margin-top:16px;">';
		echo '<p><label><input type="checkbox" name="cybermaps_robots_manager[content_usage_enabled]" value="1" ' . checked( $enabled, true, false ) . '> <strong>' . esc_html__( 'Publish AIPREF Content-Usage', 'cybermaps' ) . '</strong></label></p>';
		echo '<p class="cm-text-sm cm-text-muted">' . esc_html__( 'Opt in to the current Content-Usage draft. The sitewide values use the AI Training and Search Content-Signal choices above; path rules override them using longest-prefix matching. This is a publisher preference, not access control.', 'cybermaps' ) . '</p>';
		echo '<table class="widefat striped cybermaps-content-usage-table"><thead><tr><th>' . esc_html__( 'Path prefix', 'cybermaps' ) . '</th><th>' . esc_html__( 'AI training', 'cybermaps' ) . '</th><th>' . esc_html__( 'Search', 'cybermaps' ) . '</th></tr></thead><tbody data-max="' . esc_attr( (string) \Cybermaps\Discovery\Robots::MAX_CONTENT_USAGE_OVERRIDES ) . '">';
		foreach ( $rows as $index => $row ) {
			echo '<tr><td><label class="screen-reader-text" for="cybermaps-content-usage-path-' . esc_attr( (string) $index ) . '">' . esc_html__( 'Content-Usage path prefix', 'cybermaps' ) . '</label><input id="cybermaps-content-usage-path-' . esc_attr( (string) $index ) . '" type="text" name="cybermaps_robots_manager[content_usage_overrides][' . esc_attr( (string) $index ) . '][path]" value="' . esc_attr( $row[0] ) . '" placeholder="/private/" maxlength="2048" style="width:100%;"></td>';
			echo '<td><select name="cybermaps_robots_manager[content_usage_overrides][' . esc_attr( (string) $index ) . '][ai-train]"><option value="">' . esc_html__( 'Inherit', 'cybermaps' ) . '</option><option value="yes" ' . selected( $row[1], 'yes', false ) . '>' . esc_html__( 'Allow', 'cybermaps' ) . '</option><option value="no" ' . selected( $row[1], 'no', false ) . '>' . esc_html__( 'Deny', 'cybermaps' ) . '</option></select></td>';
			echo '<td><select name="cybermaps_robots_manager[content_usage_overrides][' . esc_attr( (string) $index ) . '][search]"><option value="">' . esc_html__( 'Inherit', 'cybermaps' ) . '</option><option value="yes" ' . selected( $row[2], 'yes', false ) . '>' . esc_html__( 'Allow', 'cybermaps' ) . '</option><option value="no" ' . selected( $row[2], 'no', false ) . '>' . esc_html__( 'Deny', 'cybermaps' ) . '</option></select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<button type="button" class="button cybermaps-add-content-usage-row" style="margin-top:10px;">' . esc_html__( 'Add path rule', 'cybermaps' ) . '</button>';
		echo '<p class="cm-text-xs cm-text-muted">' . esc_html__( 'Up to 50 valid leading-slash path prefixes are stored. Empty rows and unsupported preferences are ignored.', 'cybermaps' ) . '</p></div>';
		wp_add_inline_script(
			'cybermaps-command-center',
			'jQuery(function($){$(".cybermaps-add-content-usage-row").on("click",function(){var $body=$(this).prevAll("table").first().find("tbody"),max=parseInt($body.data("max"),10),index=$body.children("tr").length;if(index>=max){return;}var $row=$body.children("tr").last().clone();$row.find("input,select").each(function(){var $field=$(this),name=$field.attr("name");$field.attr("name",name.replace(/\\[\\d+\\]/,"["+index+"]"));if($field.is("input")){$field.val("");}else{$field.val("");}});$row.find("label").each(function(){$(this).attr("for",$(this).attr("for").replace(/-\\d+$/,"-"+index));});$body.append($row);});});'
		);
	}
}
