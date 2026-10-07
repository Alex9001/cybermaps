<?php
declare(strict_types=1);

// WP-CLI eval-file rewrites source before eval, invalidating strict_types.
// A normal require preserves the source contract and passes explicit arguments.
$args = array_slice( $argv, 1 );
require '/var/www/html/wp-load.php';
require __DIR__ . '/operation.php';
