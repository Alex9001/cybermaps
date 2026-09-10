<?php
/**
 * Related-resource suggestions for recognized-bot 404 responses.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds literal WordPress search suggestions to a problem-details 404.
 */
class NotFoundSuggestions {

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'template_redirect', array( $this, 'handle_404' ), 1 );
	}

	/**
	 * Handle 404 errors for AI agents.
	 */
	public function handle_404() {
		if ( ! is_404() || ! Integrity::is_hub_enabled() ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_scalar( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		if ( empty( $ua ) ) {
			return;
		}

		$bot_id = \Cybermaps\Core\CrawlerRegistry::identify_bot( $ua );
		if ( ! $bot_id ) {
			// Also check if it's a generic AI request
			if ( stripos( $ua, 'bot' ) === false && stripos( $ua, 'spider' ) === false && stripos( $ua, 'crawler' ) === false ) {
				return;
			}
		}
		\Cybermaps\Core\ReadOnlyRequest::enforce();

		$path = \Cybermaps\Core\URLManager::get_request_path();
		$slug = PublicationConstraints::bounded_text(
			sanitize_text_field( rawurldecode( trim( basename( $path ), '/' ) ) ),
			200
		);

		// Find literal WordPress text-search alternatives.
		$alternatives = $this->find_alternatives( $slug );

		$error_doc = $this->get_problem_document( $path, $alternatives );

		status_header( 404 );
		nocache_headers();
		header( 'Vary: User-Agent', false );
		header( 'Content-Type: application/problem+json; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_json_encode( $error_doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}
		exit;
	}

	/**
	 * Build the generic RFC 9457 problem document for a crawler-facing 404.
	 *
	 * @param array<int, array{url:string,title:string}> $alternatives Eligible alternatives.
	 * @return array<string, mixed>
	 */
	public function get_problem_document( string $path, array $alternatives ): array {
		$error_doc = array(
			'type'     => 'about:blank',
			'title'    => __( 'Not Found', 'cybermaps' ),
			'status'   => 404,
			'detail'   => sprintf(
				/* translators: %s: requested URL path. */
				__( 'The requested resource at %s does not exist or has been moved.', 'cybermaps' ),
				$path
			),
			'instance' => \Cybermaps\Core\URLManager::get_home_url( $path ),
		);

		if ( ! empty( $alternatives ) ) {
			$error_doc['suggested_alternatives'] = $alternatives;
			$error_doc['detail']                .= ' ' . __( 'Related eligible resources are listed in suggested_alternatives.', 'cybermaps' );
		}

		return $error_doc;
	}

	/**
	 * Find eligible literal text-search alternatives for a slug.
	 *
	 * @param string $slug The requested slug.
	 * @return array
	 */
	private function find_alternatives( $slug ) {
		if ( empty( $slug ) ) {
			return array();
		}

		// Convert a dashed slug to a normal WordPress text-search query.
		$parts        = explode( '-', $slug );
		$search_query = implode( ' ', $parts );

		$settings  = \Cybermaps\Core\ConfigurationStore::settings();
		$inventory = new PublicationInventory( $settings );
		if ( ! $inventory->has_included_post_types() ) {
			return array();
		}

		$args = $inventory->get_query_args(
			array(
				's'              => $search_query,
				'posts_per_page' => 10,
			)
		);

		$query        = new \WP_Query( $args );
		$alternatives = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post = isset( $query->post ) && is_object( $query->post )
					? $query->post
					: null;
				if (
					! is_object( $post )
					|| ! ( new PublicationEligibility( null, $settings ) )->post( $post, PublicationEligibility::AI )->indexable
				) {
					continue;
				}
				$alternatives[] = array(
					'url'   => \Cybermaps\Core\URLManager::rewrite_url( (string) get_permalink( (int) $post->ID ) ),
					'title' => get_the_title( (int) $post->ID ),
				);
				if ( count( $alternatives ) >= 3 ) {
					break;
				}
			}
			wp_reset_postdata();
		}

		return $alternatives;
	}
}
