<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\IdentityHub;
use Cybermaps\Core\CacheManager;
use PHPUnit\Framework\TestCase;

final class IdentityHubTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings' => array( 'static_engine_mode' => 'off' ),
        );
        $GLOBALS['cybermaps_mock_transients'] = array();
        $GLOBALS['cybermaps_mock_scheduled']  = array();
    }

    public function test_identity_builder_uses_the_styled_form_scope(): void {
        $source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/IdentityHub.php' );

        $this->assertIsString( $source );
        $this->assertStringContainsString(
            'class="cybermaps-identity-builder cybermaps-identity-builder-single-column"',
            $source
        );
    }

    /**
     * @dataProvider incompatible_subtype_provider
     */
    public function test_sanitizer_clears_incompatible_precise_type(
        string $primary_type,
        string $precise_type
    ): void {
        $result = ( new IdentityHub() )->sanitize_identity_data(
            array(
                'type'         => $primary_type,
                'precise_type' => $precise_type,
            )
        );

        $this->assertSame( $primary_type, $result['type'] );
        $this->assertSame( '', $result['precise_type'] );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function incompatible_subtype_provider(): array {
        return array(
            'person cannot be a restaurant'       => array( 'Person', 'Restaurant' ),
            'local business cannot be corporation' => array( 'LocalBusiness', 'Corporation' ),
            'organization cannot become a person'  => array( 'Organization', 'Person' ),
            'unknown schema type is rejected'      => array( 'Organization', 'MadeUpBusiness' ),
        );
    }

    public function test_sanitizer_retains_compatible_local_business_subtype(): void {
        $result = ( new IdentityHub() )->sanitize_identity_data(
            array(
                'type'         => 'LocalBusiness',
                'precise_type' => 'Restaurant',
            )
        );

        $this->assertSame( 'Restaurant', $result['precise_type'] );
    }

    public function test_absent_inactive_tab_payload_preserves_saved_identity(): void {
        $stored = array(
            'type' => 'Organization',
            'name' => 'Example Organization',
        );
        $GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = $stored;

        $this->assertSame(
            $stored,
            ( new IdentityHub() )->sanitize_identity_data( null )
        );
    }

    public function test_first_creation_adapter_uses_empty_identity_as_old_value(): void {
        $identity = new class() extends IdentityHub {
            /** @var array{mixed, mixed} */
            public array $received = array();

            public function on_identity_updated( $old_value, $new_value ): void {
                $this->received = array( $old_value, $new_value );
            }
        };
        $payload = array(
            'type' => 'Organization',
            'name' => 'Example Organization',
        );

        // WordPress passes the option name first on add_option_{$option}.
        $identity->on_identity_added( 'cybermaps_identity_data', $payload );

        $this->assertSame( array( array(), $payload ), $identity->received );
    }

    public function test_first_created_identity_republishes_full_static_output(): void {
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
        $payload = array(
            'type' => 'Organization',
            'name' => 'New Organization',
        );
        $GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = $payload;
        CacheManager::set( 'cybermaps_first_identity_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );

        ( new IdentityHub() )->on_identity_added( 'cybermaps_identity_data', $payload );

        $this->assertFalse( get_transient( 'cybermaps_first_identity_discovery' ) );
        $this->assertArrayHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
        $this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
    }

    public function test_sanitizer_accepts_browser_compacted_json_payload(): void {
        $result = ( new IdentityHub() )->sanitize_identity_data(
            wp_json_encode(
                array(
                    'type' => 'Organization',
                    'name' => 'Serialized Identity',
                    'social_profiles' => array(
                        'https://example.com/profile',
                    ),
                )
            )
        );

        $this->assertSame( 'Serialized Identity', $result['name'] );
        $this->assertSame( array( 'https://example.com/profile' ), $result['social_profiles'] );
    }

    public function test_identity_change_clears_dynamic_output_without_static_off_sync(): void {
        CacheManager::set( 'cybermaps_identity_discovery', '{}', HOUR_IN_SECONDS, 'discovery' );

        ( new IdentityHub() )->on_identity_updated( array( 'name' => 'Old' ), array( 'name' => 'New' ) );

        $this->assertFalse( get_transient( 'cybermaps_identity_discovery' ) );
        $this->assertArrayNotHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
        $this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
    }

    public function test_identity_change_republishes_full_static_output(): void {
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';

        ( new IdentityHub() )->on_identity_updated( array( 'name' => 'Old' ), array( 'name' => 'New' ) );

        $this->assertArrayHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
        $this->assertArrayHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
    }

    public function test_sanitizer_normalizes_unknown_primary_type(): void {
        $result = ( new IdentityHub() )->sanitize_identity_data(
            array(
                'type'         => 'RemoteScript',
                'precise_type' => 'Corporation',
            )
        );

        $this->assertSame( 'Organization', $result['type'] );
        $this->assertSame( 'Corporation', $result['precise_type'] );
    }

    public function test_sanitizer_enforces_nested_identity_contracts(): void {
        $result = ( new IdentityHub() )->sanitize_identity_data(
            array(
                'image_id'        => -15,
                'address_country' => 'us',
                'latitude'        => '0',
                'longitude'       => '-181',
                'hours'           => array(
                    'monday' => array(
                        array( 'open' => '09:00', 'close' => '17:30' ),
                        array( 'open' => '25:00', 'close' => '17:00' ),
                        array( 'open' => '12:00', 'close' => '12:00' ),
                    ),
                    'funday' => array(
                        array( 'open' => '09:00', 'close' => '17:00' ),
                    ),
                ),
                'catalogs' => array(
                    array(
                        'mode'  => 'remote',
                        'item_type' => 'Product',
                        'name'  => 'Services',
                        'items' => array( 'Consulting', '' ),
                    ),
                    array(
                        'mode' => 'manual',
                        'name' => 'Empty catalog',
                    ),
                ),
                'social_profiles' => array( 'https://example.com/profile', '' ),
                'contact_points'  => array(
                    array(
                        'type'  => 'Sales',
                        'email' => 'sales@example.com',
                    ),
                    array(
                        'type'  => 'Invented',
                        'phone' => '+1 555 0100',
                    ),
                    array(
                        'type' => 'Customer Support',
                    ),
                ),
            )
        );

        $this->assertSame( 0, $result['image_id'] );
        $this->assertSame( 'US', $result['address_country'] );
        $this->assertSame( '0', $result['latitude'] );
        $this->assertSame( '', $result['longitude'] );
        $this->assertSame(
            array(
                'monday' => array(
                    array( 'open' => '09:00', 'close' => '17:30' ),
                ),
            ),
            $result['hours']
        );
        $this->assertSame(
            array(
                array(
                    'mode'      => 'manual',
                    'item_type' => 'Product',
                    'name'      => 'Services',
                    'items'     => array( 'Consulting' ),
                    'parent_id' => 0,
                ),
            ),
            $result['catalogs']
        );
        $this->assertSame( array( 'https://example.com/profile' ), $result['social_profiles'] );
        $this->assertSame(
            array(
                array(
                    'type'  => 'Sales',
                    'phone' => '',
                    'email' => 'sales@example.com',
                ),
            ),
            $result['contact_points']
        );
    }

    public function test_sanitizer_rejects_non_iso_country_codes_and_non_numeric_coordinates(): void {
        $result = ( new IdentityHub() )->sanitize_identity_data(
            array(
                'address_country' => 'United States',
                'latitude'        => 'north',
                'longitude'       => '180',
            )
        );

        $this->assertSame( '', $result['address_country'] );
        $this->assertSame( '', $result['latitude'] );
        $this->assertSame( '180', $result['longitude'] );
    }

    public function test_sanitizer_accepts_any_valid_iso_country_code(): void {
        $result = ( new IdentityHub() )->sanitize_identity_data(
            array(
                'address_country' => 'ke',
            )
        );

        $this->assertSame( 'KE', $result['address_country'] );
    }

    public function test_sanitizer_bounds_identity_repeaters_and_public_text(): void {
        $hours = array_fill(
            0,
            \Cybermaps\Core\IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY + 1,
            array( 'open' => '09:00', 'close' => '17:00' )
        );
        $socials = array();
        for ( $index = 0; $index < \Cybermaps\Core\IdentityEntityBuilder::MAX_SOCIAL_PROFILES + 1; ++$index ) {
            $socials[] = 'https://example.com/profile-' . $index;
        }
        $contacts = array_fill(
            0,
            \Cybermaps\Core\IdentityEntityBuilder::MAX_CONTACT_POINTS + 1,
            array( 'type' => 'Sales', 'phone' => '+1 555 0100' )
        );
        $catalogs = array();
        for ( $catalog = 0; $catalog < \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS + 1; ++$catalog ) {
            $items = array();
            for ( $item = 0; $item < \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG + 1; ++$item ) {
                $items[] = 'Item ' . $catalog . '-' . $item;
            }
            $catalogs[] = array(
                'mode'      => 'manual',
                'item_type' => 'Product',
                'name'      => 'Catalog ' . $catalog,
                'items'     => $items,
            );
        }

        $result = ( new IdentityHub() )->sanitize_identity_data(
            array(
                'name'            => str_repeat( 'n', \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH + 10 ),
                'description'     => str_repeat( 'd', \Cybermaps\Core\IdentityEntityBuilder::MAX_DESCRIPTION_LENGTH + 10 ),
                'hours'           => array( 'monday' => $hours ),
                'social_profiles' => $socials,
                'contact_points'  => $contacts,
                'catalogs'        => $catalogs,
            )
        );

        $this->assertSame( \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH, strlen( $result['name'] ) );
        $this->assertSame( \Cybermaps\Core\IdentityEntityBuilder::MAX_DESCRIPTION_LENGTH, strlen( $result['description'] ) );
        $this->assertCount( \Cybermaps\Core\IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY, $result['hours']['monday'] );
        $this->assertCount( \Cybermaps\Core\IdentityEntityBuilder::MAX_SOCIAL_PROFILES, $result['social_profiles'] );
        $this->assertCount( \Cybermaps\Core\IdentityEntityBuilder::MAX_CONTACT_POINTS, $result['contact_points'] );
        $this->assertLessThanOrEqual( \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS, count( $result['catalogs'] ) );
        $this->assertSame(
            \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_TOTAL,
            array_sum( array_map( static fn ( array $catalog ): int => count( $catalog['items'] ), $result['catalogs'] ) )
        );
        foreach ( $result['catalogs'] as $catalog ) {
            $this->assertLessThanOrEqual(
                \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG,
                count( $catalog['items'] )
            );
        }
    }

    public function test_sanitizer_discards_malformed_nested_values_without_php_warnings(): void {
        $input = array(
            'type'            => array( 'LocalBusiness' ),
            'precise_type'    => array( 'Restaurant' ),
            'name'            => array( 'Example' ),
            'description'     => array( 'Description' ),
            'image_id'        => array( 25 ),
            'address_country' => array( 'US' ),
            'address'         => array( '123 Main Street' ),
            'latitude'        => array( '37.8044' ),
            'longitude'       => array( '-122.2711' ),
            'hours' => array(
                'monday' => array(
                    array(
                        'open'  => array( '09:00' ),
                        'close' => '17:00',
                    ),
                ),
            ),
            'catalogs' => array(
                array(
                    'mode'      => array( 'manual' ),
                    'name'      => array( 'Services' ),
                    'items'     => array( array( 'Consulting' ), 'Support' ),
                    'parent_id' => array( 10 ),
                ),
            ),
            'social_profiles' => array(
                array( 'https://invalid.example' ),
                '/relative',
                'javascript:alert(1)',
                'https://social.example/profile',
                'https://social.example/profile',
            ),
            'contact_points' => array(
                array(
                    'type'  => array( 'Sales' ),
                    'phone' => array( '+1 555 0100' ),
                    'email' => array( 'sales@example.com' ),
                ),
                array(
                    'type'  => 'Sales',
                    'phone' => '',
                    'email' => 'sales@example.com',
                ),
            ),
        );

        set_error_handler(
            static function ( int $severity, string $message ): never {
                throw new \ErrorException( $message, 0, $severity );
            }
        );
        try {
            $result = ( new IdentityHub() )->sanitize_identity_data( $input );
        } finally {
            restore_error_handler();
        }

        $this->assertSame( 'Organization', $result['type'] );
        $this->assertSame( '', $result['precise_type'] );
        $this->assertSame( '', $result['name'] );
        $this->assertSame( '', $result['description'] );
        $this->assertSame( 0, $result['image_id'] );
        $this->assertSame( '', $result['address_country'] );
        $this->assertSame( '', $result['address'] );
        $this->assertSame( '', $result['latitude'] );
        $this->assertSame( '', $result['longitude'] );
        $this->assertSame( array(), $result['hours'] );
        $this->assertSame(
            array(
                array(
                    'mode'      => 'manual',
                    'item_type' => 'Service',
                    'name'      => '',
                    'items'     => array( 'Support' ),
                    'parent_id' => 0,
                ),
            ),
            $result['catalogs']
        );
        $this->assertSame( array( 'https://social.example/profile' ), $result['social_profiles'] );
        $this->assertSame(
            array(
                array(
                    'type'  => 'Sales',
                    'phone' => '',
                    'email' => 'sales@example.com',
                ),
            ),
            $result['contact_points']
        );
    }

    public function test_identity_controls_have_stable_or_reindexed_accessible_names(): void {
        $source = (string) file_get_contents( CYBERMAPS_PLUGIN_DIR . 'src/Admin/IdentityHub.php' );

        foreach (
            array(
                'cybermaps-identity-type',
                'cybermaps-identity-precise-type',
                'cybermaps-identity-name',
                'cybermaps-identity-description',
                'cybermaps-identity-country',
                'cybermaps-identity-address',
                'cybermaps-identity-city',
                'cybermaps-identity-region',
                'cybermaps-identity-postal-code',
                'cybermaps-identity-latitude',
                'cybermaps-identity-longitude',
                'cybermaps-identity-phone',
                'cybermaps-identity-email',
            ) as $control_id
        ) {
            $this->assertStringContainsString( 'for="' . $control_id . '"', $source );
            $this->assertStringContainsString( 'id="' . $control_id . '"', $source );
        }

        $this->assertStringContainsString( 'function reindexSocialProfiles()', $source );
        $this->assertStringContainsString( 'name: "cybermaps_identity_data[social_profiles][" + index + "]"', $source );
        $this->assertStringContainsString( 'function updateRepeaterLimits()', $source );
        $this->assertStringContainsString( 'function refreshPreciseTypeOptions()', $source );
        $this->assertStringContainsString( 'function reindexSlots(container, day)', $source );
        $this->assertStringContainsString( 'function reindexContactPoints()', $source );
        $this->assertStringContainsString( 'function reindexCatalogs()', $source );
        $this->assertStringContainsString( 'function updateLocalBusinessFields()', $source );
        $this->assertStringContainsString( '"aria-label": cybermapsIH.socialProfile', $source );
        $this->assertStringContainsString( '"aria-label": cybermapsIH.contactType', $source );
        $this->assertStringContainsString( '"aria-label": cybermapsIH.catalogItem', $source );
        $this->assertStringContainsString( 'cybermaps-catalog-__INDEX__-parent', $source );
        $this->assertStringContainsString( 'for="cybermaps-catalog-__INDEX__-parent"', $source );
        $this->assertStringContainsString( 'pattern="[A-Za-z]{2}"', $source );
        $this->assertStringContainsString( '<select id="cybermaps-identity-precise-type"', $source );
        $this->assertStringNotContainsString( '<datalist id="cybermaps-schema-types"', $source );
    }

    public function test_dynamic_identity_controls_do_not_interpolate_copy_or_media_urls_into_html(): void {
        $source = (string) file_get_contents( CYBERMAPS_PLUGIN_DIR . 'src/Admin/IdentityHub.php' );
        $script_start = strpos( $source, 'jQuery(document).ready(function($)' );
        $script_end = strpos( $source, '});\'', false === $script_start ? 0 : $script_start );

        $this->assertNotFalse( $script_start );
        $this->assertNotFalse( $script_end );
        $script = substr( $source, (int) $script_start, (int) $script_end - (int) $script_start );

        $this->assertStringContainsString( 'function makeElement(tagName, attributes, text)', $script );
        $this->assertStringContainsString( 'var attachmentUrl = safeMediaUrl(attachment.url);', $script );
        $this->assertStringContainsString( 'parsed.protocol === "http:" || parsed.protocol === "https:"', $script );
        $this->assertStringNotContainsString( '.html(', $script );
        $this->assertDoesNotMatchRegularExpression(
            '/cybermapsIH\.[A-Za-z]+\s*\+\s*"</',
            $script
        );
        $this->assertDoesNotMatchRegularExpression(
            '/"<[^"\n]*"\s*\+\s*cybermapsIH\.[A-Za-z]+/',
            $script
        );
    }

    public function test_rendered_identity_controls_have_unique_ids_and_accessible_names(): void {
        $GLOBALS['cybermaps_mock_inline_scripts'] = array();
        $GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
            'type'  => 'LocalBusiness',
            'name'  => 'Example Cafe',
            'social_profiles' => array( 'https://social.example/cafe' ),
            'contact_points'  => array(
                array(
                    'type'  => 'Sales',
                    'phone' => '+1 555 0100',
                    'email' => '',
                ),
            ),
            'hours' => array(
                'monday' => array(
                    array( 'open' => '09:00', 'close' => '17:00' ),
                ),
            ),
            'catalogs' => array(
                array(
                    'mode'      => 'manual',
                    'item_type' => 'Product',
                    'name'      => 'Services',
                    'items'     => array( 'Consulting' ),
                    'parent_id' => 0,
                ),
                array(
                    'mode'      => 'auto',
                    'item_type' => 'Service',
                    'name'      => 'Programs',
                    'items'     => array(),
                    'parent_id' => 42,
                ),
            ),
        );

        ob_start();
        ( new IdentityHub() )->render_identity_builder();
        $html = (string) ob_get_clean();

        preg_match_all( '/<(?:input|select|textarea)\b[^>]*>/i', $html, $matches );
        $ids = array();
        foreach ( $matches[0] as $control ) {
            if ( 1 === preg_match( '/\btype="hidden"/i', $control ) ) {
                continue;
            }

            $this->assertSame( 1, preg_match( '/\bid="([^"]+)"/i', $control, $id_match ), $control );
            $id = $id_match[1];
            $ids[] = $id;

            if ( str_contains( $id, '__INDEX__' ) || str_contains( $control, 'aria-label=' ) ) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/<label\b[^>]*\bfor="' . preg_quote( $id, '/' ) . '"/i',
                $html,
                $control
            );
        }

        $this->assertNotEmpty( $ids );
        $this->assertSame( $ids, array_values( array_unique( $ids ) ) );
    }

    public function test_business_only_controls_follow_the_resolved_type_without_dropping_values(): void {
        $GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
            'type'      => 'Organization',
            'name'      => 'Example Organization',
            'latitude'  => '37.8044',
            'longitude' => '-122.2711',
            'hours'     => array(
                'monday' => array(
                    array( 'open' => '09:00', 'close' => '17:00' ),
                ),
            ),
        );

        ob_start();
        ( new IdentityHub() )->render_identity_builder();
        $organization_html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/class="cybermaps-local-business-only" aria-hidden="true" style="display: none;/',
            $organization_html
        );
        $this->assertStringContainsString(
            'name="cybermaps_identity_data[latitude]" value="37.8044"',
            $organization_html
        );
        $this->assertStringContainsString(
            'name="cybermaps_identity_data[hours][monday][0][open]" value="09:00"',
            $organization_html
        );

        $GLOBALS['cybermaps_mock_options']['cybermaps_identity_data']['precise_type'] = 'Restaurant';

        ob_start();
        ( new IdentityHub() )->render_identity_builder();
        $restaurant_html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/class="cybermaps-local-business-only" aria-hidden="false" style="display: grid;/',
            $restaurant_html
        );
        $this->assertStringContainsString(
            'Published only for LocalBusiness and its supported business subtypes.',
            $restaurant_html
        );
    }
}
