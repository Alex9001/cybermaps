<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

use Cybermaps\Content\VisibleTextExtractor;
use Cybermaps\Core\URLManager;
use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts one stored post into measurements and reproducible findings.
 */
final class ContentAuditEvaluator {
	private VisibleTextExtractor $extractor;
	private PublicationEligibility $eligibility;

	public function __construct(
		?VisibleTextExtractor $extractor = null,
		?PublicationEligibility $eligibility = null
	) {
		$this->extractor   = $extractor ?? new VisibleTextExtractor();
		$this->eligibility = $eligibility ?? new PublicationEligibility();
	}

	/**
	 * @return array{
	 *   resource:array<string,mixed>,
	 *   findings:array<int,array<string,mixed>>
	 * }
	 */
	public function evaluate(
		object $post,
		AuditPolicy $policy,
		?int $now = null,
		?bool $has_attached_image = null
	): array {
		$now                = $now ?? time();
		$post_id            = (int) ( $post->ID ?? 0 );
		$post_type          = sanitize_key( (string) ( $post->post_type ?? 'post' ) );
		$text               = $this->extractor->from_post( $post );
		$words              = $this->extractor->word_count( $text );
		$rules              = $policy->for_post_type( $post_type );
		$modified           = (string) ( $post->post_modified_gmt ?? $post->post_date_gmt ?? '' );
		$modified_timestamp = '' !== $modified ? strtotime( $modified . ' UTC' ) : false;
		$age_days           = false === $modified_timestamp
			? null
			: max( 0, (int) floor( ( $now - $modified_timestamp ) / DAY_IN_SECONDS ) );
		$has_media          = $this->has_media( $post, $has_attached_image );
		$decision           = $this->eligibility->post( $post, PublicationEligibility::REPORT );
		$findings           = array();

		$this->add_thin_finding( $findings, $decision->indexable, $words, $rules );
		$this->add_stale_finding( $findings, $decision->indexable, $age_days, $modified, $rules );
		$this->add_media_finding( $findings, $decision->indexable, $has_media, $rules );

		$url = URLManager::rewrite_url( (string) get_permalink( $post_id ) );

		return array(
			'resource' => array(
				'resource_key' => sprintf(
					'post:%d:%d',
					function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
					$post_id
				),
				'object_type'  => 'post',
				'object_id'    => $post_id,
				'post_type'    => $post_type,
				'title'        => (string) ( $post->post_title ?? get_the_title( $post_id ) ),
				'url'          => $url,
				'modified_gmt' => $modified,
				'word_count'   => $words,
				'age_days'     => $age_days,
				'has_media'    => $has_media,
				'indexable'    => $decision->indexable,
				'indexability' => $decision->to_array(),
				'content_hash' => hash( 'sha256', $text ),
				'measurement'  => array(
					'extractor' => 'literal-visible-text-v1',
					'policy'    => $rules,
				),
			),
			'findings' => $findings,
		);
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function add_thin_finding( array &$findings, bool $indexable, int $words, array $rules ): void {
		if ( ! $indexable || $words >= $rules['min_words'] ) {
			return;
		}

		$measured_words = sprintf(
			/* translators: %d: visible word count. */
			_n( '%d word', '%d words', $words, 'cybermaps' ),
			$words
		);
		$minimum_words = sprintf(
			/* translators: %d: visible word count. */
			_n( '%d word', '%d words', $rules['min_words'], 'cybermaps' ),
			$rules['min_words']
		);
		$findings[] = array(
			'key'            => 'thin_content',
			'severity'       => 'warning',
			'summary'        => sprintf(
				/* translators: 1: localized measured word count, 2: localized minimum word count. */
				__( 'Visible text has %1$s; policy minimum is %2$s.', 'cybermaps' ),
				$measured_words,
				$minimum_words
			),
			'evidence'       => array(
				'actual_words'  => $words,
				'minimum_words' => $rules['min_words'],
				'measurement'   => 'literal_visible_stored_text',
			),
			'recommendation' => __( 'Review whether this resource needs more useful visible information, consolidation, or intentional exclusion.', 'cybermaps' ),
		);
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function add_stale_finding( array &$findings, bool $indexable, ?int $age_days, string $modified, array $rules ): void {
		if ( ! $indexable || $rules['max_age_days'] <= 0 || null === $age_days || $age_days <= $rules['max_age_days'] ) {
			return;
		}

		$content_age = sprintf(
			/* translators: %d: day count. */
			_n( '%d day', '%d days', $age_days, 'cybermaps' ),
			$age_days
		);
		$review_interval = sprintf(
			/* translators: %d: day count. */
			_n( '%d day', '%d days', $rules['max_age_days'], 'cybermaps' ),
			$rules['max_age_days']
		);
		$findings[] = array(
			'key'            => 'stale_content',
			'severity'       => 'review',
			'summary'        => sprintf(
				/* translators: 1: localized content age, 2: localized review interval. */
				__( 'Last modified %1$s ago; review interval is %2$s.', 'cybermaps' ),
				$content_age,
				$review_interval
			),
			'evidence'       => array(
				'age_days'          => $age_days,
				'maximum_days'      => $rules['max_age_days'],
				'last_modified_gmt' => $modified,
			),
			'recommendation' => __( 'Confirm the information remains accurate; update the resource only when a substantive change is warranted.', 'cybermaps' ),
		);
	}

	/** @param array<int,array<string,mixed>> $findings */
	private function add_media_finding( array &$findings, bool $indexable, bool $has_media, array $rules ): void {
		if ( ! $indexable || ! $rules['require_media'] || $has_media ) {
			return;
		}

		$findings[] = array(
			'key'            => 'missing_media',
			'severity'       => 'review',
			'summary'        => __( 'No featured image, attached image, or stored visual-media block or markup was detected.', 'cybermaps' ),
			'evidence'       => array(
				'require_media' => true,
				'has_media'     => false,
				'measurement'   => 'stored_content_and_wordpress_media',
			),
			'recommendation' => __( 'Add useful visual media only if it improves comprehension or delivery; decorative media is not required.', 'cybermaps' ),
		);
	}

	private function has_media( object $post, ?bool $has_attached_image = null ): bool {
		$post_id = (int) ( $post->ID ?? 0 );
		if ( absint( get_post_meta( $post_id, '_thumbnail_id', true ) ) > 0 ) {
			return true;
		}

		$content = (string) ( $post->post_content ?? '' );
		if ( 1 === preg_match( '/<(?:img|picture|video)\b|<!--\s*wp:(?:image|gallery|cover|media-text|video)\b/i', $content ) ) {
			return true;
		}

		if ( null !== $has_attached_image ) {
			return $has_attached_image;
		}

		if ( function_exists( 'get_attached_media' ) ) {
			$attachments = get_attached_media( 'image', $post_id );
			return is_array( $attachments ) && ! empty( $attachments );
		}

		return false;
	}
}
