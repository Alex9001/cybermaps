<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Core;
use Cybermaps\Core\AbilityKernel;
use PHPUnit\Framework\TestCase;
final class AbilityKernelTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['cybermaps_mock_abilities'] = array();
        $GLOBALS['cybermaps_mock_ability_categories'] = array();
        $GLOBALS['cybermaps_mock_options'] = array();
    }
    protected function tearDown(): void { $GLOBALS['cybermaps_mock_abilities'] = array(); }
    public function test_standalone_core_registers_only_public_search_and_opts_out_of_automatic_mcp(): void {
        $kernel = AbilityKernel::get_instance();
        $kernel->register_categories(); $kernel->register_abilities();
        self::assertSame( array( 'cybermaps/search' ), array_keys( $GLOBALS['cybermaps_mock_abilities'] ) );
        self::assertCount( 1, $GLOBALS['cybermaps_mock_ability_categories'] );
        $meta = wp_get_ability( 'cybermaps/search' )->get_meta();
        self::assertTrue( $meta['public'] ); self::assertFalse( $meta['mcp']['public'] );
        self::assertTrue( $meta['annotations']['readonly'] );
    }
    public function test_a_third_party_ability_does_not_populate_cybermaps_public_catalog(): void {
        wp_register_ability( 'evil/delete-site', array( 'meta' => array( 'public' => true, 'annotations' => array( 'readonly' => true ) ) ) );
        self::assertFalse( AbilityKernel::get_instance()->has_public_abilities() );
        self::assertFalse( method_exists( AbilityKernel::class, 'execute_tool' ) );
        self::assertFalse( method_exists( AbilityKernel::class, 'webmcp_catalog' ) );
    }
}
