<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the shared Schema.org entity used on-page and in the knowledge graph.
 */
final class IdentityEntityBuilder {
	public const MAX_HOURS_SLOTS_PER_DAY = 4;
	public const MAX_SOCIAL_PROFILES     = 20;
	public const MAX_CONTACT_POINTS      = 12;
	public const MAX_CATALOGS            = 12;
	public const MAX_OFFERS_PER_CATALOG  = 50;
	public const MAX_OFFERS_TOTAL        = 200;
	public const MAX_TEXT_LENGTH         = 256;
	public const MAX_DESCRIPTION_LENGTH  = 8192;
	public const MAX_URL_LENGTH          = 2048;
	public const MAX_PHONE_LENGTH        = 64;
	public const MAX_EMAIL_LENGTH        = 254;

	/**
	 * @param array<string, mixed> $data          Sanitized identity settings.
	 * @param bool                 $fallback_name Use the site title when no
	 *                                            operator-authored name exists.
	 * @return array<string, mixed>
	 */
	public static function build( array $data, bool $fallback_name = false ): array {
		$type        = SchemaRegistry::get_entity_type( $data );
		$name        = self::text_value( $data['name'] ?? '' );
		$description = self::textarea_value( $data['description'] ?? '' );
		$entity      = array(
			'@type' => $type,
			'@id'   => URLManager::get_home_url( '/#' . SchemaRegistry::get_entity_fragment( $data ) ),
			'name'  => '' !== $name || ! $fallback_name
				? $name
				: self::text_value( get_bloginfo( 'name' ) ),
			'url'   => URLManager::get_home_url( '/' ),
		);
		if ( '' !== $description ) {
			$entity['description'] = $description;
		}

		self::add_image( $entity, $data, $type );
		self::add_address( $entity, $data );
		self::add_contact( $entity, $data );
		if ( SchemaRegistry::is_local_business_type( $type ) ) {
			self::add_geo( $entity, $data );
			self::add_hours( $entity, $data );
		}
		self::add_social_profiles( $entity, $data );
		self::add_contact_points( $entity, $data );
		self::add_catalogs( $entity, $data );

		return $entity;
	}

	private static function add_image( array &$entity, array $data, string $type ): void {
		$image_id = self::positive_id( $data['image_id'] ?? 0 );
		if (
			$image_id < 1
			|| ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $image_id ) )
		) {
			return;
		}

		$attachment_url = wp_get_attachment_url( $image_id );
		$image          = is_string( $attachment_url )
			? self::public_url( URLManager::rewrite_url( $attachment_url ) )
			: '';
		if ( '' !== $image ) {
			$entity['image'] = $image;
			if ( 'Person' !== $type ) {
				$entity['logo'] = $image;
			}
		}
	}

	private static function add_address( array &$entity, array $data ): void {
		$country = strtoupper( self::text_value( $data['address_country'] ?? '' ) );
		$fields  = array(
			'streetAddress'   => self::text_value( $data['address'] ?? '' ),
			'addressLocality' => self::text_value( $data['city'] ?? '' ),
			'addressRegion'   => self::text_value( $data['address_region'] ?? '' ),
			'postalCode'      => self::text_value( $data['postal_code'] ?? '' ),
			'addressCountry'  => 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
		);
		$fields  = array_filter( $fields, static fn( string $value ): bool => '' !== $value );
		if ( ! empty( $fields ) ) {
			$entity['address'] = array( '@type' => 'PostalAddress' ) + $fields;
		}
	}

	private static function add_contact( array &$entity, array $data ): void {
		$phone = self::short_text_value( $data['phone'] ?? '', self::MAX_PHONE_LENGTH );
		$email = self::email_value( $data['email'] ?? '' );
		if ( '' !== $phone ) {
			$entity['telephone'] = $phone;
		}
		if ( '' !== $email ) {
			$entity['email'] = $email;
		}
	}

	private static function add_geo( array &$entity, array $data ): void {
		$latitude  = self::text_value( $data['latitude'] ?? '' );
		$longitude = self::text_value( $data['longitude'] ?? '' );
		if (
			'' !== $latitude
			&& '' !== $longitude
			&& is_numeric( $latitude )
			&& is_numeric( $longitude )
			&& (float) $latitude >= -90.0
			&& (float) $latitude <= 90.0
			&& (float) $longitude >= -180.0
			&& (float) $longitude <= 180.0
		) {
			$entity['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $latitude,
				'longitude' => $longitude,
			);
		}
	}

	private static function add_social_profiles( array &$entity, array $data ): void {
		$profiles        = array();
		$stored_profiles = $data['social_profiles'] ?? array();
		$stored_profiles = is_array( $stored_profiles )
			? array_slice( $stored_profiles, 0, self::MAX_SOCIAL_PROFILES )
			: array();
		foreach ( $stored_profiles as $profile ) {
			$url = self::public_url( $profile );
			if ( '' !== $url ) {
				$profiles[] = $url;
			}
		}
		if ( ! empty( $profiles ) ) {
			$entity['sameAs'] = array_values( array_unique( $profiles ) );
		}
	}

	private static function add_contact_points( array &$entity, array $data ): void {
		$allowed       = array( 'Customer Support', 'Technical Support', 'Sales' );
		$points        = array();
		$stored_points = $data['contact_points'] ?? array();
		$stored_points = is_array( $stored_points )
			? array_slice( $stored_points, 0, self::MAX_CONTACT_POINTS )
			: array();
		foreach ( $stored_points as $point ) {
			$item = self::contact_point( $point, $allowed );
			if ( null !== $item ) {
				$points[] = $item;
			}
		}
		if ( ! empty( $points ) ) {
			$entity['contactPoint'] = $points;
		}
	}

	private static function add_hours( array &$entity, array $data ): void {
		$day_map      = array(
			'monday'    => 'Mo',
			'tuesday'   => 'Tu',
			'wednesday' => 'We',
			'thursday'  => 'Th',
			'friday'    => 'Fr',
			'saturday'  => 'Sa',
			'sunday'    => 'Su',
		);
		$hours        = array();
		$stored_hours = $data['hours'] ?? array();
		$stored_hours = is_array( $stored_hours ) ? $stored_hours : array();
		foreach ( $day_map as $day => $abbreviation ) {
			$slots = $stored_hours[ $day ] ?? array();
			if ( ! is_array( $slots ) ) {
				continue;
			}
			$slots = array_slice( $slots, 0, self::MAX_HOURS_SLOTS_PER_DAY );
			foreach ( $slots as $slot ) {
				$hours_slot = self::opening_hours_slot( $slot, $abbreviation );
				if ( '' !== $hours_slot ) {
					$hours[] = $hours_slot;
				}
			}
		}
		if ( ! empty( $hours ) ) {
			$entity['openingHours'] = $hours;
		}
	}

	private static function add_catalogs( array &$entity, array $data ): void {
		$catalogs        = array();
		$offer_count     = 0;
		$stored_catalogs = $data['catalogs'] ?? array();
		$stored_catalogs = self::catalogs_value( $stored_catalogs );
		foreach ( $stored_catalogs as $catalog ) {
			if ( $offer_count >= self::MAX_OFFERS_TOTAL ) {
				break;
			}
			if ( ! is_array( $catalog ) ) {
				continue;
			}

			$mode        = self::catalog_mode( $catalog );
			$item_type   = self::catalog_item_type( $catalog['item_type'] ?? 'Service' );
			$offer_limit = min(
				self::MAX_OFFERS_PER_CATALOG,
				self::MAX_OFFERS_TOTAL - $offer_count
			);
			$items       = self::catalog_items( $catalog, $mode, $item_type, $offer_limit );

			if ( empty( $items ) ) {
				continue;
			}
			$offer_count  += count( $items );
			$name          = self::catalog_name( $catalog, $mode );
			$fallback_name = self::catalog_fallback_name( $item_type );
			$catalogs[]    = array(
				'@type'           => 'OfferCatalog',
				'name'            => '' !== $name ? $name : $fallback_name,
				'itemListElement' => $items,
			);
		}
		if ( ! empty( $catalogs ) ) {
			$entity['hasOfferCatalog'] = $catalogs;
		}
	}

	/** @return array{0?:array<string,string>}|null */
	private static function contact_point( mixed $point, array $allowed ): ?array {
		if ( ! is_array( $point ) ) {
			return null;
		}
		$type  = self::text_value( $point['type'] ?? '' );
		$phone = self::short_text_value( $point['phone'] ?? '', self::MAX_PHONE_LENGTH );
		$email = self::email_value( $point['email'] ?? '' );
		if ( ! in_array( $type, $allowed, true ) || ( '' === $phone && '' === $email ) ) {
			return null;
		}
		$item = array(
			'@type'       => 'ContactPoint',
			'contactType' => $type,
		);
		if ( '' !== $phone ) {
			$item['telephone'] = $phone;
		}
		if ( '' !== $email ) {
			$item['email'] = $email;
		}
		return $item;
	}

	private static function opening_hours_slot( mixed $slot, string $abbreviation ): string {
		$open  = is_array( $slot ) ? self::text_value( $slot['open'] ?? '' ) : '';
		$close = is_array( $slot ) ? self::text_value( $slot['close'] ?? '' ) : '';
		if ( 1 !== preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $open ) || 1 !== preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $close ) || $open === $close ) {
			return '';
		}
		return $abbreviation . ' ' . $open . '-' . $close;
	}

	private static function catalog_items( array $catalog, string $mode, string $item_type, int $limit ): array {
		return 'auto' === $mode ? self::automatic_catalog_items( $catalog, $item_type, $limit ) : self::manual_catalog_items( $catalog, $mode, $item_type, $limit );
	}

	private static function automatic_catalog_items( array $catalog, string $item_type, int $limit ): array {
		$parent_id = self::positive_id( $catalog['parent_id'] ?? 0 );
		$parent    = $parent_id > 0 ? get_post( $parent_id ) : null;
		if ( ! self::is_public_page( $parent ) ) {
			return array();
		}
		$children = get_pages(
			array(
				'parent'      => $parent_id,
				'post_status' => 'publish',
				'post_type'   => 'page',
				'sort_column' => 'menu_order',
				'number'      => $limit,
			)
		);
		$items    = array();
		foreach ( is_array( $children ) ? array_slice( $children, 0, $limit ) : array() as $child ) {
			$offer = self::catalog_offer( $child, $item_type );
			if ( null !== $offer ) {
				$items[] = $offer;
			}
		}
		return $items;
	}

	private static function catalog_offer( mixed $child, string $item_type ): ?array {
		if ( ! self::is_public_page( $child ) ) {
			return null;
		}
		$name      = self::text_value( $child->post_title ?? '' );
		$permalink = get_permalink( (int) $child->ID );
		$url       = is_string( $permalink ) ? self::public_url( URLManager::rewrite_url( $permalink ) ) : '';
		if ( '' === $name || '' === $url ) {
			return null;
		}
		return array(
			'@type'       => 'Offer',
			'itemOffered' => array(
				'@type' => $item_type,
				'name'  => $name,
				'url'   => $url,
			),
		);
	}

	private static function manual_catalog_items( array $catalog, string $mode, string $item_type, int $limit ): array {
		$stored_items = 'manual' === $mode && is_array( $catalog['items'] ?? null ) ? array_slice( $catalog['items'], 0, $limit ) : array();
		$items        = array();
		foreach ( $stored_items as $name ) {
			$name = self::text_value( $name );
			if ( '' !== $name ) {
				$items[] = array(
					'@type'       => 'Offer',
					'itemOffered' => array(
						'@type' => $item_type,
						'name'  => $name,
					),
				);
			}
		}
		return $items;
	}

	private static function catalog_name( array $catalog, string $mode ): string {
		$name = self::text_value( $catalog['name'] ?? '' );
		return '' !== $name || 'auto' !== $mode ? $name : self::text_value( get_the_title( self::positive_id( $catalog['parent_id'] ?? 0 ) ) );
	}

	private static function catalogs_value( mixed $value ): array {
		return is_array( $value ) ? array_slice( $value, 0, self::MAX_CATALOGS ) : array();
	}

	private static function catalog_mode( array $catalog ): string {
		return is_scalar( $catalog['mode'] ?? null ) ? sanitize_key( (string) $catalog['mode'] ) : '';
	}

	private static function catalog_fallback_name( string $item_type ): string {
		return 'Product' === $item_type ? __( 'Products', 'cybermaps' ) : __( 'Services', 'cybermaps' );
	}

	private static function text_value( mixed $value ): string {
		return self::short_text_value( $value, self::MAX_TEXT_LENGTH );
	}

	private static function short_text_value( mixed $value, int $maximum ): string {
		return \Cybermaps\Discovery\PublicationConstraints::bounded_text(
			is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '',
			$maximum
		);
	}

	private static function textarea_value( mixed $value ): string {
		return \Cybermaps\Discovery\PublicationConstraints::bounded_text(
			is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '',
			self::MAX_DESCRIPTION_LENGTH
		);
	}

	private static function positive_id( mixed $value ): int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return 0;
		}

		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return 0;
		}

		$id = (int) $value;
		return $id > 0 && (string) $id === $value ? $id : 0;
	}

	private static function catalog_item_type( mixed $value ): string {
		$type = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return in_array( $type, array( 'Service', 'Product' ), true ) ? $type : 'Service';
	}

	private static function email_value( mixed $value ): string {
		$email = \Cybermaps\Discovery\PublicationConstraints::bounded_text(
			$value,
			self::MAX_EMAIL_LENGTH
		);
		return '' !== $email ? sanitize_email( $email ) : '';
	}

	private static function public_url( mixed $value ): string {
		return URLManager::sanitize_http_url(
			\Cybermaps\Discovery\PublicationConstraints::bounded_text(
				$value,
				self::MAX_URL_LENGTH
			)
		);
	}

	private static function is_public_page( mixed $post ): bool {
		return is_object( $post )
			&& ! empty( $post->ID )
			&& 'page' === (string) ( $post->post_type ?? '' )
			&& 'publish' === (string) ( $post->post_status ?? '' )
			&& '' === (string) ( $post->post_password ?? '' );
	}
}
