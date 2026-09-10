<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fail-closed resolver for the optional user-claimed OAuth registration mode. */
final class AgentRegistrationMode {
	public const OFF          = 'off';
	public const USER_CLAIMED = 'user_claimed';

	/** @param array<string,mixed>|null $settings General settings snapshot. */
	public static function resolve( ?array $settings = null ): string {
		$settings = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
		return self::USER_CLAIMED === ( $settings['agent_registration_mode'] ?? self::OFF )
			? self::USER_CLAIMED
			: self::OFF;
	}

	/** @param array<string,mixed>|null $settings General settings snapshot. */
	public static function is_user_claimed( ?array $settings = null ): bool {
		return self::USER_CLAIMED === self::resolve( $settings );
	}
}
