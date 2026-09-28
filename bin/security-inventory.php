<?php
declare(strict_types=1);
/** Emit named-function security scopes; comments/line movement do not change code identity. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$root = dirname( __DIR__ );
$paths = array( 'cybermaps.php', 'uninstall.php' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() ) {
		$paths[] = substr( $file->getPathname(), strlen( $root ) + 1 );
	}
}
$result = array();
foreach ( $paths as $path ) {
	$tokens = token_get_all( (string) file_get_contents( $root . '/' . $path ) );
	$scopes = array();
	$line = 1;
	foreach ( $tokens as $i => $token ) {
		if ( ! is_array( $token ) || T_FUNCTION !== $token[0] ) {
			continue;
		}
		$j = $i + 1;
		while ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG ), true ) ) {
			++$j;
		}
		if ( ! isset( $tokens[ $j ] ) || ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) {
			continue;
		}
		$name = $tokens[ $j ][1];
		while ( isset( $tokens[ $j ] ) && '{' !== $tokens[ $j ] && ';' !== $tokens[ $j ] ) {
			++$j;
		}
		if ( ! isset( $tokens[ $j ] ) || ';' === $tokens[ $j ] ) {
			continue;
		}
		$depth = 1;
		$end = $j + 1;
		while ( isset( $tokens[ $end ] ) && $depth > 0 ) {
			$t = $tokens[ $end ];
			if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( '}' === $t ) {
				--$depth;
			}
			++$end;
		}
		$scopes[] = array( 'name' => $name, 'start' => $i, 'end' => $end - 1 );
	}
	$groups = array();
	foreach ( $tokens as $i => $token ) {
		$scope = '@file';
		foreach ( $scopes as $candidate ) {
			if ( $i >= $candidate['start'] && $i <= $candidate['end'] ) {
				$scope = $candidate['name'];
				break;
			}
		}
		$key = $path . '::' . $scope;
		$groups[ $key ] ??= array( 'path' => $path, 'scope' => $scope, 'code' => '', 'requests' => array(), 'suppressions' => array(), 'lines' => array() );
		$text = is_array( $token ) ? $token[1] : $token;
		$line = is_array( $token ) ? $token[2] : $line;
		$groups[ $key ]['lines'][] = $line;
		if ( is_array( $token ) && T_VARIABLE === $token[0] && preg_match( '/^\$_(?:GET|POST|REQUEST|SERVER|COOKIE|FILES)$/', $text ) ) {
			$groups[ $key ]['requests'][] = $text;
		}
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && preg_match( '/phpcs:(?:ignore|disable).*?(?:NonceVerification|EscapeOutput|ValidatedSanitizedInput|PreparedSQL|DirectDatabaseQuery)/', $text ) ) {
			$groups[ $key ]['suppressions'][] = trim( $text );
		}
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$groups[ $key ]['code'] .= strlen( $text ) . ':' . $text;
		}
		$line += substr_count( $text, "\n" );
	}
	$file_code_hash = hash( 'sha256', implode( '', array_column( $groups, 'code' ) ) );
	foreach ( $groups as $key => $group ) {
		$group['file_code_sha256'] = $file_code_hash;
		$group['code_sha256'] = hash( 'sha256', $group['code'] );
		unset( $group['code'] );
		$group['requests'] = array_values( array_unique( $group['requests'] ) );
		$group['lines'] = array_values( array_unique( $group['lines'] ) );
		sort( $group['requests'] );
		sort( $group['suppressions'] );
		$result[ $key ] = $group;
	}
}
ksort( $result );
echo json_encode( $result, JSON_THROW_ON_ERROR );
