<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

use Cybermaps\Admin\DiscoveryAuditor;
use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\Plugin;
use Cybermaps\Core\PublicationPostTypes;
use Cybermaps\Core\TranslationHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded, read-only facts used by Quick Setup recommendations. */
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

	/** @return array<string,mixed> */
	public function client_data(): array {
		$analysis               = $this->analysis;
		$analysis['archetypes'] = ( new DiscoveryAuditor() )->get_all_archetypes();

		return array(
			'wizard_version' => SetupWizardRegistry::VERSION,
			'steps'          => SetupWizardRegistry::steps(),
			'choices'        => SetupWizardRegistry::choices(),
			'analysis'       => $analysis,
			'multisite'      => $this->multisite,
			'answers'        => $this->recommended_answers(),
		);
	}

	/** Stable structural context that must not drift between prepare and apply. */
	public function environment_hash(): string {
		$types = array_map(
			static fn( array $type ): string => (string) $type['name'],
			$this->public_types
		);
		return 'sha256:' . hash(
			'sha256',
			self::encode(
				array(
					'wizard_version' => SetupWizardRegistry::VERSION,
					'public_types'   => $types,
					'translation'    => $this->translation_environment,
					'languages'      => $this->languages,
					'multisite'      => $this->multisite,
				)
			)
		);
	}

	/** @return array<string,mixed> */
	public function configuration(): array {
		return $this->configuration;
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

	/** @return array<string,mixed> */
	private function recommended_answers(): array {
		$identity  = $this->configuration['cybermaps_identity_data'];
		$site      = self::site_identity_defaults();
		$type      = self::string_value( $identity['type'] ?? 'Organization' );
		$archetype = isset( $this->analysis['archetype'] ) ? (string) $this->analysis['archetype'] : 'medium-business';
		if ( ! isset( SetupWizardRegistry::choices()['website_type'][ $archetype ] ) ) {
			$archetype = 'medium-business';
		}
		if ( ! in_array( $type, array( 'Organization', 'LocalBusiness', 'Person' ), true ) ) {
			$type = 'Organization';
		}

		return array(
			'website_type'         => $archetype,
			'ai_visibility'        => 'on',
			'operations'           => 'insights',
			'identity_type'        => $type,
			'identity_name'        => self::string_value( $identity['name'] ?? $site['name'] ),
			'identity_description' => self::string_value( $identity['description'] ?? $site['tagline'] ),
			'identity_image_id'    => absint( $identity['image_id'] ?? 0 ),
		);
	}

	/** @return array{name:string,tagline:string} */
	private static function site_identity_defaults(): array {
		return array(
			'name'    => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
			'tagline' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'description' ) : '',
		);
	}

	/** @return array<string,mixed> */
	private static function load_configuration(): array {
		return array(
			'cybermaps_settings'         => ConfigurationStore::settings(),
			'cybermaps_identity_data'    => ConfigurationStore::identity(),
			'cybermaps_robots_manager'   => ConfigurationStore::robots(),
			'cybermaps_discovery_center' => ConfigurationStore::discovery(),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function load_public_types( array $analysis ): array {
		$stats  = isset( $analysis['stats'] ) && is_array( $analysis['stats'] ) ? $analysis['stats'] : array();
		$counts = isset( $stats['post_types'] ) && is_array( $stats['post_types'] ) ? $stats['post_types'] : array();
		$types  = array();
		foreach ( PublicationPostTypes::objects() as $type ) {
			$name    = isset( $type->name ) ? (string) $type->name : '';
			$types[] = array(
				'name'  => $name,
				'label' => isset( $type->label ) ? (string) $type->label : $name,
				'count' => max( 0, (int) ( $counts[ $name ] ?? 0 ) ),
			);
		}
		return $types;
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @param array<string,mixed> $value */
	private static function encode( array $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}
}
