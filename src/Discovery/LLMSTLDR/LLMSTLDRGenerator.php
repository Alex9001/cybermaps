<?php
declare(strict_types=1);

namespace Cybermaps\Discovery\LLMSTLDR;

use Cybermaps\Content\VisibleTextExtractor;
use Cybermaps\Core\URLManager;
use Cybermaps\Discovery\PublicationInventory;
use Cybermaps\Discovery\PublicationScanBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds Cybermaps' experimental, literal budgeted site briefing.
 */
final class LLMSTLDRGenerator {
	private PublicationInventory $inventory;
	private VisibleTextExtractor $extractor;

	public function __construct(
		?PublicationInventory $inventory = null,
		?VisibleTextExtractor $extractor = null
	) {
		$this->inventory = $inventory ?? new PublicationInventory();
		$this->extractor = $extractor ?? new VisibleTextExtractor();
	}

	/**
	 * Generate a complete publication while accounting for every emitted byte.
	 *
	 * @return array{
	 *   output:string,
	 *   body:string,
	 *   token_estimate:int,
	 *   token_budget:int,
	 *   eligible_count:int,
	 *   candidate_upper_bound:int,
	 *   scanned_count:int,
	 *   scan_truncated:bool,
	 *   selected_count:int,
	 *   omitted_count:int,
	 *   partial_count:int
	 * }
	 */
	public function generate_publication( array $settings, string $site_name ): array {
		$stored_budget  = $settings['llms_tldr_token_budget'] ?? null;
		$budget         = is_scalar( $stored_budget ) && is_numeric( $stored_budget )
			? max(
				\Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MIN,
				min(
					\Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MAX,
					(int) $stored_budget
				)
			)
			: \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_DEFAULT;
		$eligible_upper = $this->inventory->published_candidate_upper_bound();
		$pinned_ids     = $this->pinned_ids( $settings['llms_pinned_ids'] ?? '' );
		$eligible_count = 0;
		$selected_count = 0;

		$scan_limit    = \Cybermaps\Discovery\PublicationConstraints::BRIEFING_CANDIDATE_SCAN_MAX;
		$scan_metadata = $this->render_scan_metadata( min( $eligible_upper, $scan_limit ), $eligible_upper, $eligible_upper > $scan_limit );
		$body          = '';
		$scan          = new PublicationScanBudget( $scan_limit );

		foreach ( $this->ordered_posts( $pinned_ids, $scan ) as $post ) {
			++$eligible_count;
			$entry         = $this->render_entry( $post );
			$next_selected = $selected_count + 1;
			$next_omitted  = max( 0, $eligible_upper - $next_selected );
			$next_header   = $this->render_header(
				$settings,
				$site_name,
				$budget,
				$eligible_upper,
				$next_selected,
				$next_omitted
			);

			if (
				TokenBudget::estimate_bytes(
					strlen( $next_header ) + strlen( $scan_metadata ) + strlen( $body ) + strlen( $entry )
				) > $budget
			) {
				continue;
			}

			$selected_count = $next_selected;
			$body          .= $entry;
		}

		$header = $this->render_header(
			$settings,
			$site_name,
			$budget,
			$eligible_count,
			$selected_count,
			max( 0, $eligible_count - $selected_count )
		);
		$scan->checkpoint();
		$scanned_count  = $scan->scanned();
		$scan_truncated = $scan->truncated();

		$header = $header . $this->render_scan_metadata( $scanned_count, $eligible_upper, $scan_truncated );
		if ( '' === $body ) {
			$empty = "No entries fit the configured budget, or no eligible content is published.\n";
			if ( TokenBudget::estimate_tokens( $header . $empty ) <= $budget ) {
				$body = $empty;
			}
		}

		$output = $header . $body;

		return array(
			'output'                => $output,
			'body'                  => $body,
			'token_estimate'        => TokenBudget::estimate_tokens( $output ),
			'token_budget'          => $budget,
			'eligible_count'        => $eligible_count,
			'candidate_upper_bound' => $eligible_upper,
			'scanned_count'         => $scanned_count,
			'scan_truncated'        => $scan_truncated,
			'selected_count'        => $selected_count,
			'omitted_count'         => max( 0, $eligible_count - $selected_count ),
			'partial_count'         => 0,
		);
	}

	private function render_scan_metadata( int $scanned, int $upper_bound, bool $truncated ): string {
		return sprintf(
			"> Candidate-Scan: scanned %d of at most %d candidates; truncated %s\n\n",
			$scanned,
			$upper_bound,
			$truncated ? 'yes' : 'no'
		);
	}

	/**
	 * Yield bounded priority resources first, then lazily traverse the remaining
	 * complete inventory without collecting it in memory.
	 *
	 * @param int[] $pinned_ids Priority post IDs.
	 * @return \Generator<int, object>
	 */
	private function ordered_posts( array $pinned_ids, PublicationScanBudget $scan ): \Generator {
		foreach ( $this->inventory->iterate_posts_by_ids( $pinned_ids, $scan ) as $post ) {
			$post_id = (int) ( $post->ID ?? 0 );
			if ( $post_id < 1 ) {
				continue;
			}
			yield $post;
		}

		if ( $scan->truncated() ) {
			return;
		}

		// Skip the complete bounded priority list. Ineligible or missing IDs would
		// not be emitted by the regular iterator anyway.
		foreach ( $this->inventory->iterate_posts( $pinned_ids, $scan ) as $post ) {
			yield $post;
		}
	}

	/**
	 * Normalize the administrator's priority list to a small deterministic set.
	 *
	 * @return int[]
	 */
	private function pinned_ids( mixed $value ): array {
		$values = is_array( $value )
			? $value
			: explode( ',', is_scalar( $value ) ? (string) $value : '' );
		$ids    = array();
		foreach ( $values as $candidate ) {
			$id = is_scalar( $candidate ) ? absint( $candidate ) : 0;
			if ( $id < 1 || in_array( $id, $ids, true ) ) {
				continue;
			}
			$ids[] = $id;
			if ( count( $ids ) >= \Cybermaps\Discovery\PublicationConstraints::BRIEFING_PINNED_IDS_MAX ) {
				break;
			}
		}

		return $ids;
	}

	private function render_header(
		array $settings,
		string $site_name,
		int $budget,
		int $eligible,
		int $selected,
		int $omitted
	): string {
		$output  = '# ' . $this->plain_line( $site_name ) . " — Budgeted Site Briefing\n";
		$output .= "> Profile: Cybermaps Budgeted Site Briefing\n";
		$output .= "> Format-Version: 0.2-draft\n";
		$output .= "> Status: Experimental vendor proposal\n";
		$output .= "> Known-Automatic-Consumers: none documented\n";
		$output .= "> Selection-Method: pinned IDs, then configured content-type order, last-modified descending, ID ascending; entries that do not fit are omitted\n";
		$output .= "> Content-Extraction: stored visible text; shortcodes and dynamic blocks are not executed\n";
		$output .= "> Token-Estimate-Method: ceil(UTF-8 bytes / 4); approximate\n";
		$output .= '> Token-Budget: ' . $budget . "\n";
		$output .= sprintf(
			"> Coverage: selected %d of %d eligible; omitted %d; partial 0\n",
			$selected,
			$eligible,
			$omitted
		);

		$license = \Cybermaps\Discovery\PublicationConstraints::content_license(
			$settings['llms_content_license'] ?? ''
		);
		if ( '' !== $license ) {
			$output .= '> License-Assertion: ' . $this->plain_line( $license ) . "\n";
		}

		return $output . "\n";
	}

	private function render_entry( object $post ): string {
		$title    = $this->plain_line( (string) get_the_title( $post ) );
		$url      = URLManager::rewrite_url( (string) get_permalink( $post ) );
		$modified = (string) ( $post->post_modified_gmt ?? $post->post_date_gmt ?? '' );
		$summary  = $this->extractor->summary( $post, 60 );

		$output  = '## ' . $title . "\n\n";
		$output .= '- URL: ' . $url . "\n";
		$output .= '- Content-Type: ' . sanitize_key( (string) ( $post->post_type ?? 'post' ) ) . "\n";
		if ( '' !== $modified && '0000-00-00 00:00:00' !== $modified ) {
			$output .= '- Last-Modified: ' . gmdate( 'c', strtotime( $modified . ' UTC' ) ) . "\n";
		}
		$output .= '- Extract: ' . ( '' !== $summary ? str_replace( "\n", ' ', $summary ) : '[No visible stored text]' ) . "\n\n";

		return $output;
	}

	private function plain_line( string $value ): string {
		return preg_replace( '/\s+/u', ' ', trim( $value ) ) ?? trim( $value );
	}
}
