<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit per-content-type rules captured with every audit run.
 */
final class AuditPolicy {
	/**
	 * @param array<string, array{min_words:int,max_age_days:int,require_media:bool}> $rules
	 */
	public function __construct( private readonly array $rules ) {}

	public static function from_settings( ?array $settings = null ): self {
		$settings = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();

		return new self(
			array(
				'post' => array(
					'min_words'     => self::bounded_int( $settings['audit_post_min_words'] ?? 300, 1, 10000 ),
					'max_age_days'  => self::bounded_int( $settings['audit_post_max_age_days'] ?? 365, 0, 36500 ),
					'require_media' => ! isset( $settings['audit_post_require_media'] )
						|| '0' !== (string) $settings['audit_post_require_media'],
				),
				'page' => array(
					'min_words'     => self::bounded_int( $settings['audit_page_min_words'] ?? 150, 1, 10000 ),
					'max_age_days'  => self::bounded_int( $settings['audit_page_max_age_days'] ?? 0, 0, 36500 ),
					'require_media' => ! empty( $settings['audit_page_require_media'] ),
				),
			)
		);
	}

	/**
	 * @return array{min_words:int,max_age_days:int,require_media:bool}
	 */
	public function for_post_type( string $post_type ): array {
		return $this->rules[ $post_type ] ?? array(
			'min_words'     => 150,
			'max_age_days'  => 0,
			'require_media' => false,
		);
	}

	/**
	 * @return array<string, array{min_words:int,max_age_days:int,require_media:bool}>
	 */
	public function to_array(): array {
		return $this->rules;
	}

	public function hash(): string {
		return hash( 'sha256', (string) wp_json_encode( $this->rules ) );
	}

	private static function bounded_int( mixed $value, int $minimum, int $maximum ): int {
		return max( $minimum, min( $maximum, abs( (int) $value ) ) );
	}
}
