<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic fallback for advertised RAG chunk publications.
 */
class RAGChunk {

	private AIContentSelector $selector;

	private Chunker $chunker;
	private bool $default_selector;

	public function __construct( ?AIContentSelector $selector = null, ?Chunker $chunker = null ) {
		$this->default_selector = null === $selector;
		$this->selector         = $selector ?? new AIContentSelector();
		$this->chunker          = $chunker ?? new Chunker();
	}

	/**
	 * Serve /discovery/chunks/{post_id}.json.
	 */
	public function handle(): void {
		$path = (string) \Cybermaps\Core\URLManager::get_request_path();
		if ( 1 !== \preg_match( '#^/discovery/chunks/([1-9][0-9]*)\.json$#', $path, $matches ) ) {
			return;
		}

		$generation = \Cybermaps\Core\CacheManager::get_generation( 'discovery', true );
		try {
			$this->require_current_generation( $generation );
			$settings = \Cybermaps\Core\ConfigurationStore::publication_settings();
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			PublicationRequestGuard::serve_unavailable( $error );
		}
		if (
			empty( $settings['enable_discovery_hub'] )
			|| empty( $settings['enable_rag_chunks'] )
		) {
			return;
		}

		PublicationRequestGuard::enforce_active_route();

		$post_id = (int) $matches[1];
		$output  = null;

		try {
			$output = $this->get_content( $post_id );
			$this->require_current_generation( $generation );
		} catch ( PublicationSizeLimitException $error ) {
			PublicationRequestGuard::serve_size_limit_error( $error );
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			PublicationRequestGuard::serve_unavailable( $error );
		} catch ( \Throwable ) {
			\status_header( 500 );
			\nocache_headers();
			\header( 'Content-Type: application/json; charset=utf-8' );
			exit;
		}

		if ( null === $output ) {
			$this->send_not_found();
		}

		// The body also depends on chunk and publication settings, so the post's
		// modification date alone is not an authoritative Last-Modified value.
		try {
			Integrity::send_headers( $output, HOUR_IN_SECONDS, null, $generation );
			$this->require_current_generation( $generation );
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			PublicationRequestGuard::serve_unavailable( $error );
		}
		\header( 'Content-Type: application/json; charset=utf-8' );

		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			\Cybermaps\Core\ProtocolOutput::emit( $output, 'json' );
		}
		exit;
	}

	/**
	 * Resolve an authorized chunk payload without exposing excluded content.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_payload( int $post_id ): ?array {
		$generation = \Cybermaps\Core\CacheManager::get_generation( 'discovery', true );
		$this->require_current_generation( $generation );
		$settings = \Cybermaps\Core\ConfigurationStore::publication_settings();
		\Cybermaps\Core\ConfigurationStore::publication_discovery();
		if ( $this->default_selector ) {
			$this->selector = new AIContentSelector( $settings );
		}
		if ( ! $this->selector->contains( $post_id ) ) {
			$this->require_current_generation( $generation );
			return null;
		}

		$chunks = $this->chunker->get_chunks( $post_id );
		$this->require_current_generation( $generation );
		return \is_array( $chunks ) && ! empty( $chunks ) ? $chunks : null;
	}

	/**
	 * Serialize the same payload used by dynamic and static delivery.
	 */
	public function get_content( int $post_id ): ?string {
		$generation = \Cybermaps\Core\CacheManager::get_generation( 'discovery', true );
		$this->require_current_generation( $generation );
		$payload = $this->get_payload( $post_id );
		$output  = $this->encode_payload( $payload );
		$this->require_current_generation( $generation );
		return $output;
	}

	/** Authorization, chunk construction and serialization share one privacy fence. */
	private function require_current_generation( int $generation ): void {
		if ( $generation < 0 || \Cybermaps\Core\CacheManager::get_generation( 'discovery', true ) !== $generation ) {
			throw new \Cybermaps\Core\BuildUnavailableException( esc_html__( 'Cybermaps content changed during RAG publication. Please retry shortly.', 'cybermaps' ) );
		}
	}

	/**
	 * Serialize a post already selected by the bounded static inventory scanner.
	 *
	 * The caller must supply an ID returned by AIContentSelector::get_id_batch().
	 * This avoids rebuilding the complete inventory merely to authorize each item
	 * in the same trusted synchronization operation.
	 */
	public function get_content_for_selected_post( int $post_id ): ?string {
		$chunks  = $post_id > 0 ? $this->chunker->get_chunks( $post_id ) : null;
		$payload = \is_array( $chunks ) && ! empty( $chunks ) ? $chunks : null;
		return $this->encode_payload( $payload );
	}

	/**
	 * @param array<string,mixed>|null $payload
	 */
	private function encode_payload( ?array $payload ): ?string {
		if ( null === $payload ) {
			return null;
		}

		PublicationSizeLimitException::require_value_capacity( $payload, 'chunks.json', Chunker::MAX_OUTPUT_BYTES );
		$output = \wp_json_encode( $payload );
		if ( ! \is_string( $output ) ) {
			throw new \RuntimeException(
				__( 'A RAG chunk publication could not be encoded as JSON.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception-only diagnostic; JSON or escaped admin consumers own the eventual output boundary.
			);
		}

		PublicationSizeLimitException::require_capacity( strlen( $output ), 'chunks.json', Chunker::MAX_OUTPUT_BYTES );
		return $output;
	}

	/**
	 * Return one indistinguishable response for disabled, excluded, private, and
	 * nonexistent content so the endpoint cannot be used as a content oracle.
	 */
	private function send_not_found(): never {
		\status_header( 404 );
		\nocache_headers();
		\header( 'Content-Type: application/json; charset=utf-8' );

		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			\Cybermaps\Core\ProtocolOutput::emit( '{"error":"not_found"}', 'json' );
		}
		exit;
	}
}
