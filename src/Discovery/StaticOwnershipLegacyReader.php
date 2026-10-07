<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded decoder for the retired ownership option's serialized array contract.
 *
 * The reader accepts only strings and nonnegative integers inside the exact
 * ownership record shape. It never invokes PHP's general object decoder.
 */
final class StaticOwnershipLegacyReader {
	private const CHUNK_BYTES = 65536;

	/** @var \Closure(int,int):?string */
	private \Closure $read;
	private string $buffer = '';
	private int $position;
	private int $length;
	private int $buffer_start = -1;

	/** @param callable(int,int):?string $read Zero-based bounded byte reader. */
	public function __construct( callable $read, int $length, int $position = 0 ) {
		$this->read     = \Closure::fromCallable( $read );
		$this->length   = max( 0, $length );
		$this->position = max( 0, $position );
	}

	public function position(): int {
		return $this->position;
	}

	/** Return the declared number of entries in the outer array. */
	public function begin(): ?int {
		return $this->number_token( 'a:', ':{' );
	}

	/** Verify the exact end of the outer array and source bytes. */
	public function finish(): bool {
		return $this->consume( '}' ) && $this->position === $this->length;
	}

	/** @return array{path:string,hash:string,generation:int}|null */
	public function next( int $schema, int $generation ): ?array {
		$path = $this->string_token( StaticOwnershipStore::MAX_PATH_LENGTH );
		if ( null === $path || '' === $path ) {
			return null;
		}
		$record = 0 === $schema ? $this->legacy_record( $generation ) : $this->shard_record();
		if ( null === $record ) {
			return null;
		}
		return array( 'path' => $path ) + $record;
	}

	/** @return array{hash:string,generation:int}|null */
	private function legacy_record( int $generation ): ?array {
		$hash = $this->string_token( 32 );
		return self::valid_hash( $hash ) ? array(
			'hash'       => strtolower( $hash ),
			'generation' => $generation,
		) : null;
	}

	/** @return array{hash:string,generation:int}|null */
	private function shard_record(): ?array {
		if ( 's:' === $this->peek( 2 ) ) {
			return $this->legacy_record( 0 );
		}
		if ( ! $this->consume( 'a:2:{' ) ) {
			return null;
		}
		$record = array();
		for ( $field = 0; $field < 2; ++$field ) {
			$key = $this->string_token( 10 );
			if ( ! in_array( $key, array( 'hash', 'generation' ), true ) || array_key_exists( $key, $record ) ) {
				return null;
			}
			$record[ $key ] = 'hash' === $key ? $this->string_token( 32 ) : $this->number_token( 'i:', ';' );
		}
		if ( ! $this->consume( '}' ) || ! self::valid_hash( $record['hash'] ?? null ) || ! is_int( $record['generation'] ?? null ) ) {
			return null;
		}
		return array(
			'hash'       => strtolower( $record['hash'] ),
			'generation' => $record['generation'],
		);
	}

	private static function valid_hash( mixed $hash ): bool {
		return is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{32}$/iD', $hash );
	}

	private function string_token( int $maximum ): ?string {
		$length = $this->number_token( 's:', ':"' );
		if ( null === $length || $length > $maximum ) {
			return null;
		}
		$value = $this->peek( $length );
		if ( null === $value ) {
			return null;
		}
		$this->position += $length;
		return $this->consume( '";' ) ? $value : null;
	}

	private function number_token( string $prefix, string $suffix ): ?int {
		if ( ! $this->consume( $prefix ) ) {
			return null;
		}
		$digits  = '';
		$maximum = strlen( (string) PHP_INT_MAX );
		for ( $index = 0; $index <= $maximum; ++$index ) {
			$byte = $this->peek( 1 );
			if ( null === $byte || ! ctype_digit( $byte ) ) {
				break;
			}
			$digits .= $byte;
			++$this->position;
		}
		if ( '' === $digits || (string) (int) $digits !== $digits || ! $this->consume( $suffix ) ) {
			return null;
		}
		return (int) $digits;
	}

	private function consume( string $expected ): bool {
		if ( $this->peek( strlen( $expected ) ) !== $expected ) {
			return false;
		}
		$this->position += strlen( $expected );
		return true;
	}

	private function peek( int $length ): ?string {
		if ( $length < 0 || $this->position + $length > $this->length ) {
			return null;
		}
		$offset = $this->position - $this->buffer_start;
		if ( $offset < 0 || $offset + $length > strlen( $this->buffer ) ) {
			$chunk = ( $this->read )( $this->position, min( self::CHUNK_BYTES, $this->length - $this->position ) );
			if ( ! is_string( $chunk ) || strlen( $chunk ) < $length || strlen( $chunk ) > self::CHUNK_BYTES ) {
				return null;
			}
			$this->buffer       = $chunk;
			$this->buffer_start = $this->position;
			$offset             = 0;
		}
		return substr( $this->buffer, $offset, $length );
	}
}
