<?php
declare(strict_types=1);

namespace Cybermaps\Tests\SEO;

use Cybermaps\SEO\IndexabilityResolver;
use Cybermaps\SEO\PluginSeoAdapter;
use Cybermaps\SEO\SeoCompatibilityAdapter;
use Cybermaps\SEO\SeoContext;
use Cybermaps\SEO\SeoSignals;
use PHPUnit\Framework\TestCase;

/**
 * Isolated Yoast legacy meta path without leaking globals into other tests.
 */
final class YoastCompatibilityFixtureTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_post_meta'] );
		parent::tearDown();
	}

	public function test_legacy_yoast_noindex_and_canonical_are_read(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', 'fixture' );
		}

		$GLOBALS['cybermaps_mock_post_meta'] = array(
			42 => array(
				'_yoast_wpseo_meta-robots-noindex' => '1',
				'_yoast_wpseo_canonical'           => 'https://example.com/canonical-post',
			),
		);

		$signals = ( new PluginSeoAdapter() )->get_signals(
			new SeoContext(
				SeoContext::POST,
				42,
				'post',
				'https://example.com/post'
			)
		);

		$this->assertInstanceOf( SeoSignals::class, $signals );
		$this->assertTrue( $signals->noindex );
		$this->assertSame( 'https://example.com/canonical-post', $signals->canonical );
	}

	public function test_conflicting_canonicals_add_conflict_reason(): void {
		$first  = new SeoSignals( 'adapter_a', false, false, false, 'https://example.com/a' );
		$second = new SeoSignals( 'adapter_b', false, false, false, 'https://example.com/b' );

		$decision = ( new IndexabilityResolver(
			array(
				new class( $first ) implements SeoCompatibilityAdapter {
					public function __construct( private SeoSignals $signals ) {}

					public function get_id(): string {
						return 'a';
					}

					public function get_signals( SeoContext $context ): ?SeoSignals {
						unset( $context );
						return $this->signals;
					}
				},
				new class( $second ) implements SeoCompatibilityAdapter {
					public function __construct( private SeoSignals $signals ) {}

					public function get_id(): string {
						return 'b';
					}

					public function get_signals( SeoContext $context ): ?SeoSignals {
						unset( $context );
						return $this->signals;
					}
				},
			)
		) )->resolve(
			new SeoContext( SeoContext::POST, 1, 'post', 'https://example.com/post' )
		);

		$this->assertContains( 'canonical_conflict', $decision->reasons );
		$this->assertSame( 'https://example.com/b', $decision->canonical_url );
	}
}
