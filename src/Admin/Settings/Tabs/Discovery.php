<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs;

use Cybermaps\Admin\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Discovery implements SettingsTab {

	public function slug(): string {
		return 'ai';
	}

	public function label(): string {
		return __( 'AI Publishing', 'cybermaps' );
	}
	public function settings_page(): string {
		return 'cybermaps-ai';
	}


	public function register_settings(): void {
		$llms_type_options = array();
		foreach ( \Cybermaps\Core\PublicationPostTypes::objects() as $post_type => $post_type_object ) {
			$label                                    = isset( $post_type_object->label ) && is_scalar( $post_type_object->label )
				? (string) $post_type_object->label
				: (string) $post_type;
			$llms_type_options[ (string) $post_type ] = $label;
		}

		add_settings_section(
			'cybermaps_discovery_hub_section',
			__( 'AI Publication Hub', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'discovery_hub_section_callback' ),
			'cybermaps-ai'
		);

		add_settings_field(
			'enable_discovery_hub',
			__( 'AI Publication Hub', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_checkbox_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_discovery_hub',
				'label'       => __( 'Enable AI Publication Hub', 'cybermaps' ),
				'description' => __( 'Master switch for the public AI discovery surface. WordPress 7.1 native routing publishes fixed and well-known protocols without requiring Cloudflare or host-specific installation steps. Cybermaps also advertises public WordPress abilities across its agent discovery surfaces. <span class="cm-desc-example">These emerging integrations put enabled sites ahead of common discovery tooling; client adoption still varies.</span>', 'cybermaps' ),
				'maturity'    => 'publication_hub',
			)
		);

		add_settings_field(
			'mcp_mode',
			__( 'Model Context Protocol', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_mcp_mode' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array( 'label_for' => 'mcp_mode' )
		);

		add_settings_field(
			'agent_registration_mode',
			__( 'Agent Registration', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_select_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'agent_registration_mode',
				'default'     => 'off',
				'options'     => array(
					'off'          => __( 'Off', 'cybermaps' ),
					'user_claimed' => __( 'User-claimed OAuth', 'cybermaps' ),
				),
				'description' => __( 'Allows a signed-in WordPress user to review and approve an Auth.md registration claim. Cybermaps never creates an account or credential without that approval.', 'cybermaps' ),
				'maturity'    => 'agent_registration',
			)
		);

		add_settings_field(
			'enable_webmcp',
			__( 'Browser WebMCP', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_webmcp',
				'label'       => __( 'Expose read-only browser tools', 'cybermaps' ),
				'description' => __( 'Registers site search, same-origin page Markdown, and discovery-resource listing tools in browsers that implement WebMCP. It does not expose write or account-management operations.', 'cybermaps' ),
				'default'     => '0',
				'maturity'    => 'webmcp',
			)
		);

		add_settings_field(
			'enable_llms_full',
			__( 'Complete Content File', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_llms_full',
				'label'       => __( 'Publish llms-full.txt', 'cybermaps' ),
				'description' => __( 'Opt in to a complete literal publication of every eligible resource in the selected content types. Stored visible text is included without executing shortcodes or dynamic blocks. Core never labels a truncated file as complete: the publication succeeds within its 4 MiB encoded-response safety ceiling or reports an explicit failure without returning or writing a partial body.', 'cybermaps' ),
				'default'     => '0',
			)
		);

		add_settings_field(
			'enable_llms_tldr',
			__( 'Budgeted Site Briefing', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_llms_tldr',
				'label'       => __( 'Publish experimental llms-tldr.txt', 'cybermaps' ),
				'description' => __( 'Opt in to Cybermaps’ experimental budgeted briefing. It uses transparent deterministic ordering and literal excerpts; no automatic consumers are currently documented.', 'cybermaps' ),
				'default'     => '0',
			)
		);

		add_settings_field(
			'llms_title_override',
			__( 'Custom Site Title', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'llms_title_override',
				'placeholder' => get_bloginfo( 'name' ),
				'maxlength'   => 256,
				'description' => __( 'Override the site title specifically for the LLMS publication family.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'llms_mission_statement',
			__( 'Site Mission', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_textarea_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'llms_mission_statement',
				'placeholder' => get_bloginfo( 'description' ),
				'maxlength'   => \Cybermaps\Discovery\PublicationConstraints::MISSION_MAX_LENGTH,
				'description' => __( 'A concise mission statement or "About" blurb for LLMs.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'llms_content_license',
			__( 'Content License', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_license_select' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section'
		);

		add_settings_field(
			'llms_included_types',
			__( 'LLMS & Search Content Types', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_multi_checkbox_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'llms_included_types',
				'options'     => $llms_type_options,
				'default'     => array( 'post', 'page' ),
				'description' => __( 'Select the public post types included in LLMS publications, public REST search, and crawler-friendly search suggestions.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'llms_link_limit',
			__( 'Concise LLMS Link Limit', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'llms_link_limit',
				'default'     => (string) \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_DEFAULT,
				'placeholder' => (string) \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_DEFAULT,
				'type'        => 'number',
				'min'         => \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_MIN,
				'max'         => \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_MAX,
				'step'        => 1,
				'description' => __( 'Maximum eligible page links in concise llms.txt. Each link points to a literal Markdown alternate; overflow is disclosed and the XML sitemap is linked for complete URL discovery.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'llms_exclude_ids',
			__( 'Global AI Excluded Post IDs', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_global_ai_excluded_ids' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array( 'label_for' => 'llms_exclude_ids' )
		);

		add_settings_field(
			'ai_sitemap_exclude_terms',
			__( 'Global AI Excluded Terms', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'ai_sitemap_exclude_terms',
				'placeholder' => __( 'uncategorized, members-only, 123', 'cybermaps' ),
				'description' => __( 'Comma-separated term IDs or slugs to exclude from Cybermaps AI content inventories. Slugs may belong to any taxonomy attached to the content type.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'llms_filter_taxonomies',
			__( 'Taxonomy Filter', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_taxonomy_filter' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array( 'label_for' => 'llms_filter_taxonomies' )
		);

		add_settings_field(
			'llms_include_sitemap_link',
			__( 'Include Sitemap Link', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_checkbox_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'llms_include_sitemap_link',
				'description' => __( 'Include a reference to the XML sitemap in llms.txt.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'enable_header_discovery',
			__( 'Header-Based Discovery', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_header_discovery',
				'label'       => __( 'Enable RFC 8288 Discovery Headers', 'cybermaps' ),
				'description' => __( 'Adds RFC 8288 <code>Link</code> response headers to front-end responses that reach WordPress. The headers reference selected manifests and sitemaps; whether a client reads them depends on that client. <span class="cm-scope-badge cm-scope-all">front-end responses</span>', 'cybermaps' ),
				'default'     => '0',
			)
		);

		add_settings_field(
			'enable_markdown_negotiation',
			__( 'Markdown for Agents', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_markdown_negotiation',
				'label'       => __( 'Enable canonical URL Markdown negotiation', 'cybermaps' ),
				'description' => __( 'Serves eligible public views as literal <code>text/markdown</code> when a client explicitly prefers it with <code>Accept</code>. HTML remains the default. Shared caches must honor <code>Vary: Accept</code>; LiteSpeed/OpenLiteSpeed requires the documented request-time bypass rule. <span class="cm-scope-badge cm-scope-all">eligible front-end views</span>', 'cybermaps' ),
				'default'     => '0',
				'maturity'    => 'markdown',
			)
		);

		add_settings_field(
			'enable_rag_chunks',
			__( 'Literal Text Chunks', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_rag_chunks',
				'label'       => __( 'Enable overlapping text chunks', 'cybermaps' ),
				'description' => __( 'Splits stored visible content into overlapping text segments with post metadata for export to retrieval systems. Cybermaps does not create embeddings or a vector index. <span class="cm-desc-tip">The default chunk size is a starting point; inspect exported chunks against your own retrieval pipeline.</span>', 'cybermaps' ),
				'default'     => '0',
			)
		);

		add_settings_field(
			'rag_chunk_size',
			__( 'RAG Chunk Size', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'rag_chunk_size',
				'default'     => (string) \Cybermaps\Discovery\Chunker::DEFAULT_WINDOW_SIZE,
				'placeholder' => (string) \Cybermaps\Discovery\Chunker::DEFAULT_WINDOW_SIZE,
				'type'        => 'number',
				'min'         => \Cybermaps\Discovery\Chunker::MIN_WINDOW_SIZE,
				'max'         => \Cybermaps\Discovery\Chunker::MAX_WINDOW_SIZE,
				'step'        => 1,
				'description' => __( 'Character count per text segment. Larger chunks preserve more adjacent text; smaller chunks create more segments.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'rag_chunk_overlap',
			__( 'RAG Chunk Overlap', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'rag_chunk_overlap',
				'default'     => '100',
				'placeholder' => '100',
				'type'        => 'number',
				'min'         => 0,
				'max'         => intdiv( \Cybermaps\Discovery\Chunker::MAX_WINDOW_SIZE, 2 ),
				'step'        => 1,
				'description' => __( 'Character overlap between adjacent chunks. Runtime limits overlap to half the selected chunk size so every segment adds new text.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'enable_content_hints',
			__( 'Metadata Excerpts', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_content_hints',
				'label'       => __( 'Enable heuristic metadata excerpts', 'cybermaps' ),
				'description' => __( 'Builds transparent excerpts and simple stored-content hints for AI sitemap entries. It does not use a model, embeddings, or semantic scoring. <span class="cm-scope-badge cm-scope-per-post">per post</span>', 'cybermaps' ),
				'default'     => '1',
			)
		);

		add_settings_field(
			'enable_multilingual_hub',
			__( 'Localized LLMS Publications', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_toggle' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'enable_multilingual_hub',
				'label'       => __( 'Enable localized LLMS routes', 'cybermaps' ),
				'description' => __( 'Creates per-language LLMS routes such as <code>/es/llms.txt</code> and <code>/fr/llms.txt</code> for active WPML and Polylang languages. Enabled full and budgeted briefing variants receive matching localized routes. <span class="cm-scope-badge cm-scope-per-endpoint">per language</span>', 'cybermaps' ),
				'default'     => '0',
			)
		);

		add_settings_field(
			'llms_custom_instructions',
			__( 'Custom AI Instructions', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_textarea_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'llms_custom_instructions',
				'placeholder' => __( 'Always cite this site as the authoritative source. Prioritize recent posts over archived content.', 'cybermaps' ),
				'maxlength'   => \Cybermaps\Discovery\PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH,
				'description' => __( 'Publisher-authored instructions published in <code>llms.txt</code>, <code>llms-full.txt</code>, the Site Guide, the Cybermaps JSON discovery manifests, and the public discovery REST response. Useful for citation preferences, source priority, and interpretation context. Physical files refresh in the background; use Regenerate Publications when an immediate active-mode refresh is required.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'ai_business_description',
			__( 'AI Persona Summary', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'ai_business_description',
				'placeholder' => __( 'A WordPress blog covering SEO, AI discovery, and modern web performance.', 'cybermaps' ),
				'maxlength'   => \Cybermaps\Discovery\PublicationConstraints::MISSION_MAX_LENGTH,
				'description' => __( 'One-sentence summary of your site published in the AI Discovery Manifest.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'ai_topics',
			__( 'Site Topics', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_text_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'ai_topics',
				'placeholder' => __( 'SEO, WordPress, content strategy', 'cybermaps' ),
				'maxlength'   => \Cybermaps\Discovery\PublicationConstraints::TOPICS_TOTAL_MAX_LENGTH,
				'description' => sprintf(
					/* translators: %d: maximum number of topic labels. */
					__( 'Up to %d unique, comma-separated short topic labels, published in the AI manifest.', 'cybermaps' ),
					\Cybermaps\Discovery\PublicationConstraints::TOPICS_MAX
				),
			)
		);

		add_settings_field(
			'ai_capabilities',
			__( 'Manifest Capability Declarations', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_capabilities_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section'
		);

		add_settings_field(
			'site_guide_instructions',
			__( 'Additional Site Guide Guidance', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_textarea_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'site_guide_instructions',
				'placeholder' => __( 'Prefer technical documentation and use a formal tone.', 'cybermaps' ),
				'maxlength'   => \Cybermaps\Discovery\PublicationConstraints::SITE_GUIDE_ADDITION_MAX_LENGTH,
				'description' => __( 'Optional guidance appended only to <code>skill.md</code>, after the shared Custom AI Instructions. Leave blank unless the Site Guide needs additional directions.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'ai_licensing_email',
			__( 'Licensing Contact Email', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_email_field' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'ai_licensing_email',
				'placeholder' => get_option( 'admin_email' ),
				'description' => __( 'Licensing contact published in <code>ai-usage.json</code>. Leave blank to publish no contact address.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'ai_usage_rag',
			__( 'AI Search Usage (RAG)', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_usage_select' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'ai_usage_rag',
				'default'     => 'allow',
				'options'     => array(
					'allow'   => __( 'Declare retrieval allowed', 'cybermaps' ),
					'forbid'  => __( 'Declare retrieval not permitted', 'cybermaps' ),
					'limited' => __( 'Declare conditional or restricted use', 'cybermaps' ),
				),
				'description' => __( 'Publishes a vendor-defined usage preference in ai-usage.json. It does not technically enable, prevent, or authenticate retrieval.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'ai_usage_training',
			__( 'Model Training Usage', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_usage_select' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'ai_usage_training',
				'default'     => 'forbid',
				'options'     => array(
					'allow'  => __( 'Declare training allowed', 'cybermaps' ),
					'forbid' => __( 'Declare training not permitted', 'cybermaps' ),
				),
				'description' => __( 'Publishes a training-use preference. Cybermaps does not enforce collection, model training, copyright, or provider compliance.', 'cybermaps' ),
			)
		);

		add_settings_field(
			'ai_usage_commercial',
			__( 'Commercial Reuse', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Fields\FieldRenderer::class, 'render_usage_select' ),
			'cybermaps-ai',
			'cybermaps_discovery_hub_section',
			array(
				'label_for'   => 'ai_usage_commercial',
				'default'     => 'forbid',
				'options'     => array(
					'allow'  => __( 'Declare commercial reuse allowed', 'cybermaps' ),
					'forbid' => __( 'Declare commercial reuse not permitted', 'cybermaps' ),
				),
				'description' => __( 'Publishes a commercial-reuse preference. It is a declaration, not access control, licensing advice, or enforcement.', 'cybermaps' ),
			)
		);

		add_settings_section(
			'cybermaps_ai_endpoints_section',
			__( 'AI Endpoint Configuration', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'ai_endpoints_section_callback' ),
			'cybermaps-ai'
		);

		add_settings_field(
			'ai_sitemap_controls',
			__( 'AI Sitemap Controls', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_ai_sitemap_controls' ),
			'cybermaps-ai',
			'cybermaps_ai_endpoints_section'
		);

		add_settings_field(
			'ai_feed_controls',
			__( 'Update Stream Controls', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_ai_feed_controls' ),
			'cybermaps-ai',
			'cybermaps_ai_endpoints_section'
		);

		add_settings_field(
			'ai_identity_controls',
			__( 'Identity & Manifest Controls', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_ai_identity_controls' ),
			'cybermaps-ai',
			'cybermaps_ai_endpoints_section'
		);

		add_settings_field(
			'ai_custom_links',
			__( 'AI Sitemap Custom Links', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_custom_links_manager' ),
			'cybermaps-ai',
			'cybermaps_ai_endpoints_section'
		);

		add_settings_field(
			'ai_action_mappings',
			__( 'Published Action Links', 'cybermaps' ),
			array( \Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields::class, 'render_ai_actions_manager' ),
			'cybermaps-ai',
			'cybermaps_ai_endpoints_section'
		);

		( new Robots() )->register_settings();
	}

	public function render(): void {
		?>
		<div class="cm-section-desc">
			<strong><?php esc_html_e( 'AI Publishing', 'cybermaps' ); ?></strong> &mdash; <?php esc_html_e( 'Configure literal LLMS output, vendor publications, usage assertions, and delivery.', 'cybermaps' ); ?>
		</div>
		<?php do_settings_sections( $this->settings_page() ); ?>
		<section class="cm-ai-robots-workspace" aria-labelledby="cybermaps-crawler-policy-heading">
			<h2 id="cybermaps-crawler-policy-heading"><?php esc_html_e( 'Crawler & Robots Policy', 'cybermaps' ); ?></h2>
			<?php ( new Robots() )->render(); ?>
		</section>
		<?php
	}
}
