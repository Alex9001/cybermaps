<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Exact, bounded input contract shared by AJAX and direct plan callers. */
final class SetupWizardRequestSanitizer {
	private const ANSWER_KEYS           = array(
		'ai_visibility',
		'identity_description',
		'identity_image_id',
		'identity_name',
		'identity_type',
		'operations',
		'website_type',
	);
	private const MAX_NAME_BYTES        = 256;
	private const MAX_DESCRIPTION_BYTES = 8192;

	/** @param array<string,mixed> $request @return array<string,mixed> */
	public static function sanitize( array $request ): array {
		self::validate_request_shape( $request );
		$answers = $request['answers'];
		$choices = SetupWizardRegistry::choices();
		foreach ( array( 'website_type', 'ai_visibility', 'operations', 'identity_type' ) as $key ) {
			if ( ! array_key_exists( $answers[ $key ], $choices[ $key ] ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Quick Setup contains an unsupported choice.', 'cybermaps' ) );
			}
		}
		return array(
			'wizard_version' => $request['wizard_version'],
			'answers'        => array(
				'website_type'         => $answers['website_type'],
				'ai_visibility'        => $answers['ai_visibility'],
				'operations'           => $answers['operations'],
				'identity_type'        => $answers['identity_type'],
				'identity_name'        => sanitize_text_field( $answers['identity_name'] ),
				'identity_description' => sanitize_textarea_field( $answers['identity_description'] ),
				'identity_image_id'    => $answers['identity_image_id'],
			),
		);
	}

	/** @param array<string,mixed> $request */
	private static function validate_request_shape( array $request ): void {
		$keys = array_keys( $request );
		sort( $keys, SORT_STRING );
		if ( array( 'answers', 'wizard_version' ) !== $keys || ! is_int( $request['wizard_version'] ?? null ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The Quick Setup request has an invalid schema.', 'cybermaps' ) );
		}
		$answers = $request['answers'];
		if ( ! is_array( $answers ) || array_is_list( $answers ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Quick Setup answers must be an object.', 'cybermaps' ) );
		}
		$answer_keys = array_keys( $answers );
		sort( $answer_keys, SORT_STRING );
		if ( self::ANSWER_KEYS !== $answer_keys ) {
			throw new \InvalidArgumentException( esc_html__( 'Quick Setup answers do not match the documented schema.', 'cybermaps' ) );
		}
		foreach ( array( 'website_type', 'ai_visibility', 'operations', 'identity_type', 'identity_name', 'identity_description' ) as $key ) {
			if ( ! is_string( $answers[ $key ] ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Quick Setup answer types are invalid.', 'cybermaps' ) );
			}
		}
		if (
			strlen( $answers['identity_name'] ) > self::MAX_NAME_BYTES
			|| strlen( $answers['identity_description'] ) > self::MAX_DESCRIPTION_BYTES
			|| ! is_int( $answers['identity_image_id'] )
			|| $answers['identity_image_id'] < 0
		) {
			throw new \InvalidArgumentException( esc_html__( 'Quick Setup answer values exceed their documented bounds.', 'cybermaps' ) );
		}
	}
}
