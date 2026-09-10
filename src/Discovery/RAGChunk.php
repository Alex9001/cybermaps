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

	public function __construct( ?AIContentSelector $selector = null, ?Chunker $chunker = null ) {
		$this->selector = $selector ?? new AIContentSelector();
		$this->chunker  = $chunker ?? new Chunker();
	}

	/**
	 * Serve /discovery/chunks/{post_id}.json.
	 */
	public function handle(): void {
		$path = (string) \Cybermaps\Core\URLManager::get_request_path();
		if ( 1 !== \preg_match( '#^/discovery/chunks/([1-9][0-9]*)\.json$#', $path, $matches ) ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
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
		Integrity::send_headers( $output, 3600 );
		\header( 'Content-Type: application/json; charset=utf-8' );

		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
		exit;
	}

	/**
	 * Resolve an authorized chunk payload without exposing excluded content.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_payload( int $post_id ): ?array {
		if ( ! $this->selector->contains( $post_id ) ) {
			return null;
		}

		$chunks = $this->chunker->get_chunks( $post_id );
		return \is_array( $chunks ) && ! empty( $chunks ) ? $chunks : null;
	}

	/**
	 * Serialize the same payload used by dynamic and static delivery.
	 */
	public function get_content( int $post_id ): ?string {
		$payload = $this->get_payload( $post_id );
		return $this->encode_payload( $payload );
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

		$output = \wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! \is_string( $output ) ) {
			throw new \RuntimeException(
				__( 'A RAG chunk publication could not be encoded as JSON.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception-only diagnostic; JSON or escaped admin consumers own the eventual output boundary.
			);
		}

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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '{"error":"not_found"}';
		}
		exit;
	}
}
