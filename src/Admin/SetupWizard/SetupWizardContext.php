<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

use Cybermaps\Admin\DiscoveryAuditor;
use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Audit\ReportPresentation;
use Cybermaps\Core\IdentityEntityBuilder;
use Cybermaps\Core\Plugin;
use Cybermaps\Core\PublicationPostTypes;
use Cybermaps\Core\SchemaRegistry;
use Cybermaps\Core\TranslationHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded, read-only facts used by Guided Setup recommendations.
 */
final class SetupWizardContext {
	/** @var array<string,mixed> */
	private array $configuration;

	/** @var array<string,mixed> */
	private array $analysis;

	/** @var array<int,array<string,mixed>> */
	private array $public_types;

	private bool $multisite;

	private bool $translation_environment;

	/** @var string[] */
	private array $languages;

	private function __construct() {
		$this->configuration           = self::load_configuration();
		$this->analysis                = ( new DiscoveryAuditor() )->analyze();
		$this->public_types            = self::load_public_types( $this->analysis );
		$this->multisite               = function_exists( 'is_multisite' ) && is_multisite();
		$this->translation_environment = Plugin::is_translation_environment();
		$this->languages               = TranslationHelper::get_active_languages();
	}

	public static function build(): self {
		return new self();
	}

	/**
	 * Values intended for the authenticated wizard browser.
	 *
	 * @return array<string,mixed>
	 */
	public function client_data(): array {
		$analysis               = $this->analysis;
		$analysis['archetypes'] = ( new DiscoveryAuditor() )->get_all_archetypes();

		return array(
			'wizard_version' => SetupWizardRegistry::VERSION,
			'steps'          => SetupWizardRegistry::steps(),
			'questions'      => SetupWizardRegistry::questions(),
			'section_modes'  => array_fill_keys( SetupWizardRegistry::section_ids(), 'keep' ),
			'analysis'       => $analysis,
			'public_types'   => $this->public_types,
			'translation'    => array(
				'available' => $this->translation_environment,
				'languages' => $this->languages,
			),
			'multisite'      => $this->multisite,
			'identity'       => array(
				'catalogs' => $this->automatic_catalogs(),
				'types'    => SchemaRegistry::get_types(),
			),
			'report_themes'  => ReportPresentation::themes(),
			'answers'        => $this->recommended_answers(),
			'current'        => $this->current_answer_values(),
			'limits'         => array(
				'catalogs' => IdentityEntityBuilder::MAX_CATALOGS,
			),
		);
	}

	/**
	 * Stable structural context that must not drift between preview and apply.
	 */
	public function environment_hash(): string {
		$types   = array_map(
			static fn( array $type ): string => (string) $type['name'],
			$this->public_types
		);
		$payload = array(
			'wizard_version' => SetupWizardRegistry::VERSION,
			'public_types'   => $types,
			'translation'    => $this->translation_environment,
			'languages'      => $this->languages,
			'multisite'      => $this->multisite,
		);

		return 'sha256:' . hash( 'sha256', self::encode( $payload ) );
	}

	/** @return array<string,mixed> */
	public function configuration(): array {
		return $this->configuration;
	}

	/** @return array<string,mixed> */
	public function analysis(): array {
		return $this->analysis;
	}

	/** @return array<int,array<string,mixed>> */
	public function public_types(): array {
		return $this->public_types;
	}

	public function is_multisite(): bool {
		return $this->multisite;
	}

	public function has_translation_environment(): bool {
		return $this->translation_environment;
	}

	/**
	 * Verify a parent page at preview and apply time. A catalog is only useful
	 * when the source page is public and has at least one public direct child.
	 */
	public function valid_catalog_parent( int $parent_id ): bool {
		if ( ! self::can_inspect_catalog_parent( $parent_id ) ) {
			return false;
		}
		$parent = get_post( $parent_id );
		if ( ! self::is_public_page( $parent ) ) {
			return false;
		}

		return self::public_child_count( $parent_id, 1 ) > 0;
	}

	/**
	 * @return array<int,array{id:int,label:string,children:int}>
	 */
	public function search_catalog_parents( string $query, int $page = 1 ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$args    = self::catalog_search_args( $query, $page );
		$results = array();
		foreach ( (array) get_posts( $args ) as $post ) {
			if ( count( $results ) >= 20 ) {
				continue;
			}
			$item = self::catalog_search_item( $post );
			if ( null === $item ) {
				continue;
			}
			$results[] = $item;
		}

		return $results;
	}

	/** @return array<int,array<string,mixed>> */
	public function automatic_catalogs(): array {
		$items = array();
		foreach ( $this->configured_catalogs() as $index => $catalog ) {
			$item = self::automatic_catalog_item( $index, $catalog );
			if ( null === $item ) {
				continue;
			}
			$items[] = $item;
		}

		return $items;
	}

	/** @return array<string,mixed> */
	private function recommended_answers(): array {
		$archetype = $this->analysis_archetype();
		$editorial = in_array( $archetype, array( 'newspaper', 'blog' ), true );
		$news      = 'newspaper' === $archetype;
		$types     = array_map( static fn( array $type ): string => (string) $type['name'], $this->public_types );
		$settings  = $this->configuration_array( 'cybermaps_settings' );
		$identity  = $this->configuration_array( 'cybermaps_identity_data' );
		$site      = self::site_identity_defaults();

		return array(
			'strategy_profile'          => $archetype,
			'strategy_clear_custom'     => false,
			'sitemap_surfaces'          => self::recommended_sitemap_surfaces( $editorial ),
			'sitemap_media'             => 'standard',
			'sitemap_media_features'    => array( 'video', 'multimodal' ),
			'sitemap_specials'          => self::recommended_sitemap_specials( $news, $editorial ),
			'sitemap_rss_types'         => self::recommended_rss_types( $types ),
			'sitemap_integration'       => array( 'redirect_core', 'robots', 'cache' ),
			'sitemap_translations'      => $this->translation_environment,
			'ai_hub'                    => true,
			'ai_types'                  => $types,
			'ai_separate_sitemap_types' => false,
			'ai_sitemap_types'          => $types,
			'ai_features'               => $this->recommended_ai_features(),
			'ai_mission'                => self::string_value( $settings['llms_mission_statement'] ?? $site['tagline'] ),
			'ai_business_description'   => self::string_value( $settings['ai_business_description'] ?? $site['tagline'] ),
			'ai_topics'                 => self::joined_values( $settings['ai_topics'] ?? array() ),
			'ai_capabilities'           => array( 'search_content', 'read_articles' ),
			'ai_usage_rag'              => 'allow',
			'ai_usage_training'         => 'forbid',
			'ai_usage_commercial'       => 'forbid',
			'ai_license'                => '',
			'ai_licensing_email'        => '',
			'ai_sync_signals'           => false,
			'ai_signal_search'          => 'yes',
			'identity_type'             => self::string_value( $identity['type'] ?? 'Organization' ),
			'identity_precise_type'     => self::string_value( $identity['precise_type'] ?? '' ),
			'identity_name'             => self::string_value( $identity['name'] ?? $site['name'] ),
			'identity_description'      => self::string_value( $identity['description'] ?? $site['tagline'] ),
			'identity_image_id'         => absint( $identity['image_id'] ?? 0 ),
			'identity_kg_link'          => false,
			'catalog_action'            => 'none',
			'catalog_index'             => 0,
			'catalog_parent_id'         => 0,
			'catalog_item_type'         => 'Service',
			'catalog_name'              => '',
			'analytics_mode'            => 'anonymized',
			'analytics_retention'       => 30,
			'delivery_mode'             => $this->recommended_delivery_mode(),
			'report_measurements'       => self::report_defaults(),
			'report_branding_configure' => false,
			'report_branding'           => array(
				'agency_name'        => '',
				'agency_url'         => '',
				'agency_logo'        => '',
				'site_name_override' => '',
				'report_theme'       => 'swiss',
			),
		);
	}

	/** @return array<string,mixed> */
	private function current_answer_values(): array {
		$settings = $this->configuration_array( 'cybermaps_settings' );
		$identity = $this->configuration_array( 'cybermaps_identity_data' );
		$robots   = $this->configuration_array( 'cybermaps_robots_manager' );
		$signals  = self::content_signals( $robots );

		return array(
			'analytics_mode'       => self::current_analytics_mode( $settings ),
			'analytics_retention'  => max( 1, min( 365, (int) ( $settings['log_retention_days'] ?? 30 ) ) ),
			'delivery_mode'        => $this->current_delivery_mode( $settings ),
			'ai_usage_rag'         => self::string_value( $settings['ai_usage_rag'] ?? 'allow' ),
			'ai_usage_training'    => self::string_value( $settings['ai_usage_training'] ?? 'forbid' ),
			'ai_usage_commercial'  => self::string_value( $settings['ai_usage_commercial'] ?? 'forbid' ),
			'ai_license'           => self::string_value( $settings['llms_content_license'] ?? '' ),
			'ai_licensing_email'   => self::string_value( $settings['ai_licensing_email'] ?? '' ),
			'ai_sync_signals'      => ! empty( $signals ),
			'ai_signal_search'     => self::string_value( $signals['search'] ?? 'yes' ),
			'identity_kg_link'     => ! empty( $settings['ai_kg_link_org'] ),
			'report_measurements'  => self::current_report_measurements( $settings ),
			'report_branding'      => self::current_report_branding( $settings ),
			'identity_name'        => self::string_value( $identity['name'] ?? '' ),
			'identity_description' => self::string_value( $identity['description'] ?? '' ),
		);
	}

	private static function can_inspect_catalog_parent( int $parent_id ): bool {
		return $parent_id > 0
			&& function_exists( 'get_post' )
			&& function_exists( 'get_pages' );
	}

	private static function public_child_count( int $parent_id, int $limit ): int {
		$children = get_pages(
			array(
				'parent'      => $parent_id,
				'post_status' => 'publish',
				'post_type'   => 'page',
				'number'      => $limit,
			)
		);
		$count    = 0;
		foreach ( is_array( $children ) ? $children : array() as $child ) {
			if ( self::is_public_page( $child ) ) {
				++$count;
			}
		}

		return $count;
	}

	/** @return array<string,mixed> */
	private static function catalog_search_args( string $query, int $page ): array {
		$page  = max( 1, min( 50, $page ) );
		$query = substr( trim( $query ), 0, 100 );
		$args  = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'offset'         => ( $page - 1 ) * 60,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		);
		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		return $args;
	}

	/** @return array{id:int,label:string,children:int}|null */
	private static function catalog_search_item( mixed $post ): ?array {
		if ( ! self::is_public_page( $post ) ) {
			return null;
		}
		if ( ! function_exists( 'get_pages' ) ) {
			return null;
		}
		$id = isset( $post->ID ) ? absint( $post->ID ) : 0;
		if ( $id < 1 ) {
			return null;
		}
		$count = self::public_child_count( $id, IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG );
		if ( $count < 1 ) {
			return null;
		}

		return array(
			'id'       => $id,
			'label'    => self::post_title( $post ),
			'children' => $count,
		);
	}

	/** @return array<int|string,mixed> */
	private function configured_catalogs(): array {
		$identity = $this->configuration_array( 'cybermaps_identity_data' );
		$catalogs = $identity['catalogs'] ?? array();
		return is_array( $catalogs ) ? $catalogs : array();
	}

	/** @return array<string,mixed>|null */
	private static function automatic_catalog_item( int|string $index, mixed $catalog ): ?array {
		if ( ! is_array( $catalog ) ) {
			return null;
		}
		if ( 'auto' !== (string) ( $catalog['mode'] ?? '' ) ) {
			return null;
		}

		return array(
			'index'     => (int) $index,
			'name'      => is_scalar( $catalog['name'] ?? null ) ? (string) $catalog['name'] : '',
			'item_type' => is_scalar( $catalog['item_type'] ?? null ) ? (string) $catalog['item_type'] : 'Service',
			'parent_id' => absint( $catalog['parent_id'] ?? 0 ),
		);
	}

	private function analysis_archetype(): string {
		return isset( $this->analysis['archetype'] ) ? (string) $this->analysis['archetype'] : 'medium-business';
	}

	/** @return array<string,mixed> */
	private function configuration_array( string $key ): array {
		$value = $this->configuration[ $key ] ?? array();
		return is_array( $value ) ? $value : array();
	}

	/** @return array{name:string,tagline:string} */
	private static function site_identity_defaults(): array {
		if ( ! function_exists( 'get_bloginfo' ) ) {
			return array(
				'name'    => '',
				'tagline' => '',
			);
		}

		return array(
			'name'    => (string) get_bloginfo( 'name' ),
			'tagline' => (string) get_bloginfo( 'description' ),
		);
	}

	/** @return string[] */
	private static function recommended_sitemap_surfaces( bool $editorial ): array {
		$surfaces = array( 'homepage' );
		if ( $editorial ) {
			$surfaces[] = 'authors';
			$surfaces[] = 'archives';
		}

		return $surfaces;
	}

	/** @return string[] */
	private static function recommended_sitemap_specials( bool $news, bool $editorial ): array {
		$specials = array();
		if ( $news ) {
			$specials[] = 'news';
		}
		if ( $editorial ) {
			$specials[] = 'rss';
		}
		$specials[] = 'html';

		return $specials;
	}

	/**
	 * @param string[] $types Public post-type names.
	 * @return string[]
	 */
	private static function recommended_rss_types( array $types ): array {
		return in_array( 'post', $types, true ) ? array( 'post' ) : array_slice( $types, 0, 1 );
	}

	/** @return string[] */
	private function recommended_ai_features(): array {
		$features = array( 'headers', 'hints', 'sitemap_link' );
		if ( $this->translation_environment ) {
			$features[] = 'localized';
		}

		return $features;
	}

	private function recommended_delivery_mode(): string {
		return $this->multisite ? 'off' : 'well_known';
	}

	/**
	 * @param array<string,mixed> $robots Robots manager configuration.
	 * @return array<string,mixed>
	 */
	private static function content_signals( array $robots ): array {
		$signals = $robots['content_signals'] ?? array();
		return is_array( $signals ) ? $signals : array();
	}

	/** @param array<string,mixed> $settings General settings. */
	private static function current_analytics_mode( array $settings ): string {
		if ( empty( $settings['enable_analytics'] ) ) {
			return 'off';
		}

		return empty( $settings['anonymize_analytics_ips'] ) ? 'full' : 'anonymized';
	}

	/** @param array<string,mixed> $settings General settings. */
	private function current_delivery_mode( array $settings ): string {
		return $this->multisite ? 'off' : (string) ( $settings['static_engine_mode'] ?? 'well_known' );
	}

	/**
	 * @param array<string,mixed> $settings General settings.
	 * @return array<string,int|bool>
	 */
	private static function current_report_measurements( array $settings ): array {
		return array(
			'audit_post_min_words'     => (int) ( $settings['audit_post_min_words'] ?? 300 ),
			'audit_post_max_age_days'  => (int) ( $settings['audit_post_max_age_days'] ?? 365 ),
			'audit_post_require_media' => ! empty( $settings['audit_post_require_media'] ),
			'audit_page_min_words'     => (int) ( $settings['audit_page_min_words'] ?? 150 ),
			'audit_page_max_age_days'  => (int) ( $settings['audit_page_max_age_days'] ?? 0 ),
			'audit_page_require_media' => ! empty( $settings['audit_page_require_media'] ),
		);
	}

	/**
	 * @param array<string,mixed> $settings General settings.
	 * @return array<string,string>
	 */
	private static function current_report_branding( array $settings ): array {
		return array(
			'agency_name'        => self::string_value( $settings['agency_name'] ?? '' ),
			'agency_url'         => self::string_value( $settings['agency_url'] ?? '' ),
			'agency_logo'        => self::string_value( $settings['agency_logo'] ?? '' ),
			'site_name_override' => self::string_value( $settings['site_name_override'] ?? '' ),
			'report_theme'       => self::string_value( $settings['report_theme'] ?? 'swiss' ),
		);
	}

	/** @return array<string,mixed> */
	private static function array_option( string $name ): array {
		$value = get_option( $name, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @return array<string,mixed> */
	private static function discovery_strategy_option(): array {
		$strategy = get_option( 'cybermaps_discovery_center', array() );
		if ( is_string( $strategy ) ) {
			$decoded = json_decode( $strategy, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $strategy ) ? $strategy : array();
	}

	/**
	 * @param array<string,mixed> $analysis Discovery audit analysis.
	 * @return array<string,mixed>
	 */
	private static function public_type_counts( array $analysis ): array {
		$stats = $analysis['stats'] ?? array();
		if ( ! is_array( $stats ) ) {
			return array();
		}
		$counts = $stats['post_types'] ?? array();
		return is_array( $counts ) ? $counts : array();
	}

	/**
	 * @param mixed               $type   Registered post-type object.
	 * @param array<string,mixed> $counts Published counts keyed by post type.
	 * @return array<string,mixed>|null
	 */
	private static function public_type_item( mixed $type, array $counts ): ?array {
		if ( ! is_object( $type ) || empty( $type->name ) ) {
			return null;
		}
		$name = (string) $type->name;

		return array(
			'name'  => $name,
			'label' => isset( $type->label ) ? (string) $type->label : $name,
			'count' => max( 0, (int) ( $counts[ $name ] ?? 0 ) ),
		);
	}

	/** @return array<string,mixed> */
	private static function load_configuration(): array {
		return array(
			'cybermaps_settings'         => self::array_option( 'cybermaps_settings' ),
			'cybermaps_identity_data'    => self::array_option( 'cybermaps_identity_data' ),
			'cybermaps_robots_manager'   => self::array_option( 'cybermaps_robots_manager' ),
			'cybermaps_discovery_center' => self::discovery_strategy_option(),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function load_public_types( array $analysis ): array {
		$counts = self::public_type_counts( $analysis );
		$types  = array();
		foreach ( PublicationPostTypes::objects() as $type ) {
			$item = self::public_type_item( $type, $counts );
			if ( null === $item ) {
				continue;
			}
			$types[] = $item;
		}

		return $types;
	}

	private static function is_public_page( mixed $post ): bool {
		return is_object( $post )
			&& isset( $post->ID, $post->post_type, $post->post_status )
			&& 'page' === (string) $post->post_type
			&& 'publish' === (string) $post->post_status;
	}

	private static function post_title( object $post ): string {
		$title = isset( $post->post_title ) && is_scalar( $post->post_title ) ? trim( (string) $post->post_title ) : '';
		return '' !== $title ? $title : sprintf(
			/* translators: %d: WordPress page ID. */
			__( 'Page #%d', 'cybermaps' ),
			absint( $post->ID ?? 0 )
		);
	}

	/** @return array<string,mixed> */
	private static function report_defaults(): array {
		$defaults = array();
		foreach ( array(
			'audit_post_min_words',
			'audit_post_max_age_days',
			'audit_post_require_media',
			'audit_page_min_words',
			'audit_page_max_age_days',
			'audit_page_require_media',
		) as $field_id ) {
			$field = AIConfigurationRegistry::get_field( $field_id );
			if ( is_array( $field ) ) {
				$defaults[ $field_id ] = $field['effective_default'];
			}
		}

		return $defaults;
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function joined_values( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return '';
		}
		return implode( ', ', array_filter( array_map( static fn( mixed $item ): string => is_scalar( $item ) ? trim( (string) $item ) : '', $value ) ) );
	}

	/** @param array<string,mixed> $value */
	private static function encode( array $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}
}
