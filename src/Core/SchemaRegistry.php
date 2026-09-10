<?php
declare(strict_types=1);
namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for Schema.org types used in Identity Hub.
 */
class SchemaRegistry {

	/**
	 * Resolve a compatible Schema.org type from the broad and precise choices.
	 *
	 * A Person cannot use an organization/business subtype, and an organization
	 * cannot be narrowed to Person. Unknown precise values fall back to the
	 * configured broad type instead of publishing an invalid @type.
	 *
	 * @param array<string, mixed> $identity_data Configured identity values.
	 */
	public static function get_entity_type( array $identity_data ): string {
		$primary_type = is_scalar( $identity_data['type'] ?? null )
			? (string) $identity_data['type']
			: 'Organization';
		if ( ! in_array( $primary_type, array( 'Organization', 'LocalBusiness', 'Person' ), true ) ) {
			$primary_type = 'Organization';
		}

		$precise_type = is_scalar( $identity_data['precise_type'] ?? null )
			? (string) $identity_data['precise_type']
			: '';
		if ( '' === $precise_type || ! in_array( $precise_type, self::get_types(), true ) ) {
			return $primary_type;
		}

		if ( 'Person' === $primary_type ) {
			return 'Person';
		}

		if ( 'Person' === $precise_type ) {
			return $primary_type;
		}

		if (
			'LocalBusiness' === $primary_type
			&& ! in_array( $precise_type, self::get_local_business_types(), true )
		) {
			return 'LocalBusiness';
		}

		return $precise_type;
	}

	/**
	 * Return the stable fragment used to identify the configured primary entity.
	 *
	 * The primary type is the authoritative broad classification. Precise types
	 * are subtypes and must not turn a configured person into an organization
	 * identifier (or vice versa).
	 *
	 * @param array<string, mixed> $identity_data Configured identity values.
	 */
	public static function get_entity_fragment( array $identity_data ): string {
		return 'Person' === self::get_entity_type( $identity_data ) ? 'person' : 'organization';
	}

	/**
	 * Get a list of common Schema.org types for Organization and LocalBusiness.
	 *
	 * @return array
	 */
	public static function get_types() {
		return array(
			'Organization',
			'LocalBusiness',
			'Person',
			'Corporation',
			'Airline',
			'EducationalOrganization',
			'GovernmentOrganization',
			'MedicalOrganization',
			'NGO',
			'SportsOrganization',
			'ProfessionalService',
			'LegalService',
			'AccountingService',
			'RealEstateAgent',
			'MedicalBusiness',
			'Dentist',
			'GeneralContractor',
			'HomeAndConstructionBusiness',
			'AutomotiveBusiness',
			'Restaurant',
			'CafeOrCoffeeShop',
			'BarOrPub',
			'Hotel',
			'LodgingBusiness',
			'Store',
			'ShoppingCenter',
		);
	}

	/**
	 * Determine whether a resolved entity type belongs to LocalBusiness.
	 */
	public static function is_local_business_type( string $type ): bool {
		return in_array( $type, self::get_local_business_types(), true );
	}

	/**
	 * Return the supported LocalBusiness family used for compatibility checks.
	 *
	 * @return string[]
	 */
	private static function get_local_business_types(): array {
		return array(
			'LocalBusiness',
			'ProfessionalService',
			'LegalService',
			'AccountingService',
			'RealEstateAgent',
			'MedicalBusiness',
			'Dentist',
			'GeneralContractor',
			'HomeAndConstructionBusiness',
			'AutomotiveBusiness',
			'Restaurant',
			'CafeOrCoffeeShop',
			'BarOrPub',
			'Hotel',
			'LodgingBusiness',
			'Store',
			'ShoppingCenter',
		);
	}
}
