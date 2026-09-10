<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Logs;
use PHPUnit\Framework\TestCase;

final class LogsExportTest extends TestCase {
	public function test_csv_rows_quote_values_and_neutralize_whitespace_prefixed_formulas(): void {
		$method = new \ReflectionMethod( Logs::class, 'output_csv_row' );

		ob_start();
		$method->invoke( null, array( " \t=HYPERLINK(\"bad\")", 'ordinary,value' ) );
		$output = (string) ob_get_clean();

		$this->assertSame(
			"\"' \t=HYPERLINK(\"\"bad\"\")\",\"ordinary,value\"\r\n",
			$output
		);
	}

	public function test_log_export_declares_utf8_bom_and_bounded_batches(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Logs.php' );

		$this->assertStringContainsString( 'echo "\\xEF\\xBB\\xBF"', $source );
		$this->assertStringContainsString( '$batch_size = 500', $source );
		$this->assertStringContainsString( 'get_export_batch', $source );
		$this->assertStringContainsString( 'X-Content-Type-Options: nosniff', $source );
	}
}
