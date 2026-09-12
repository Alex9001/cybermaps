<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Content\VisibleTextExtractor;
use Cybermaps\Core\TranslationHelper;
use Cybermaps\Core\URLManager;
use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds bounded Markdown summaries for public WordPress collection views.
 */
final class MarkdownCollection {
	public const ITEM_LIMIT     = 100;
	private const SCAN_LIMIT    = 500;
	private const SUMMARY_WORDS = 55;

	/**
	 * @param object[]            $posts Main-query post objects.
	 * @param array<string,mixed> $settings Settings snapshot.
	 */
	public function render(
		string $title,
		string $source,
		array $posts,
		int $page,
		string $previous_url,
		string $next_url,
		array $settings
	): string {
		$output    = $this->header( $title, $source, $page );
		$items     = 0;
		$truncated = count( $posts ) > self::SCAN_LIMIT;
		$scanned   = array_slice( $posts, 0, self::SCAN_LIMIT );

		foreach ( $scanned as $post ) {
			if ( ! $this->is_eligible_post( $post, $settings ) ) {
				continue;
			}
			if ( self::ITEM_LIMIT <= $items ) {
				$truncated = true;
				break;
			}
			$entry = $this->entry( $post );
			if ( LLMS::OUTPUT_MAX_BYTES < strlen( $output . $entry ) ) {
				$truncated = true;
				break;
			}
			$output .= $entry;
			++$items;
		}

		if ( 0 === $items ) {
			$output .= __( 'No eligible public items are present on this collection page.', 'cybermaps' ) . "\n\n";
		}
		if ( $truncated ) {
			$output .= '> ' . __( 'This representation was truncated at its bounded collection limit. Use the canonical page and pagination links for the remaining items.', 'cybermaps' ) . "\n\n";
		}

		return $this->append_navigation( $output, $previous_url, $next_url );
	}

	private function header( string $title, string $source, int $page ): string {
		$title    = $this->markdown_text( $title );
		$language = TranslationHelper::normalize_hreflang( (string) get_locale() );
		$output   = '# ' . ( '' !== $title ? $title : __( 'Content collection', 'cybermaps' ) ) . "\n\n";
		$output  .= '- Source: ' . $source . "\n";
		$output  .= "- Content-Type: collection\n";
		if ( '' !== $language ) {
			$output .= '- Language: ' . $language . "\n";
		}
		$output .= '- Page: ' . max( 1, $page ) . "\n\n## Content\n\n";
		return $output;
	}

	/**
	 * @param array<string,mixed> $settings Settings snapshot.
	 */
	private function is_eligible_post( mixed $post, array $settings ): bool {
		return is_object( $post )
			&& (int) ( $post->ID ?? 0 ) > 0
			&& ( new PublicationEligibility( null, $settings ) )->post( $post, PublicationEligibility::AI )->indexable;
	}

	private function entry( object $post ): string {
		$post_id   = (int) $post->ID;
		$title     = $this->markdown_text( (string) get_the_title( $post_id ) );
		$permalink = URLManager::rewrite_url( (string) get_permalink( $post_id ) );
		$output    = '### [' . ( '' !== $title ? $title : __( 'Untitled resource', 'cybermaps' ) ) . '](' . $permalink . ")\n\n";
		$output   .= '- Content-Type: ' . sanitize_key( (string) ( $post->post_type ?? 'post' ) ) . "\n";
		$output   .= $this->date_line( 'Published', (string) ( $post->post_date_gmt ?? '' ) );
		$output   .= $this->date_line( 'Modified', (string) ( $post->post_modified_gmt ?? '' ) );
		$summary   = $this->summary( $post );
		return $output . ( '' !== $summary ? "\n" . $summary . "\n\n" : "\n" );
	}

	private function date_line( string $label, string $date ): string {
		if ( '' === $date || '0000-00-00 00:00:00' === $date ) {
			return '';
		}
		$timestamp = strtotime( $date . ' UTC' );
		return false === $timestamp ? '' : '- ' . $label . ': ' . gmdate( 'c', $timestamp ) . "\n";
	}

	private function summary( object $post ): string {
		return ( new VisibleTextExtractor() )->summary( $post, self::SUMMARY_WORDS );
	}

	private function append_navigation( string $output, string $previous_url, string $next_url ): string {
		if ( '' === $previous_url && '' === $next_url ) {
			return $output;
		}
		$output .= "## Navigation\n\n";
		if ( '' !== $previous_url ) {
			$output .= '- Previous: ' . $previous_url . "\n";
		}
		if ( '' !== $next_url ) {
			$output .= '- Next: ' . $next_url . "\n";
		}
		return $output;
	}

	private function markdown_text( string $text ): string {
		$text = preg_replace( '/\s+/u', ' ', trim( $text ) ) ?? trim( $text );
		return str_replace( array( '\\', '[', ']', '*', '`' ), array( '\\\\', '\\[', '\\]', '\\*', '\\`' ), $text );
	}
}
