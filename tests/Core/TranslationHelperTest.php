<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\TranslationHelper;

final class TranslationHelperTest extends \WP_UnitTestCase {
	/**
	 * @var array<string, array<int, callable>>
	 */
	private array $previous_filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->previous_filters = (array) ( $GLOBALS['cybermaps_mock_filter_callbacks'] ?? array() );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks'] = $this->previous_filters;
		parent::tearDown();
	}

	public function test_active_plugin_slug_is_preserved_while_route_separators_are_equivalent(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['wpml_active_languages'] = array(
			static fn(): array => array(
				'pt-br' => array(),
				'en'    => array(),
			),
		);

		$this->assertSame( array( 'pt-br', 'en' ), TranslationHelper::get_active_languages() );
		$this->assertTrue( TranslationHelper::is_active_language( 'pt_br' ) );
		$this->assertSame( 'pt-br', TranslationHelper::resolve_active_language( 'pt_br' ) );
		$this->assertSame( '', TranslationHelper::resolve_active_language( 'fr' ) );
	}

	public function test_current_wpml_language_keeps_the_registered_slug(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['wpml_current_language'] = array(
			static fn(): string => 'pt-BR',
		);

		$this->assertSame( 'pt-br', TranslationHelper::get_current_language() );
	}

	public function test_hreflang_normalization_accepts_bcp47_style_tags_only(): void {
		$this->assertSame( 'en-US', TranslationHelper::normalize_hreflang( ' en_us ' ) );
		$this->assertSame( 'zh-Hans-CN', TranslationHelper::normalize_hreflang( 'ZH-hans-cn' ) );
		$this->assertSame( 'es-419', TranslationHelper::normalize_hreflang( 'es-419' ) );
		$this->assertSame( 'x-default', TranslationHelper::normalize_hreflang( 'X-DEFAULT' ) );
		$this->assertSame( '', TranslationHelper::normalize_hreflang( '--bad--' ) );
		$this->assertSame( '', TranslationHelper::normalize_hreflang( array( 'en-US' ) ) );
	}
}
