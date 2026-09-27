<?php
declare(strict_types=1);

// Disposable PHP development server only; never distributed with the plugin.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_string( $path ) && '/' !== $path && is_file( '/var/www/html' . $path ) ) {
	return false;
}
require '/var/www/html/index.php';
