<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\MCP\Protocol;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Constructs the one Core OAuth service used by routes and publications. */
final class OAuthServiceFactory {
	public static function create(): OAuthService {
		return new OAuthService(
			new WpdbOAuthRepository(),
			new WordPressUserAuthorizer(),
			new ClientRegistrationValidator(),
			\trailingslashit( \home_url( '/' ) ),
			\rest_url( EndpointRegistry::REST_NAMESPACE . Protocol::ROUTE ),
			\rest_url( EndpointRegistry::REST_NAMESPACE . '/oauth/authorize' ),
			\rest_url( EndpointRegistry::REST_NAMESPACE . '/oauth/token' ),
			\rest_url( EndpointRegistry::REST_NAMESPACE . '/oauth/revoke' )
		);
	}
}
