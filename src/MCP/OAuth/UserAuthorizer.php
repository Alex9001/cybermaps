<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates the WordPress user binding carried by an OAuth grant. */
interface UserAuthorizer {
	/** @param string[] $scopes */
	public function assert_scopes_allowed( int $user_id, array $scopes ): void;
}
