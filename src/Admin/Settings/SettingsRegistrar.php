<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings;

use Cybermaps\Admin\ConfigurationMutationStore;
use Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer;
use Cybermaps\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsRegistrar {
	/**
	 * Narrow Settings API group used by the standalone analytics form.
	 *
	 * The main settings workspaces share site, identity, strategy, and robots
	 * options. Reusing that broad group on a page that submits only
	 * cybermaps_settings would cause options.php to pass absent options through
	 * their preservation sanitizers unnecessarily.
	 */
	public const ANALYTICS_OPTIONS_GROUP = 'cybermaps_analytics_options_group';
	private const CONFIGURATION_ROOTS    = array(
		'cybermaps_settings',
		'cybermaps_discovery_center',
		'cybermaps_robots_manager',
		'cybermaps_identity_data',
	);

	public static function register_all( SettingsPage $page ): void {
		wp_set_option_autoload_values( array_fill_keys( self::CONFIGURATION_ROOTS, false ) );

		register_setting(
			'cybermaps_options_group',
			'cybermaps_settings',
			array( SettingsSanitizer::class, 'sanitize' )
		);
		add_filter( 'allowed_options', array( self::class, 'allow_analytics_option' ) );
		register_setting(
			'cybermaps_options_group',
			'cybermaps_discovery_center',
			array( DiscoveryCenterSanitizer::class, 'sanitize' )
		);
		register_setting(
			'cybermaps_options_group',
			'cybermaps_robots_manager',
			array( RobotsManagerSanitizer::class, 'sanitize' )
		);

		self::maybe_enable_translation_integrations();

		foreach ( $page->get_tabs() as $tab ) {
			$tab->register_settings();
		}
	}

	/** Initialize only a missing choice, without overwriting a concurrent save. */
	private static function maybe_enable_translation_integrations(): void {
		if ( ! \Cybermaps\Core\Plugin::is_translation_environment() ) {
			return;
		}
		try {
			$before = ConfigurationMutationStore::read( 'cybermaps_settings' );
		} catch ( \RuntimeException ) {
			return;
		}
		$options = $before['exists'] ? $before['value'] : array();
		if ( ! is_array( $options ) || ( array() !== $options && array_is_list( $options ) ) ) {
			return;
		}
		if ( array_key_exists( 'enable_translation_integrations', $options ) ) {
			return;
		}

		$options['enable_translation_integrations'] = '1';
		$after                                      = ConfigurationMutationStore::target( $options );
		if ( ConfigurationMutationStore::write( 'cybermaps_settings', $before, $after ) ) {
			ConfigurationMutationStore::notify( 'cybermaps_settings', $before, $after );
		}
	}

	/**
	 * Permit the narrow analytics form to submit the canonical settings option.
	 *
	 * WordPress stores registered-setting metadata globally by option name, not
	 * by option group. Extending the allowed-options map keeps one canonical
	 * registration and one sanitize filter while authorizing this second form.
	 *
	 * @param array<string, string[]> $allowed_options Settings API groups.
	 * @return array<string, string[]>
	 */
	public static function allow_analytics_option( array $allowed_options ): array {
		if ( ! isset( $allowed_options[ self::ANALYTICS_OPTIONS_GROUP ] ) ) {
			$allowed_options[ self::ANALYTICS_OPTIONS_GROUP ] = array();
		}
		if (
			! in_array(
				'cybermaps_settings',
				$allowed_options[ self::ANALYTICS_OPTIONS_GROUP ],
				true
			)
		) {
			$allowed_options[ self::ANALYTICS_OPTIONS_GROUP ][] = 'cybermaps_settings';
		}

		return $allowed_options;
	}
}
