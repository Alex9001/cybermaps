<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Discovery\ADP;
use Cybermaps\Discovery\LLMS;
use Cybermaps\Discovery\LLMSTLDR;
use Cybermaps\Discovery\Manager;
use Cybermaps\Discovery\MarkdownAlternate;
use Cybermaps\Discovery\PublicationHandlerResolver;
use Cybermaps\Discovery\PublicationRouter;
use Cybermaps\Discovery\RAGChunk;
use Cybermaps\Discovery\Robots;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Discovery\Throttler;

class PublicationRouterTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_hooks']               = array();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'static_engine_mode'   => 'off',
				'enable_discovery_hub' => '1',
				'enable_rag_chunks'    => '1',
			),
		);
		$registry_instance = new \ReflectionProperty( EndpointRegistry::class, 'instance' );
		$registry_instance->setValue( null, null );
	}

	public function test_manager_registers_one_fixed_router_and_parameterized_chunk_fallback(): void {
		( new Manager( new ADP(), new Robots() ) )->register_hooks();

		$request_classes  = array();
		$rest_classes     = array();
		foreach ( $GLOBALS['wp_hooks'] as $hook ) {
			if ( ! \is_array( $hook['callback'] ) || ! \is_object( $hook['callback'][0] ) ) {
				continue;
			}
			$class = $hook['callback'][0]::class;
			if ( 'parse_request' === $hook['hook'] ) {
				$request_classes[] = $class;
			}
			if ( 'rest_pre_dispatch' === $hook['hook'] ) {
				$rest_classes[] = $class;
			}
		}

		$this->assertSame( 1, \count( \array_filter( $request_classes, static fn( string $class ): bool => PublicationRouter::class === $class ) ) );
		$this->assertContains( RAGChunk::class, $request_classes );
		$this->assertContains( MarkdownAlternate::class, $request_classes );
		$this->assertContains( LLMS::class, $request_classes );
		$this->assertContains( LLMSTLDR::class, $request_classes );
		$this->assertContains( Throttler::class, $request_classes );
		$this->assertContains( Throttler::class, $rest_classes );
		$this->assertNotContains( ADP::class, $request_classes );
		$this->assertNotEmpty(
			array_filter(
				$GLOBALS['wp_hooks'],
				static fn( array $hook ): bool => StaticBridge::TIME_SENSITIVE_REFRESH_HOOK === $hook['hook']
					&& is_array( $hook['callback'] )
					&& 'refresh_time_sensitive_publications' === $hook['callback'][1]
			)
		);
	}

	public function test_tldr_fallthrough_matches_canonical_and_localized_paths_only(): void {
		$handler = new class() extends LLMSTLDR {
			public function match_language( string $path ): ?string {
				return $this->get_request_language( $path );
			}
		};

		$this->assertSame( '', $handler->match_language( '/llms-tldr.txt' ) );
		$this->assertSame( 'fr', $handler->match_language( '/fr/llms-tldr.txt' ) );
		$this->assertSame( 'pt_br', $handler->match_language( '/pt_br/llms-tldr.txt' ) );
		$this->assertNull( $handler->match_language( '/fr/llms.txt' ) );
		$this->assertNull( $handler->match_language( '/private/fr/llms-tldr.txt' ) );
	}

	public function test_localized_llms_routes_require_enabled_hub_and_active_language(): void {
		$llms = new class() extends LLMS {
			public function match( string $path ): ?array {
				return $this->match_request( $path );
			}

			public function localized_enabled( string $language, array $settings ): bool {
				return $this->localized_request_is_enabled( $language, $settings );
			}
		};
		$tldr = new class() extends LLMSTLDR {
			public function localized_enabled( string $language, array $settings ): bool {
				return $this->localized_request_is_enabled( $language, $settings );
			}
		};
		$enabled = array( 'enable_multilingual_hub' => '1' );

		$this->assertSame(
			array( 'type' => 'summary', 'language' => 'en' ),
			$llms->match( '/en/llms.txt' )
		);
		$this->assertSame(
			array( 'type' => 'full', 'language' => 'en' ),
			$llms->match( '/en/llms-full.txt' )
		);
		$this->assertNull( $llms->match( '/en/private/llms.txt' ) );
		$this->assertTrue( $llms->localized_enabled( 'en', $enabled ) );
		$this->assertTrue( $tldr->localized_enabled( 'en', $enabled ) );
		$this->assertFalse( $llms->localized_enabled( 'fr', $enabled ) );
		$this->assertFalse( $tldr->localized_enabled( 'en', array() ) );
	}

	public function test_handler_instances_are_cached_per_endpoint_not_per_class(): void {
		$resolver = new PublicationHandlerResolver( new ADP(), EndpointRegistry::get_instance() );

		$first = $resolver->resolve(
			PublicationRouterTestHandler::class,
			'extension_one',
			array()
		);
		$first_again = $resolver->resolve(
			PublicationRouterTestHandler::class,
			'extension_one',
			array()
		);
		$second = $resolver->resolve(
			PublicationRouterTestHandler::class,
			'extension_two',
			array()
		);

		$this->assertSame( $first, $first_again );
		$this->assertNotSame( $first, $second );
	}

	public function test_chunk_routes_enforce_guard_after_enablement_checks(): void {
		$method = new \ReflectionMethod( RAGChunk::class, 'handle' );
		$lines  = file( (string) $method->getFileName() );
		$source = false === $lines
			? ''
			: implode(
				'',
				array_slice(
					$lines,
					$method->getStartLine() - 1,
					$method->getEndLine() - $method->getStartLine() + 1
				)
			);
		$guard_position   = strpos( $source, 'PublicationRequestGuard::enforce_active_route' );
		$settings_position = strpos( $source, 'ConfigurationStore::settings' );

		$this->assertNotFalse( $guard_position );
		$this->assertNotFalse( $settings_position );
		$this->assertLessThan( $guard_position, $settings_position );
		$this->assertSame( 1, substr_count( $source, 'PublicationRequestGuard::enforce_active_route' ) );
	}

	public function test_fixed_router_uses_the_shared_options_aware_publication_guard(): void {
		$method = new \ReflectionMethod( PublicationRouter::class, 'handle' );
		$lines  = file( (string) $method->getFileName() );
		$source = false === $lines
			? ''
			: implode(
				'',
				array_slice(
					$lines,
					$method->getStartLine() - 1,
					$method->getEndLine() - $method->getStartLine() + 1
				)
			);

		$this->assertStringContainsString( 'PublicationRequestGuard::enforce_active_route()', $source );
		$this->assertStringNotContainsString( 'ReadOnlyRequest::enforce()', $source );
	}

	public function test_fixed_router_does_not_claim_paths_while_hub_is_disabled(): void {
		$registry = EndpointRegistry::get_instance();
		$this->assertTrue(
			$registry->register(
				'router_hub_contract',
				array(
					'kind'           => 'path',
					'path'           => '/router-hub-contract.json',
					'type'           => 'application/json',
					'format'         => 'json',
					'handler_class'  => PublicationRouterTestHandler::class,
					'static_targets' => array(),
				)
			)
		);
		$_SERVER['REQUEST_URI']    = '/router-hub-contract.json';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		PublicationRouterTestHandler::$calls = 0;

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '0';
		( new PublicationRouter( new ADP(), $registry ) )->handle();
		$this->assertSame( 0, PublicationRouterTestHandler::$calls );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '1';
		( new PublicationRouter( new ADP(), $registry ) )->handle();
		$this->assertSame( 1, PublicationRouterTestHandler::$calls );

		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
	}
}

final class PublicationRouterTestHandler {
	public static int $calls = 0;

	public function handle(): void {
		++self::$calls;
	}
}
