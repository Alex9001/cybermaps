<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiscoveryFields {

	/** Render the explicit optional MCP access tier. */
	public static function render_mcp_mode( $args ): void {
		unset( $args );
		\Cybermaps\Admin\Settings\Fields\FieldRenderer::render_select_field(
			array(
				'label_for'   => 'mcp_mode',
				'default'     => 'off',
				'options'     => array(
					'off'        => __( 'Off', 'cybermaps' ),
					'discovery'  => __( 'Discovery only', 'cybermaps' ),
					'read_only'  => __( 'Read-only', 'cybermaps' ),
					'operations' => __( 'Operations', 'cybermaps' ),
				),
				'description' => __( 'MCP is disabled by default and remains unavailable while the AI Publication Hub is disabled.', 'cybermaps' ),
				'maturity'    => 'mcp',
			)
		);
	}

	public static function render_ai_sitemap_controls() {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$types   = isset( $options['ai_sitemap_types'] ) ? (array) $options['ai_sitemap_types'] : array( 'post', 'page' );
		$limit   = \Cybermaps\Discovery\PublicationConstraints::ai_sitemap_limit(
			$options['ai_sitemap_limit'] ?? \Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_DEFAULT
		);

		// Include public custom types such as portfolios while consistently
		// excluding WordPress attachment rows from publication inventories.
		$public_types = \Cybermaps\Core\PublicationPostTypes::objects();

		echo '<strong>' . esc_html__( 'Included Content Types:', 'cybermaps' ) . '</strong><br><div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 15px;">';
		foreach ( $public_types as $pt ) {
			$checked = in_array( $pt->name, $types, true ) ? 'checked' : '';
			echo '<label class="cm-checkbox-wrapper">';
			echo '<input type="checkbox" name="cybermaps_settings[ai_sitemap_types][]" value="' . esc_attr( $pt->name ) . '" ' . checked( $checked, 'checked', false ) . '> ' . esc_html( $pt->label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</label>';
		}
		echo '</div>';

		echo '<div style="margin-top: 15px;">';
		echo '<label for="ai_sitemap_limit"><strong>' . esc_html__( 'Items per Type:', 'cybermaps' ) . '</strong></label><br>';
		echo '<input type="number" id="ai_sitemap_limit" name="cybermaps_settings[ai_sitemap_limit]" value="' . esc_attr( $limit ) . '" min="' . esc_attr( \Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_MIN ) . '" max="' . esc_attr( \Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_MAX ) . '" class="small-text"> <span class="cybermaps-desc">'
			. esc_html(
				sprintf(
					/* translators: 1: minimum item limit, 2: maximum item limit. */
					__( 'Maximum eligible posts per selected type in ai-sitemap.xml (%1$d–%2$d).', 'cybermaps' ),
					\Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_MIN,
					\Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_MAX
				)
			)
			. '</span>';
		echo '</div>';
	}

	public static function render_global_ai_excluded_ids(): void {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$ids     = \Cybermaps\Core\PositiveIdList::parse( $options['llms_exclude_ids'] ?? '' );

		echo '<input type="text" id="llms_exclude_ids" name="cybermaps_settings[llms_exclude_ids]" value="' . esc_attr( implode( ', ', $ids ) ) . '" class="regular-text" placeholder="12, 45, 89">';
		echo '<p class="description">' . esc_html__( 'Comma-separated post IDs excluded from Cybermaps AI content inventories, including LLMS, the feed, AI sitemap, chunks, search, and crawler-friendly search suggestions.', 'cybermaps' ) . '</p>';
	}
	public static function ai_endpoints_section_callback() {
		echo '<div class="cm-section-prose">' . wp_kses_post( __( '<strong>Per-publication content control.</strong> Cybermaps emits a mix of established formats, community conventions, and vendor extensions. Configure what each publication exposes and use AI Discovery Status to see its classification. <span class="cm-field-hint">Most publications honor the AI Publication Hub master toggle above.</span>', 'cybermaps' ) ) . '</div>';
	}
	public static function render_ai_feed_controls() {
		$options         = \Cybermaps\Core\ConfigurationStore::settings();
		$full_content    = ! empty( $options['ai_feed_full_content'] );
		$include_authors = ! empty( $options['ai_feed_include_authors'] );
		$limit           = \Cybermaps\Discovery\PublicationConstraints::feed_limit(
			$options['ai_feed_limit'] ?? \Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_DEFAULT
		);

		echo '<div class="cybermaps-benefit">';
		echo '<span class="dashicons dashicons-rss"></span>';
		echo '<div>' . wp_kses_post( __( '<strong>Payload choice:</strong> Including full content gives a feed reader more stored text in one request, but increases response size. Cybermaps does not control whether an external retrieval system consumes it.', 'cybermaps' ) ) . '</div>';
		echo '</div>';

		echo '<div style="display: flex; flex-direction: column; gap: 5px;">';
		echo '<label class="cm-checkbox-wrapper"><input type="checkbox" name="cybermaps_settings[ai_feed_full_content]" value="1" ' . checked( $full_content, true, false ) . '> ' . esc_html__( 'Include Full Stored Content', 'cybermaps' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label class="cm-checkbox-wrapper"><input type="checkbox" name="cybermaps_settings[ai_feed_include_authors]" value="1" ' . checked( $include_authors, true, false ) . '> ' . esc_html__( 'Include Author Metadata', 'cybermaps' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<div style="margin-top: 10px;">';
		echo '<label for="ai_feed_limit"><strong>' . esc_html__( 'Max Items:', 'cybermaps' ) . '</strong></label> <input type="number" id="ai_feed_limit" name="cybermaps_settings[ai_feed_limit]" value="' . esc_attr( $limit ) . '" min="' . esc_attr( \Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_MIN ) . '" max="' . esc_attr( \Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_MAX ) . '" class="small-text"> <span class="cybermaps-desc">'
			. esc_html(
				sprintf(
					/* translators: 1: minimum feed item limit, 2: maximum feed item limit. */
					__( 'Recent eligible posts in feed.json (%1$d–%2$d).', 'cybermaps' ),
					\Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_MIN,
					\Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_MAX
				)
			)
			. '</span>';
		echo '</div>';
	}
	public static function render_custom_links_manager() {
		$options           = \Cybermaps\Core\ConfigurationStore::settings();
		$links             = isset( $options['ai_sitemap_custom_links'] ) ? (array) $options['ai_sitemap_custom_links'] : array();
		$custom_links_i18n = wp_json_encode(
			array(
				'externalResourceUrl'      => __( 'External resource URL', 'cybermaps' ),
				'externalResourcePriority' => __( 'External resource priority', 'cybermaps' ),
				'action'                   => __( 'Action', 'cybermaps' ),
				'remove'                   => __( 'Remove', 'cybermaps' ),
				'noLinks'                  => __( 'No external links added yet.', 'cybermaps' ),
			)
		);

		echo '<div id="ai-sitemap-custom-links-container" style="max-width: 800px;">';
		echo '<p class="cybermaps-desc">'
			. wp_kses_post(
				sprintf(
					/* translators: %d: maximum number of custom links. */
					__( 'Add up to %d external resources to <code>ai-sitemap.xml</code>. These links are not added to LLMS or the budgeted site briefing.', 'cybermaps' ),
					\Cybermaps\Discovery\PublicationConstraints::CUSTOM_LINKS_MAX
				)
			)
			. '</p>';
		echo '<table class="widefat fixed striped cybermaps-responsive-editor-table" id="ai-custom-links-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'External resources published in the AI sitemap', 'cybermaps' ) . '</caption>';
		echo '<thead><tr><th scope="col">' . esc_html__( 'External Resource URL', 'cybermaps' ) . '</th><th scope="col" style="width: 100px;">' . esc_html__( 'Priority', 'cybermaps' ) . '</th><th scope="col" style="width: 80px;">' . esc_html__( 'Action', 'cybermaps' ) . '</th></tr></thead>';
		echo '<tbody>';

		if ( empty( $links ) ) {
			echo '<tr class="no-links"><td colspan="3" style="text-align: center;">' . esc_html__( 'No external links added yet.', 'cybermaps' ) . '</td></tr>';
		} else {
			foreach ( $links as $index => $link ) {
				echo '<tr>';
				echo '<td data-label="' . esc_attr__( 'External Resource URL', 'cybermaps' ) . '"><input type="url" name="cybermaps_settings[ai_sitemap_custom_links][' . esc_attr( $index ) . '][url]" value="' . esc_url( $link['url'] ) . '" aria-label="' . esc_attr__( 'External resource URL', 'cybermaps' ) . '" style="width: 100%;"></td>';
				echo '<td data-label="' . esc_attr__( 'Priority', 'cybermaps' ) . '"><input type="number" name="cybermaps_settings[ai_sitemap_custom_links][' . esc_attr( $index ) . '][priority]" value="' . esc_attr( $link['priority'] ) . '" step="0.1" min="0.1" max="1.0" aria-label="' . esc_attr__( 'External resource priority', 'cybermaps' ) . '" style="width: 70px;"></td>';
				echo '<td data-label="' . esc_attr__( 'Action', 'cybermaps' ) . '"><button type="button" class="button remove-row">' . esc_html__( 'Remove', 'cybermaps' ) . '</button></td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';
		echo '<p><button type="button" class="button" id="add-ai-custom-link">' . esc_html__( 'Add External Resource', 'cybermaps' ) . '</button></p>';
		echo '</div>';

		wp_add_inline_script(
			'cybermaps-command-center',
			'document.addEventListener("DOMContentLoaded", function() {
    var tableElement = document.getElementById("ai-custom-links-table");
    var addBtn = document.getElementById("add-ai-custom-link");
    if (!tableElement || !addBtn) return;
    var table = tableElement.getElementsByTagName("tbody")[0];
    var maxLinks = ' . (int) \Cybermaps\Discovery\PublicationConstraints::CUSTOM_LINKS_MAX . ';
    var copy = ' . $custom_links_i18n . ';

    function reindexLinks() {
        table.querySelectorAll("tr:not(.no-links)").forEach(function(row, index) {
            var url = row.querySelector("input[type=\"url\"]");
            var priority = row.querySelector("input[type=\"number\"]");
            if (row.cells.length >= 3) {
                row.cells[0].setAttribute("data-label", copy.externalResourceUrl);
                row.cells[1].setAttribute("data-label", copy.externalResourcePriority);
                row.cells[2].setAttribute("data-label", copy.action);
            }
            if (url) {
                url.name = "cybermaps_settings[ai_sitemap_custom_links][" + index + "][url]";
                url.setAttribute("aria-label", copy.externalResourceUrl);
            }
            if (priority) {
                priority.name = "cybermaps_settings[ai_sitemap_custom_links][" + index + "][priority]";
                priority.setAttribute("aria-label", copy.externalResourcePriority);
            }
        });
        addBtn.disabled = table.querySelectorAll("tr:not(.no-links)").length >= maxLinks;
    }

    function insertEmptyRow() {
        var emptyRow = table.insertRow();
        var cell = emptyRow.insertCell();
        emptyRow.className = "no-links";
        cell.colSpan = 3;
        cell.style.textAlign = "center";
        cell.textContent = copy.noLinks;
    }

    reindexLinks();

    addBtn.addEventListener("click", function() {
        var noLinksRow = table.querySelector(".no-links");
        if (noLinksRow) noLinksRow.remove();
        reindexLinks();
        var index = table.querySelectorAll("tr:not(.no-links)").length;
        if (index >= maxLinks) return;
        var row = table.insertRow();
        row.innerHTML =
            "<td><input type=\"url\" name=\"cybermaps_settings[ai_sitemap_custom_links][" + index + "][url]\" value=\"\" style=\"width: 100%;\" placeholder=\"https://external-page.com\"></td>" +
            "<td><input type=\"number\" name=\"cybermaps_settings[ai_sitemap_custom_links][" + index + "][priority]\" value=\"0.5\" step=\"0.1\" min=\"0.1\" max=\"1.0\" style=\"width: 70px;\"></td>" +
            "<td><button type=\"button\" class=\"button remove-row\"></button></td>";
        var removeButton = row.querySelector(".remove-row");
        removeButton.textContent = copy.remove;
        attachRemoveEvent(removeButton);
        reindexLinks();
    });

    function attachRemoveEvent(btn) {
        btn.addEventListener("click", function() {
            btn.closest("tr").remove();
            reindexLinks();
            if (table.rows.length === 0) {
                insertEmptyRow();
            }
            reindexLinks();
        });
    }

    table.querySelectorAll(".remove-row").forEach(attachRemoveEvent);
});'
		);
	}
	public static function render_ai_actions_manager() {
		$options  = \Cybermaps\Core\ConfigurationStore::settings();
		$mappings = isset( $options['ai_action_mappings'] ) ? (array) $options['ai_action_mappings'] : array();

		$action_types = array(
			'ContactAction'   => __( 'Contact / Message', 'cybermaps' ),
			'BuyAction'       => __( 'Purchase / Checkout', 'cybermaps' ),
			'ReserveAction'   => __( 'Booking / Reservation', 'cybermaps' ),
			'SubscribeAction' => __( 'Subscription / News', 'cybermaps' ),
			'SearchAction'    => __( 'Search Discovery', 'cybermaps' ),
		);
		$actions_i18n = wp_json_encode(
			array(
				'actionUrl'         => __( 'Action URL', 'cybermaps' ),
				'actionType'        => __( 'Action type', 'cybermaps' ),
				'actionDescription' => __( 'Action description', 'cybermaps' ),
				'descriptionPh'     => __( 'Core contact page', 'cybermaps' ),
				'action'            => __( 'Action', 'cybermaps' ),
				'remove'            => __( 'Remove', 'cybermaps' ),
				'noActions'         => __( 'No action mappings defined.', 'cybermaps' ),
				'types'             => $action_types,
			)
		);

		echo '<div id="ai-actions-manager-container" style="max-width: 800px;">';
		echo '<p class="cybermaps-desc">'
			. esc_html(
				sprintf(
					/* translators: %d: maximum number of action mappings. */
					__( 'Publish up to %d operator-defined action links and descriptions. Cybermaps does not verify that a client understands the format or that a target performs the declared action.', 'cybermaps' ),
					\Cybermaps\Discovery\PublicationConstraints::ACTION_MAPPINGS_MAX
				)
			)
			. '</p>';
		echo '<table class="widefat fixed striped cybermaps-responsive-editor-table" id="ai-actions-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Machine-readable action mappings', 'cybermaps' ) . '</caption>';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Action URL', 'cybermaps' ) . '</th><th scope="col" style="width: 150px;">' . esc_html__( 'Action Type', 'cybermaps' ) . '</th><th scope="col">' . esc_html__( 'Description', 'cybermaps' ) . '</th><th scope="col" style="width: 80px;">' . esc_html__( 'Action', 'cybermaps' ) . '</th></tr></thead>';
		echo '<tbody>';

		if ( empty( $mappings ) ) {
			echo '<tr class="no-actions"><td colspan="4" style="text-align: center;">' . esc_html__( 'No action mappings defined.', 'cybermaps' ) . '</td></tr>';
		} else {
			foreach ( $mappings as $index => $map ) {
				if ( ! is_array( $map ) ) {
					continue;
				}
				$saved_type = \Cybermaps\Discovery\PublicationConstraints::action_type( $map['type'] ?? '' );
				echo '<tr>';
				echo '<td data-label="' . esc_attr__( 'Action URL', 'cybermaps' ) . '"><input type="url" name="cybermaps_settings[ai_action_mappings][' . esc_attr( $index ) . '][url]" value="' . esc_url( (string) ( $map['url'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Action URL', 'cybermaps' ) . '" style="width: 100%;"></td>';
				echo '<td data-label="' . esc_attr__( 'Action Type', 'cybermaps' ) . '"><select name="cybermaps_settings[ai_action_mappings][' . esc_attr( $index ) . '][type]" aria-label="' . esc_attr__( 'Action type', 'cybermaps' ) . '" style="width: 100%;">';
				foreach ( $action_types as $val => $label ) {
					echo '<option value="' . esc_attr( $val ) . '" ' . selected( $saved_type, $val, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select></td>';
				echo '<td data-label="' . esc_attr__( 'Description', 'cybermaps' ) . '"><input type="text" name="cybermaps_settings[ai_action_mappings][' . esc_attr( $index ) . '][desc]" value="' . esc_attr( (string) ( $map['desc'] ?? '' ) ) . '" maxlength="' . esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ) . '" aria-label="' . esc_attr__( 'Action description', 'cybermaps' ) . '" style="width: 100%;"></td>';
				echo '<td data-label="' . esc_attr__( 'Action', 'cybermaps' ) . '"><button type="button" class="button remove-action-row">' . esc_html__( 'Remove', 'cybermaps' ) . '</button></td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';
		echo '<p><button type="button" class="button" id="add-ai-action">' . esc_html__( 'Add Action Endpoint', 'cybermaps' ) . '</button></p>';
		echo '</div>';

		wp_add_inline_script(
			'cybermaps-command-center',
			'document.addEventListener("DOMContentLoaded", function() {
    var table = document.querySelector("#ai-actions-table tbody");
    var addBtn = document.getElementById("add-ai-action");
    if (!table || !addBtn) return;
    var maxActions = ' . (int) \Cybermaps\Discovery\PublicationConstraints::ACTION_MAPPINGS_MAX . ';
    var copy = ' . $actions_i18n . ';

    function reindexActions() {
        table.querySelectorAll("tr:not(.no-actions)").forEach(function(row, index) {
            var url = row.querySelector("input[type=\"url\"]");
            var type = row.querySelector("select");
            var desc = row.querySelector("input[type=\"text\"]");
            if (row.cells.length >= 4) {
                row.cells[0].setAttribute("data-label", copy.actionUrl);
                row.cells[1].setAttribute("data-label", copy.actionType);
                row.cells[2].setAttribute("data-label", copy.actionDescription);
                row.cells[3].setAttribute("data-label", copy.action);
            }
            if (url) {
                url.name = "cybermaps_settings[ai_action_mappings][" + index + "][url]";
                url.setAttribute("aria-label", copy.actionUrl);
            }
            if (type) {
                type.name = "cybermaps_settings[ai_action_mappings][" + index + "][type]";
                type.setAttribute("aria-label", copy.actionType);
            }
            if (desc) {
                desc.name = "cybermaps_settings[ai_action_mappings][" + index + "][desc]";
                desc.setAttribute("aria-label", copy.actionDescription);
                desc.setAttribute("maxlength", "' . (int) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH . '");
            }
        });
        addBtn.disabled = table.querySelectorAll("tr:not(.no-actions)").length >= maxActions;
    }

    function insertEmptyRow() {
        var emptyRow = table.insertRow();
        var cell = emptyRow.insertCell();
        emptyRow.className = "no-actions";
        cell.colSpan = 4;
        cell.style.textAlign = "center";
        cell.textContent = copy.noActions;
    }

    reindexActions();

    addBtn.addEventListener("click", function() {
        var noActionsRow = table.querySelector(".no-actions");
        if (noActionsRow) noActionsRow.remove();
        reindexActions();
        var index = table.querySelectorAll("tr:not(.no-actions)").length;
        if (index >= maxActions) return;
        var row = table.insertRow();
        row.innerHTML =
            "<td><input type=\"url\" name=\"cybermaps_settings[ai_action_mappings][" + index + "][url]\" value=\"\" style=\"width: 100%;\" placeholder=\"https://site.com/contact\"></td>" +
            "<td><select name=\"cybermaps_settings[ai_action_mappings][" + index + "][type]\" style=\"width: 100%;\"></select></td>" +
            "<td><input type=\"text\" name=\"cybermaps_settings[ai_action_mappings][" + index + "][desc]\" value=\"\" style=\"width: 100%;\"></td>" +
            "<td><button type=\"button\" class=\"button remove-action-row\"></button></td>";
        var typeSelect = row.querySelector("select");
        Object.keys(copy.types).forEach(function(value) {
            typeSelect.appendChild(new Option(copy.types[value], value));
        });
        row.querySelector("input[type=\"text\"]").setAttribute("placeholder", copy.descriptionPh);
        var removeButton = row.querySelector(".remove-action-row");
        removeButton.textContent = copy.remove;
        attachActionRemoveEvent(removeButton);
        reindexActions();
    });

    document.querySelectorAll(".remove-action-row").forEach(attachActionRemoveEvent);

    function attachActionRemoveEvent(btn) {
        btn.addEventListener("click", function() {
            btn.closest("tr").remove();
            reindexActions();
            if (table.rows.length === 0) {
                insertEmptyRow();
            }
            reindexActions();
        });
    }
});'
		);
	}
	public static function discovery_hub_section_callback() {
		echo '<div class="cm-section-prose">' . wp_kses_post( __( '<strong>Master control for AI publication.</strong> The AI Publication Hub controls registered fixed-path discovery publications, public discovery REST routes, localized LLMS, and RAG chunk routes. Enabling it makes those representations available; it does not guarantee discovery, ingestion, citation, or ranking. Use AI Discovery Status to verify fixed-path public delivery. <span class="cm-scope-badge cm-scope-all">affects the public discovery surface</span>', 'cybermaps' ) ) . '</div>';
	}
	public static function render_api_catalog() {
		$hub_enabled = \Cybermaps\Discovery\Integrity::is_hub_enabled();
		$catalog_url = \Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'api_catalog' );

		echo '<div class="cybermaps-benefit" aria-describedby="api-catalog-maturity" style="border-left-color: ' . ( $hub_enabled ? '#10b981' : '#94a3b8' ) . ';">';
		echo '<span class="dashicons dashicons-rest-api" style="color: ' . ( $hub_enabled ? '#10b981' : '#94a3b8' ) . ';"></span>';
		echo '<div>';
		if ( $hub_enabled ) {
			echo '<strong>' . esc_html__( 'Web API Discovery:', 'cybermaps' ) . '</strong> ' . esc_html__( 'Cybermaps publishes its Linkset API Catalog at /.well-known/api-catalog using the RFC 9727 profile and registered api-catalog relation. /api-catalog remains a dynamic compatibility alias.', 'cybermaps' );
			echo '<div style="margin-top: 10px;">';
			echo '<a href="' . esc_url( $catalog_url ) . '" target="_blank" rel="noopener noreferrer" class="button button-secondary">' . esc_html__( 'View API Catalog', 'cybermaps' ) . '</a>';
			echo '</div>';
			echo '<p style="margin-top: 10px; color: #64748b;">' . esc_html__( 'The API Catalog stays dynamic in every Static File Engine mode so its Linkset media profile, rel="api-catalog" header, and request analytics work without web-server configuration. AI Discovery Status validates the public result.', 'cybermaps' ) . '</p>';
		} else {
			echo '<strong>' . esc_html__( 'Web API Discovery:', 'cybermaps' ) . '</strong> ';
			echo '<span style="color:#64748b;">' . esc_html__( 'Inactive — the AI Publication Hub is currently disabled.', 'cybermaps' ) . '</span>';
		}
		echo '</div>';
		echo '</div>';
		\Cybermaps\Admin\MaturityGuidance::render( 'api-catalog', 'api_catalog' );
	}
	public static function render_taxonomy_filter() {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$value   = isset( $options['llms_filter_taxonomies'] ) ? $options['llms_filter_taxonomies'] : '';
		echo '<input type="text" id="llms_filter_taxonomies" name="cybermaps_settings[llms_filter_taxonomies]" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="category, post_tag">';
		echo '<p class="description">' . wp_kses_post( __( 'Comma-separated taxonomy names, such as <code>category</code> or <code>post_tag</code>. When set, the LLMS inventory includes only posts assigned at least one term in any named taxonomy. This selects whole taxonomies, not individual terms.', 'cybermaps' ) ) . '</p>';
	}
	public static function render_capabilities_field() {
		$options      = \Cybermaps\Core\ConfigurationStore::settings();
		$capabilities = isset( $options['ai_capabilities'] ) ? (array) $options['ai_capabilities'] : array();

		$options_list = array(
			'search_content'   => __( 'Declare content search', 'cybermaps' ),
			'read_articles'    => __( 'Declare article reading', 'cybermaps' ),
			'extract_entities' => __( 'Declare entity extraction', 'cybermaps' ),
		);

		echo '<p class="cybermaps-desc">' . esc_html__( 'Publishes operator-selected capability labels in the AI Discovery Manifest. These declarations do not enable or disable REST search, create reader or entity APIs, or verify that a client can perform the claimed operation. Select only labels that accurately describe your published site surface.', 'cybermaps' ) . '</p>';
		echo '<div style="display: flex; flex-direction: column; gap: 5px;">';
		foreach ( $options_list as $key => $label ) {
			$checked = in_array( $key, $capabilities, true ) ? 'checked' : '';
			echo '<label class="cm-checkbox-wrapper">';
			echo '<input type="checkbox" name="cybermaps_settings[ai_capabilities][]" value="' . esc_attr( $key ) . '" ' . checked( $checked, 'checked', false ) . '> ' . esc_html( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</label>';
		}
		echo '</div>';
	}
	public static function render_license_select() {
		$options  = \Cybermaps\Core\ConfigurationStore::settings();
		$selected = isset( $options['llms_content_license'] ) ? $options['llms_content_license'] : '';
		$licenses = array(
			'none'         => array(
				'key'   => '',
				'label' => __( 'No Assertion', 'cybermaps' ),
				'desc'  => __( '<strong>No assertion:</strong> Cybermaps will not claim that your site content is covered by a particular license.', 'cybermaps' ),
			),
			'CC-BY-4-0'    => array(
				'key'   => 'CC-BY-4.0',
				'label' => __( 'CC BY 4.0', 'cybermaps' ),
				'desc'  => __( '<strong>Operator assertion:</strong> Publishes the CC BY 4.0 label. Review the license terms and confirm that you have the right to apply it; Cybermaps does not enforce compliance.', 'cybermaps' ),
			),
			'CC-BY-SA-4-0' => array(
				'key'   => 'CC-BY-SA-4.0',
				'label' => __( 'CC BY-SA 4.0', 'cybermaps' ),
				'desc'  => __( '<strong>Operator assertion:</strong> Publishes the CC BY-SA 4.0 label. Review the license terms and confirm that you have the right to apply it; Cybermaps does not enforce compliance.', 'cybermaps' ),
			),
			'CC-BY-NC-4-0' => array(
				'key'   => 'CC-BY-NC-4.0',
				'label' => __( 'CC BY-NC 4.0', 'cybermaps' ),
				'desc'  => __( '<strong>Operator assertion:</strong> Publishes the CC BY-NC 4.0 label. This metadata does not technically prevent access or use.', 'cybermaps' ),
			),
			'CC0-1-0'      => array(
				'key'   => 'CC0-1.0',
				'label' => __( 'CC0 (Public Domain)', 'cybermaps' ),
				'desc'  => __( '<strong>Operator assertion:</strong> Publishes the CC0 1.0 label. Confirm that you have the rights required to make this dedication.', 'cybermaps' ),
			),
			'MIT'          => array(
				'key'   => 'MIT',
				'label' => __( 'MIT License', 'cybermaps' ),
				'desc'  => __( '<strong>Operator assertion:</strong> Publishes the MIT label. This software-oriented license may not fit general site content; review it before use.', 'cybermaps' ),
			),
			'GPL-3-0'      => array(
				'key'   => 'GPL-3.0',
				'label' => __( 'GPL v3.0', 'cybermaps' ),
				'desc'  => __( '<strong>Operator assertion:</strong> Publishes the GPL-3.0 label. This software license may not fit general site content; review it before use.', 'cybermaps' ),
			),
			'Apache-2-0'   => array(
				'key'   => 'Apache-2.0',
				'label' => __( 'Apache 2.0', 'cybermaps' ),
				'desc'  => __( '<strong>Operator assertion:</strong> Publishes the Apache-2.0 label. This software license may not fit general site content; review it before use.', 'cybermaps' ),
			),
			'Proprietary'  => array(
				'key'   => 'Proprietary',
				'label' => __( 'All Rights Reserved', 'cybermaps' ),
				'desc'  => __( '<strong>Operator assertion:</strong> Publishes an All Rights Reserved label. It is a notice, not a technical access control.', 'cybermaps' ),
			),
		);

		echo '<div class="cybermaps-license-picker" role="group" aria-label="' . esc_attr__( 'Content license assertion', 'cybermaps' ) . '">';
		foreach ( $licenses as $id => $meta ) {
			$active = ( $selected === $meta['key'] ) ? 'active' : '';
			echo '<button type="button" class="cybermaps-license-btn ' . esc_attr( $active ) . '" data-license="' . esc_attr( $meta['key'] ) . '" data-target="' . esc_attr( $id ) . '" aria-pressed="' . ( '' !== $active ? 'true' : 'false' ) . '" aria-controls="license-info-' . esc_attr( $id ) . '">' . esc_html( $meta['label'] ) . '</button>';
		}
		echo '<input type="hidden" id="cybermaps_llms_license" name="cybermaps_settings[llms_content_license]" value="' . esc_attr( $selected ) . '">';
		echo '</div>';

		foreach ( $licenses as $id => $meta ) {
			$display = ( $selected === $meta['key'] ) ? 'display:flex;' : 'display:none;';
			echo '<div id="license-info-' . esc_attr( $id ) . '" class="cybermaps-benefit cybermaps-license-info" style="' . esc_attr( $display ) . '">';
			echo '<span class="dashicons dashicons-info"></span>';
			echo '<div>' . wp_kses_post( $meta['desc'] ) . '</div>';
			echo '</div>';
		}

		wp_add_inline_script(
			'cybermaps-command-center',
			'jQuery(document).ready(function($) {
    $(".cybermaps-license-btn").on("click", function() {
        var val = $(this).data("license");
        var target = $(this).data("target");
        $(".cybermaps-license-btn").removeClass("active").attr("aria-pressed", "false");
        $(this).addClass("active").attr("aria-pressed", "true");
        $("#cybermaps_llms_license").val(val);
        $(".cybermaps-license-info").hide();
        $("#license-info-" + target).css("display", "flex");
    });
});'
		);
	}
	public static function render_ai_identity_controls() {
		$options            = \Cybermaps\Core\ConfigurationStore::settings();
		$expose_admin       = ! empty( $options['ai_kg_expose_admin'] );
		$link_org           = ! empty( $options['ai_kg_link_org'] );
		$manifest_endpoints = isset( $options['ai_manifest_endpoints'] ) ? (array) $options['ai_manifest_endpoints'] : array( 'llms.txt', 'feed.json', 'knowledge-graph.json', 'ai-sitemap.xml' );

		echo '<strong>' . esc_html__( 'Knowledge Graph Privacy:', 'cybermaps' ) . '</strong><br><div style="margin-top: 10px; display: flex; flex-direction: column; gap: 5px; margin-bottom: 15px;">';
		echo '<label class="cm-checkbox-wrapper"><input type="checkbox" name="cybermaps_settings[ai_kg_expose_admin]" value="1" ' . checked( $expose_admin, true, false ) . '> ' . esc_html__( 'Expose Admin User as “Person”', 'cybermaps' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label class="cm-checkbox-wrapper"><input type="checkbox" name="cybermaps_settings[ai_kg_link_org]" value="1" ' . checked( $link_org, true, false ) . '> ' . esc_html__( 'Link Primary Entity to Website', 'cybermaps' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		$pinned_knowledge = isset( $options['llms_pinned_ids'] ) ? $options['llms_pinned_ids'] : '';

		echo '<div style="margin-bottom: 20px; border-top: 1px solid var(--cm-soft-bg); padding-top: 15px;">';
		$pinned_help = sprintf(
			/* translators: %d: maximum number of priority IDs. */
			__( 'Up to %d unique, comma-separated IDs considered first by the experimental budgeted briefing. Every item still has to fit the configured total budget.', 'cybermaps' ),
			\Cybermaps\Discovery\PublicationConstraints::BRIEFING_PINNED_IDS_MAX
		);
		echo '<label for="llms_pinned_ids"><strong>' . esc_html__( 'Briefing Priority IDs:', 'cybermaps' ) . '</strong></label> ' . \Cybermaps\Admin\AccessibleTooltip::get( $pinned_help ) . '<br>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The label and AccessibleTooltip markup are escaped.
		echo '<input type="text" id="llms_pinned_ids" name="cybermaps_settings[llms_pinned_ids]" value="' . esc_attr( $pinned_knowledge ) . '" class="regular-text" style="margin-top: 5px; width: 100%; max-width: 400px;" placeholder="' . esc_attr__( 'e.g. 102, 54, 8', 'cybermaps' ) . '">';
		echo '<p class="cybermaps-desc">' . esc_html__( 'Priority affects selection order only; it is not a quality or authority score.', 'cybermaps' ) . '</p>';
		echo '</div>';

		$tldr_token_budget = isset( $options['llms_tldr_token_budget'] )
			? (int) $options['llms_tldr_token_budget']
			: \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_DEFAULT;

		echo '<div style="margin-bottom: 20px; border-top: 1px solid var(--cm-soft-bg); padding-top: 15px;">';
		echo '<label for="llms_tldr_token_budget"><strong>' . esc_html__( 'Briefing Token Budget:', 'cybermaps' ) . '</strong></label> ' . \Cybermaps\Admin\AccessibleTooltip::get( __( 'Approximate maximum for the entire file, including its header and priority entries. Cybermaps estimates one token per four UTF-8 bytes.', 'cybermaps' ) ) . '<br>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The label and AccessibleTooltip markup are escaped.
		echo '<input type="number" id="llms_tldr_token_budget" name="cybermaps_settings[llms_tldr_token_budget]" value="' . esc_attr( (string) $tldr_token_budget ) . '" min="' . esc_attr( (string) \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MIN ) . '" max="' . esc_attr( (string) \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MAX ) . '" step="1000" class="small-text" style="margin-top: 5px;">';
		echo '<p class="cybermaps-desc">' . esc_html__( 'The full eligible inventory is considered. Entries are ordered deterministically and omitted only when they do not fit.', 'cybermaps' ) . '</p>';
		echo '</div>';

		echo '<strong>' . esc_html__( 'AI Manifest (/ai.json) Endpoint Visibility:', 'cybermaps' ) . '</strong><br><div style="margin-top: 10px; display: flex; flex-direction: column; gap: 5px;">';
		$endpoints = array(
			'llms.txt'             => __( 'llms.txt Hub', 'cybermaps' ),
			'llms-full.txt'        => __( 'Expanded LLMS Map', 'cybermaps' ),
			'skill.md'             => __( 'AI Manual (skill.md)', 'cybermaps' ),
			'ai-usage.json'        => __( 'Usage Policy (ai-usage.json)', 'cybermaps' ),
			'ai-actions.json'      => __( 'Action Sitemap (ai-actions.json)', 'cybermaps' ),
			'llms-tldr.txt'        => __( 'Experimental Budgeted Briefing', 'cybermaps' ),
			'feed.json'            => __( 'Freshness Feed', 'cybermaps' ),
			'knowledge-graph.json' => __( 'Knowledge Graph', 'cybermaps' ),
			'ai-sitemap.xml'       => __( 'AI Sitemap', 'cybermaps' ),
		);

		foreach ( $endpoints as $key => $label ) {
			$checked = in_array( $key, $manifest_endpoints, true ) ? 'checked' : '';
			echo '<label class="cm-checkbox-wrapper"><input type="checkbox" name="cybermaps_settings[ai_manifest_endpoints][]" value="' . esc_attr( $key ) . '" ' . checked( $checked, 'checked', false ) . '> '
				. esc_html(
					sprintf(
						/* translators: %s: AI manifest endpoint label. */
						__( 'Show %s', 'cybermaps' ),
						$label
					)
				)
				. '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}
}
