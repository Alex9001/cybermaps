<?php
declare(strict_types=1);

namespace Cybermaps\Tests\MCP;

use Cybermaps\MCP\OAuth\AgentRegistrationMode;
use Cybermaps\MCP\OAuth\AuthMd;
use Cybermaps\MCP\OAuth\OAuthService;
use PHPUnit\Framework\TestCase;

final class AuthMdTest extends TestCase {
	public function test_registration_mode_is_fail_closed_and_defaults_off(): void {
		$this->assertSame( AgentRegistrationMode::OFF, AgentRegistrationMode::resolve( array() ) );
		$this->assertSame( AgentRegistrationMode::OFF, AgentRegistrationMode::resolve( array( 'agent_registration_mode' => 'unexpected' ) ) );
		$this->assertSame( AgentRegistrationMode::USER_CLAIMED, AgentRegistrationMode::resolve( array( 'agent_registration_mode' => 'user_claimed' ) ) );
	}

	public function test_auth_markdown_is_available_only_for_user_claimed_oauth(): void {
		$handler = new AuthMd();
		$this->assertSame( '', $handler->get_content( array( 'agent_registration_mode' => 'off' ) ) );
		$this->assertSame( '', $handler->get_content( array( 'agent_registration_mode' => 'user_claimed' ) ) );

		$content = $handler->get_content(
			array(
				'agent_registration_mode' => 'user_claimed',
				'enable_discovery_hub'    => '1',
				'mcp_mode'                 => 'read_only',
			)
		);
		$this->assertStringContainsString( 'HTTPS Client ID Metadata Document', $content );
		$this->assertStringContainsString( '/oauth/device-authorization', $content );
		$this->assertStringContainsString( '/cybermaps-agent-auth', $content );
		$this->assertStringContainsString( OAuthService::DEVICE_GRANT_TYPE, $content );
		$this->assertStringContainsString( 'explicitly approve', $content );
		$this->assertStringContainsString( 'does not provide OpenID Connect, ID tokens, or JWKS', $content );
		$this->assertStringNotContainsString( 'Dynamic Client Registration endpoint is available', $content );
	}
}
