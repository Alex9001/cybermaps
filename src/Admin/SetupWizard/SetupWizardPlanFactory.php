<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Core\IdentityEntityBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts reviewed Guided Setup answers into the canonical configuration
 * changes envelope. This class is intentionally pure: it never writes an
 * option and MigrationHub remains the transaction owner.
 */
final class SetupWizardPlanFactory {
	/** @var array<string,mixed> */
	private array $answers;

	/** @var array<string,string> */
	private array $modes;

	private SetupWizardContext $context;

	/** @var array<string,array<string,mixed>> */
	private array $changes = array();

	/** @var array<string,string> */
	private array $rationales = array();

	/** @var string[] */
	private array $reset_sections = array();

	/** @param array<string,mixed> $request */
	public static function build( array $request, SetupWizardContext $context ): array {
		$factory = new self( $request, $context );
		return $factory->make();
	}

	/** @param array<string,mixed> $request */
	private function __construct( array $request, SetupWizardContext $context ) {
		$version = isset( $request['wizard_version'] ) ? (int) $request['wizard_version'] : 0;
		if ( SetupWizardRegistry::VERSION !== $version ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'This Guided Setup session is out of date. Reload it before continuing.', 'cybermaps' ) );
		}
		$answers = $request['answers'] ?? array();
		if ( ! is_array( $answers ) || array_is_list( $answers ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'Guided Setup answers must be an object.', 'cybermaps' ) );
		}
		$modes = $request['section_modes'] ?? array();
		if ( ! is_array( $modes ) || array_is_list( $modes ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'Guided Setup section choices must be an object.', 'cybermaps' ) );
		}

		$this->answers = $answers;
		$this->context = $context;
		$this->modes   = array();
		foreach ( SetupWizardRegistry::section_ids() as $section_id ) {
			$mode = isset( $modes[ $section_id ] ) && is_scalar( $modes[ $section_id ] )
				? sanitize_key( (string) $modes[ $section_id ] )
				: 'keep';
			if ( ! in_array( $mode, array( 'keep', 'configure', 'reset' ), true ) ) {
				throw new \InvalidArgumentException(
					/* translators: %s: Guided Setup section identifier. */
					sprintf( __( 'The %s setup choice is invalid.', 'cybermaps' ), $section_id ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Non-HTML exception data.
				);
			}
			$this->modes[ $section_id ] = $mode;
		}
	}

	/** @return array<string,mixed> */
	private function make(): array {
		$this->plan_strategy();
		$this->plan_sitemaps();
		$this->plan_ai();
		$this->plan_identity();
		$this->plan_analytics();
		$this->plan_delivery();
		$this->plan_reports();
		$this->validate_dependencies();

		$document = array(
			'format'         => AIConfigurationRegistry::CHANGES_FORMAT,
			'format_version' => AIConfigurationRegistry::FORMAT_VERSION,
			'plugin_version' => defined( 'CYBERMAPS_VERSION' ) ? (string) CYBERMAPS_VERSION : '',
			'changes'        => $this->changes,
		);
		$content  = wp_json_encode( $document, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $content ) || '' === $content ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \RuntimeException( __( 'Cybermaps could not prepare the Guided Setup configuration document.', 'cybermaps' ) );
		}

		return array(
			'document'         => $document,
			'content'          => $content,
			'rationales'       => $this->rationales,
			'reset_sections'   => $this->reset_sections,
			'environment_hash' => $this->context->environment_hash(),
		);
	}

	private function plan_strategy(): void {
		$mode = $this->modes['strategy'];
		if ( 'keep' === $mode ) {
			return;
		}
		if ( 'reset' === $mode ) {
			$this->reset_sections[] = 'strategy';
			$this->add( 'discovery_archetype', $this->default_value( 'discovery_archetype' ), __( 'Reset guided strategy fields to their Cybermaps defaults.', 'cybermaps' ) );
			$this->add( 'discovery_overrides', new \stdClass(), __( 'Reset custom publication weights.', 'cybermaps' ) );
			$this->add( 'discovery_type_intents', new \stdClass(), __( 'Reset custom content intents.', 'cybermaps' ) );
			$this->add( 'discovery_disabled', new \stdClass(), __( 'Reset custom disabled content groups.', 'cybermaps' ) );
			return;
		}

		$archetypes = ( new \Cybermaps\Admin\DiscoveryAuditor() )->get_all_archetypes();
		$archetype  = $this->choice( 'strategy_profile', array_keys( $archetypes ), 'medium-business' );
		$this->add( 'discovery_archetype', $archetype, __( 'Confirmed site profile.', 'cybermaps' ) );
		if ( $this->boolean( 'strategy_clear_custom' ) ) {
			$this->add( 'discovery_overrides', new \stdClass(), __( 'Replace custom weights with the selected profile baseline.', 'cybermaps' ) );
			$this->add( 'discovery_type_intents', new \stdClass(), __( 'Replace custom intents with the selected profile baseline.', 'cybermaps' ) );
			$this->add( 'discovery_disabled', new \stdClass(), __( 'Re-enable custom-disabled content groups.', 'cybermaps' ) );
		}
	}

	private function plan_sitemaps(): void {
		$mode = $this->modes['sitemaps'];
		if ( 'keep' === $mode ) {
			return;
		}
		if ( 'reset' === $mode ) {
			$this->reset_sections[] = 'sitemaps';
			foreach ( SetupWizardRegistry::controlled_fields()['sitemaps'] as $field_id ) {
				$this->add( $field_id, $this->default_value( $field_id ), __( 'Reset guided sitemap fields to their Cybermaps defaults.', 'cybermaps' ) );
			}
			return;
		}

		$surfaces = $this->string_list( 'sitemap_surfaces', array( 'homepage', 'authors', 'archives', 'empty_terms' ) );
		$this->add( 'include_homepage', in_array( 'homepage', $surfaces, true ), __( 'Selected sitemap archive coverage.', 'cybermaps' ) );
		$this->add( 'include_authors', in_array( 'authors', $surfaces, true ), __( 'Selected sitemap archive coverage.', 'cybermaps' ) );
		$this->add( 'include_archives', in_array( 'archives', $surfaces, true ), __( 'Selected sitemap archive coverage.', 'cybermaps' ) );
		$this->add( 'include_empty_terms', in_array( 'empty_terms', $surfaces, true ), __( 'Selected sitemap archive coverage.', 'cybermaps' ) );

		$media          = $this->choice( 'sitemap_media', array( 'none', 'standard', 'advanced' ), 'standard' );
		$media_features = 'none' === $media ? array() : $this->string_list( 'sitemap_media_features', array( 'video', 'multimodal' ) );
		$this->add( 'media_discovery_intensity', $media, __( 'Selected media discovery depth.', 'cybermaps' ) );
		$this->add( 'enable_video_schema', in_array( 'video', $media_features, true ), __( 'Selected media publishing enhancement.', 'cybermaps' ) );
		$this->add( 'enable_multimodal_discovery', in_array( 'multimodal', $media_features, true ), __( 'Selected media publishing enhancement.', 'cybermaps' ) );

		$specials = $this->string_list( 'sitemap_specials', array( 'news', 'rss', 'html', 'indexnow', 'websub' ) );
		$this->add( 'enable_google_news', in_array( 'news', $specials, true ), __( 'Selected specialized publication.', 'cybermaps' ) );
		$this->add( 'enable_rss_sitemap', in_array( 'rss', $specials, true ), __( 'Selected specialized publication.', 'cybermaps' ) );
		$this->add( 'enable_shortcode', in_array( 'html', $specials, true ), __( 'Selected specialized publication.', 'cybermaps' ) );
		$this->add( 'enable_indexnow', in_array( 'indexnow', $specials, true ), __( 'Selected notification service.', 'cybermaps' ) );
		$this->add( 'enable_websub', in_array( 'websub', $specials, true ), __( 'Selected notification service.', 'cybermaps' ) );
		if ( in_array( 'rss', $specials, true ) ) {
			$this->add( 'rss_sitemap_types', $this->post_types( 'sitemap_rss_types' ), __( 'Selected RSS content types.', 'cybermaps' ) );
		}
		if ( in_array( 'websub', $specials, true ) && '' === trim( $this->current_string( 'websub_hubs' ) ) ) {
			$this->add( 'websub_hubs', $this->default_value( 'websub_hubs' ), __( 'Added the default public WebSub hubs because none were configured.', 'cybermaps' ) );
		}

		$integration = $this->string_list( 'sitemap_integration', array( 'redirect_core', 'robots', 'cache' ) );
		$this->add( 'redirect_wp_sitemap', in_array( 'redirect_core', $integration, true ), __( 'Selected WordPress integration.', 'cybermaps' ) );
		$this->add( 'inject_robots', in_array( 'robots', $integration, true ), __( 'Selected WordPress integration.', 'cybermaps' ) );
		$this->add( 'enable_caching', in_array( 'cache', $integration, true ), __( 'Selected WordPress integration.', 'cybermaps' ) );
		if ( $this->context->has_translation_environment() ) {
			$this->add( 'enable_translation_integrations', $this->boolean( 'sitemap_translations' ), __( 'Selected alternate-language sitemap support.', 'cybermaps' ) );
		}
	}

	private function plan_ai(): void {
		$mode = $this->modes['ai'];
		if ( 'keep' === $mode ) {
			return;
		}
		if ( 'reset' === $mode ) {
			$this->reset_sections[] = 'ai';
			foreach ( SetupWizardRegistry::controlled_fields()['ai'] as $field_id ) {
				if ( 'ai_kg_link_org' === $field_id ) {
					continue;
				}
				$this->add( $field_id, $this->default_value( $field_id ), __( 'Reset guided AI publishing fields to their Cybermaps defaults.', 'cybermaps' ) );
			}
			return;
		}

		$hub_enabled = $this->boolean( 'ai_hub' );
		$this->add( 'enable_discovery_hub', $hub_enabled, __( 'Selected AI Publication Hub state.', 'cybermaps' ) );
		$this->add( 'mcp_mode', $hub_enabled ? $this->choice( 'mcp_mode', array( 'off', 'discovery', 'read_only', 'operations' ), 'off' ) : 'off', __( 'Selected Model Context Protocol access tier.', 'cybermaps' ) );
		if ( ! $hub_enabled ) {
			return;
		}

		$features = $this->string_list( 'ai_features', array( 'headers', 'hints', 'sitemap_link', 'full', 'tldr', 'rag', 'localized' ) );
		$this->add( 'enable_header_discovery', in_array( 'headers', $features, true ), __( 'Selected AI publication feature.', 'cybermaps' ) );
		$this->add( 'enable_content_hints', in_array( 'hints', $features, true ), __( 'Selected AI publication feature.', 'cybermaps' ) );
		$this->add( 'llms_include_sitemap_link', in_array( 'sitemap_link', $features, true ), __( 'Selected AI publication feature.', 'cybermaps' ) );
		$this->add( 'enable_llms_full', in_array( 'full', $features, true ), __( 'Selected optional LLMS publication.', 'cybermaps' ) );
		$this->add( 'enable_llms_tldr', in_array( 'tldr', $features, true ), __( 'Selected optional LLMS publication.', 'cybermaps' ) );
		$this->add( 'enable_rag_chunks', in_array( 'rag', $features, true ), __( 'Selected optional retrieval publication.', 'cybermaps' ) );
		$this->add(
			'enable_multilingual_hub',
			$this->context->has_translation_environment() && in_array( 'localized', $features, true ),
			__( 'Selected localized AI publication support.', 'cybermaps' )
		);

		$types = $this->post_types( 'ai_types' );
		$this->add( 'llms_included_types', $types, __( 'Selected LLMS and search content types.', 'cybermaps' ) );
		$this->add(
			'ai_sitemap_types',
			$this->boolean( 'ai_separate_sitemap_types' ) ? $this->post_types( 'ai_sitemap_types' ) : $types,
			__( 'Selected AI sitemap content types.', 'cybermaps' )
		);
		$this->add( 'llms_mission_statement', $this->text( 'ai_mission' ), __( 'Confirmed LLMS site summary.', 'cybermaps' ) );
		$this->add( 'ai_business_description', $this->text( 'ai_business_description' ), __( 'Confirmed AI Discovery Manifest summary.', 'cybermaps' ) );
		$this->add( 'ai_topics', $this->topics(), __( 'Confirmed site topics.', 'cybermaps' ) );
		$this->add( 'ai_capabilities', $this->string_list( 'ai_capabilities', array( 'search_content', 'read_articles', 'extract_entities' ) ), __( 'Confirmed truthful capability declarations.', 'cybermaps' ) );

		$this->add( 'ai_usage_rag', $this->choice( 'ai_usage_rag', array( 'allow', 'limited', 'forbid' ), 'allow' ), __( 'Confirmed retrieval-use preference.', 'cybermaps' ) );
		$this->add( 'ai_usage_training', $this->choice( 'ai_usage_training', array( 'allow', 'forbid' ), 'forbid' ), __( 'Confirmed training-use preference.', 'cybermaps' ) );
		$this->add( 'ai_usage_commercial', $this->choice( 'ai_usage_commercial', array( 'allow', 'forbid' ), 'forbid' ), __( 'Confirmed commercial-reuse preference.', 'cybermaps' ) );
		$this->add( 'llms_content_license', $this->license(), __( 'Confirmed content license assertion.', 'cybermaps' ) );
		$this->add( 'ai_licensing_email', $this->text( 'ai_licensing_email' ), __( 'Confirmed public licensing contact.', 'cybermaps' ) );
		$this->plan_content_signals();
		$this->plan_manifest_endpoints( $features );
	}

	private function plan_content_signals(): void {
		if ( ! $this->boolean( 'ai_sync_signals' ) ) {
			return;
		}
		$current             = $this->context->configuration()['cybermaps_robots_manager']['content_signals'] ?? array();
		$signals             = is_array( $current ) ? $current : array();
		$training            = $this->choice( 'ai_usage_training', array( 'allow', 'forbid' ), 'forbid' );
		$rag                 = $this->choice( 'ai_usage_rag', array( 'allow', 'limited', 'forbid' ), 'allow' );
		$signals['ai-train'] = 'allow' === $training ? 'yes' : 'no';
		if ( 'limited' !== $rag ) {
			$signals['ai-input'] = 'allow' === $rag ? 'yes' : 'no';
		}
		$signals['search'] = $this->choice( 'ai_signal_search', array( 'yes', 'no' ), 'yes' );
		$this->add( 'content_signals', $signals, __( 'Published matching Content-Signal preferences.', 'cybermaps' ) );
	}

	/** @param string[] $features */
	private function plan_manifest_endpoints( array $features ): void {
		$current = $this->current_array( 'ai_manifest_endpoints' );
		$manual  = array_values( array_intersect( $current, array( 'skill.md', 'ai-actions.json' ) ) );
		$managed = array( 'llms.txt', 'feed.json', 'knowledge-graph.json', 'ai-sitemap.xml', 'ai-usage.json' );
		if ( in_array( 'full', $features, true ) ) {
			$managed[] = 'llms-full.txt';
		}
		if ( in_array( 'tldr', $features, true ) ) {
			$managed[] = 'llms-tldr.txt';
		}
		$this->add(
			'ai_manifest_endpoints',
			array_values( array_unique( array_merge( $managed, $manual ) ) ),
			__( 'Updated the AI manifest with the selected publication package while preserving manual guide and action entries.', 'cybermaps' )
		);
	}

	private function plan_identity(): void {
		$mode = $this->modes['identity'];
		if ( 'keep' === $mode ) {
			return;
		}
		if ( 'reset' === $mode ) {
			$this->reset_sections[] = 'identity';
			foreach ( array( 'identity_type', 'identity_precise_type', 'identity_name', 'identity_description', 'identity_image_id', 'ai_kg_link_org' ) as $field_id ) {
				$this->add( $field_id, $this->default_value( $field_id ), __( 'Reset guided identity fields while preserving detailed identity and catalog data.', 'cybermaps' ) );
			}
			return;
		}

		$type    = $this->choice( 'identity_type', array( 'Organization', 'LocalBusiness', 'Person' ), 'Organization' );
		$precise = 'Person' === $type ? '' : $this->text( 'identity_precise_type' );
		$this->add( 'identity_type', $type, __( 'Confirmed public identity type.', 'cybermaps' ) );
		$this->add( 'identity_precise_type', $precise, __( 'Confirmed public identity subtype.', 'cybermaps' ) );
		$this->add( 'identity_name', $this->text( 'identity_name' ), __( 'Confirmed public identity name.', 'cybermaps' ) );
		$this->add( 'identity_description', $this->text( 'identity_description' ), __( 'Confirmed public identity description.', 'cybermaps' ) );
		$this->add( 'identity_image_id', max( 0, (int) $this->answer( 'identity_image_id', 0 ) ), __( 'Selected public identity image.', 'cybermaps' ) );
		$this->add( 'ai_kg_link_org', 'Person' !== $type && $this->boolean( 'identity_kg_link' ), __( 'Selected Knowledge Graph identity link.', 'cybermaps' ) );
		$this->plan_catalog();
	}

	private function plan_catalog(): void {
		$action = $this->choice( 'catalog_action', array( 'none', 'add', 'edit' ), 'none' );
		if ( 'none' === $action ) {
			return;
		}
		$parent_id = max( 0, (int) $this->answer( 'catalog_parent_id', 0 ) );
		if ( ! $this->context->valid_catalog_parent( $parent_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'Choose a published parent Page with at least one published direct child for the automatic catalog.', 'cybermaps' ) );
		}

		$catalogs = $this->identity_catalogs();
		$catalog  = $this->automatic_catalog( $parent_id );
		$result   = 'add' === $action
			? self::append_catalog( $catalogs, $catalog )
			: $this->replace_catalog( $catalogs, $catalog );
		$this->add( 'identity_catalogs', array_values( $result['catalogs'] ), $result['reason'] );
	}

	/**
	 * Return the current identity catalogs in their stored order.
	 *
	 * @return array<int,mixed>
	 */
	private function identity_catalogs(): array {
		$identity = $this->context->configuration()['cybermaps_identity_data'] ?? array();
		return is_array( $identity ) && isset( $identity['catalogs'] ) && is_array( $identity['catalogs'] )
			? $identity['catalogs']
			: array();
	}

	/**
	 * Build one normalized automatic catalog from reviewed answers.
	 *
	 * @return array<string,mixed>
	 */
	private function automatic_catalog( int $parent_id ): array {
		return array(
			'mode'      => 'auto',
			'item_type' => $this->choice( 'catalog_item_type', array( 'Service', 'Product' ), 'Service' ),
			'name'      => $this->text( 'catalog_name' ),
			'items'     => array(),
			'parent_id' => $parent_id,
		);
	}

	/**
	 * Append a catalog without exceeding the public identity bound.
	 *
	 * @param array<int,mixed>    $catalogs Existing catalogs.
	 * @param array<string,mixed> $catalog  New automatic catalog.
	 * @return array{catalogs:array<int,mixed>,reason:string}
	 */
	private static function append_catalog( array $catalogs, array $catalog ): array {
		if ( count( $catalogs ) >= IdentityEntityBuilder::MAX_CATALOGS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'The maximum number of offer catalogs is already configured. Edit an existing automatic catalog or remove one in Schema.', 'cybermaps' ) );
		}
		$catalogs[] = $catalog;
		return array(
			'catalogs' => $catalogs,
			'reason'   => __( 'Added an automatic catalog while preserving existing catalogs.', 'cybermaps' ),
		);
	}

	/**
	 * Replace only the reviewed automatic catalog index.
	 *
	 * @param array<int,mixed>    $catalogs Existing catalogs.
	 * @param array<string,mixed> $catalog  Replacement automatic catalog.
	 * @return array{catalogs:array<int,mixed>,reason:string}
	 */
	private function replace_catalog( array $catalogs, array $catalog ): array {
		$index = (int) $this->answer( 'catalog_index', -1 );
		if ( ! isset( $catalogs[ $index ] ) || ! is_array( $catalogs[ $index ] ) || 'auto' !== (string) ( $catalogs[ $index ]['mode'] ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'The selected automatic catalog is no longer available. Reload Guided Setup and choose it again.', 'cybermaps' ) );
		}
		$catalogs[ $index ] = $catalog;
		return array(
			'catalogs' => $catalogs,
			'reason'   => __( 'Updated the selected automatic catalog while preserving every other catalog.', 'cybermaps' ),
		);
	}

	private function plan_analytics(): void {
		$mode = $this->modes['analytics'];
		if ( 'keep' === $mode ) {
			return;
		}
		if ( 'reset' === $mode ) {
			$this->reset_sections[] = 'analytics';
			foreach ( SetupWizardRegistry::controlled_fields()['analytics'] as $field_id ) {
				$this->add( $field_id, $this->default_value( $field_id ), __( 'Reset crawler analytics to their Cybermaps defaults.', 'cybermaps' ) );
			}
			return;
		}
		$analytics_mode = $this->choice( 'analytics_mode', array( 'off', 'anonymized', 'full' ), 'anonymized' );
		$this->add( 'enable_analytics', 'off' !== $analytics_mode, __( 'Selected crawler analytics privacy mode.', 'cybermaps' ) );
		$this->add( 'anonymize_analytics_ips', 'anonymized' === $analytics_mode, __( 'Selected crawler analytics privacy mode.', 'cybermaps' ) );
		$this->add( 'log_retention_days', max( 1, min( 365, (int) $this->answer( 'analytics_retention', 30 ) ) ), __( 'Selected crawler analytics retention period.', 'cybermaps' ) );
	}

	private function plan_delivery(): void {
		$mode = $this->modes['delivery'];
		if ( 'keep' === $mode ) {
			return;
		}
		if ( 'reset' === $mode ) {
			$this->reset_sections[] = 'delivery';
			$this->add( 'static_engine_mode', $this->context->is_multisite() ? 'off' : $this->default_value( 'static_engine_mode' ), __( 'Reset static publication delivery to its applicable default.', 'cybermaps' ) );
			return;
		}
		$selected = $this->choice( 'delivery_mode', array( 'off', 'well_known', 'all' ), 'well_known' );
		$this->add( 'static_engine_mode', $this->context->is_multisite() ? 'off' : $selected, __( 'Selected Static File Engine delivery mode.', 'cybermaps' ) );
	}

	private function plan_reports(): void {
		$mode = $this->modes['reports'];
		if ( 'keep' === $mode ) {
			return;
		}
		if ( 'reset' === $mode ) {
			$this->reset_sections[] = 'reports';
			foreach ( SetupWizardRegistry::controlled_fields()['reports'] as $field_id ) {
				$this->add( $field_id, $this->default_value( $field_id ), __( 'Reset report policy and presentation to their Cybermaps defaults.', 'cybermaps' ) );
			}
			return;
		}
		$measurements = $this->report_measurements();
		$this->plan_report_measurements( $measurements );
		if ( $this->boolean( 'report_branding_configure' ) ) {
			$this->plan_report_branding( $this->report_branding() );
		}
	}

	/**
	 * Validate the report measurement answer object.
	 *
	 * @return array<string,mixed>
	 */
	private function report_measurements(): array {
		$measurements = $this->answer( 'report_measurements', array() );
		if ( ! is_array( $measurements ) || array_is_list( $measurements ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'Report measurement choices must be an object.', 'cybermaps' ) );
		}
		return $measurements;
	}

	/**
	 * Add bounded measurement-policy changes.
	 *
	 * @param array<string,mixed> $measurements Reviewed measurements.
	 */
	private function plan_report_measurements( array $measurements ): void {
		foreach ( array(
			'audit_post_min_words'    => array( 1, 10000, 300 ),
			'audit_post_max_age_days' => array( 0, 36500, 365 ),
			'audit_page_min_words'    => array( 1, 10000, 150 ),
			'audit_page_max_age_days' => array( 0, 36500, 0 ),
		) as $field_id => $limits ) {
			$this->add( $field_id, max( $limits[0], min( $limits[1], (int) ( $measurements[ $field_id ] ?? $limits[2] ) ) ), __( 'Configured report measurement policy.', 'cybermaps' ) );
		}
		foreach ( array( 'audit_post_require_media', 'audit_page_require_media' ) as $field_id ) {
			$this->add( $field_id, ! empty( $measurements[ $field_id ] ), __( 'Configured report measurement policy.', 'cybermaps' ) );
		}
	}

	/**
	 * Validate the report presentation answer object.
	 *
	 * @return array<string,mixed>
	 */
	private function report_branding(): array {
		$branding = $this->answer( 'report_branding', array() );
		if ( ! is_array( $branding ) || array_is_list( $branding ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'Report presentation choices must be an object.', 'cybermaps' ) );
		}
		return $branding;
	}

	/**
	 * Add the reviewed report presentation changes.
	 *
	 * @param array<string,mixed> $branding Reviewed presentation values.
	 */
	private function plan_report_branding( array $branding ): void {
		foreach ( array( 'agency_name', 'agency_url', 'agency_logo', 'site_name_override' ) as $field_id ) {
			$this->add( $field_id, isset( $branding[ $field_id ] ) && is_scalar( $branding[ $field_id ] ) ? (string) $branding[ $field_id ] : '', __( 'Configured client report presentation.', 'cybermaps' ) );
		}
		$themes = array_keys( \Cybermaps\Audit\ReportPresentation::themes() );
		$this->add( 'report_theme', isset( $branding['report_theme'] ) && is_scalar( $branding['report_theme'] ) && in_array( (string) $branding['report_theme'], $themes, true ) ? (string) $branding['report_theme'] : 'swiss', __( 'Configured client report presentation.', 'cybermaps' ) );
	}

	private function validate_dependencies(): void {
		$hub = $this->effective_boolean( 'enable_discovery_hub' );
		if ( $this->effective_boolean( 'enable_websub' ) && ! $hub ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'WebSub requires the AI Publication Hub. Enable the Hub or remove WebSub from specialized publications.', 'cybermaps' ) );
		}
		if ( $this->effective_boolean( 'enable_multilingual_hub' ) && ! $this->effective_boolean( 'enable_translation_integrations' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'Localized AI publications require translation integrations. Enable alternate-language sitemap relationships or turn off localized AI publishing.', 'cybermaps' ) );
		}
	}

	private function add( string $field_id, mixed $value, string $reason ): void {
		$field = AIConfigurationRegistry::get_field( $field_id );
		if ( ! is_array( $field ) || 'forbidden' === ( SetupWizardRegistry::field_policies()[ $field_id ] ?? 'forbidden' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal field identifiers are non-HTML exception data.
			throw new \LogicException( sprintf( 'Guided Setup attempted to write unavailable field %s.', $field_id ) );
		}
		$section_id = (string) $field['section_id'];
		if ( ! isset( $this->changes[ $section_id ] ) ) {
			$this->changes[ $section_id ] = array();
		}
		$this->changes[ $section_id ][ $field_id ] = $value;
		$this->rationales[ $field_id ]             = $reason;
	}

	private function default_value( string $field_id ): mixed {
		$field = AIConfigurationRegistry::get_field( $field_id );
		if ( ! is_array( $field ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal field identifiers are non-HTML exception data.
			throw new \LogicException( sprintf( 'Unknown Guided Setup field %s.', $field_id ) );
		}
		$value = $field['effective_default'];
		if ( $value instanceof \stdClass ) {
			return new \stdClass();
		}
		return is_array( $value ) ? $this->copy_array( $value ) : $value;
	}

	/** @param array<mixed> $value @return array<mixed> */
	private function copy_array( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( $item instanceof \stdClass ) {
				$value[ $key ] = new \stdClass();
			} elseif ( is_array( $item ) ) {
				$value[ $key ] = $this->copy_array( $item );
			}
		}
		return $value;
	}

	private function effective_boolean( string $field_id ): bool {
		$field = AIConfigurationRegistry::get_field( $field_id );
		if ( is_array( $field ) ) {
			$section = (string) $field['section_id'];
			if ( isset( $this->changes[ $section ] ) && array_key_exists( $field_id, $this->changes[ $section ] ) ) {
				return (bool) $this->changes[ $section ][ $field_id ];
			}
		}
		return $this->current_boolean( $field_id );
	}

	private function current_boolean( string $field_id ): bool {
		$value = $this->current_value( $field_id );
		return is_bool( $value ) ? $value : in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	private function current_string( string $field_id ): string {
		$value = $this->current_value( $field_id );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @return string[] */
	private function current_array( string $field_id ): array {
		$value = $this->current_value( $field_id );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	private function current_value( string $field_id ): mixed {
		$field = AIConfigurationRegistry::get_field( $field_id );
		if ( ! is_array( $field ) ) {
			return null;
		}
		$option = (string) $field['option'];
		$key    = (string) $field['field'];
		$root   = $this->context->configuration()[ $option ] ?? array();
		return is_array( $root ) && array_key_exists( $key, $root ) ? $root[ $key ] : $field['effective_default'];
	}

	private function answer( string $key, mixed $fallback = null ): mixed {
		return array_key_exists( $key, $this->answers ) ? $this->answers[ $key ] : $fallback;
	}

	private function boolean( string $key ): bool {
		$value = $this->answer( $key, false );
		return is_bool( $value ) ? $value : in_array( strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/** @param string[] $allowed */
	private function choice( string $key, array $allowed, string $fallback ): string {
		$value = $this->answer( $key, $fallback );
		$value = is_scalar( $value ) ? (string) $value : $fallback;
		if ( ! in_array( $value, $allowed, true ) ) {
			/* translators: %s: Guided Setup question identifier. */
			throw new \InvalidArgumentException( sprintf( __( 'The %s selection is invalid.', 'cybermaps' ), $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Non-HTML exception data.
		}
		return $value;
	}

	private function text( string $key ): string {
		$value = $this->answer( $key, '' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** @param string[] $allowed @return string[] */
	private function string_list( string $key, array $allowed ): array {
		$value = $this->answer( $key, array() );
		if ( ! is_array( $value ) ) {
			/* translators: %s: Guided Setup question identifier. */
			throw new \InvalidArgumentException( sprintf( __( 'The %s selection must be a list.', 'cybermaps' ), $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Non-HTML exception data.
		}
		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) || ! in_array( (string) $item, $allowed, true ) ) {
				/* translators: %s: Guided Setup question identifier. */
				throw new \InvalidArgumentException( sprintf( __( 'The %s selection contains an unsupported value.', 'cybermaps' ), $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Non-HTML exception data.
			}
			$items[] = (string) $item;
		}
		return array_values( array_unique( $items ) );
	}

	/** @return string[] */
	private function post_types( string $key ): array {
		$allowed = array_map( static fn( array $type ): string => (string) $type['name'], $this->context->public_types() );
		$types   = $this->string_list( $key, $allowed );
		if ( empty( $types ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'Select at least one public content type.', 'cybermaps' ) );
		}
		return $types;
	}

	/** @return string[] */
	private function topics(): array {
		$value = $this->answer( 'ai_topics', array() );
		if ( is_string( $value ) ) {
			$split = preg_split( '/,/', $value );
			$value = false === $split ? array() : $split;
		}
		if ( ! is_array( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'Site topics must be a comma-separated list.', 'cybermaps' ) );
		}
		$topics = array();
		foreach ( $value as $topic ) {
			if ( ! is_scalar( $topic ) ) {
				continue;
			}
			$topic = trim( (string) $topic );
			if ( '' !== $topic ) {
				$topics[] = $topic;
			}
		}
		return array_values( array_unique( $topics ) );
	}

	private function license(): string {
		$value   = $this->text( 'ai_license' );
		$field   = AIConfigurationRegistry::get_field( 'llms_content_license' );
		$allowed = is_array( $field ) && isset( $field['allowed']['enum'] ) && is_array( $field['allowed']['enum'] ) ? $field['allowed']['enum'] : array( '' );
		if ( ! in_array( $value, $allowed, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML data; escape only at the presentation boundary.
			throw new \InvalidArgumentException( __( 'The content license selection is invalid.', 'cybermaps' ) );
		}
		return $value;
	}
}
