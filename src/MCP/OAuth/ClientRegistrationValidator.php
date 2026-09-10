<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates administrator-created public clients and their metadata documents.
 *
 * This is deliberately not a Dynamic Client Registration implementation.
 */
final class ClientRegistrationValidator {
	/** @var string[] */
	private const SUPPORTED_SCOPES = array(
		'cybermaps:read',
		'cybermaps:abilities:execute',
		'cybermaps:audit',
		'cybermaps:publish',
		'cybermaps:purge',
	);

	public function __construct( private readonly bool $allow_http_development = false ) {
	}

	/**
	 * @param string[] $redirect_uris
	 * @param string[] $scopes
	 * @return array<string, mixed>
	 */
	public function validate_administrator_client(
		string $client_id,
		string $client_name,
		array $redirect_uris,
		array $scopes,
		string $metadata_uri = ''
	): array {
		$client_id = trim( $client_id );
		if ( '' === $client_id || strlen( $client_id ) > 191 ) {
			throw new OAuthException( 'invalid_client_metadata', 'The client ID must be between 1 and 191 bytes.' );
		}
		if ( '' === trim( $client_name ) || strlen( $client_name ) > 191 ) {
			throw new OAuthException( 'invalid_client_metadata', 'The client name must be between 1 and 191 bytes.' );
		}
		if ( empty( $redirect_uris ) || count( $redirect_uris ) > 20 ) {
			throw new OAuthException( 'invalid_client_metadata', 'A client must have between one and twenty redirect URIs.' );
		}

		$validated_redirects = array();
		foreach ( $redirect_uris as $redirect_uri ) {
			$redirect_uri = is_string( $redirect_uri ) ? $redirect_uri : '';
			$this->assert_redirect_uri( $redirect_uri );
			if ( isset( $validated_redirects[ $redirect_uri ] ) ) {
				throw new OAuthException( 'invalid_client_metadata', 'Redirect URIs must be unique.' );
			}
			$validated_redirects[ $redirect_uri ] = true;
		}

		if ( '' !== $metadata_uri ) {
			$this->assert_remote_metadata_uri( $metadata_uri );
		}

		return array(
			'client_id'     => $client_id,
			'client_name'   => trim( $client_name ),
			'redirect_uris' => array_keys( $validated_redirects ),
			'scopes'        => $this->normalize_scopes( $scopes ),
			'metadata_uri'  => $metadata_uri,
		);
	}

	/**
	 * Fetch a Client ID Metadata Document after an administrator selected its URL.
	 *
	 * @return array<string, mixed>
	 */
	public function validate_preapproved_metadata_document( string $client_id, string $metadata_uri ): array {
		$this->assert_remote_metadata_uri( $metadata_uri );
		$document = $this->fetch_metadata_document( $metadata_uri, $client_id );

		$redirect_uris = $document['redirect_uris'] ?? array();
		$scopes        = preg_split( '/\s+/', trim( (string) ( $document['scope'] ?? '' ) ) );
		return $this->validate_administrator_client(
			$client_id,
			(string) ( $document['client_name'] ?? $client_id ),
			is_array( $redirect_uris ) ? $redirect_uris : array(),
			is_array( $scopes ) ? $scopes : array(),
			$metadata_uri
		);
	}

	/** @return array<string,mixed> */
	private function fetch_metadata_document( string $metadata_uri, string $client_id ): array {
		$response = \wp_safe_remote_get(
			$metadata_uri,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 65536,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( \is_wp_error( $response ) || 200 !== \wp_remote_retrieve_response_code( $response ) ) {
			throw new OAuthException( 'invalid_client_metadata', 'The Client ID Metadata Document could not be retrieved.' );
		}
		$body     = is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
		$document = json_decode( $body, true );
		if ( ! is_array( $document ) || (string) ( $document['client_id'] ?? '' ) !== $client_id ) {
			throw new OAuthException( 'invalid_client_metadata', 'The Client ID Metadata Document is invalid.' );
		}
		return $document;
	}

	/** @return string[] */
	public function normalize_scopes( array $scopes ): array {
		$accepted = array();
		foreach ( $scopes as $scope ) {
			$scope = is_string( $scope ) ? trim( $scope ) : '';
			if ( ! in_array( $scope, self::SUPPORTED_SCOPES, true ) ) {
				throw new OAuthException( 'invalid_scope', 'The requested scope is not supported.' );
			}
			$accepted[ $scope ] = true;
		}
		if ( empty( $accepted ) ) {
			throw new OAuthException( 'invalid_scope', 'At least one scope is required.' );
		}

		return array_values( array_filter( self::SUPPORTED_SCOPES, static fn( string $scope ): bool => isset( $accepted[ $scope ] ) ) );
	}

	public function assert_registered_redirect_uri( array $client, string $redirect_uri ): void {
		$this->assert_redirect_uri( $redirect_uri );
		$registered = is_array( $client['redirect_uris'] ?? null ) ? $client['redirect_uris'] : array();
		foreach ( $registered as $registered_uri ) {
			if ( is_string( $registered_uri ) && hash_equals( $registered_uri, $redirect_uri ) ) {
				return;
			}
		}
		throw new OAuthException( 'invalid_request', 'The redirect URI is not registered for this client.' );
	}

	private function assert_redirect_uri( string $uri ): void {
		$parts = \wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['fragment'], $parts['user'], $parts['pass'] ) || empty( $parts['host'] ) ) {
			throw new OAuthException( 'invalid_client_metadata', 'A redirect URI must be an absolute URI without a fragment or credentials.' );
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' !== $scheme && ! ( $this->allow_http_development && 'http' === $scheme && $this->is_development_host( (string) $parts['host'] ) ) ) {
			throw new OAuthException( 'invalid_client_metadata', 'Redirect URIs must use HTTPS.' );
		}
	}

	private function assert_remote_metadata_uri( string $uri ): void {
		$parts = \wp_parse_url( $uri );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['fragment'], $parts['user'], $parts['pass'] ) || empty( $parts['host'] ) || ! \wp_http_validate_url( $uri ) ) {
			throw new OAuthException( 'invalid_client_metadata', 'Client metadata must use a publicly routable HTTPS URL.' );
		}
	}

	private function is_development_host( string $host ): bool {
		$host = strtolower( $host );
		return 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host || str_ends_with( $host, '.test' );
	}
}
