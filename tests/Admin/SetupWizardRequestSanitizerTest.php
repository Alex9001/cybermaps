<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\SetupWizard\SetupWizardController;
use Cybermaps\Admin\SetupWizard\SetupWizardRegistry;
use Cybermaps\Admin\SetupWizard\SetupWizardRequestSanitizer;
use PHPUnit\Framework\TestCase;

final class SetupWizardRequestSanitizerTest extends TestCase {
	private function request(): array {
		return array(
			'wizard_version' => SetupWizardRegistry::VERSION,
			'answers' => array(
				'website_type' => 'blog',
				'ai_visibility' => 'on',
				'operations' => 'insights',
				'identity_type' => 'Organization',
				'identity_name' => '<b>Example</b>',
				'identity_description' => "First <b>line</b>\nSecond line",
				'identity_image_id' => 0,
			),
		);
	}

	public function test_boundary_returns_only_normalized_fields(): void {
		$request = $this->request();
		$actual = SetupWizardRequestSanitizer::sanitize( $request );
		self::assertSame( 'Example', $actual['answers']['identity_name'] );
		self::assertSame( "First line\nSecond line", $actual['answers']['identity_description'] );
		self::assertSame( $actual, SetupWizardRequestSanitizer::sanitize( $actual ) );
		self::assertSame( 0, $actual['answers']['identity_image_id'] );
	}

	public function test_controller_never_returns_raw_decoded_json(): void {
		$previous = $_POST;
		try {
			$_POST['payload'] = wp_json_encode( $this->request() );
			$method = new \ReflectionMethod( SetupWizardController::class, 'payload' );
			self::assertSame( 'Example', $method->invoke( null )['answers']['identity_name'] );
		} finally {
			$_POST = $previous;
		}
	}

	/** @dataProvider invalid_json_provider */
	public function test_controller_rejects_invalid_json_and_size( string $raw ): void {
		$previous = $_POST;
		try {
			$_POST['payload'] = $raw;
			$method = new \ReflectionMethod( SetupWizardController::class, 'payload' );
			$this->expectException( \InvalidArgumentException::class );
			$method->invoke( null );
		} finally {
			$_POST = $previous;
		}
	}

	public static function invalid_json_provider(): array {
		return array_map( static fn( $value ) => array( $value ), array( '', '{invalid', '[]', '{}', 'null', '"text"', str_repeat( 'x', 16385 ) ) );
	}

	public function test_invalid_choice_is_rejected_instead_of_repaired(): void {
		$request = $this->request();
		$request['answers']['website_type'] = '<b>blog</b>';
		$this->expectException( \InvalidArgumentException::class );
		SetupWizardRequestSanitizer::sanitize( $request );
	}
}
