<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Final validation and emission boundary for non-admin response protocols.
 */
final class ProtocolOutput {
	/** Encode a public JSON payload without presentation-only flags. */
	public static function json( mixed $payload ): string {
		$encoded = \wp_json_encode( $payload );
		return \is_string( $encoded ) ? $encoded : '{}';
	}

	/** Validate a complete XML or RSS document before emission/publication. */
	public static function xml( string $document ): string {
		$document = self::valid_utf8( $document );
		if ( 1 === \preg_match( '/<!\s*(?:DOCTYPE|ENTITY)\b/i', $document ) ) {
			throw new \UnexpectedValueException( 'Unsafe XML declaration.' );
		}
		return $document;
	}

	/** Validate Markdown or plain-text output before emission/publication. */
	public static function text( string $document ): string {
		return \str_replace( "\0", '', self::valid_utf8( $document ) );
	}

	/** Validate a spreadsheet-safe CSV document before emission. */
	public static function csv( string $document ): string {
		return \str_replace( "\0", '', self::valid_utf8( $document ) );
	}

	/**
	 * Apply the deliberately narrow standalone-report HTML contract.
	 */
	public static function report_html( string $document ): string {
		$document = self::valid_utf8( $document );
		$document = \preg_replace( '/\A<!doctype html>/i', '', $document ) ?? '';
		return '<!doctype html>' . \wp_kses( $document, self::report_allowed_html(), array( 'http', 'https' ) );
	}

	/** Exact standalone-report element and attribute contract. */
	private static function report_allowed_html(): array {
		return array(
			'html'    => array( 'lang' => true ),
			'head'    => array(),
			'meta'    => array(
				'charset' => true,
				'name'    => true,
				'content' => true,
			),
			'title'   => array(),
			'link'    => array(
				'rel'   => true,
				'id'    => true,
				'href'  => true,
				'media' => true,
			),
			'body'    => array( 'class' => true ),
			'main'    => array( 'class' => true ),
			'header'  => array( 'class' => true ),
			'footer'  => array( 'class' => true ),
			'section' => array( 'class' => true ),
			'div'     => array( 'class' => true ),
			'p'       => array( 'class' => true ),
			'h1'      => array( 'class' => true ),
			'h2'      => array( 'class' => true ),
			'span'    => array( 'class' => true ),
			'strong'  => array( 'class' => true ),
			'small'   => array( 'class' => true ),
			'code'    => array( 'class' => true ),
			'table'   => array( 'class' => true ),
			'thead'   => array(),
			'tbody'   => array(),
			'tr'      => array(),
			'th'      => array(
				'colspan' => true,
				'scope'   => true,
			),
			'td'      => array( 'colspan' => true ),
			'a'       => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			),
			'img'     => array(
				'src' => true,
				'alt' => true,
			),
		);
	}

	/**
	 * Emit only content that has passed the matching contextual validator.
	 */
	public static function emit( string $document, string $protocol ): void {
		if ( 'html' === $protocol ) {
			echo '<!doctype html>';
			echo \wp_kses( self::report_body( $document ), self::report_allowed_html(), array( 'http', 'https' ) );
			return;
		}
		if ( 'json' === $protocol ) {
			echo \wp_json_encode( self::json_value( $document ) );
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Non-HTML protocol sink only; final contextual validation and producer escaping are reviewed in docs/dev/protocol-output-review.md and wporg-source-allowlist.json.
		echo self::non_html_document( $document, $protocol );
	}

	/** HTML and JSON cannot enter this byte-preserving protocol boundary. */
	private static function non_html_document( string $document, string $protocol ): string {
		return match ( $protocol ) {
			'xml'  => self::xml( $document ),
			'csv'  => self::csv( $document ),
			'text' => self::text( $document ),
			default => throw new \InvalidArgumentException( 'Unknown output protocol.' ),
		};
	}

	private static function report_body( string $document ): string {
		return \preg_replace( '/\A<!doctype html>/i', '', self::valid_utf8( $document ) ) ?? '';
	}

	/** Decode without converting JSON objects into arrays; encoding occurs at the sink. */
	private static function json_value( string $document ): mixed {
		try {
			return json_decode( self::valid_utf8( $document ), false, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new \UnexpectedValueException( 'Protocol output is not valid JSON.' );
		}
	}

	private static function valid_utf8( string $document ): string {
		if ( 1 !== \preg_match( '//u', $document ) ) {
			throw new \UnexpectedValueException( 'Protocol output is not valid UTF-8.' );
		}
		return $document;
	}
}
