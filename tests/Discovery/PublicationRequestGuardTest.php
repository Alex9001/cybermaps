<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\LLMS;
use Cybermaps\Discovery\LLMSTLDR;
use Cybermaps\Discovery\MarkdownAlternate;
use Cybermaps\Discovery\PublicationRequestGuard;
use Cybermaps\Discovery\RAGChunk;
use PHPUnit\Framework\TestCase;

final class PublicationRequestGuardTest extends TestCase {
	public function test_guard_source_answers_options_and_rejects_other_methods(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Discovery/PublicationRequestGuard.php'
		);

		$this->assertStringContainsString( 'Integrity::handle_preflight', $source );
		$this->assertStringContainsString( 'status_header( 405 )', $source );
		$this->assertStringContainsString( 'Allow: GET, HEAD, OPTIONS', $source );
	}

	public function test_active_parameterized_handlers_invoke_guard_after_enablement(): void {
		foreach ( array( LLMS::class, LLMSTLDR::class, RAGChunk::class, MarkdownAlternate::class ) as $class ) {
			$method = new \ReflectionMethod( $class, 'handle' );
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

			$guard_position = strpos( $source, 'PublicationRequestGuard::enforce_active_route' );
			$this->assertNotFalse( $guard_position, $class );
			$this->assertLessThan(
				$guard_position,
				strpos( $source, 'ConfigurationStore::settings' ),
				$class
			);
		}
	}
}
