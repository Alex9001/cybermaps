<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\AIDiscoveryStatus;
use Cybermaps\Admin\ContentAuditManager;
use Cybermaps\Admin\DiscoveryAnalytics;
use Cybermaps\Admin\IdentityHub;
use Cybermaps\Admin\Logs;
use Cybermaps\Admin\Settings;
use Cybermaps\Admin\SitemapStatus;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'CYBERMAPS_PLUGIN_BASENAME' ) ) {
    define( 'CYBERMAPS_PLUGIN_BASENAME', 'cybermaps/cybermaps.php' );
}

class AdminHooksTest extends TestCase {

    private array $original_options;

    protected function setUp(): void {
        parent::setUp();
        $this->original_options = $GLOBALS['cybermaps_mock_options'];
        $GLOBALS['wp_hooks'] = array();
        $GLOBALS['cybermaps_mock_admin_pages'] = array();
        $GLOBALS['cybermaps_mock_enqueued_styles'] = array();
        $GLOBALS['cybermaps_mock_enqueued_scripts'] = array();
        $GLOBALS['cybermaps_mock_style_data'] = array();
    }

    protected function tearDown(): void {
        $GLOBALS['cybermaps_mock_options'] = $this->original_options;
        parent::tearDown();
    }

    public function test_core_admin_actions_are_registered_without_a_plan_check(): void {
        ( new Settings() )->register_hooks();
        ( new ContentAuditManager() )->register_hooks();

        $hooks = array_column( $GLOBALS['wp_hooks'], 'hook' );

        $this->assertContains( 'wp_ajax_cybermaps_preview_config', $hooks );
        $this->assertContains( 'wp_ajax_cybermaps_import_config', $hooks );
        $this->assertContains( 'admin_post_cybermaps_export_config', $hooks );
        $this->assertContains( 'admin_post_cybermaps_run_content_audit', $hooks );
        $this->assertContains( 'admin_post_cybermaps_export_content_audit', $hooks );
        $this->assertContains( 'admin_post_cybermaps_export_discovery_report', $hooks );
        $this->assertContains( 'cybermaps_cleanup_logs_event', $hooks );
        $this->assertSame( 1, array_count_values( $hooks )['admin_post_cybermaps_run_content_audit'] );
        $this->assertSame( 1, array_count_values( $hooks )['admin_post_cybermaps_export_config'] );
        $this->assertSame( 1, array_count_values( $hooks )['admin_post_cybermaps_export_content_audit'] );
        $this->assertSame( 1, array_count_values( $hooks )['admin_post_cybermaps_export_discovery_report'] );
    }

    public function test_configuration_hooks_cover_option_updates_and_first_creation(): void {
        ( new Settings() )->register_hooks();
        ( new IdentityHub() )->register_hooks();

        $expected = array(
            'update_option_cybermaps_settings'          => 'on_settings_updated',
            'add_option_cybermaps_settings'             => 'on_settings_added',
            'update_option_cybermaps_discovery_center'  => 'on_discovery_center_updated',
            'add_option_cybermaps_discovery_center'     => 'on_discovery_center_added',
            'update_option_cybermaps_robots_manager'    => 'on_robots_manager_updated',
            'add_option_cybermaps_robots_manager'       => 'on_robots_manager_added',
            'update_option_cybermaps_identity_data'     => 'on_identity_updated',
            'add_option_cybermaps_identity_data'        => 'on_identity_added',
        );

        foreach ( $expected as $hook => $method ) {
            $registrations = array_values(
                array_filter(
                    $GLOBALS['wp_hooks'],
                    static fn ( array $registered ): bool =>
                        'action' === $registered['type']
                        && $hook === $registered['hook']
                )
            );

            $this->assertCount( 1, $registrations, $hook );
            $this->assertSame( 10, $registrations[0]['priority'], $hook );
            $this->assertSame( 2, $registrations[0]['accepted_args'], $hook );
            $this->assertIsArray( $registrations[0]['callback'], $hook );
            $this->assertSame( $method, $registrations[0]['callback'][1], $hook );
        }
    }

    public function test_settings_normalizes_the_retired_static_boolean_once(): void {
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
            'enable_static_engine' => '1',
        );

        ( new Settings() )->register_hooks();

        $settings = $GLOBALS['cybermaps_mock_options']['cybermaps_settings'];
        $this->assertSame( 'well_known', $settings['static_engine_mode'] );
        $this->assertArrayNotHasKey( 'enable_static_engine', $settings );
    }

    public function test_settings_persists_the_canonical_mode_when_it_is_missing(): void {
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
            'enable_discovery_hub' => '1',
        );

        ( new Settings() )->register_hooks();

        $settings = $GLOBALS['cybermaps_mock_options']['cybermaps_settings'];
        $this->assertSame( 'well_known', $settings['static_engine_mode'] );
        $this->assertSame( '1', $settings['enable_discovery_hub'] );
    }

    public function test_log_export_action_is_always_registered(): void {
        ( new Logs() )->register_hooks();

        $hooks = array_column( $GLOBALS['wp_hooks'], 'hook' );

        $this->assertContains( 'admin_post_cybermaps_export_logs', $hooks );
        $this->assertContains( 'admin_post_cybermaps_clear_logs', $hooks );
        $this->assertNotContains( 'wp_ajax_cybermaps_dismiss_logs_notice', $hooks );
    }

    public function test_discovery_analytics_uses_a_capability_wordpress_grants(): void {
        ( new DiscoveryAnalytics() )->add_page();

        $this->assertCount( 1, $GLOBALS['cybermaps_mock_admin_pages'] );
        $this->assertSame( 'manage_options', $GLOBALS['cybermaps_mock_admin_pages'][0]['capability'] );

        $source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/DiscoveryAnalytics.php' );
        $this->assertStringNotContainsString( '$wpdb->get_var( $wpdb->prepare(', $source );
    }

    public function test_status_subpages_enqueue_styles_using_the_registered_wordpress_hook(): void {
        $pages = array(
            array( new SitemapStatus(), 'cybermaps-sitemap-status' ),
            array( new DiscoveryAnalytics(), 'cybermaps-discovery-analytics' ),
            array( new AIDiscoveryStatus(), 'cybermaps-ai-discovery-status' ),
        );

        foreach ( $pages as list( $page, $slug ) ) {
            $GLOBALS['cybermaps_mock_enqueued_styles'] = array();

            $page->add_page();
            $page->enqueue_scripts( 'cybermaps-settings_page_' . $slug );
            $this->assertArrayNotHasKey( 'cybermaps-command-center', $GLOBALS['cybermaps_mock_enqueued_styles'] );

            $page->enqueue_scripts( 'cybermaps_page_' . $slug );
            $this->assertSame(
                CYBERMAPS_PLUGIN_URL . 'assets/css/admin-command-center.css',
                $GLOBALS['cybermaps_mock_enqueued_styles']['cybermaps-command-center']['src'] ?? null
            );
            $this->assertSame(
                CYBERMAPS_VERSION,
                $GLOBALS['cybermaps_mock_enqueued_styles']['cybermaps-command-center']['version'] ?? null
            );
            $this->assertTrue(
                $GLOBALS['cybermaps_mock_style_data']['cybermaps-command-center']['rtl'] ?? false
            );
        }
    }

    public function test_discovery_analytics_enqueues_its_page_scoped_activity_script(): void {
        $page = new DiscoveryAnalytics();
        $page->add_page();

        $page->enqueue_scripts( 'cybermaps_page_cybermaps-ai-discovery-status' );
        $this->assertArrayNotHasKey( 'cybermaps-discovery-analytics', $GLOBALS['cybermaps_mock_enqueued_scripts'] );

        $page->enqueue_scripts( 'cybermaps_page_cybermaps-discovery-analytics' );
        $this->assertSame(
            CYBERMAPS_PLUGIN_URL . 'assets/js/discovery-analytics.js',
            $GLOBALS['cybermaps_mock_enqueued_scripts']['cybermaps-discovery-analytics']['src'] ?? null
        );
    }

    public function test_discovery_analytics_presents_identity_evidence_without_legacy_filter_terms(): void {
        $root   = dirname( __DIR__, 2 );
        $source = (string) file_get_contents( $root . '/src/Admin/DiscoveryAnalytics.php' );
        $script = (string) file_get_contents( $root . '/assets/js/discovery-analytics.js' );

        $this->assertStringContainsString( "'unknown_clients'", $source );
        $this->assertStringContainsString( 'Unidentified request patterns', $source );
        $this->assertStringContainsString( 'Claimed (UA signature matched)', $source );
        $this->assertStringContainsString( 'Crawler ID: %s', $source );
        $this->assertStringContainsString( 'Requester: %s', $source );
        $this->assertStringContainsString( 'WP user: #%d', $source );
        $this->assertStringContainsString( 'Source: %3$s · Storage: %4$s', $source );
        $this->assertStringContainsString( 'data-cm-filter-identity', $source );
        $this->assertStringContainsString( 'data-cm-identity-group', $source );
        $this->assertStringContainsString( 'data-cm-filter-identity', $script );
        $this->assertStringContainsString( 'cmIdentityGroup', $script );
        $this->assertStringNotContainsString( 'data-cm-filter-recognition', $source . $script );
        $this->assertStringNotContainsString( 'data-cm-recognition', $source . $script );
        $this->assertStringNotContainsString( "esc_html_e( 'Authenticated'", $source );
        $this->assertStringNotContainsString( "esc_html_e( 'Recognized'", $source );
    }

    public function test_discovery_status_inventory_uses_a_single_responsive_surface(): void {
        $root   = dirname( __DIR__, 2 );
        $source = (string) file_get_contents( $root . '/src/Admin/AIDiscoveryStatus.php' );
        $styles = (string) file_get_contents( $root . '/assets/css/admin-command-center.css' );

        $this->assertStringContainsString( 'widefat cm-discovery-status-table', $source );
        $this->assertStringContainsString( 'cm-discovery-status-endpoint-row', $source );
        $this->assertStringContainsString( 'cm-discovery-status-field-label', $source );
        $this->assertStringNotContainsString( '<colgroup>', $source );
        $this->assertStringNotContainsString( 'cm-discovery-status-table-scroll', $source );
        $this->assertStringNotContainsString( 'min-width: 1600px;', $styles );
        $this->assertStringContainsString( 'grid-template-columns: repeat(12, minmax(0, 1fr));', $styles );
        $this->assertMatchesRegularExpression(
            '/\.cm-discovery-status-endpoint-row\s*\{[^}]*border-radius:\s*0;/s',
            $styles
        );
    }

    public function test_advanced_settings_no_longer_buries_a_duplicate_logs_panel(): void {
        $advanced = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Advanced.php' );

        $this->assertStringNotContainsString( 'LogsTab', $advanced );
        $this->assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/LogsTab.php' );
    }
}
