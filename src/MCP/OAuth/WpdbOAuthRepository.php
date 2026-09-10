<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WordPress database implementation for OAuth client, grant, code, and token records. */
final class WpdbOAuthRepository implements OAuthRepository {
	public const SCHEMA_VERSION = '2';
	public const SCHEMA_OPTION  = 'cybermaps_mcp_oauth_schema_version';

	public static function create_tables(): void {
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return;
		}
		global $wpdb;
		$charset    = $wpdb->get_charset_collate();
		$clients    = $wpdb->prefix . 'cybermaps_mcp_oauth_clients';
		$grants     = $wpdb->prefix . 'cybermaps_mcp_oauth_grants';
		$codes      = $wpdb->prefix . 'cybermaps_mcp_oauth_codes';
		$tokens     = $wpdb->prefix . 'cybermaps_mcp_oauth_tokens';
		$devices    = $wpdb->prefix . 'cybermaps_mcp_oauth_devices';
		$statements = array(
			"CREATE TABLE $clients (\nclient_id varchar(191) NOT NULL,\nclient_name varchar(191) NOT NULL,\nredirect_uris longtext NOT NULL,\nscopes varchar(255) NOT NULL,\nmetadata_uri text NOT NULL,\ncreated_gmt datetime NOT NULL,\nPRIMARY KEY  (client_id)\n) $charset;",
			"CREATE TABLE $grants (\nid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nclient_id varchar(191) NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\nscopes varchar(255) NOT NULL,\ncreated_gmt datetime NOT NULL,\nupdated_gmt datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY client_user (client_id,user_id),\nKEY user_id (user_id)\n) $charset;",
			"CREATE TABLE $codes (\nid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\ncode_hash char(64) NOT NULL,\nclient_id varchar(191) NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\nredirect_uri text NOT NULL,\ncode_challenge varchar(128) NOT NULL,\nscopes varchar(255) NOT NULL,\naudience text NOT NULL,\nexpires_gmt datetime NOT NULL,\nconsumed_gmt datetime DEFAULT NULL,\ncreated_gmt datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY code_hash (code_hash),\nKEY expires_gmt (expires_gmt),\nKEY client_id (client_id)\n) $charset;",
			"CREATE TABLE $tokens (\nid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\ntoken_hash char(64) NOT NULL,\ntoken_type varchar(16) NOT NULL,\nfamily_id char(64) NOT NULL,\nclient_id varchar(191) NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\nscopes varchar(255) NOT NULL,\naudience text NOT NULL,\nexpires_gmt datetime NOT NULL,\nrevoked_gmt datetime DEFAULT NULL,\ncreated_gmt datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY token_hash (token_hash),\nKEY family_id (family_id),\nKEY expires_gmt (expires_gmt),\nKEY client_id (client_id),\nKEY user_id (user_id)\n) $charset;",
			"CREATE TABLE $devices (\nid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\ndevice_code_hash char(64) NOT NULL,\nuser_code_hash char(64) NOT NULL,\nclient_id varchar(191) NOT NULL,\nuser_id bigint(20) unsigned NOT NULL DEFAULT 0,\nscopes varchar(255) NOT NULL,\naudience text NOT NULL,\nstatus varchar(16) NOT NULL,\ninterval_seconds smallint(5) unsigned NOT NULL,\nlast_poll_gmt datetime DEFAULT NULL,\nexpires_gmt datetime NOT NULL,\ndecided_gmt datetime DEFAULT NULL,\nconsumed_gmt datetime DEFAULT NULL,\ncreated_gmt datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY device_code_hash (device_code_hash),\nUNIQUE KEY user_code_hash (user_code_hash),\nKEY expires_gmt (expires_gmt),\nKEY client_id (client_id)\n) $charset;",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $statements as $statement ) {
			$wpdb->last_error = '';
			\dbDelta( $statement );
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( 'Unable to create Cybermaps MCP OAuth storage.' );
			}
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function drop_tables(): void {
		global $wpdb;
		foreach ( array( 'cybermaps_mcp_oauth_devices', 'cybermaps_mcp_oauth_tokens', 'cybermaps_mcp_oauth_codes', 'cybermaps_mcp_oauth_grants', 'cybermaps_mcp_oauth_clients' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_option( self::SCHEMA_OPTION );
	}

	/** @param array<string, mixed> $client */
	public function save_client( array $client ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_clients';
		$data  = array(
			'client_id'     => (string) $client['client_id'],
			'client_name'   => (string) $client['client_name'],
			'redirect_uris' => (string) wp_json_encode( $client['redirect_uris'] ),
			'scopes'        => implode( ' ', (array) $client['scopes'] ),
			'metadata_uri'  => (string) ( $client['metadata_uri'] ?? '' ),
			'created_gmt'   => $this->gmt( time() ),
		);
		if ( false === $wpdb->replace( $table, $data ) ) {
			throw new \RuntimeException( 'Unable to save the OAuth client.' );
		}
	}

	/** @return array<string, mixed>|null */
	public function find_client( string $client_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_clients';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE client_id = %s', $table, $client_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$redirect_uris        = json_decode( (string) $row['redirect_uris'], true );
		$row['redirect_uris'] = is_array( $redirect_uris ) ? $redirect_uris : array();
		$row['scopes']        = $this->decode_scopes( (string) $row['scopes'] );
		return $row;
	}

	/** @param array<string, mixed> $code */
	public function save_authorization_code( array $code ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_codes';
		if ( false === $wpdb->insert( $table, $this->encode_code( $code ) ) ) {
			throw new \RuntimeException( 'Unable to save the OAuth authorization code.' );
		}
	}

	/** @return array<string, mixed>|null */
	public function find_authorization_code( string $code_hash ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_codes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE code_hash = %s', $table, $code_hash ), ARRAY_A );
		return is_array( $row ) ? $this->decode_code( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function consume_authorization_code( string $code_hash, int $now ): ?array {
		$row = $this->find_authorization_code( $code_hash );
		if ( null === $row ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_codes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET consumed_gmt = %s WHERE code_hash = %s AND consumed_gmt IS NULL AND expires_gmt > %s', $table, $this->gmt( $now ), $code_hash, $this->gmt( $now ) ) );
		return 1 === $updated ? $row : null;
	}

	/** @param array<string,mixed> $authorization */
	public function save_device_authorization( array $authorization ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_devices';
		if ( false === $wpdb->insert( $table, $this->encode_device_authorization( $authorization ) ) ) {
			throw new \RuntimeException( 'Unable to save the OAuth device authorization.' );
		}
	}

	/** @return array<string,mixed>|null */
	public function find_device_authorization( string $device_code_hash ): ?array {
		return $this->find_device_by_hash( 'device_code_hash', $device_code_hash );
	}

	/** @return array<string,mixed>|null */
	public function find_device_authorization_by_user_code( string $user_code_hash ): ?array {
		return $this->find_device_by_hash( 'user_code_hash', $user_code_hash );
	}

	public function update_device_poll( string $device_code_hash, int $polled_at, int $interval ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_devices';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET last_poll_gmt = %s, interval_seconds = %d WHERE device_code_hash = %s AND status = 'pending' AND expires_gmt > %s", $table, $this->gmt( $polled_at ), $interval, $device_code_hash, $this->gmt( $polled_at ) ) );
	}

	public function decide_device_authorization( string $user_code_hash, int $user_id, string $status, int $now ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_devices';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s, user_id = %d, decided_gmt = %s WHERE user_code_hash = %s AND status = 'pending' AND expires_gmt > %s", $table, $status, $user_id, $this->gmt( $now ), $user_code_hash, $this->gmt( $now ) ) );
		return 1 === $updated;
	}

	/** @return array<string,mixed>|null */
	public function consume_device_authorization( string $device_code_hash, int $now ): ?array {
		$record = $this->find_device_authorization( $device_code_hash );
		if ( ! is_array( $record ) ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_devices';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'consumed', consumed_gmt = %s WHERE device_code_hash = %s AND status = 'approved' AND consumed_gmt IS NULL AND expires_gmt > %s", $table, $this->gmt( $now ), $device_code_hash, $this->gmt( $now ) ) );
		return 1 === $updated ? $record : null;
	}

	public function delete_expired_device_authorizations( int $now ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_devices';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_gmt <= %s OR consumed_gmt IS NOT NULL', $table, $this->gmt( $now ) ) );
		return false === $deleted ? 0 : max( 0, (int) $deleted );
	}

	/** @param string[] $scopes */
	public function save_grant( string $client_id, int $user_id, array $scopes, int $now ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_grants';
		$data  = array(
			'client_id'   => $client_id,
			'user_id'     => $user_id,
			'scopes'      => implode( ' ', $scopes ),
			'created_gmt' => $this->gmt( $now ),
			'updated_gmt' => $this->gmt( $now ),
		);
		if ( false === $wpdb->replace( $table, $data ) ) {
			throw new \RuntimeException( 'Unable to save the OAuth grant.' );
		}
	}

	/** @param array<string, mixed> $token */
	public function save_token( array $token ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_tokens';
		if ( false === $wpdb->insert( $table, $this->encode_token( $token ) ) ) {
			throw new \RuntimeException( 'Unable to save the OAuth token.' );
		}
	}

	/** @return array<string, mixed>|null */
	public function find_token( string $token_hash ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', $table, $token_hash ), ARRAY_A );
		return is_array( $row ) ? $this->decode_token( $row ) : null;
	}

	/** @return array{status:string,token:array<string,mixed>|null} */
	public function consume_refresh_token( string $token_hash, int $now ): array {
		$token = $this->find_token( $token_hash );
		if ( null === $token || 'refresh' !== $token['token_type'] ) {
			return array(
				'status' => 'unknown',
				'token'  => null,
			);
		}
		if ( (int) $token['expires_at'] <= $now ) {
			return array(
				'status' => 'expired',
				'token'  => $token,
			);
		}
		if ( null !== $token['revoked_at'] ) {
			return array(
				'status' => 'replayed',
				'token'  => $token,
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_gmt = %s WHERE token_hash = %s AND revoked_gmt IS NULL', $table, $this->gmt( $now ), $token_hash ) );
		return 1 === $updated
			? array(
				'status' => 'active',
				'token'  => $token,
			)
			: array(
				'status' => 'replayed',
				'token'  => $token,
			);
	}

	public function revoke_token( string $token_hash, int $now ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_gmt = %s WHERE token_hash = %s AND revoked_gmt IS NULL', $table, $this->gmt( $now ), $token_hash ) );
	}

	public function revoke_family( string $family_id, int $now ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_gmt = %s WHERE family_id = %s AND revoked_gmt IS NULL', $table, $this->gmt( $now ), $family_id ) );
	}

	public function revoke_client_tokens( string $client_id, int $now ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_gmt = %s WHERE client_id = %s AND revoked_gmt IS NULL', $table, $this->gmt( $now ), $client_id ) );
	}

	/** @param array<string, mixed> $code @return array<string, mixed> */
	private function encode_code( array $code ): array {
		return array(
			'code_hash'      => (string) $code['code_hash'],
			'client_id'      => (string) $code['client_id'],
			'user_id'        => (int) $code['user_id'],
			'redirect_uri'   => (string) $code['redirect_uri'],
			'code_challenge' => (string) $code['code_challenge'],
			'scopes'         => implode( ' ', (array) $code['scopes'] ),
			'audience'       => (string) $code['audience'],
			'expires_gmt'    => $this->gmt( (int) $code['expires_at'] ),
			'created_gmt'    => $this->gmt( (int) $code['created_at'] ),
		);
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function decode_code( array $row ): array {
		$row['user_id']    = (int) $row['user_id'];
		$row['scopes']     = $this->decode_scopes( (string) $row['scopes'] );
		$expires_at        = strtotime( (string) $row['expires_gmt'] . ' UTC' );
		$row['expires_at'] = false === $expires_at ? 0 : $expires_at;
		return $row;
	}

	/** @return array<string,mixed>|null */
	private function find_device_by_hash( string $column, string $hash ): ?array {
		if ( ! in_array( $column, array( 'device_code_hash', 'user_code_hash' ), true ) ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'cybermaps_mcp_oauth_devices';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE $column = %s", $table, $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column is selected from the fixed allowlist above.
		return is_array( $row ) ? $this->decode_device_authorization( $row ) : null;
	}

	/** @param array<string,mixed> $authorization @return array<string,mixed> */
	private function encode_device_authorization( array $authorization ): array {
		return array(
			'device_code_hash' => (string) $authorization['device_code_hash'],
			'user_code_hash'   => (string) $authorization['user_code_hash'],
			'client_id'        => (string) $authorization['client_id'],
			'user_id'          => (int) $authorization['user_id'],
			'scopes'           => implode( ' ', (array) $authorization['scopes'] ),
			'audience'         => (string) $authorization['audience'],
			'status'           => (string) $authorization['status'],
			'interval_seconds' => (int) $authorization['interval'],
			'last_poll_gmt'    => null,
			'expires_gmt'      => $this->gmt( (int) $authorization['expires_at'] ),
			'decided_gmt'      => null,
			'consumed_gmt'     => null,
			'created_gmt'      => $this->gmt( (int) $authorization['created_at'] ),
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function decode_device_authorization( array $row ): array {
		$row['user_id']      = (int) $row['user_id'];
		$row['scopes']       = $this->decode_scopes( (string) $row['scopes'] );
		$row['interval']     = (int) $row['interval_seconds'];
		$expires_at          = strtotime( (string) $row['expires_gmt'] . ' UTC' );
		$row['expires_at']   = false === $expires_at ? 0 : $expires_at;
		$last_poll_at        = empty( $row['last_poll_gmt'] ) ? false : strtotime( (string) $row['last_poll_gmt'] . ' UTC' );
		$row['last_poll_at'] = false === $last_poll_at ? null : $last_poll_at;
		return $row;
	}

	/** @param array<string, mixed> $token @return array<string, mixed> */
	private function encode_token( array $token ): array {
		return array(
			'token_hash'  => (string) $token['token_hash'],
			'token_type'  => (string) $token['token_type'],
			'family_id'   => (string) $token['family_id'],
			'client_id'   => (string) $token['client_id'],
			'user_id'     => (int) $token['user_id'],
			'scopes'      => implode( ' ', (array) $token['scopes'] ),
			'audience'    => (string) $token['audience'],
			'expires_gmt' => $this->gmt( (int) $token['expires_at'] ),
			'created_gmt' => $this->gmt( (int) $token['created_at'] ),
		);
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function decode_token( array $row ): array {
		$row['user_id']    = (int) $row['user_id'];
		$row['scopes']     = $this->decode_scopes( (string) $row['scopes'] );
		$expires_at        = strtotime( (string) $row['expires_gmt'] . ' UTC' );
		$row['expires_at'] = false === $expires_at ? 0 : $expires_at;
		$revoked_at        = empty( $row['revoked_gmt'] ) ? false : strtotime( (string) $row['revoked_gmt'] . ' UTC' );
		$row['revoked_at'] = false === $revoked_at ? null : $revoked_at;
		return $row;
	}

	/** @return string[] */
	private function decode_scopes( string $scopes ): array {
		return array_values( array_filter( explode( ' ', trim( $scopes ) ) ) );
	}

	private function gmt( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
