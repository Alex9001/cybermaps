<?php
declare(strict_types=1);

namespace Cybermaps\Admin\Settings\Sanitizers;

use Cybermaps\Core\IdentityEntityBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Exact bounded identity transport, separate from legacy read normalization. */
final class IdentitySubmission {
	public static function decode( mixed $input ): ?array {
		return SettingsSubmission::decode( $input, self::schema() );
	}

	public static function import_record( array $input ): ?array {
		return SettingsSubmission::import_record( $input, self::schema() );
	}

	private static function text( int $maximum = IdentityEntityBuilder::MAX_TEXT_LENGTH ): array {
		// Permit a bounded amount of pasted markup/whitespace before normalization.
		return array(
			'type' => 'text',
			'max'  => 4 * $maximum,
		);
	}

	private static function collection( int $maximum, array $value ): array {
		return array(
			'type'  => 'list',
			'max'   => 2 * $maximum,
			'value' => $value,
		);
	}

	private static function schema(): array {
		$schema = array_fill_keys(
			array( 'type', 'precise_type', 'name', 'address', 'city', 'address_region', 'postal_code', 'address_country' ),
			self::text()
		);
		return $schema + array(
			'description'     => self::text( IdentityEntityBuilder::MAX_DESCRIPTION_LENGTH ),
			'image_id'        => array( 'type' => 'id' ),
			'phone'           => self::text( IdentityEntityBuilder::MAX_PHONE_LENGTH ),
			'email'           => self::text( IdentityEntityBuilder::MAX_EMAIL_LENGTH ),
			'latitude'        => array(
				'type'  => 'number',
				'empty' => true,
			),
			'longitude'       => array(
				'type'  => 'number',
				'empty' => true,
			),
			'hours'           => self::hours(),
			'catalogs'        => self::collection( IdentityEntityBuilder::MAX_CATALOGS, self::catalog() ),
			'social_profiles' => self::collection( IdentityEntityBuilder::MAX_SOCIAL_PROFILES, self::text( IdentityEntityBuilder::MAX_URL_LENGTH ) ),
			'contact_points'  => self::collection( IdentityEntityBuilder::MAX_CONTACT_POINTS, self::contact() ),
		);
	}

	private static function hours(): array {
		return array(
			'type'   => 'record',
			'fields' => array_fill_keys(
				array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ),
				self::collection(
					IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY,
					array(
						'type'   => 'record',
						'fields' => array(
							'open'  => self::text( 16 ),
							'close' => self::text( 16 ),
						),
					)
				)
			),
		);
	}

	private static function catalog(): array {
		return array(
			'type'   => 'record',
			'fields' => array(
				'mode'      => self::text( 32 ),
				'item_type' => self::text( 32 ),
				'name'      => self::text(),
				'parent_id' => array( 'type' => 'id' ),
				'items'     => self::collection( IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG, self::text() ),
			),
		);
	}

	private static function contact(): array {
		return array(
			'type'   => 'record',
			'fields' => array(
				'type'  => self::text(),
				'phone' => self::text( IdentityEntityBuilder::MAX_PHONE_LENGTH ),
				'email' => self::text( IdentityEntityBuilder::MAX_EMAIL_LENGTH ),
			),
		);
	}
}
