<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Core\SchemaRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Converts Quick Setup answers into a bounded configuration changes envelope. */
final class SetupWizardPlanFactory {
	/** @var array<string,mixed> */
	private array $answers;

	private SetupWizardContext $context;

	/** @var array<string,array<string,mixed>> */
	private array $changes = array();

	/** @var array<string,string> */
	private array $rationales = array();

	/** @param array<string,mixed> $request */
	public static function build( array $request, SetupWizardContext $context ): array {
		$factory = new self( $request, $context );
		return $factory->make();
	}

	/** @param array<string,mixed> $request */
	private function __construct( array $request, SetupWizardContext $context ) {
		$version = isset( $request['wizard_version'] ) ? (int) $request['wizard_version'] : 0;
		if ( SetupWizardRegistry::VERSION !== $version ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML JSON data.
			throw new \InvalidArgumentException( __( 'This Quick Setup session is out of date. Reload it before continuing.', 'cybermaps' ) );
		}

		$answers = $request['answers'] ?? array();
		if ( ! is_array( $answers ) || array_is_list( $answers ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML JSON data.
			throw new \InvalidArgumentException( __( 'Quick Setup answers must be an object.', 'cybermaps' ) );
		}

		$this->answers = $answers;
		$this->context = $context;
	}

	/** @return array<string,mixed> */
	private function make(): array {
		$website_type = $this->choice( 'website_type', array_keys( SetupWizardRegistry::choices()['website_type'] ) );
		$ai_enabled   = 'on' === $this->choice( 'ai_visibility', array( 'on', 'off' ) );
		$operations   = $this->choice( 'operations', array( 'insights', 'performance' ) );
		$identity     = $this->identity_answers();

		$this->plan_strategy( $website_type );
		$this->plan_sitemaps( $website_type );
		$this->plan_ai( $ai_enabled, $identity['description'] );
		$this->plan_identity( $identity, $ai_enabled );
		$this->plan_operations( $operations );

		$document = array(
			'format'         => AIConfigurationRegistry::CHANGES_FORMAT,
			'format_version' => AIConfigurationRegistry::FORMAT_VERSION,
			'plugin_version' => defined( 'CYBERMAPS_VERSION' ) ? (string) CYBERMAPS_VERSION : '',
			'changes'        => $this->changes,
		);
		$content  = wp_json_encode( $document, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $content ) || '' === $content ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML JSON data.
			throw new \RuntimeException( __( 'Cybermaps could not prepare the Quick Setup preset.', 'cybermaps' ) );
		}

		return array(
			'document'         => $document,
			'content'          => $content,
			'rationales'       => $this->rationales,
			'reset_sections'   => array(),
			'environment_hash' => $this->context->environment_hash(),
		);
	}

	private function plan_strategy( string $website_type ): void {
		$this->add( 'discovery_archetype', $website_type, __( 'Matches the kind of website you selected.', 'cybermaps' ) );
	}

	private function plan_sitemaps( string $website_type ): void {
		$editorial = in_array( $website_type, array( 'blog', 'newspaper' ), true );
		$news      = 'newspaper' === $website_type;

		$this->add( 'include_homepage', true, __( 'Makes the homepage part of the sitemap.', 'cybermaps' ) );
		$this->add( 'include_authors', $editorial, __( 'Matches the publishing style you selected.', 'cybermaps' ) );
		$this->add( 'include_archives', $editorial, __( 'Matches the publishing style you selected.', 'cybermaps' ) );
		$this->add( 'include_empty_terms', false, __( 'Keeps empty archive pages out of the sitemap.', 'cybermaps' ) );
		$this->add( 'inject_robots', true, __( 'Helps crawlers find your public maps.', 'cybermaps' ) );
		$this->add( 'enable_caching', true, __( 'Keeps dynamic sitemap delivery responsive.', 'cybermaps' ) );
		$this->add( 'redirect_wp_sitemap', true, __( 'Uses Cybermaps as the main sitemap.', 'cybermaps' ) );
		$this->add( 'enable_shortcode', true, __( 'Makes the visual sitemap available when you need it.', 'cybermaps' ) );
		$this->add( 'media_discovery_intensity', 'standard', __( 'Uses a balanced level of media discovery.', 'cybermaps' ) );
		$this->add( 'enable_google_news', $news, __( 'Matches the publishing style you selected.', 'cybermaps' ) );
		$this->add( 'enable_rss_sitemap', $editorial, __( 'Matches the publishing style you selected.', 'cybermaps' ) );
		if ( $editorial ) {
			$this->add( 'rss_sitemap_types', $this->rss_types(), __( 'Publishes recent articles in the RSS sitemap.', 'cybermaps' ) );
		}
		if ( $this->context->has_translation_environment() ) {
			$this->add( 'enable_translation_integrations', true, __( 'Includes the languages already active on this site.', 'cybermaps' ) );
		}
	}

	private function plan_ai( bool $enabled, string $description ): void {
		$this->add(
			'enable_discovery_hub',
			$enabled,
			$enabled
			? __( 'Publishes a machine-readable guide to your content.', 'cybermaps' )
			: __( 'Keeps AI discovery switched off for now.', 'cybermaps' )
		);
		if ( ! $enabled ) {
			if ( (bool) $this->current_value( 'enable_websub' ) ) {
				$this->add( 'enable_websub', false, __( 'Turns off the notification feature that depends on AI discovery.', 'cybermaps' ) );
			}
			return;
		}

		$this->add( 'enable_header_discovery', true, __( 'Points compatible tools to your discovery publications.', 'cybermaps' ) );
		$this->add( 'enable_content_hints', true, __( 'Adds useful context to AI sitemap entries.', 'cybermaps' ) );
		$this->add( 'llms_include_sitemap_link', true, __( 'Connects the AI guide to your sitemap.', 'cybermaps' ) );
		if ( $this->context->has_translation_environment() ) {
			$this->add( 'enable_multilingual_hub', true, __( 'Publishes discovery guides for active site languages.', 'cybermaps' ) );
		}

		$types = $this->current_array( 'llms_included_types' );
		if ( empty( $types ) ) {
			$types = $this->public_types();
			$this->add( 'llms_included_types', $types, __( 'Uses the public content types available on this site.', 'cybermaps' ) );
		}
		if ( empty( $this->current_array( 'ai_sitemap_types' ) ) ) {
			$this->add( 'ai_sitemap_types', $types, __( 'Uses the same public content in the AI sitemap.', 'cybermaps' ) );
		}

		$manifest = array_values(
			array_unique(
				array_merge(
					$this->current_array( 'ai_manifest_endpoints' ),
					array( 'llms.txt', 'feed.json', 'knowledge-graph.json', 'ai-sitemap.xml', 'ai-usage.json' )
				)
			)
		);
		$this->add( 'ai_manifest_endpoints', $manifest, __( 'Lists the basic discovery publications while preserving existing choices.', 'cybermaps' ) );

		if ( '' !== $description && '' === trim( $this->current_string( 'llms_mission_statement' ) ) ) {
			$this->add( 'llms_mission_statement', $description, __( 'Uses your public introduction as the initial site summary.', 'cybermaps' ) );
		}
		if ( '' !== $description && '' === trim( $this->current_string( 'ai_business_description' ) ) ) {
			$this->add( 'ai_business_description', $description, __( 'Uses your public introduction as the initial discovery summary.', 'cybermaps' ) );
		}
	}

	/** @param array{type:string,name:string,description:string,image_id:int} $identity */
	private function plan_identity( array $identity, bool $ai_enabled ): void {
		$this->add( 'identity_type', $identity['type'], __( 'Matches who this website represents.', 'cybermaps' ) );
		$this->add( 'identity_precise_type', $this->compatible_precise_type( $identity['type'] ), __( 'Keeps a compatible detailed identity type when one is already set.', 'cybermaps' ) );
		$this->add( 'identity_name', $identity['name'], __( 'Sets the public name for this website.', 'cybermaps' ) );
		$this->add( 'identity_description', $identity['description'], __( 'Sets the public introduction for this website.', 'cybermaps' ) );
		$this->add( 'identity_image_id', $identity['image_id'], __( 'Sets the public logo or profile image.', 'cybermaps' ) );
		if ( $ai_enabled && 'Person' !== $identity['type'] ) {
			$this->add( 'ai_kg_link_org', true, __( 'Connects the public identity to AI discovery output.', 'cybermaps' ) );
		}
	}

	private function plan_operations( string $operations ): void {
		$insights = 'insights' === $operations;
		$this->add(
			'enable_analytics',
			$insights,
			$insights
			? __( 'Shows crawler activity observed by WordPress.', 'cybermaps' )
			: __( 'Skips crawler activity logging.', 'cybermaps' )
		);
		if ( $insights ) {
			$this->add( 'anonymize_analytics_ips', true, __( 'Masks IP addresses before activity is stored.', 'cybermaps' ) );
			$this->add( 'log_retention_days', 30, __( 'Keeps activity for 30 days.', 'cybermaps' ) );
		}
		$this->add(
			'static_engine_mode',
			$this->context->is_multisite() ? 'off' : ( $insights ? 'off' : 'all' ),
			$insights
			? __( 'Lets WordPress observe eligible discovery requests.', 'cybermaps' )
			: __( 'Favors static delivery for lower runtime work.', 'cybermaps' )
		);
	}

	/** @return array{type:string,name:string,description:string,image_id:int} */
	private function identity_answers(): array {
		$type        = $this->choice( 'identity_type', array_keys( SetupWizardRegistry::choices()['identity_type'] ) );
		$name        = $this->text( 'identity_name' );
		$description = $this->text( 'identity_description' );
		$image_id    = max( 0, (int) $this->answer( 'identity_image_id', 0 ) );
		if ( '' === $name ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are non-HTML JSON data.
			throw new \InvalidArgumentException( __( 'Add the public name for this website.', 'cybermaps' ) );
		}
		return compact( 'type', 'name', 'description', 'image_id' );
	}

	private function compatible_precise_type( string $type ): string {
		$precise = $this->current_string( 'identity_precise_type' );
		if ( '' === $precise ) {
			return '';
		}
		$candidate = array(
			'type'         => $type,
			'precise_type' => $precise,
		);
		return SchemaRegistry::get_entity_type( $candidate ) === $precise ? $precise : '';
	}

	/** @return string[] */
	private function rss_types(): array {
		$types = $this->public_types();
		return in_array( 'post', $types, true ) ? array( 'post' ) : array_slice( $types, 0, 1 );
	}

	/** @return string[] */
	private function public_types(): array {
		return array_values(
			array_filter(
				array_map(
					static fn( array $type ): string => (string) ( $type['name'] ?? '' ),
					$this->context->public_types()
				)
			)
		);
	}

	private function add( string $field_id, mixed $value, string $reason ): void {
		$field  = AIConfigurationRegistry::get_field( $field_id );
		$policy = SetupWizardRegistry::field_policies()[ $field_id ] ?? 'forbidden';
		if ( ! is_array( $field ) || ! in_array( $policy, array( 'explicit', 'derived' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal field identifier in non-HTML exception data.
			throw new \LogicException( sprintf( 'Quick Setup attempted to write unavailable field %s.', $field_id ) );
		}
		$section_id = (string) $field['section_id'];
		if ( ! isset( $this->changes[ $section_id ] ) ) {
			$this->changes[ $section_id ] = array();
		}
		$this->changes[ $section_id ][ $field_id ] = $value;
		$this->rationales[ $field_id ]             = $reason;
	}

	private function current_value( string $field_id ): mixed {
		$field = AIConfigurationRegistry::get_field( $field_id );
		if ( ! is_array( $field ) ) {
			return null;
		}
		$root = $this->context->configuration()[ (string) $field['option'] ] ?? array();
		return is_array( $root ) && array_key_exists( (string) $field['field'], $root )
			? $root[ (string) $field['field'] ]
			: $field['effective_default'];
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

	private function answer( string $key, mixed $fallback = null ): mixed {
		return array_key_exists( $key, $this->answers ) ? $this->answers[ $key ] : $fallback;
	}

	/** @param string[] $allowed */
	private function choice( string $key, array $allowed ): string {
		$value = $this->answer( $key );
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( ! in_array( $value, $allowed, true ) ) {
			/* translators: %s: Quick Setup question identifier. */
			throw new \InvalidArgumentException( sprintf( __( 'Choose an answer for %s.', 'cybermaps' ), $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Question identifier in non-HTML exception data.
		}
		return $value;
	}

	private function text( string $key ): string {
		$value = $this->answer( $key, '' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
