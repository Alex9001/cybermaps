<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rechecks the WordPress capability required by each OAuth scope. */
final class WordPressUserAuthorizer implements UserAuthorizer {
	/** @var array<string, string> */
	private array $capabilities;

	/**
	 * @param array<string, string> $capabilities Optional scope-to-capability overrides.
	 */
	public function __construct( array $capabilities = array() ) {
		$this->capabilities = array_merge(
			array(
				'cybermaps:read'              => 'read',
				'cybermaps:abilities:execute' => 'manage_options',
				'cybermaps:audit'             => 'manage_options',
				'cybermaps:publish'           => 'manage_options',
				'cybermaps:purge'             => 'manage_options',
			),
			$capabilities
		);
	}

	/** @param string[] $scopes */
	public function assert_scopes_allowed( int $user_id, array $scopes ): void {
		if ( $user_id < 1 ) {
			throw new OAuthException( 'access_denied', 'A WordPress user is required.', 403 );
		}

		foreach ( $scopes as $scope ) {
			$capability = $this->capabilities[ $scope ] ?? '';
			if ( '' === $capability ) {
				throw new OAuthException( 'invalid_scope', 'The requested scope is not supported.' );
			}

			$allowed = \function_exists( 'user_can' )
				? \user_can( $user_id, $capability )
				: \current_user_can( $capability );
			if ( ! $allowed ) {
				throw new OAuthException( 'access_denied', 'The WordPress user no longer has the required permission.', 403 );
			}
		}
	}
}
