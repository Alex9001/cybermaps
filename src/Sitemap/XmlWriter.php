<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small sitemap XML writer with a native XMLWriter fast path.
 *
 * XMLWriter is not guaranteed by WordPress' PHP requirements. Keeping the
 * renderer contract behind this adapter prevents a missing optional extension
 * from turning the plugin's primary sitemap route into a fatal error.
 */
final class XmlWriter {
	private ?object $native = null;
	private string $output  = '';

	/** @var string[] */
	private array $elements = array();

	private bool $open_start_element = false;

	/**
	 * @param bool $force_fallback Test seam for the extension-free writer.
	 */
	public function __construct( bool $force_fallback = false ) {
		if ( ! $force_fallback && \class_exists( \XMLWriter::class ) ) {
			$writer = new \XMLWriter();
			if ( $writer->openMemory() ) {
				$this->native = $writer;
			}
		}
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Adapter API intentionally mirrors native XMLWriter.
	public function startDocument( string $version = '1.0', string $encoding = 'UTF-8' ): void {
		if ( null !== $this->native ) {
			$this->native->startDocument( $version, $encoding );
			return;
		}

		$this->output .= '<?xml version="' . $this->attribute( $version )
			. '" encoding="' . $this->attribute( $encoding ) . '"?>';
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Adapter API intentionally mirrors native XMLWriter.
	public function writePI( string $target, string $content ): void {
		if ( null !== $this->native ) {
			$this->native->writePI( $target, $content );
			return;
		}

		$this->close_start_element();
		$this->output .= '<?' . $this->name( $target ) . ' '
			. \str_replace( '?>', '? >', $content ) . '?>';
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Adapter API intentionally mirrors native XMLWriter.
	public function startElement( string $name ): void {
		if ( null !== $this->native ) {
			$this->native->startElement( $name );
			return;
		}

		$this->close_start_element();
		$name                     = $this->name( $name );
		$this->output            .= '<' . $name;
		$this->elements[]         = $name;
		$this->open_start_element = true;
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Adapter API intentionally mirrors native XMLWriter.
	public function writeAttribute( string $name, mixed $value ): void {
		if ( null !== $this->native ) {
			$this->native->writeAttribute( $name, $this->scalar( $value ) );
			return;
		}
		if ( ! $this->open_start_element ) {
			throw new \LogicException(
				__( 'A sitemap XML attribute must follow an opening element.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal writer-state exception; it is not emitted into XML or HTML.
			);
		}

		$this->output .= ' ' . $this->name( $name ) . '="'
			. $this->attribute( $this->scalar( $value ) ) . '"';
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Adapter API intentionally mirrors native XMLWriter.
	public function writeElement( string $name, mixed $content = '' ): void {
		if ( null !== $this->native ) {
			$this->native->writeElement( $name, $this->scalar( $content ) );
			return;
		}

		$this->close_start_element();
		$name          = $this->name( $name );
		$this->output .= '<' . $name . '>' . $this->text( $this->scalar( $content ) )
			. '</' . $name . '>';
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Adapter API intentionally mirrors native XMLWriter.
	public function endElement(): void {
		if ( null !== $this->native ) {
			$this->native->endElement();
			return;
		}

		$name = \array_pop( $this->elements );
		if ( ! \is_string( $name ) ) {
			throw new \LogicException(
				__( 'A sitemap XML element was closed without a matching opening element.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal writer-state exception; it is not emitted into XML or HTML.
			);
		}
		if ( $this->open_start_element ) {
			$this->output            .= '/>';
			$this->open_start_element = false;
			return;
		}

		$this->output .= '</' . $name . '>';
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Adapter API intentionally mirrors native XMLWriter.
	public function endDocument(): void {
		if ( null !== $this->native ) {
			$this->native->endDocument();
			return;
		}

		while ( ! empty( $this->elements ) ) {
			$this->endElement();
		}
	}

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Adapter API intentionally mirrors native XMLWriter.
	public function outputMemory(): string {
		if ( null !== $this->native ) {
			$output = $this->native->outputMemory();
			return \is_string( $output ) ? $output : '';
		}

		return $this->output;
	}

	private function close_start_element(): void {
		if ( $this->open_start_element ) {
			$this->output            .= '>';
			$this->open_start_element = false;
		}
	}

	private function name( string $name ): string {
		if (
			1 !== \preg_match(
				'/\A[A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?\z/D',
				$name
			)
		) {
			throw new \InvalidArgumentException(
				__( 'A sitemap XML element or attribute name is invalid.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation exception; it is not emitted into XML or HTML.
			);
		}

		return $name;
	}

	private function scalar( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}

	private function attribute( string $value ): string {
		return \htmlspecialchars(
			$value,
			ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,
			'UTF-8'
		);
	}

	private function text( string $value ): string {
		return \htmlspecialchars(
			$value,
			ENT_XML1 | ENT_SUBSTITUTE,
			'UTF-8'
		);
	}
}
