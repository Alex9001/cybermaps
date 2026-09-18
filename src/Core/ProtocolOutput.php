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
		$allowed  = array(
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

		return '<!doctype html>' . \wp_kses( $document, $allowed, array( 'http', 'https' ) );
	}

	/**
	 * Emit only content that has passed the matching contextual validator.
	 */
	public static function emit( string $document, string $protocol ): void {
		$output = match ( $protocol ) {
			'json' => self::json_document( $document ),
			'html' => self::report_html( $document ),
			'xml'  => self::xml( $document ),
			'csv'  => self::csv( $document ),
			'text' => self::text( $document ),
			default => throw new \InvalidArgumentException( 'Unknown output protocol.' ),
		};

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed protocol boundary; output is contextually validated above and tracked by wporg-source-allowlist.json.
		echo $output;
	}

	private static function json_document( string $document ): string {
		$document = self::valid_utf8( $document );
		json_decode( $document, true, 512 );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \UnexpectedValueException( 'Protocol output is not valid JSON.' );
		}
		return $document;
	}

	private static function valid_utf8( string $document ): string {
		if ( 1 !== \preg_match( '//u', $document ) ) {
			throw new \UnexpectedValueException( 'Protocol output is not valid UTF-8.' );
		}
		return $document;
	}
}
