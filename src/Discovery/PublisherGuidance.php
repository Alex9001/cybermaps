<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the publisher-authored guidance shared by discovery publications.
 *
 * This value is published as descriptive guidance. It is not elevated to a
 * system prompt or mixed into structured formats whose schemas have no place
 * for publisher instructions.
 */
final class PublisherGuidance {

	/**
	 * Get the shared guidance from a supplied settings snapshot or WordPress.
	 *
	 * @param array<string, mixed>|null $settings Settings snapshot.
	 */
	public static function get( ?array $settings = null ): string {
		if ( null === $settings ) {
			$settings = \Cybermaps\Core\ConfigurationStore::settings();
		}

		return self::normalize(
			PublicationConstraints::bounded_text(
				$settings['llms_custom_instructions'] ?? '',
				PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH
			),
			PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH
		);
	}

	/**
	 * Get the optional guidance published only in the Site Guide.
	 *
	 * @param array<string, mixed>|null $settings Settings snapshot.
	 */
	public static function get_site_guide_guidance( ?array $settings = null ): string {
		if ( null === $settings ) {
			$settings = \Cybermaps\Core\ConfigurationStore::settings();
		}

		return self::normalize(
			PublicationConstraints::bounded_text(
				$settings['site_guide_instructions'] ?? '',
				PublicationConstraints::SITE_GUIDE_ADDITION_MAX_LENGTH
			),
			PublicationConstraints::SITE_GUIDE_ADDITION_MAX_LENGTH
		);
	}

	private static function normalize( string $guidance, int $maximum ): string {
		$guidance = sanitize_textarea_field( $guidance );
		$guidance = str_replace( array( "\r\n", "\r" ), "\n", trim( $guidance ) );
		return PublicationConstraints::bounded_text( $guidance, $maximum );
	}
}
