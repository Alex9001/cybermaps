<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\MigrationHub;
use Cybermaps\Admin\Settings\SettingsAjax;
use PHPUnit\Framework\TestCase;

final class ConfigurationExchangeContractTest extends TestCase {
	public function test_export_and_import_handlers_require_capability_and_action_specific_nonces(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/SettingsAjax.php'
		);

		$export_start  = strpos( $source, 'public static function handle_export_config' );
		$preview_start = strpos( $source, 'public static function ajax_preview_config' );
		$import_start  = strpos( $source, 'public static function ajax_import_config' );
		$this->assertNotFalse( $export_start );
		$this->assertNotFalse( $preview_start );
		$this->assertNotFalse( $import_start );

		$export  = substr( $source, (int) $export_start, (int) $preview_start - (int) $export_start );
		$preview = substr( $source, (int) $preview_start, (int) $import_start - (int) $preview_start );
		$import = substr( $source, (int) $import_start );

		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $export );
		$this->assertStringContainsString( "check_admin_referer( 'cybermaps_export_config' )", $export );
		$this->assertStringContainsString( 'self::require_post_request();', $preview );
		$this->assertStringContainsString( "check_ajax_referer( 'cybermaps_exchange_action', 'nonce' )", $preview );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $preview );
		$this->assertStringContainsString( 'MigrationHub::get_instance()->preview(', $preview );
		$this->assertStringContainsString( '), 403 );', $preview );
		$this->assertStringContainsString( 'self::require_post_request();', $import );
		$this->assertStringContainsString( "check_ajax_referer( 'cybermaps_exchange_action', 'nonce' )", $import );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $import );
		$this->assertStringContainsString( '), 403 );', $import );
	}

	public function test_every_import_requires_a_matching_server_preview_and_high_impact_acknowledgement(): void {
		$root    = dirname( __DIR__, 2 );
		$handler = (string) file_get_contents( $root . '/src/Admin/Settings/SettingsAjax.php' );
		$script  = (string) file_get_contents( $root . '/assets/js/admin-command-center.js' );

		$this->assertStringContainsString( 'import_previewed(', $handler );
		$this->assertStringContainsString( "\$_POST['content_hash']", $handler );
		$this->assertStringContainsString( "\$_POST['configuration_hash']", $handler );
		$this->assertStringContainsString( "\$_POST['acknowledge_high_impact']", $handler );
		$this->assertStringContainsString( 'preview_has_high_impact_changes', $handler );

		$this->assertStringContainsString( "action: 'cybermaps_preview_config'", $script );
		$this->assertStringContainsString( "action: 'cybermaps_import_config'", $script );
		$this->assertStringContainsString( 'content_hash: preview.content_hash', $script );
		$this->assertStringContainsString( 'configuration_hash: preview.configuration_hash', $script );
		$this->assertStringContainsString( 'previewErrors.length === 0', $script );
		$this->assertStringContainsString( 'I reviewed and acknowledge these high-impact changes.', $script );
		$this->assertStringContainsString( 'expand to review the complete value', $script );
		$this->assertStringNotContainsString( 'rendered.slice( 0, 497 )', $script );
	}

	public function test_ui_accepts_only_current_backup_and_brief_formats(): void {
		$script = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/assets/js/admin-command-center.js'
		);

		$this->assertStringContainsString( 'cybermaps-ai-configuration-changes', $script );
		$this->assertStringContainsString( 'CYBERMAPS-AI-', $script );
		$this->assertStringContainsString( 'Earlier Markdown templates are no longer supported.', $script );
		$this->assertStringNotContainsString( 'availableSections', $script );
		$this->assertStringNotContainsString( 'selectedSections', $script );
		$this->assertStringNotContainsString( 'sections:', $script );
		$this->assertStringContainsString( 'Preview Changes', $script );
		$this->assertStringContainsString( 'Apply Reviewed Changes', $script );
	}

	public function test_unchanged_high_risk_fields_do_not_require_a_false_acknowledgement(): void {
		$method = new \ReflectionMethod( SettingsAjax::class, 'preview_has_high_impact_changes' );

		$this->assertFalse(
			$method->invoke(
				null,
				array(
					'high_impact_changes' => array(),
					'changes'            => array(
						array(
							'high_impact' => true,
							'status'      => 'unchanged',
						),
					),
				)
			)
		);
		$this->assertTrue(
			$method->invoke(
				null,
				array(
					'high_impact_changes' => array(),
					'changes'            => array(
						array(
							'high_impact' => true,
							'status'      => 'changed',
						),
					),
				)
			)
		);
	}

	public function test_exchange_uses_one_server_owned_size_limit_and_no_server_filesystem_io(): void {
		$root       = dirname( __DIR__, 2 );
		$migration  = (string) file_get_contents( $root . '/src/Admin/MigrationHub.php' );
		$handler    = (string) file_get_contents( $root . '/src/Admin/Settings/SettingsAjax.php' );
		$assets     = (string) file_get_contents( $root . '/src/Admin/Settings/SettingsAssets.php' );
		$script     = (string) file_get_contents( $root . '/assets/js/admin-command-center.js' );

		$this->assertSame( 1048576, MigrationHub::get_max_import_bytes() );
		$this->assertStringContainsString( 'MigrationHub::get_max_import_bytes()', $assets );
		$this->assertStringContainsString( 'cybermaps_discovery.exchange_max_import_bytes', $script );
		$this->assertDoesNotMatchRegularExpression(
			'/\\b(?:file_put_contents|fopen|fwrite|fclose|unlink|rename|copy)\\s*\\(/',
			$migration . "\n" . $handler
		);
	}

	public function test_ui_describes_site_level_scope_and_server_validation_before_replace(): void {
		$root     = dirname( __DIR__, 2 );
		$script   = (string) file_get_contents( $root . '/assets/js/admin-command-center.js' );
		$advanced = (string) file_get_contents(
			$root . '/src/Admin/Settings/Tabs/Advanced/AdvancedFields.php'
		);

		$this->assertStringContainsString( 'verified by the server before any settings change', $script );
		$this->assertStringContainsString( 'Network settings, translation relationships, analytics history', $script );
		$this->assertStringContainsString( 'may be reconciled from the restored settings', $script );
		$this->assertStringContainsString( 'cross-site translation relationships, analytics history', $advanced );
		$this->assertStringContainsString( 'WordPress content, media, and generated files are not included', $advanced );
		$this->assertStringContainsString( 'Download AI Configuration Brief', $script );
		$this->assertStringContainsString( 'configured public identity/contact/catalog context', $script );
		$this->assertStringContainsString( 'The private Cybermaps REST API secret and IndexNow key are excluded', $script );
		$this->assertStringContainsString( 'Keep the complete JSON backup private', $script );
		$this->assertStringNotContainsString( 'integration credentials', $script );
		$this->assertStringNotContainsString( 'integration secrets', $script . $advanced );
	}

	public function test_only_current_exchange_api_is_public_and_filenames_are_attachment_safe(): void {
		$this->assertTrue( method_exists( MigrationHub::class, 'import' ) );
		$this->assertTrue( method_exists( MigrationHub::class, 'generate_backup' ) );
		$this->assertFalse( method_exists( MigrationHub::class, 'parse_markdown' ) );
		$this->assertFalse( method_exists( MigrationHub::class, 'import_config' ) );
		$this->assertSame( 2, ( new \ReflectionMethod( MigrationHub::class, 'import' ) )->getNumberOfParameters() );
		$this->assertSame( 2, ( new \ReflectionMethod( MigrationHub::class, 'preview' ) )->getNumberOfParameters() );
		$this->assertSame( 4, ( new \ReflectionMethod( MigrationHub::class, 'import_previewed' ) )->getNumberOfParameters() );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/MigrationHub.php' );
		$this->assertStringNotContainsString( 'prepare_markdown', $source );
		$this->assertStringNotContainsString( 'parse_markdown_entries', $source );
		$this->assertStringNotContainsString( 'parse_legacy_crawler_override', $source );

		$method = new \ReflectionMethod( SettingsAjax::class, 'export_filename' );
		$name   = (string) $method->invoke( null, true );

		$this->assertMatchesRegularExpression(
			'/^cybermaps-backup-[a-z0-9.-]+-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}\\.cybermaps\\.json$/',
			$name
		);
		$this->assertStringNotContainsString( '"', $name );
		$this->assertMatchesRegularExpression(
			'/^cybermaps-ai-brief-[a-z0-9.-]+-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}\.cyberconf\.md$/',
			(string) $method->invoke( null, false )
		);
	}
}
