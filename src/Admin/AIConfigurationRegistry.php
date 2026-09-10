<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical, non-secret configuration contract for AI-assisted editing.
 *
 * Runtime sanitizers remain authoritative when values are written. This
 * registry describes that accepted surface in a form shared by configuration
 * briefs, import previews, generated documentation, and schema consumers.
 */
final class AIConfigurationRegistry {
	public const CATALOG_FORMAT = 'cybermaps-ai-configuration-catalog';
	public const CHANGES_FORMAT = 'cybermaps-ai-configuration-changes';
	public const FORMAT_VERSION = 2;

	/**
	 * Fields that must never enter the editable AI contract.
	 *
	 * @var string[]
	 */
	public const FORBIDDEN_FIELDS = array(
		'api_secret',
		'delete_data_on_uninstall',
	);

	/**
	 * Ordered section metadata keyed by stable section ID.
	 *
	 * @return array<string,array{id:string,label:string,purpose:string}>
	 */
	public static function get_sections(): array {
		return array(
			'core_settings'        => array(
				'id'      => 'core_settings',
				'label'   => 'Core Settings',
				'purpose' => 'XML, RSS, media, language, notification, and HTML sitemap behavior.',
			),
			'ai_publishing'        => array(
				'id'      => 'ai_publishing',
				'label'   => 'AI Publishing',
				'purpose' => 'Public AI discovery files, content selection, publisher guidance, and usage declarations.',
			),
			'reports_deliverables' => array(
				'id'      => 'reports_deliverables',
				'label'   => 'Reports & Deliverables',
				'purpose' => 'Content-review thresholds and printable report presentation.',
			),
			'analytics'            => array(
				'id'      => 'analytics',
				'label'   => 'Analytics',
				'purpose' => 'Crawler request recording, privacy, and retention.',
			),
			'advanced_maintenance' => array(
				'id'      => 'advanced_maintenance',
				'label'   => 'Advanced & Maintenance',
				'purpose' => 'Static publication and alternate public-origin routing.',
			),
			'discovery_strategy'   => array(
				'id'      => 'discovery_strategy',
				'label'   => 'Discovery Strategy',
				'purpose' => 'Per-content-group publication, intent, and relative-weight strategy.',
			),
			'site_identity'        => array(
				'id'      => 'site_identity',
				'label'   => 'Identity Hub',
				'purpose' => 'Public Schema.org identity, contact, location, hours, and offer catalogs.',
			),
			'robots_control'       => array(
				'id'      => 'robots_control',
				'label'   => 'Robots Control',
				'purpose' => 'Virtual robots.txt ownership, crawler policies, and Content-Signal declarations.',
			),
		);
	}

	/**
	 * Ordered field metadata keyed by stable editable field ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_fields(): array {
		$fields   = array_merge(
			self::core_fields(),
			self::ai_fields(),
			self::report_fields(),
			self::analytics_fields(),
			self::advanced_fields(),
			self::strategy_fields(),
			self::identity_fields(),
			self::robots_fields()
		);
		$sections = self::get_sections();

		foreach ( $fields as $id => &$field ) {
			$section_id             = (string) $field['section_id'];
			$field['section_label'] = (string) $sections[ $section_id ]['label'];
			$field['id']            = $id;
		}
		unset( $field );

		return $fields;
	}

	/**
	 * Return the complete machine-readable catalog envelope.
	 *
	 * @return array{format:string,format_version:int,plugin_version:string,sections:array<int,array<string,mixed>>,fields:array<int,array<string,mixed>>}
	 */
	public static function get_catalog(): array {
		return array(
			'format'         => self::CATALOG_FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => self::plugin_version(),
			'sections'       => array_values( self::get_sections() ),
			'fields'         => array_values( self::get_fields() ),
		);
	}

	/**
	 * Return one field definition, or null for an unknown/forbidden field.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_field( string $field_id ): ?array {
		$fields = self::get_fields();
		return $fields[ $field_id ] ?? null;
	}

	/**
	 * Return one section definition, or null for an unknown section.
	 *
	 * @return array{id:string,label:string,purpose:string}|null
	 */
	public static function get_section( string $section_id ): ?array {
		$sections = self::get_sections();
		return $sections[ $section_id ] ?? null;
	}

	/**
	 * Field-to-storage lookup for import and preview services.
	 *
	 * @return array<string,array{option:string,field:string,section_id:string,section_label:string}>
	 */
	public static function get_mapping_lookup(): array {
		$lookup = array();
		foreach ( self::get_fields() as $id => $field ) {
			$lookup[ $id ] = array(
				'option'        => (string) $field['option'],
				'field'         => (string) $field['field'],
				'section_id'    => (string) $field['section_id'],
				'section_label' => (string) $field['section_label'],
			);
		}

		return $lookup;
	}

	/**
	 * JSON Schema Draft 2020-12 for the sentinel changes envelope.
	 *
	 * Null and omitted fields both mean "preserve the destination". Every object
	 * is closed so an AI cannot silently invent section or field names.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_json_schema(): array {
		$plugin_version     = self::plugin_version();
		$section_properties = array();
		foreach ( self::get_sections() as $section_id => $section ) {
			$field_properties = array();
			foreach ( self::get_fields() as $field_id => $field ) {
				if ( $section_id !== $field['section_id'] ) {
					continue;
				}
				$field_properties[ $field_id ] = self::field_json_schema( $field );
			}

			$section_schema                    = array(
				'type'                 => 'object',
				'title'                => (string) $section['label'],
				'description'          => (string) $section['purpose'],
				'properties'           => $field_properties,
				'additionalProperties' => false,
			);
			$section_properties[ $section_id ] = array_merge(
				$section_schema,
				self::section_json_schema_keywords( $section_id )
			);
		}

		return array(
			'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
			'$id'                  => 'https://cybermaps.dev/specs/ai-configuration/' . rawurlencode( $plugin_version ) . '/schema.json',
			'format_version'       => self::FORMAT_VERSION,
			'plugin_version'       => $plugin_version,
			'title'                => 'Cybermaps AI configuration changes',
			'description'          => 'A merge-only set of reviewed Cybermaps configuration changes. Null and omitted values preserve the destination.',
			'type'                 => 'object',
			'required'             => array( 'format', 'format_version', 'plugin_version', 'changes' ),
			'properties'           => array(
				'format'         => array(
					'const' => self::CHANGES_FORMAT,
				),
				'format_version' => array(
					'const' => self::FORMAT_VERSION,
				),
				'plugin_version' => array(
					'type'  => 'string',
					'const' => $plugin_version,
				),
				'changes'        => array(
					'type'                 => 'object',
					'properties'           => $section_properties,
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	public static function get_catalog_hash(): string {
		return hash( 'sha256', self::encode( self::get_catalog() ) );
	}

	public static function get_schema_hash(): string {
		return hash( 'sha256', self::encode( self::get_json_schema() ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function core_fields(): array {
		$section                       = 'core_settings';
		$route                         = array(
			'pattern'   => '^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$',
			'maxLength' => 180,
		);
		$page_url_line                 = self::http_url( false );
		$page_url_line['x-public-url'] = true;
		$sitemap_url_line              = $page_url_line;
		$sitemap_url_line['pattern']   = '^https?://(?![^/?#]*@)[^/?#]+/[^?#]*\.[xX][mM][lL](?:[?#].*)?$';
		$excluded_ids                  = array(
			'items'              => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'uniqueItems'        => true,
			'maxItems'           => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX,
			'x-max-joined-bytes' => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES,
			'x-join-separator'   => ', ',
		);
		$excluded_terms                = array(
			'items'              => array(
				'anyOf' => array(
					array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					array(
						'type'      => 'string',
						'pattern'   => '^[a-z0-9_-]*[a-z_-][a-z0-9_-]*$',
						'maxLength' => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_SLUG_MAX_LENGTH,
					),
				),
			),
			'uniqueItems'        => true,
			'maxItems'           => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX,
			'x-max-joined-bytes' => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES,
			'x-join-separator'   => ', ',
		);

		return array(
			'site_language'                   => self::field(
				$section,
				'site_language',
				'Site language',
				'Sets the canonical BCP 47-style hreflang code used in sitemap and discovery output. A blank editable value inherits the WordPress site language; en is the final fallback.',
				'string',
				array(
					'pattern'               => '^(?:x-default|[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*)$',
					'maxLength'             => 35,
					'x-canonical-separator' => '-',
					'x-runtime-fallback'    => 'WordPress site language, then en',
				),
				'en',
				'en-US',
				'medium'
			),
			'sitemap_url_base'                => self::field( $section, 'sitemap_url_base', 'Sitemap URL base', 'Sets the collision-safe public base slug for the primary XML sitemap.', 'string', $route, 'sitemap', 'sitemap', 'high' ),
			'include_homepage'                => self::boolean_field( $section, 'include_homepage', 'Include homepage', 'Includes the homepage in the miscellaneous XML sitemap.', false ),
			'include_authors'                 => self::boolean_field( $section, 'include_authors', 'Include author archives', 'Includes public author archives in the sitemap inventory.', false ),
			'include_archives'                => self::boolean_field( $section, 'include_archives', 'Include date archives', 'Includes date archive pages in the sitemap inventory.', false ),
			'include_empty_terms'             => self::boolean_field( $section, 'include_empty_terms', 'Include empty terms', 'Allows empty public taxonomy terms to appear in sitemap output.', false ),
			'update_comment_post'             => self::boolean_field( $section, 'update_comment_post', 'Comments update post modified time', 'Updates a post modified timestamp when it receives a comment.', false, 'medium' ),
			'update_comment_page'             => self::boolean_field( $section, 'update_comment_page', 'Comments update page modified time', 'Updates a page modified timestamp when it receives a comment.', false, 'medium' ),
			'inject_robots'                   => self::boolean_field( $section, 'inject_robots', 'Advertise publications in robots.txt', 'Adds Cybermaps sitemap and discovery locations to the virtual robots.txt response.', false, 'high' ),
			'enable_caching'                  => self::boolean_field( $section, 'enable_caching', 'Cache dynamic sitemap responses', 'Caches dynamically generated sitemap responses until relevant invalidation.', false, 'medium' ),
			'enable_translation_integrations' => self::boolean_field( $section, 'enable_translation_integrations', 'Translation integrations', 'Uses supported multilingual plugins to publish alternate-language sitemap relationships.', false, 'medium' ),
			'external_sitemaps'               => self::field(
				$section,
				'external_sitemaps',
				'External sitemap references',
				'Adds up to 100 unique absolute public HTTP or HTTPS URLs whose path ends in .xml to the sitemap index, one per line.',
				'string',
				array(
					'contentMediaType' => 'text/plain',
					'x-line-schema'    => $sitemap_url_line,
					'x-max-lines'      => 100,
				),
				'',
				'https://example.com/store-sitemap.xml',
				'medium'
			),
			'external_pages'                  => self::field(
				$section,
				'external_pages',
				'External pages',
				'Adds up to 1000 unique absolute public HTTP or HTTPS non-WordPress page URLs to the miscellaneous sitemap, one per line.',
				'string',
				array(
					'contentMediaType' => 'text/plain',
					'x-line-schema'    => $page_url_line,
					'x-max-lines'      => 1000,
				),
				'',
				'https://example.com/landing-page',
				'medium'
			),
			'exclude_post_ids'                => self::field( $section, 'exclude_post_ids', 'XML excluded post IDs', 'Lists positive post IDs omitted from standard XML sitemap children. Supply the complete list; importing it replaces the existing list.', 'array', $excluded_ids, array(), array( 12, 45, 89 ), 'medium' ),
			'exclude_categories'              => self::field( $section, 'exclude_categories', 'XML excluded categories', 'Lists positive category IDs or canonical non-numeric slugs omitted from standard XML sitemap children. Supply the complete list; importing it replaces the existing list.', 'array', $excluded_terms, array(), array( 'private', 'archive' ), 'medium' ),
			'enable_indexnow'                 => self::boolean_field( $section, 'enable_indexnow', 'IndexNow notifications', 'Notifies configured IndexNow services after eligible content changes.', false, 'high' ),
			'enable_websub'                   => self::boolean_field( $section, 'enable_websub', 'WebSub notifications', 'Sends publication notifications to configured WebSub hubs for the JSON Feed topic.', false, 'high', array( self::dependency( 'enable_discovery_hub', true ), self::dependency( 'websub_hubs', 'non-empty' ) ) ),
			'websub_hubs'                     => self::field(
				$section,
				'websub_hubs',
				'WebSub hubs',
				'Lists up to 10 unique public HTTPS WebSub hub URLs without embedded credentials, one per line.',
				'string',
				array(
					'contentMediaType' => 'text/plain',
					'x-line-schema'    => array_merge( self::https_url(), array( 'x-public-url' => true ) ),
					'x-max-lines'      => 10,
				),
				"https://pubsubhubbub.appspot.com/\nhttps://pubsubhubbub.superfeedr.com/",
				'https://pubsubhubbub.appspot.com/',
				'high',
				array( self::dependency( 'enable_websub', true ) )
			),
			'enable_google_news'              => self::boolean_field( $section, 'enable_google_news', 'Google News sitemap', 'Publishes a dedicated recent-news sitemap.', false, 'medium' ),
			'news_publication_name'           => self::field(
				$section,
				'news_publication_name',
				'News publication name',
				'Sets the publication name embedded in Google News sitemap entries; a blank editable value uses the WordPress site title.',
				'string',
				array(
					'maxLength'          => \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH,
					'x-runtime-fallback' => 'WordPress site title',
				),
				'',
				'Example Newsroom',
				'medium',
				array( self::dependency( 'enable_google_news', true ) )
			),
			'news_sitemap_url_base'           => self::field( $section, 'news_sitemap_url_base', 'News sitemap URL base', 'Sets the collision-safe base slug for the News sitemap.', 'string', $route, 'sitemap-news', 'sitemap-news', 'high', array( self::dependency( 'enable_google_news', true ) ) ),
			'redirect_wp_sitemap'             => self::boolean_field( $section, 'redirect_wp_sitemap', 'Redirect WordPress sitemaps', 'Redirects WordPress Core sitemap routes to the Cybermaps sitemap.', true, 'high' ),
			'redirect_default_sitemap'        => self::boolean_field( $section, 'redirect_default_sitemap', 'Redirect conventional sitemap.xml', 'Redirects /sitemap.xml when the configured primary route differs.', false, 'high' ),
			'redirect_news_sitemap'           => self::boolean_field( $section, 'redirect_news_sitemap', 'Redirect conventional News sitemap', 'Redirects /sitemap-news.xml when the configured News route differs.', false, 'high' ),
			'enable_video_schema'             => self::boolean_field( $section, 'enable_video_schema', 'Video schema', 'Publishes on-page VideoObject markup from eligible observed videos.', true, 'medium', array( self::dependency( 'media_discovery_intensity', 'standard-or-advanced' ) ) ),
			'enable_rss_sitemap'              => self::boolean_field( $section, 'enable_rss_sitemap', 'RSS sitemap', 'Publishes the configured recent-item RSS sitemap.', false, 'medium' ),
			'rss_sitemap_url_base'            => self::field( $section, 'rss_sitemap_url_base', 'RSS sitemap URL base', 'Sets the collision-safe base slug for the RSS sitemap.', 'string', $route, 'sitemap-rss', 'sitemap-rss', 'high', array( self::dependency( 'enable_rss_sitemap', true ) ) ),
			'rss_sitemap_limit'               => self::field(
				$section,
				'rss_sitemap_limit',
				'RSS sitemap item limit',
				'Limits recent entries in the RSS sitemap.',
				'integer',
				array(
					'minimum' => 1,
					'maximum' => 1000,
				),
				100,
				100,
				'medium',
				array( self::dependency( 'enable_rss_sitemap', true ) )
			),
			'rss_sitemap_types'               => self::field( $section, 'rss_sitemap_types', 'RSS sitemap content types', 'Selects public post-type slugs eligible for the RSS sitemap. Supply the complete list; importing it replaces the existing list.', 'array', self::key_list( \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX ), array( 'post' ), array( 'post', 'page' ), 'medium', array( self::dependency( 'enable_rss_sitemap', true ) ) ),
			'enable_shortcode'                => self::boolean_field( $section, 'enable_shortcode', 'HTML sitemap shortcode', 'Registers the cybermaps_sitemap shortcode for front-end HTML sitemap output.', false, 'medium' ),
			'media_discovery_intensity'       => self::field( $section, 'media_discovery_intensity', 'Media discovery depth', 'Controls whether sitemap media discovery is off, attachment-based, or includes embedded media.', 'string', array( 'enum' => array( 'none', 'standard', 'advanced' ) ), 'none', 'standard', 'medium' ),
			'enable_multimodal_discovery'     => self::boolean_field( $section, 'enable_multimodal_discovery', 'AI media hints', 'Publishes Cybermaps media hints in the AI sitemap.', true, 'medium', array( self::dependency( 'media_discovery_intensity', 'standard-or-advanced' ) ) ),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function ai_fields(): array {
		$section                 = 'ai_publishing';
		$taxonomy_filter_pattern = sprintf(
			'^\\s*(?:[a-z0-9_-]{1,%1$d}(?:\\s*,\\s*[a-z0-9_-]{1,%1$d}){0,%2$d})?\\s*$',
			\Cybermaps\Discovery\PublicationConstraints::TAXONOMY_NAME_MAX_LENGTH,
			\Cybermaps\Discovery\PublicationConstraints::TAXONOMY_FILTER_ITEMS_MAX - 1
		);
		$post_ids                = array(
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'uniqueItems' => true,
			'maxItems'    => 100,
		);
		$exclusion_ids           = array(
			'items'              => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'uniqueItems'        => true,
			'maxItems'           => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX,
			'x-max-joined-bytes' => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES,
			'x-join-separator'   => ', ',
		);
		$exclusion_terms         = array(
			'items'              => array(
				'anyOf' => array(
					array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					array(
						'type'      => 'string',
						'pattern'   => '^[a-z0-9_-]*[a-z_-][a-z0-9_-]*$',
						'maxLength' => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_SLUG_MAX_LENGTH,
					),
				),
			),
			'uniqueItems'        => true,
			'maxItems'           => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX,
			'x-max-joined-bytes' => \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES,
			'x-join-separator'   => ', ',
		);

		return array(
			'enable_discovery_hub'        => self::boolean_field( $section, 'enable_discovery_hub', 'AI Publication Hub', 'Master switch for Cybermaps public AI discovery publications and routes.', false, 'high' ),
			'mcp_mode'                    => self::field( $section, 'mcp_mode', 'Model Context Protocol', 'Explicit optional MCP access tier. MCP remains unavailable while the AI Publication Hub is disabled.', 'string', array( 'enum' => array( 'off', 'discovery', 'read_only', 'operations' ) ), 'off', 'off', 'high', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'agent_registration_mode'     => self::field( $section, 'agent_registration_mode', 'Agent registration', 'Controls opt-in user-reviewed Auth.md agent registration.', 'string', array( 'enum' => array( 'off', 'user_claimed' ) ), 'off', 'off', 'high', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'enable_webmcp'               => self::boolean_field( $section, 'enable_webmcp', 'Browser WebMCP', 'Registers read-only site tools in participating preview browsers.', false, 'medium', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'enable_header_discovery'     => self::boolean_field( $section, 'enable_header_discovery', 'Header-based discovery', 'Adds RFC 8288 Link headers for selected Cybermaps publications to eligible front-end responses.', false, 'medium', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'enable_markdown_negotiation' => self::boolean_field( $section, 'enable_markdown_negotiation', 'Markdown for Agents negotiation', 'Serves bounded stored-content Markdown at eligible canonical URLs when text/markdown is explicitly preferred.', false, 'high', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'enable_llms_full'            => self::boolean_field( $section, 'enable_llms_full', 'Complete LLMS publication', 'Publishes llms-full.txt containing complete eligible literal content within the response safety bound.', false, 'high', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'enable_llms_tldr'            => self::boolean_field( $section, 'enable_llms_tldr', 'Budgeted site briefing', 'Publishes the experimental deterministic llms-tldr.txt site briefing.', false, 'medium', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'enable_rag_chunks'           => self::boolean_field( $section, 'enable_rag_chunks', 'Literal text chunks', 'Publishes overlapping literal content chunks for external retrieval workflows.', false, 'high', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'enable_content_hints'        => self::boolean_field( $section, 'enable_content_hints', 'Metadata excerpts', 'Builds transparent excerpts and stored-content hints for AI sitemap entries.', true, 'medium', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'enable_multilingual_hub'     => self::boolean_field( $section, 'enable_multilingual_hub', 'Localized LLMS publications', 'Creates localized LLMS routes for active WPML or Polylang languages.', false, 'medium', array( self::dependency( 'enable_discovery_hub', true ), self::dependency( 'enable_translation_integrations', true ) ) ),
			'llms_include_sitemap_link'   => self::boolean_field( $section, 'llms_include_sitemap_link', 'Include sitemap link in LLMS', 'Includes a reference to the primary XML sitemap in llms.txt.', false, 'low', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'llms_link_limit'             => self::field(
				$section,
				'llms_link_limit',
				'Concise LLMS link limit',
				'Limits the eligible resource links published in concise llms.txt. Overflow is disclosed and linked to the XML sitemap.',
				'integer',
				array(
					'minimum' => \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_MIN,
					'maximum' => \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_MAX,
				),
				\Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_DEFAULT,
				100,
				'medium',
				array( self::dependency( 'enable_discovery_hub', true ) )
			),
			'llms_title_override'         => self::field(
				$section,
				'llms_title_override',
				'LLMS site title',
				'Overrides the site title specifically for the LLMS publication family; a blank editable value uses the WordPress site title.',
				'string',
				array(
					'maxLength'          => \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH,
					'x-runtime-fallback' => 'WordPress site title',
				),
				'',
				'Example Knowledge Center',
				'medium'
			),
			'llms_mission_statement'      => self::field(
				$section,
				'llms_mission_statement',
				'Site mission',
				'Publishes a concise mission or About statement for model context; a blank editable value uses the WordPress site tagline.',
				'string',
				array(
					'maxLength'          => \Cybermaps\Discovery\PublicationConstraints::MISSION_MAX_LENGTH,
					'x-runtime-fallback' => 'WordPress site tagline',
				),
				'',
				'We publish practical, independently tested WordPress guidance.',
				'medium'
			),
			'llms_custom_instructions'    => self::field( $section, 'llms_custom_instructions', 'Custom AI instructions', 'Publishes operator-authored citation, source-priority, and interpretation guidance across the discovery surface.', 'string', array( 'maxLength' => \Cybermaps\Discovery\PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH ), '', 'Treat product documentation as authoritative and cite the canonical page URL.', 'high' ),
			'llms_content_license'        => self::field( $section, 'llms_content_license', 'Content license', 'Publishes the operator-selected content license assertion.', 'string', array( 'enum' => \Cybermaps\Discovery\PublicationConstraints::CONTENT_LICENSES ), '', 'CC-BY-4.0', 'high' ),
			'llms_pinned_ids'             => self::field( $section, 'llms_pinned_ids', 'Pinned briefing post IDs', 'Pins up to 100 eligible post IDs into the budgeted briefing candidate set. Supply the complete list; importing it replaces the existing list.', 'array', $post_ids, array(), array( 12, 45 ), 'medium', array( self::dependency( 'enable_llms_tldr', true ) ) ),
			'llms_exclude_ids'            => self::field( $section, 'llms_exclude_ids', 'Global AI excluded post IDs', 'Excludes post IDs from all Cybermaps AI publication inventories, including the historical AI sitemap field. Supply the complete list; importing it replaces the existing list.', 'array', $exclusion_ids, array(), array( 12, 45 ), 'high' ),
			'llms_filter_taxonomies'      => self::field(
				$section,
				'llms_filter_taxonomies',
				'Taxonomy filter',
				'Restricts LLMS publication candidates to posts assigned at least one term in up to 100 comma-separated WordPress taxonomy names.',
				'string',
				array(
					'pattern'               => $taxonomy_filter_pattern,
					'maxLength'             => \Cybermaps\Discovery\PublicationConstraints::TAXONOMY_FILTER_MAX_LENGTH,
					'x-max-items'           => \Cybermaps\Discovery\PublicationConstraints::TAXONOMY_FILTER_ITEMS_MAX,
					'x-item-max-length'     => \Cybermaps\Discovery\PublicationConstraints::TAXONOMY_NAME_MAX_LENGTH,
					'x-canonical-separator' => ', ',
				),
				'',
				'category, post_tag',
				'high'
			),
			'llms_included_types'         => self::field( $section, 'llms_included_types', 'LLMS and search content types', 'Selects public post types included in LLMS publications, public REST search, and suggestions. Supply the complete list; importing it replaces the existing list.', 'array', self::key_list( \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX ), array( 'post', 'page' ), array( 'post', 'page' ), 'high' ),
			'llms_tldr_token_budget'      => self::field(
				$section,
				'llms_tldr_token_budget',
				'Briefing token budget',
				'Sets the approximate output token budget for llms-tldr.txt.',
				'integer',
				array(
					'minimum' => \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MIN,
					'maximum' => \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MAX,
				),
				\Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_DEFAULT,
				40000,
				'medium',
				array( self::dependency( 'enable_llms_tldr', true ) )
			),
			'ai_business_description'     => self::field(
				$section,
				'ai_business_description',
				'AI persona summary',
				'Publishes a one-sentence site summary in the AI Discovery Manifest; a blank editable value uses the WordPress site tagline.',
				'string',
				array(
					'maxLength'          => \Cybermaps\Discovery\PublicationConstraints::MISSION_MAX_LENGTH,
					'x-runtime-fallback' => 'WordPress site tagline',
				),
				'',
				'Independent tutorials and implementation guidance for WordPress professionals.',
				'medium'
			),
			'ai_topics'                   => self::field(
				$section,
				'ai_topics',
				'Site topics',
				'Publishes a bounded list of unique topic labels in the AI manifest. The joined labels may use at most 2048 UTF-8 bytes. Supply the complete list; importing it replaces the existing list.',
				'array',
				array(
					'items'              => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => \Cybermaps\Discovery\PublicationConstraints::TOPIC_LABEL_MAX_LENGTH,
						'pattern'   => '.*\S.*',
					),
					'maxItems'           => \Cybermaps\Discovery\PublicationConstraints::TOPICS_MAX,
					'uniqueItems'        => true,
					'x-max-joined-bytes' => \Cybermaps\Discovery\PublicationConstraints::TOPICS_TOTAL_MAX_LENGTH,
					'x-join-separator'   => ', ',
				),
				array(),
				array( 'WordPress', 'technical SEO' ),
				'medium'
			),
			'ai_capabilities'             => self::field(
				$section,
				'ai_capabilities',
				'Manifest capabilities',
				'Declares supported discovery capabilities in the AI manifest. Supply the complete list; importing it replaces the existing list.',
				'array',
				array(
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'search_content', 'read_articles', 'extract_entities' ),
					),
					'maxItems'    => 3,
					'uniqueItems' => true,
				),
				array(),
				array( 'search_content', 'read_articles' ),
				'medium'
			),
			'ai_action_mappings'          => self::field(
				$section,
				'ai_action_mappings',
				'Schema.org action mappings',
				'Publishes up to 100 truthful action URLs and Schema.org action types. Supply the complete list; importing it replaces the existing list.',
				'array',
				array(
					'items'    => self::object_shape(
						array(
							'url'  => self::http_url( false ),
							'type' => array(
								'type' => 'string',
								'enum' => \Cybermaps\Discovery\PublicationConstraints::ACTION_TYPES,
							),
							'desc' => array(
								'type'      => 'string',
								'maxLength' => \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH,
							),
						),
						array( 'url', 'type' )
					),
					'maxItems' => \Cybermaps\Discovery\PublicationConstraints::ACTION_MAPPINGS_MAX,
				),
				array(),
				array(
					array(
						'url'  => 'https://example.com/contact/',
						'type' => 'ContactAction',
						'desc' => 'Contact the editorial team.',
					),
				),
				'high'
			),
			'ai_manifest_endpoints'       => self::field(
				$section,
				'ai_manifest_endpoints',
				'Manifest endpoint inventory',
				'Selects optional publications advertised by the AI Discovery Manifest. Supply the complete list; importing it replaces the existing list.',
				'array',
				array(
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'llms.txt', 'llms-full.txt', 'llms-tldr.txt', 'skill.md', 'ai-usage.json', 'ai-actions.json', 'knowledge-graph.json', 'feed.json', 'ai-sitemap.xml' ),
					),
					'maxItems'    => 9,
					'uniqueItems' => true,
				),
				array( 'llms.txt', 'feed.json', 'knowledge-graph.json', 'ai-sitemap.xml' ),
				array( 'llms.txt', 'feed.json', 'knowledge-graph.json', 'ai-sitemap.xml' ),
				'high',
				array( self::dependency( 'enable_discovery_hub', true ) )
			),
			'ai_sitemap_types'            => self::field( $section, 'ai_sitemap_types', 'AI sitemap content types', 'Selects public post types eligible for AI sitemap and related inventories. Supply the complete list; importing it replaces the existing list.', 'array', self::key_list( \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX ), array( 'post', 'page' ), array( 'post', 'page' ), 'high' ),
			'ai_sitemap_limit'            => self::field(
				$section,
				'ai_sitemap_limit',
				'AI sitemap item limit',
				'Limits eligible items per selected post type in AI sitemap output.',
				'integer',
				array(
					'minimum' => \Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_MIN,
					'maximum' => \Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_MAX,
				),
				\Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_DEFAULT,
				250,
				'medium'
			),
			'ai_sitemap_exclude_terms'    => self::field( $section, 'ai_sitemap_exclude_terms', 'Global AI excluded terms', 'Excludes positive term IDs or canonical non-numeric slugs from all Cybermaps AI content inventories. Supply the complete list; importing it replaces the existing list.', 'array', $exclusion_terms, array(), array( 'members-only', 123 ), 'high' ),
			'ai_sitemap_custom_links'     => self::field(
				$section,
				'ai_sitemap_custom_links',
				'AI sitemap external links',
				'Adds up to 100 external HTTP or HTTPS resources to the AI sitemap with relative priority hints. Supply the complete list; importing it replaces the existing list.',
				'array',
				array(
					'items'    => self::object_shape(
						array(
							'url'      => self::http_url( false ),
							'priority' => array(
								'type'    => 'number',
								'minimum' => 0.1,
								'maximum' => 1.0,
							),
						),
						array( 'url' )
					),
					'maxItems' => \Cybermaps\Discovery\PublicationConstraints::CUSTOM_LINKS_MAX,
				),
				array(),
				array(
					array(
						'url'      => 'https://docs.example.com/',
						'priority' => 0.8,
					),
				),
				'high'
			),
			'ai_feed_full_content'        => self::boolean_field( $section, 'ai_feed_full_content', 'Full JSON Feed content', 'Includes complete eligible stored content rather than summaries in the JSON Feed.', false, 'high', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'ai_feed_include_authors'     => self::boolean_field( $section, 'ai_feed_include_authors', 'JSON Feed authors', 'Includes available author identity in JSON Feed items.', false, 'high', array( self::dependency( 'enable_discovery_hub', true ) ) ),
			'ai_feed_limit'               => self::field(
				$section,
				'ai_feed_limit',
				'JSON Feed item limit',
				'Limits recent items returned by the JSON Feed.',
				'integer',
				array(
					'minimum' => \Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_MIN,
					'maximum' => \Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_MAX,
				),
				\Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_DEFAULT,
				20,
				'medium'
			),
			'ai_kg_expose_admin'          => self::boolean_field( $section, 'ai_kg_expose_admin', 'Knowledge Graph administrator', 'Publishes a Person node for the WordPress user matching the site admin email when that account has a display name. The node exposes that display name and, when available, its public author-archive URL; it does not publish the email address.', false, 'high' ),
			'ai_kg_link_org'              => self::boolean_field( $section, 'ai_kg_link_org', 'Knowledge Graph identity link', 'Links the Knowledge Graph WebSite node to the configured primary identity through its publisher property.', false, 'high', array( self::dependency( 'identity_name', 'non-empty' ) ) ),
			'ai_licensing_email'          => self::field( $section, 'ai_licensing_email', 'Licensing contact email', 'Publishes a licensing contact address in ai-usage.json; an empty string clears it.', 'string', self::email_value(), '', 'licensing@example.com', 'high' ),
			'ai_usage_rag'                => self::field( $section, 'ai_usage_rag', 'AI retrieval usage', 'Publishes the operator declaration for retrieval and RAG use.', 'string', array( 'enum' => array( 'allow', 'forbid', 'limited' ) ), 'allow', 'limited', 'high' ),
			'ai_usage_training'           => self::field( $section, 'ai_usage_training', 'Model training usage', 'Publishes the operator declaration for model-training use.', 'string', array( 'enum' => array( 'allow', 'forbid' ) ), 'forbid', 'forbid', 'high' ),
			'ai_usage_commercial'         => self::field( $section, 'ai_usage_commercial', 'Commercial reuse', 'Publishes the operator declaration for commercial reuse.', 'string', array( 'enum' => array( 'allow', 'forbid' ) ), 'forbid', 'forbid', 'high' ),
			'rag_chunk_size'              => self::field(
				$section,
				'rag_chunk_size',
				'RAG chunk size',
				'Sets the maximum character count for each literal text segment.',
				'integer',
				array(
					'minimum' => \Cybermaps\Discovery\Chunker::MIN_WINDOW_SIZE,
					'maximum' => \Cybermaps\Discovery\Chunker::MAX_WINDOW_SIZE,
				),
				\Cybermaps\Discovery\Chunker::DEFAULT_WINDOW_SIZE,
				800,
				'medium',
				array( self::dependency( 'enable_rag_chunks', true ) )
			),
			'rag_chunk_overlap'           => self::field(
				$section,
				'rag_chunk_overlap',
				'RAG chunk overlap',
				'Sets adjacent-segment character overlap; runtime caps it at half the selected chunk size.',
				'integer',
				array(
					'minimum'         => 0,
					'maximum'         => intdiv( \Cybermaps\Discovery\Chunker::MAX_WINDOW_SIZE, 2 ),
					'x-maximum-field' => 'rag_chunk_size / 2',
				),
				100,
				100,
				'medium',
				array( self::dependency( 'enable_rag_chunks', true ), self::dependency( 'rag_chunk_size', 'at-least-twice-overlap' ) )
			),
			'site_guide_instructions'     => self::field( $section, 'site_guide_instructions', 'Additional Site Guide guidance', 'Appends instructions only to skill.md after the shared custom AI instructions.', 'string', array( 'maxLength' => \Cybermaps\Discovery\PublicationConstraints::SITE_GUIDE_ADDITION_MAX_LENGTH ), '', 'Prefer technical documentation and use a formal tone.', 'high' ),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function report_fields(): array {
		$section = 'reports_deliverables';

		return array(
			'audit_post_min_words'     => self::field(
				$section,
				'audit_post_min_words',
				'Post minimum words',
				'Sets the literal visible-word threshold used to flag thin posts.',
				'integer',
				array(
					'minimum' => 1,
					'maximum' => 10000,
				),
				300,
				500,
				'medium'
			),
			'audit_post_max_age_days'  => self::field(
				$section,
				'audit_post_max_age_days',
				'Post review interval',
				'Flags posts older than this many days; zero disables the age finding.',
				'integer',
				array(
					'minimum' => 0,
					'maximum' => 36500,
				),
				365,
				365,
				'medium'
			),
			'audit_post_require_media' => self::boolean_field( $section, 'audit_post_require_media', 'Review posts without media', 'Adds a finding for posts without an image or media block.', true, 'medium' ),
			'audit_page_min_words'     => self::field(
				$section,
				'audit_page_min_words',
				'Page minimum words',
				'Sets the literal visible-word threshold used to flag thin pages.',
				'integer',
				array(
					'minimum' => 1,
					'maximum' => 10000,
				),
				150,
				300,
				'medium'
			),
			'audit_page_max_age_days'  => self::field(
				$section,
				'audit_page_max_age_days',
				'Page review interval',
				'Flags pages older than this many days; zero disables the age finding.',
				'integer',
				array(
					'minimum' => 0,
					'maximum' => 36500,
				),
				0,
				730,
				'medium'
			),
			'audit_page_require_media' => self::boolean_field( $section, 'audit_page_require_media', 'Review pages without media', 'Adds a finding for pages without an image or media block.', false, 'medium' ),
			'agency_name'              => self::field( $section, 'agency_name', 'Prepared-by name', 'Displays an optional professional or agency name in printable reports.', 'string', array( 'maxLength' => 256 ), '', 'Example Studio', 'medium' ),
			'agency_url'               => self::field( $section, 'agency_url', 'Prepared-by URL', 'Links the prepared-by name in printable reports.', 'string', self::http_url(), '', 'https://example.com/', 'medium', array( self::dependency( 'agency_name', 'non-empty' ) ) ),
			'agency_logo'              => self::field( $section, 'agency_logo', 'Prepared-by logo URL', 'Displays an optional public logo in printable reports.', 'string', self::http_url(), '', 'https://example.com/logo.png', 'medium' ),
			'site_name_override'       => self::field(
				$section,
				'site_name_override',
				'Client site name',
				'Overrides the client-facing site name in printable reports; a blank editable value uses the WordPress site title.',
				'string',
				array(
					'maxLength'          => 256,
					'x-runtime-fallback' => 'WordPress site title',
				),
				'',
				'Example Client',
				'medium'
			),
			'report_theme'             => self::field( $section, 'report_theme', 'Report theme', 'Selects the visual theme used by printable content and discovery reports.', 'string', array( 'enum' => array( 'swiss', 'minimal', 'mono', 'midnight', 'cyberbrand' ) ), 'swiss', 'cyberbrand', 'low' ),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function analytics_fields(): array {
		$section = 'analytics';

		return array(
			'enable_analytics'        => self::boolean_field( $section, 'enable_analytics', 'Crawler analytics', 'Records eligible discovery and crawler requests for operational analytics.', false, 'high' ),
			'anonymize_analytics_ips' => self::boolean_field( $section, 'anonymize_analytics_ips', 'Anonymize crawler IP addresses', 'Masks IP addresses before crawler analytics are stored.', true, 'high', array( self::dependency( 'enable_analytics', true ) ) ),
			'log_retention_days'      => self::field(
				$section,
				'log_retention_days',
				'Analytics retention',
				'Sets how many days crawler request records are retained.',
				'integer',
				array(
					'minimum' => 1,
					'maximum' => 365,
				),
				30,
				30,
				'high',
				array( self::dependency( 'enable_analytics', true ) )
			),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function advanced_fields(): array {
		$section = 'advanced_maintenance';

		return array(
			'static_engine_mode'   => self::field(
				$section,
				'static_engine_mode',
				'Static File Engine mode',
				'Controls physical publication: dynamic only, the small extension-bearing Core discovery files, or the full eligible publication cache. WordPress multisite is always resolved to dynamic-only off mode regardless of this saved value.',
				'string',
				array(
					'enum'               => array( 'off', 'well_known', 'all' ),
					'x-runtime-override' => 'off on WordPress multisite',
				),
				'well_known',
				'well_known',
				'high'
			),
			'frontend_base_url'    => self::field( $section, 'frontend_base_url', 'Front-end base URL', 'Rewrites published canonical URLs for a separate public headless front end. Use an absolute HTTP or HTTPS base without credentials, query, or fragment; an empty string clears it.', 'string', self::base_url(), '', 'https://www.example.com', 'high' ),
			'cdn_enabled'          => self::boolean_field( $section, 'cdn_enabled', 'Rewrite sitemap media URLs', 'Rewrites same-site image and video URLs in eligible XML and AI sitemap entries to the configured CDN base. It does not move or serve XSLT, CSS, discovery endpoints, or generated files.', false, 'high', array( self::dependency( 'cdn_base_url', 'non-empty' ) ) ),
			'cdn_base_url'         => self::field( $section, 'cdn_base_url', 'Sitemap media CDN base URL', 'Sets the replacement base for same-site image and video URLs in eligible XML and AI sitemap entries. Use an absolute HTTP or HTTPS base without credentials, query, or fragment; an empty string clears it.', 'string', self::base_url(), '', 'https://cdn.example.com', 'high', array( self::dependency( 'cdn_enabled', true ) ) ),
			'trusted_proxy_header' => self::field(
				$section,
				'trusted_proxy_header',
				'Trusted proxy header',
				'Selects the only forwarding header Cybermaps may trust when the direct remote address falls inside a configured trusted-proxy CIDR. Off ignores forwarded client-IP headers entirely.',
				'string',
				array(
					'enum' => array( 'off', 'forwarded', 'x_forwarded_for', 'x_real_ip' ),
				),
				'off',
				'x_forwarded_for',
				'high'
			),
			'trusted_proxy_cidrs'  => self::field(
				$section,
				'trusted_proxy_cidrs',
				'Trusted proxy CIDRs',
				'Defines up to 64 IPv4 or IPv6 CIDR ranges whose direct remote addresses may forward a client IP through the selected trusted proxy header. Supply the complete list; importing it replaces the existing list.',
				'array',
				array(
					'items'       => array(
						'type'      => 'string',
						'pattern'   => '^(?:(?:\\d{1,3}\\.){3}\\d{1,3}/(?:3[0-2]|[12]?\\d)|[0-9A-Fa-f:]+/(?:12[0-8]|1[01]\\d|\\d?\\d))$',
						'maxLength' => 43,
					),
					'maxItems'    => 64,
					'uniqueItems' => true,
				),
				array(),
				array( '10.0.0.0/8', '2001:db8::/32' ),
				'high',
				array(
					array(
						'field'     => 'trusted_proxy_header',
						'condition' => 'one_of',
						'value'     => array( 'forwarded', 'x_forwarded_for', 'x_real_ip' ),
					),
				)
			),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function strategy_fields(): array {
		$section     = 'discovery_strategy';
		$key_pattern = '^(?:(?:post_type|taxonomy):)?[a-z0-9_-]+$';

		return array(
			'discovery_archetype'    => self::field( $section, 'discovery_archetype', 'Starting profile', 'Selects the baseline publication weights and intent assignments for public content groups.', 'string', array( 'enum' => array( 'newspaper', 'blog', 'ecommerce', 'knowledgebase', 'corporate', 'small-business', 'medium-business' ) ), 'medium-business', 'small-business', 'medium', array(), 'cybermaps_discovery_center', 'archetype' ),
			'discovery_overrides'    => self::field(
				$section,
				'discovery_overrides',
				'Publication weight overrides',
				'Maps content-group identities to explicit positive relative publication weights. Supply the complete map; importing it replaces the existing map.',
				'object',
				array(
					'propertyNames'        => array( 'pattern' => $key_pattern ),
					'additionalProperties' => array(
						'type'    => 'number',
						'minimum' => 0.1,
						'maximum' => 1.0,
					),
					'maxProperties'        => \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX,
				),
				self::empty_object(),
				array(
					'post_type:post'    => 0.9,
					'taxonomy:category' => 0.6,
				),
				'high',
				array(),
				'cybermaps_discovery_center',
				'overrides'
			),
			'discovery_type_intents' => self::field(
				$section,
				'discovery_type_intents',
				'Content-group intents',
				'Maps content-group identities to informational or transactional discovery intent. Supply the complete map; importing it replaces the existing map.',
				'object',
				array(
					'propertyNames'        => array( 'pattern' => $key_pattern ),
					'additionalProperties' => array(
						'type' => 'string',
						'enum' => array( 'informational', 'transactional' ),
					),
					'maxProperties'        => \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX,
				),
				self::empty_object(),
				array( 'post_type:product' => 'transactional' ),
				'medium',
				array(),
				'cybermaps_discovery_center',
				'type_intents'
			),
			'discovery_disabled'     => self::field(
				$section,
				'discovery_disabled',
				'Disabled content groups',
				'Maps content-group identities excluded from Cybermaps XML, RSS, HTML, and AI publication eligibility. Every retained entry must be true; omit a key to enable that group. Supply the complete map because importing it replaces the existing map.',
				'object',
				array(
					'propertyNames'        => array( 'pattern' => $key_pattern ),
					'additionalProperties' => array(
						'type'  => 'boolean',
						'const' => true,
					),
					'maxProperties'        => \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX,
				),
				self::empty_object(),
				array( 'taxonomy:post_tag' => true ),
				'high',
				array(),
				'cybermaps_discovery_center',
				'disabled'
			),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function identity_fields(): array {
		$section      = 'site_identity';
		$text         = array( 'maxLength' => \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH );
		$identity     = array( 'Organization', 'LocalBusiness', 'Person' );
		$schema_types = array_values(
			array_diff( \Cybermaps\Core\SchemaRegistry::get_types(), array( 'Person' ) )
		);

		return array(
			'identity_type'            => self::field( $section, 'identity_type', 'Primary identity type', 'Sets the broad Schema.org identity type published for the site.', 'string', array( 'enum' => $identity ), 'Organization', 'Organization', 'high', array(), 'cybermaps_identity_data', 'type' ),
			'identity_precise_type'    => self::field( $section, 'identity_precise_type', 'Precise identity type', 'Narrows an Organization or LocalBusiness to a compatible supported Schema.org subtype. Person requires an empty value, and LocalBusiness accepts only its supported business-family subtypes.', 'string', array( 'enum' => array_values( array_unique( array_merge( array( '' ), $schema_types ) ) ) ), '', 'ProfessionalService', 'high', array( self::dependency( 'identity_type', 'Organization-or-LocalBusiness' ) ), 'cybermaps_identity_data', 'precise_type' ),
			'identity_name'            => self::field( $section, 'identity_name', 'Public identity name', 'Publishes the canonical person, organization, or business name. An empty name suppresses the configured primary identity entity from public Schema.org output.', 'string', $text, '', 'Example Studio', 'high', array(), 'cybermaps_identity_data', 'name' ),
			'identity_description'     => self::field( $section, 'identity_description', 'Public identity description', 'Publishes the public Schema.org identity description.', 'string', array( 'maxLength' => \Cybermaps\Core\IdentityEntityBuilder::MAX_DESCRIPTION_LENGTH ), '', 'A specialist WordPress engineering and support studio.', 'high', array(), 'cybermaps_identity_data', 'description' ),
			'identity_image_id'        => self::field( $section, 'identity_image_id', 'Identity image attachment ID', 'Selects a public WordPress image attachment for the identity image and organization logo.', 'integer', array( 'minimum' => 0 ), 0, 123, 'high', array(), 'cybermaps_identity_data', 'image_id' ),
			'identity_address_country' => self::field(
				$section,
				'identity_address_country',
				'Country code',
				'Publishes the two-letter ISO-style country code in the public postal address; an empty string clears it.',
				'string',
				array(
					'anyOf' => array(
						array( 'const' => '' ),
						array(
							'pattern'   => '^[A-Z]{2}$',
							'maxLength' => 2,
						),
					),
				),
				'',
				'US',
				'high',
				array(),
				'cybermaps_identity_data',
				'address_country'
			),
			'identity_address'         => self::field( $section, 'identity_address', 'Street address', 'Publishes the public street address for the configured identity.', 'string', $text, '', '123 Market Street', 'high', array(), 'cybermaps_identity_data', 'address' ),
			'identity_city'            => self::field( $section, 'identity_city', 'City', 'Publishes the public address locality.', 'string', $text, '', 'San Francisco', 'high', array(), 'cybermaps_identity_data', 'city' ),
			'identity_address_region'  => self::field( $section, 'identity_address_region', 'Region', 'Publishes the public state, province, or address region.', 'string', $text, '', 'CA', 'high', array(), 'cybermaps_identity_data', 'address_region' ),
			'identity_postal_code'     => self::field( $section, 'identity_postal_code', 'Postal code', 'Publishes the public postal code.', 'string', $text, '', '94105', 'high', array(), 'cybermaps_identity_data', 'postal_code' ),
			'identity_phone'           => self::field( $section, 'identity_phone', 'Public phone', 'Publishes the primary public telephone number.', 'string', array( 'maxLength' => \Cybermaps\Core\IdentityEntityBuilder::MAX_PHONE_LENGTH ), '', '+1-415-555-0100', 'high', array(), 'cybermaps_identity_data', 'phone' ),
			'identity_email'           => self::field( $section, 'identity_email', 'Public email', 'Publishes the primary public email address; an empty string clears it.', 'string', self::email_value(), '', 'hello@example.com', 'high', array(), 'cybermaps_identity_data', 'email' ),
			'identity_latitude'        => self::field( $section, 'identity_latitude', 'Latitude', 'Publishes geo only when both latitude and longitude are configured and the resolved Schema.org identity is in the LocalBusiness family; an empty string clears it.', 'string', self::coordinate_value( -90, 90, 'identity_longitude' ), '', '37.7749', 'high', array( self::dependency( 'identity_type', 'resolved-local-business' ), self::dependency( 'identity_longitude', 'paired-presence' ) ), 'cybermaps_identity_data', 'latitude' ),
			'identity_longitude'       => self::field( $section, 'identity_longitude', 'Longitude', 'Publishes geo only when both longitude and latitude are configured and the resolved Schema.org identity is in the LocalBusiness family; an empty string clears it.', 'string', self::coordinate_value( -180, 180, 'identity_latitude' ), '', '-122.4194', 'high', array( self::dependency( 'identity_type', 'resolved-local-business' ), self::dependency( 'identity_latitude', 'paired-presence' ) ), 'cybermaps_identity_data', 'longitude' ),
			'identity_hours'           => self::field(
				$section,
				'identity_hours',
				'Opening hours',
				'Publishes opening-hour slots only when the resolved Schema.org identity is in the LocalBusiness family. Supply the complete weekday map; importing it replaces all existing hours.',
				'object',
				self::hours_shape(),
				self::empty_object(),
				array(
					'monday' => array(
						array(
							'open'  => '09:00',
							'close' => '17:00',
						),
					),
				),
				'high',
				array( self::dependency( 'identity_type', 'resolved-local-business' ) ),
				'cybermaps_identity_data',
				'hours'
			),
			'identity_catalogs'        => self::field(
				$section,
				'identity_catalogs',
				'Offer catalogs',
				'Publishes bounded manual or page-tree-derived Service or Product catalogs. Supply the complete list; importing it replaces all existing catalogs.',
				'array',
				self::catalog_shape(),
				array(),
				array(
					array(
						'mode'      => 'manual',
						'item_type' => 'Service',
						'name'      => 'Services',
						'items'     => array( 'Technical SEO audit' ),
						'parent_id' => 0,
					),
				),
				'high',
				array(),
				'cybermaps_identity_data',
				'catalogs'
			),
			'identity_social_profiles' => self::field(
				$section,
				'identity_social_profiles',
				'Social profiles',
				'Publishes up to 20 unique public profile URLs as sameAs identity links. Supply the complete list; importing it replaces all existing profiles.',
				'array',
				array(
					'items'       => self::http_url( false ),
					'maxItems'    => \Cybermaps\Core\IdentityEntityBuilder::MAX_SOCIAL_PROFILES,
					'uniqueItems' => true,
				),
				array(),
				array( 'https://www.linkedin.com/company/example/' ),
				'high',
				array(),
				'cybermaps_identity_data',
				'social_profiles'
			),
			'identity_contact_points'  => self::field(
				$section,
				'identity_contact_points',
				'Contact points',
				'Publishes bounded public support, technical-support, or sales contacts. Supply the complete list; importing it replaces all existing contact points.',
				'array',
				self::contact_points_shape(),
				array(),
				array(
					array(
						'type'  => 'Customer Support',
						'phone' => '+1-415-555-0100',
						'email' => 'support@example.com',
					),
				),
				'high',
				array(),
				'cybermaps_identity_data',
				'contact_points'
			),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function robots_fields(): array {
		$section     = 'robots_control';
		$crawler_ids = array_keys( \Cybermaps\Core\CrawlerRegistry::get_policy_bots() );

		return array(
			'takeover_enabled'  => self::field( $section, 'takeover_enabled', 'Robots.txt takeover', 'Replaces the public virtual robots.txt with Cybermaps-managed baseline and crawler policy output.', 'boolean', array( 'enum' => array( true, false ) ), false, false, 'high', array(), 'cybermaps_robots_manager', 'takeover_enabled' ),
			'manual_directives' => self::field(
				$section,
				'manual_directives',
				'Manual robots directives',
				'Appends bounded operator-authored robots.txt directives to the managed output.',
				'string',
				array(
					'maxLength'        => \Cybermaps\Discovery\Robots::MAX_MANUAL_DIRECTIVES_BYTES,
					'contentMediaType' => 'text/plain',
				),
				'',
				"User-agent: ExampleBot\nDisallow: /private/",
				'high',
				array(),
				'cybermaps_robots_manager',
				'manual_directives'
			),
			'crawler_overrides' => self::field(
				$section,
				'crawler_overrides',
				'Crawler overrides',
				'Maps registered crawler IDs to explicit robots permission, AI manifest-target permission, and requests-per-minute limits. Only crawler entries classified as AI training, AI search, or AI user agents can be manifest targets; other entries always store false for that value. Robots allow/deny directives are emitted only while robots.txt takeover is enabled; manifest-target permission and rate limits have independent runtime effects. Supply the complete map because importing it replaces all existing crawler overrides.',
				'object',
				array(
					'propertyNames'        => array( 'enum' => $crawler_ids ),
					'additionalProperties' => self::object_shape(
						array(
							'robots' => array( 'type' => 'boolean' ),
							'llm'    => array( 'type' => 'boolean' ),
							'tpm'    => array(
								'type'    => 'integer',
								'minimum' => 0,
								'maximum' => 10000,
							),
						),
						array( 'robots', 'llm', 'tpm' )
					),
					'maxProperties'        => count( $crawler_ids ),
				),
				self::empty_object(),
				array(
					'gptbot' => array(
						'robots' => true,
						'llm'    => true,
						'tpm'    => 60,
					),
				),
				'high',
				array(),
				'cybermaps_robots_manager',
				'overrides'
			),
			'content_signals'   => self::field(
				$section,
				'content_signals',
				'Content-Signal declarations',
				'Publishes yes/no preferences for model training, search, and AI-input use in robots.txt. Supply the complete map; importing it replaces all existing signal declarations.',
				'object',
				self::object_shape(
					array(
						'ai-train' => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
						'search'   => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
						'ai-input' => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
					)
				),
				self::empty_object(),
				array(
					'ai-train' => 'no',
					'search'   => 'yes',
					'ai-input' => 'yes',
				),
				'high',
				array(),
				'cybermaps_robots_manager',
				'content_signals'
			),
		);
	}

	/**
	 * @param array<string,mixed> $allowed JSON Schema keywords describing accepted values.
	 * @param mixed               $effective_default Runtime/UI default when no value is stored.
	 * @param mixed               $example Representative valid value.
	 * @param array<int,array<string,mixed>> $dependencies Field dependencies.
	 * @return array<string,mixed>
	 */
	private static function field(
		string $section_id,
		string $id,
		string $label,
		string $purpose,
		string $json_type,
		array $allowed,
		$effective_default,
		$example,
		string $risk = 'low',
		array $dependencies = array(),
		string $option = 'cybermaps_settings',
		?string $mapped_field = null
	): array {
		return array(
			'id'                => $id,
			'section_id'        => $section_id,
			'section_label'     => '',
			'option'            => $option,
			'field'             => $mapped_field ?? $id,
			'label'             => $label,
			'purpose'           => $purpose,
			'json_type'         => $json_type,
			'allowed'           => $allowed,
			'effective_default' => $effective_default,
			'dependencies'      => $dependencies,
			'example'           => $example,
			'risk'              => $risk,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $dependencies Field dependencies.
	 * @return array<string,mixed>
	 */
	private static function boolean_field(
		string $section_id,
		string $id,
		string $label,
		string $purpose,
		bool $effective_default,
		string $risk = 'low',
		array $dependencies = array()
	): array {
		return self::field(
			$section_id,
			$id,
			$label,
			$purpose,
			'boolean',
			array( 'enum' => array( true, false ) ),
			$effective_default,
			$effective_default,
			$risk,
			$dependencies
		);
	}

	/**
	 * @param mixed $expected Expected dependency state.
	 * @return array{field:string,condition:string,value:mixed}
	 */
	private static function dependency( string $field, $expected ): array {
		if ( 'non-empty' === $expected ) {
			return array(
				'field'     => $field,
				'condition' => 'non_empty',
				'value'     => true,
			);
		}
		if ( 'standard-or-advanced' === $expected ) {
			return array(
				'field'     => $field,
				'condition' => 'one_of',
				'value'     => array( 'standard', 'advanced' ),
			);
		}
		if ( 'Organization-or-LocalBusiness' === $expected ) {
			return array(
				'field'     => $field,
				'condition' => 'one_of',
				'value'     => array( 'Organization', 'LocalBusiness' ),
			);
		}
		if ( 'at-least-twice-overlap' === $expected ) {
			return array(
				'field'     => $field,
				'condition' => 'maximum_fraction_of',
				'value'     => 0.5,
			);
		}
		if ( 'resolved-local-business' === $expected ) {
			return array(
				'field'     => $field,
				'condition' => 'resolved_type_is_local_business',
				'value'     => true,
			);
		}
		if ( 'paired-presence' === $expected ) {
			return array(
				'field'     => $field,
				'condition' => 'paired_presence',
				'value'     => true,
			);
		}

		return array(
			'field'     => $field,
			'condition' => 'equals',
			'value'     => $expected,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function http_url( bool $allow_empty = true ): array {
		$url = array(
			'type'               => 'string',
			'format'             => 'uri',
			'pattern'            => '^https?://(?![^/?#]*@)[^/?#]+',
			'maxLength'          => \Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH,
			'x-userinfo-allowed' => false,
		);
		if ( ! $allow_empty ) {
			return $url;
		}

		return array(
			'type'    => 'string',
			'pattern' => '^(?:$|https?://(?![^/?#]*@)[^/?#]+)',
			'anyOf'   => array(
				array( 'const' => '' ),
				$url,
			),
		);
	}

	/**
	 * Public HTTPS URL without embedded credentials.
	 *
	 * @return array<string,mixed>
	 */
	private static function https_url(): array {
		return array(
			'type'               => 'string',
			'format'             => 'uri',
			'pattern'            => '^https://(?![^/?#]*@)[^/?#]+',
			'maxLength'          => \Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH,
			'x-userinfo-allowed' => false,
		);
	}

	/**
	 * Optional public base URL suitable for unambiguous path appending.
	 *
	 * @return array<string,mixed>
	 */
	private static function base_url(): array {
		$base = array(
			'type'               => 'string',
			'format'             => 'uri',
			'pattern'            => '^https?://(?![^/?#]*@)[^/?#]+(?:/[^?#]*)?$',
			'maxLength'          => \Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH,
			'x-userinfo-allowed' => false,
			'x-query-allowed'    => false,
			'x-fragment-allowed' => false,
		);

		return array(
			'type'    => 'string',
			'pattern' => '^(?:$|https?://(?![^/?#]*@)[^/?#]+(?:/[^?#]*)?)$',
			'anyOf'   => array(
				array( 'const' => '' ),
				$base,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function key_list( ?int $maximum = null ): array {
		$schema = array(
			'items'       => array(
				'type'      => 'string',
				'pattern'   => '^[a-z0-9_-]+$',
				'maxLength' => 191,
			),
			'uniqueItems' => true,
		);
		if ( null !== $maximum ) {
			$schema['maxItems'] = $maximum;
		}

		return $schema;
	}

	/**
	 * Email schema that permits an explicit empty-string clear.
	 *
	 * @return array<string,mixed>
	 */
	private static function email_value(): array {
		return array(
			'anyOf' => array(
				array( 'const' => '' ),
				array(
					'format'    => 'email',
					'maxLength' => \Cybermaps\Core\IdentityEntityBuilder::MAX_EMAIL_LENGTH,
				),
			),
		);
	}

	/**
	 * Decimal-string schema that permits an explicit empty-string clear.
	 *
	 * The canonical identity sanitizer remains authoritative for the exact
	 * numeric range; the extension keywords make those bounds visible to tools.
	 *
	 * @return array<string,mixed>
	 */
	private static function coordinate_value( int $minimum, int $maximum, string $paired_field ): array {
		$pattern = -90 === $minimum && 90 === $maximum
			? '^-?(?:(?:[0-8]?\d)(?:\.\d+)?|\.\d+|90(?:\.0+)?)$'
			: '^-?(?:(?:[0-9]?\d|1[0-7]\d)(?:\.\d+)?|\.\d+|180(?:\.0+)?)$';

		return array(
			'anyOf'             => array(
				array( 'const' => '' ),
				array( 'pattern' => $pattern ),
			),
			'x-numeric-minimum' => $minimum,
			'x-numeric-maximum' => $maximum,
			'x-paired-field'    => $paired_field,
		);
	}

	private static function empty_object(): \stdClass {
		return new \stdClass();
	}

	/**
	 * @param array<string,array<string,mixed>> $properties Object properties.
	 * @param string[]                          $required Required property names.
	 * @return array<string,mixed>
	 */
	private static function object_shape( array $properties, array $required = array() ): array {
		$shape = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( ! empty( $required ) ) {
			$shape['required'] = $required;
		}

		return $shape;
	}

	/**
	 * Cross-field constraints that apply when related values are changed in the
	 * same section object. Omitted and null fields still preserve destination
	 * values, so runtime sanitizers remain authoritative for mixed old/new state.
	 *
	 * @return array<string,mixed>
	 */
	private static function section_json_schema_keywords( string $section_id ): array {
		if ( 'site_identity' !== $section_id ) {
			return array();
		}

		$local_business_types    = array_values(
			array_filter(
				\Cybermaps\Core\SchemaRegistry::get_types(),
				static fn ( string $type ): bool =>
					\Cybermaps\Core\SchemaRegistry::is_local_business_type( $type )
			)
		);
		$nullable_empty          = array(
			'anyOf' => array(
				array( 'const' => '' ),
				array( 'type' => 'null' ),
			),
		);
		$nullable_local_business = array(
			'anyOf' => array(
				array(
					'type' => 'string',
					'enum' => array_values( array_unique( array_merge( array( '' ), $local_business_types ) ) ),
				),
				array( 'type' => 'null' ),
			),
		);

		return array(
			'allOf' => array(
				array(
					'if'   => array(
						'properties' => array( 'identity_type' => array( 'const' => 'Person' ) ),
						'required'   => array( 'identity_type' ),
					),
					'then' => array(
						'properties' => array( 'identity_precise_type' => $nullable_empty ),
					),
				),
				array(
					'if'   => array(
						'properties' => array( 'identity_type' => array( 'const' => 'LocalBusiness' ) ),
						'required'   => array( 'identity_type' ),
					),
					'then' => array(
						'properties' => array( 'identity_precise_type' => $nullable_local_business ),
					),
				),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function hours_shape(): array {
		$slot                       = self::object_shape(
			array(
				'open'  => array(
					'type'    => 'string',
					'pattern' => '^(?:[01]\d|2[0-3]):[0-5]\d$',
				),
				'close' => array(
					'type'    => 'string',
					'pattern' => '^(?:[01]\d|2[0-3]):[0-5]\d$',
				),
			),
			array( 'open', 'close' )
		);
		$slot['x-fields-not-equal'] = array( 'open', 'close' );
		$day                        = array(
			'type'     => 'array',
			'items'    => $slot,
			'maxItems' => \Cybermaps\Core\IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY,
		);
		$properties                 = array();
		foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $weekday ) {
			$properties[ $weekday ] = $day;
		}

		return self::object_shape( $properties );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function catalog_shape(): array {
		$item          = self::object_shape(
			array(
				'mode'      => array(
					'type' => 'string',
					'enum' => array( 'manual', 'auto' ),
				),
				'item_type' => array(
					'type' => 'string',
					'enum' => array( 'Service', 'Product' ),
				),
				'name'      => array(
					'type'      => 'string',
					'maxLength' => \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH,
				),
				'items'     => array(
					'type'     => 'array',
					'items'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH,
						'pattern'   => '.*\S.*',
					),
					'maxItems' => \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG,
				),
				'parent_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
			array( 'mode', 'item_type' )
		);
		$item['allOf'] = array(
			array(
				'if'   => array(
					'properties' => array( 'mode' => array( 'const' => 'manual' ) ),
					'required'   => array( 'mode' ),
				),
				'then' => array(
					'required'   => array( 'items' ),
					'properties' => array(
						'items'     => array( 'minItems' => 1 ),
						'parent_id' => array( 'const' => 0 ),
					),
				),
			),
			array(
				'if'   => array(
					'properties' => array( 'mode' => array( 'const' => 'auto' ) ),
					'required'   => array( 'mode' ),
				),
				'then' => array(
					'required'   => array( 'parent_id' ),
					'properties' => array(
						'items'     => array( 'maxItems' => 0 ),
						'parent_id' => array( 'minimum' => 1 ),
					),
				),
			),
		);

		return array(
			'items'             => $item,
			'maxItems'          => \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS,
			'x-max-total-items' => \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_TOTAL,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function contact_points_shape(): array {
		$item          = self::object_shape(
			array(
				'type'  => array(
					'type' => 'string',
					'enum' => array( 'Customer Support', 'Technical Support', 'Sales' ),
				),
				'phone' => array(
					'type'      => 'string',
					'maxLength' => \Cybermaps\Core\IdentityEntityBuilder::MAX_PHONE_LENGTH,
				),
				'email' => array( 'type' => 'string' ) + self::email_value(),
			),
			array( 'type' )
		);
		$item['anyOf'] = array(
			array(
				'required'   => array( 'phone' ),
				'properties' => array(
					'phone' => array(
						'type'      => 'string',
						'minLength' => 1,
						'pattern'   => '.*\S.*',
					),
				),
			),
			array(
				'required'   => array( 'email' ),
				'properties' => array(
					'email' => array(
						'type'      => 'string',
						'minLength' => 1,
						'format'    => 'email',
					),
				),
			),
		);

		return array(
			'items'    => $item,
			'maxItems' => \Cybermaps\Core\IdentityEntityBuilder::MAX_CONTACT_POINTS,
		);
	}

	/**
	 * Convert field metadata to one nullable JSON Schema property.
	 *
	 * @param array<string,mixed> $field Field metadata.
	 * @return array<string,mixed>
	 */
	private static function field_json_schema( array $field ): array {
		$value_schema = array_merge(
			array( 'type' => (string) $field['json_type'] ),
			(array) $field['allowed']
		);

		return array(
			'title'               => (string) $field['label'],
			'description'         => (string) $field['purpose'] . ' Set null or omit the field to preserve the destination.',
			'anyOf'               => array(
				$value_schema,
				array( 'type' => 'null' ),
			),
			'default'             => null,
			'examples'            => array( $field['example'] ),
			'x-cybermaps-option'  => (string) $field['option'],
			'x-cybermaps-field'   => (string) $field['field'],
			'x-cybermaps-risk'    => (string) $field['risk'],
			'x-cybermaps-default' => $field['effective_default'],
			'x-cybermaps-depends' => $field['dependencies'],
		);
	}

	private static function plugin_version(): string {
		return defined( 'CYBERMAPS_VERSION' ) && '' !== (string) CYBERMAPS_VERSION
			? (string) CYBERMAPS_VERSION
			: 'current';
	}

	/**
	 * Stable JSON encoding for contract hashes.
	 *
	 * @param mixed $value Value to encode.
	 */
	private static function encode( $value ): string {
		$encoded = wp_json_encode(
			$value,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( 'Cybermaps could not encode the AI configuration contract.' );
		}

		return $encoded;
	}
}
