<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ReleaseToolingTest extends TestCase {
	private string $project_root;

	protected function setUp(): void {
		$this->project_root = dirname( __DIR__, 2 );
	}

	public function test_release_validator_rejects_unexpected_nested_file_types(): void {
		$cases = array(
			'src/debug.txt'         => 'src/ may contain only PHP files',
			'assets/mockup.psd'     => 'assets/ may contain only CSS, JavaScript, and XSL files',
			'languages/source.json' => 'languages/ may contain only POT, PO, and MO files',
		);

		foreach ( $cases as $relative_path => $expected_error ) {
			$temp_dir     = $this->make_temp_directory();
			$artifact_dir = $temp_dir . '/cybermaps';

			try {
				foreach ( array( 'src', 'assets', 'languages' ) as $directory ) {
					$this->assertTrue( mkdir( $artifact_dir . '/' . $directory, 0755, true ) );
				}
				foreach ( array( 'cybermaps.php', 'uninstall.php', 'readme.txt', 'changelog.txt', 'LICENSE' ) as $file ) {
					$this->assertNotFalse( file_put_contents( $artifact_dir . '/' . $file, "fixture\n" ) );
				}

				$unexpected_path = $artifact_dir . '/' . $relative_path;
				$this->assertNotFalse( file_put_contents( $unexpected_path, "must not ship\n" ) );

				$result = $this->run_process(
					array(
						'bash',
						$this->project_root . '/bin/validate-release.sh',
						$artifact_dir,
						$temp_dir . '/cybermaps_0.0.0.zip',
					)
				);

				$this->assertNotSame( 0, $result['status'] );
				$this->assertStringContainsString( $expected_error, $result['output'] );
			} finally {
				$this->remove_temp_directory( $temp_dir );
			}
		}
	}

	public function test_manifest_checker_identifies_changed_top_level_keys(): void {
		$temp_dir = $this->make_temp_directory();

		try {
			$manifest = json_decode(
				(string) file_get_contents( $this->project_root . '/docs/dev/manifest.json' ),
				true,
				512,
				JSON_THROW_ON_ERROR
			);
			$this->assertIsArray( $manifest );
			$manifest['version'] = '0.0.0';

			$manifest_path = $temp_dir . '/manifest.json';
			$this->assertNotFalse(
				file_put_contents(
					$manifest_path,
					(string) json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
				)
			);

			$result = $this->run_process(
				array(
					PHP_BINARY,
					$this->project_root . '/bin/check-manifest.php',
					$manifest_path,
				)
			);

			$this->assertNotSame( 0, $result['status'] );
			$this->assertStringContainsString( 'Changed top-level keys: version', $result['output'] );
		} finally {
			$this->remove_temp_directory( $temp_dir );
		}
	}

	/**
	 * @param string[] $command
	 * @return array{status: int, output: string}
	 */
	private function run_process( array $command ): array {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		$environment = null;
		if ( 'bash' === basename( $command[0] ) ) {
			$inherited_environment = getenv();
			$environment           = is_array( $inherited_environment )
				? $inherited_environment
				: array();

			/*
			 * Local's bundled PHP needs its private shared-library path, but a
			 * system Bash launched from that PHP process must not inherit it.
			 * Otherwise Bash can resolve Local's readline instead of the system
			 * library and fail before the release validator is exercised.
			 */
			unset( $environment['LD_LIBRARY_PATH'] );
		}

		$process = proc_open(
			$command,
			$descriptors,
			$pipes,
			$this->project_root,
			$environment
		);
		$this->assertIsResource( $process );

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'status' => proc_close( $process ),
			'output' => (string) $stdout . (string) $stderr,
		);
	}

	private function make_temp_directory(): string {
		$directory = sys_get_temp_dir() . '/cybermaps-release-' . bin2hex( random_bytes( 8 ) );
		$this->assertTrue( mkdir( $directory, 0700 ) );
		return $directory;
	}

	private function remove_temp_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $directory );
	}
}
