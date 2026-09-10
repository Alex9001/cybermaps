<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

use Cybermaps\Core\ConfigurationStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared taxonomy relevance policy for sitemap occupancy invalidation.
 */
final class PublicationTaxonomies {
	/**
	 * Taxonomies whose changes can affect sitemap or AI publication inventories.
	 *
	 * @return string[]
	 */
	public static function relevant_taxonomies( ?array $settings = null ): array {
		$settings   = $settings ?? ConfigurationStore::settings();
		$taxonomies = array();

		$taxonomies = self::enabled_public_taxonomies();

		if ( '' !== trim( (string) ( $settings['exclude_categories'] ?? '' ) ) ) {
			$taxonomies[] = 'category';
		}

		if ( '' !== trim( (string) ( $settings['ai_sitemap_exclude_terms'] ?? '' ) ) ) {
			$taxonomies = array_merge( $taxonomies, self::publication_type_taxonomies() );
		}

		return \array_values( \array_unique( $taxonomies ) );
	}

	private static function enabled_public_taxonomies(): array {
		$taxonomies = array();
		foreach ( \get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$taxonomy_object = \get_taxonomy( (string) $taxonomy );
			if ( \is_object( $taxonomy_object ) && ! empty( $taxonomy_object->public ) && PriorityEngine::calculate( ProviderIdentity::taxonomy( (string) $taxonomy ) ) > 0 ) {
				$taxonomies[] = (string) $taxonomy;
			}
		}
		return $taxonomies;
	}

	private static function publication_type_taxonomies(): array {
		$taxonomies = array();
		foreach ( \Cybermaps\Core\PublicationPostTypes::names() as $post_type ) {
			$post_type_object = \get_post_type_object( $post_type );
			if ( \is_object( $post_type_object ) && ! empty( $post_type_object->taxonomies ) ) {
				$taxonomies = array_merge( $taxonomies, (array) $post_type_object->taxonomies );
			}
		}
		return array_map( 'strval', $taxonomies );
	}

	/**
	 * Whether a taxonomy mutation can affect publication inventories.
	 */
	public static function is_relevant_taxonomy_change( string $taxonomy, string $action = 'edit' ): bool {
		$taxonomy = \sanitize_key( $taxonomy );
		if ( '' === $taxonomy ) {
			return false;
		}

		if ( 'delete' === $action && ! \taxonomy_exists( $taxonomy ) ) {
			return true;
		}

		return \in_array( $taxonomy, self::relevant_taxonomies(), true );
	}
}
