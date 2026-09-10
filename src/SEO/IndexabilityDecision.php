<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable effective indexability result.
 */
final class IndexabilityDecision {
	/**
	 * @param bool         $indexable     Whether the source resource may be published.
	 * @param string[]     $reasons       Stable blocking reason codes.
	 * @param string       $canonical_url Effective canonical URL.
	 * @param string       $redirect_url  Effective redirect URL.
	 * @param SeoSignals[] $signals       Adapter evidence.
	 */
	public function __construct(
		public readonly bool $indexable,
		public readonly array $reasons = array(),
		public readonly string $canonical_url = '',
		public readonly string $redirect_url = '',
		public readonly array $signals = array()
	) {}

	/**
	 * Return a new decision with additional reasons.
	 *
	 * @param string[] $reasons Blocking reasons.
	 */
	public function with_reasons( array $reasons ): self {
		$merged = array_values( array_unique( array_filter( array_merge( $this->reasons, $reasons ) ) ) );

		return new self(
			empty( $merged ),
			$merged,
			$this->canonical_url,
			$this->redirect_url,
			$this->signals
		);
	}

	/**
	 * Serialize the effective decision as stable audit evidence.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'indexable'     => $this->indexable,
			'reasons'       => $this->reasons,
			'canonical_url' => $this->canonical_url,
			'redirect_url'  => $this->redirect_url,
			'signals'       => array_map(
				static fn( SeoSignals $signal ): array => array(
					'source'      => $signal->source,
					'noindex'     => $signal->noindex,
					'nofollow'    => $signal->nofollow,
					'noarchive'   => $signal->noarchive,
					'canonical'   => $signal->canonical,
					'redirect'    => $signal->redirect,
					'title'       => $signal->title,
					'description' => $signal->description,
					'details'     => $signal->details,
				),
				$this->signals
			),
		);
	}
}
