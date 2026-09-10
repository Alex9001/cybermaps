<?php
declare(strict_types=1);

namespace Cybermaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal PSR-4 loader used by the standalone WordPress.org artifact.
 */
final class Autoloader {
	public static function register(): void {
		\spl_autoload_register(
			static function ( string $class_name ): void {
				$file = self::resolve_file( $class_name );
				if ( '' !== $file ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * Resolve only canonical Cybermaps identifiers beneath the source root.
	 *
	 * @return string Absolute source file, or an empty string when rejected.
	 */
	private static function resolve_file( string $class_name ): string {
		$prefix = 'Cybermaps\\';
		if ( ! \str_starts_with( $class_name, $prefix ) ) {
			return '';
		}

		$relative_class = \substr( $class_name, \strlen( $prefix ) );
		if (
			'' === $relative_class
			|| 1 !== \preg_match(
				'/\A(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*\z/D',
				$relative_class
			)
		) {
			return '';
		}

		$file = __DIR__ . '/' . \str_replace( '\\', '/', $relative_class ) . '.php';
		return \is_file( $file ) ? $file : '';
	}
}
