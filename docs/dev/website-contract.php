<?php
declare(strict_types=1);

/** CLI-only website export. Runtime values are read from the inspected source. */

function cybermaps_website_limits(): array {
    return [
        'llms_summary_bytes' => \Cybermaps\Discovery\LLMS::OUTPUT_MAX_BYTES,
        'llms_full_bytes' => \Cybermaps\Discovery\LLMS::FULL_OUTPUT_MAX_BYTES,
        'llms_tldr_bytes' => \Cybermaps\Discovery\PublicationConstraints::BRIEFING_OUTPUT_MAX_BYTES,
        'llms_tldr_tokens_min' => \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MIN,
        'llms_tldr_tokens_max' => \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MAX,
        'llms_links_min' => \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_MIN,
        'llms_links_max' => \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_MAX,
        'llms_summary_candidates' => \Cybermaps\Discovery\PublicationConstraints::SUMMARY_CANDIDATE_SCAN_MAX,
        'llms_tldr_candidates' => \Cybermaps\Discovery\PublicationConstraints::BRIEFING_CANDIDATE_SCAN_MAX,
        'llms_work_seconds' => \Cybermaps\Discovery\PublicationScanBudget::MAX_SECONDS,
    ];
}

function cybermaps_website_defaults( array $manifest ): array {
    return [
        'static_engine_mode' => $manifest['static_engine']['default'],
        'llms_links' => \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_DEFAULT,
        'llms_tldr_tokens' => \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_DEFAULT,
        'feed_items' => \Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_DEFAULT,
        'ai_sitemap_items' => \Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_DEFAULT,
    ];
}

/** Capture registration without running request handlers or permission callbacks. */
function register_rest_route( string $namespace, string $route, array $args ): void {
    $GLOBALS['cybermaps_website_rest'][ $namespace . $route ] = [
        'namespace' => $namespace, 'route' => $route,
        'full' => '/wp-json/' . $namespace . $route,
    ];
}

function cybermaps_website_routes( array $manifest ): array {
    // Fail closed when a new registration owner needs to be added to this export.
    $owners = [
        'src/Core/RestAPI.php', 'src/MCP/Transport.php',
        'src/MCP/OAuth/OAuthRouteController.php', 'src/Admin/InternationalPanel.php',
    ];
    foreach ( $manifest['source_classes'] as $source ) {
        $body = file_get_contents( CYBERMAPS_PLUGIN_DIR . $source['file'] );
        if ( preg_match( '/\bregister_rest_route\s*\(/', $body ) && ! in_array( $source['file'], $owners, true ) ) {
            throw new RuntimeException( 'Add REST registration owner to website export: ' . $source['file'] );
        }
    }
    $routes = [];
    $rest_types = [];
    foreach ( $manifest['discovery_endpoints'] as $endpoint ) {
        if ( str_starts_with( $endpoint['path'], '/wp-json/' ) ) {
            $rest_types[ $endpoint['path'] ] = $endpoint['type'];
            continue;
        }
        $routes[ $endpoint['path'] ] = [
            'path' => $endpoint['path'],
            'category' => $endpoint['parameterized'] ? 'publication-family' : 'publication',
            'media_type' => $endpoint['type'],
            'label' => $endpoint['label'],
            'condition' => $endpoint['enabled_setting'] ?: 'Publication Hub and publication configuration',
            'static_mode' => $endpoint['static_bucket'] ?: 'dynamic',
        ];
    }
    $GLOBALS['cybermaps_website_rest'] = [];
    ( new \Cybermaps\Core\RestAPI() )->register_routes();
    // Registration only needs the mode resolver; no MCP services or handlers run.
    $transport = ( new ReflectionClass( \Cybermaps\MCP\Transport::class ) )->newInstanceWithoutConstructor();
    ( new ReflectionProperty( $transport, 'mode_resolver' ) )->setValue( $transport, static fn(): string => 'discovery' );
    $transport->register_routes();
    foreach ( [
        \Cybermaps\MCP\OAuth\OAuthRouteController::class => 'register_routes',
        \Cybermaps\Admin\InternationalPanel::class => 'register_editor_route',
    ] as $class => $method ) {
        ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor()->$method();
    }
    foreach ( $manifest['rest_api_routes'] as $endpoint ) {
        if ( ! isset( $GLOBALS['cybermaps_website_rest'][ $endpoint['namespace'] . $endpoint['route'] ] ) ) {
            throw new RuntimeException( 'Registry REST route is not registered at runtime: ' . $endpoint['full'] );
        }
    }
    foreach ( $GLOBALS['cybermaps_website_rest'] as $endpoint ) {
        $path = preg_replace( '/\(\?P<([^>]+)>[^)]+\)/', '{$1}', $endpoint['full'] );
        $routes[ $path ] = [
            'path' => $path, 'category' => 'rest', 'media_type' => $rest_types[ $endpoint['full'] ] ?? 'application/json',
            'label' => $endpoint['route'],
            'condition' => 'Route-specific enablement and permissions; see MCP/REST documentation',
            'static_mode' => 'dynamic',
        ];
    }
    $slugs = \Cybermaps\Sitemap\PublicationRouteSlugs::resolve( [] );
    $matcher = new ReflectionMethod( \Cybermaps\Sitemap\SitemapRouteMatcher::class, 'fixed_routes' );
    $fixed = $matcher->invoke( null, $slugs['sitemap_url_base'], $slugs['news_sitemap_url_base'], $slugs['rss_sitemap_url_base'] );
    $sitemaps = array_merge( array_keys( $fixed ), [
        '/' . $slugs['sitemap_url_base'] . '-authors-{page}.xml',
        '/' . $slugs['sitemap_url_base'] . '-archives-{page}.xml',
        '/' . $slugs['sitemap_url_base'] . '-posts-{post_type}-{page}.xml',
        '/' . $slugs['sitemap_url_base'] . '-taxonomies-{taxonomy}-{page}.xml',
    ] );
    foreach ( $sitemaps as $path ) {
        $example = str_replace( [ '{page}', '{post_type}', '{taxonomy}' ], [ '1', 'post', 'category' ], $path );
        $matched = \Cybermaps\Sitemap\SitemapRouteMatcher::match_path( $example, [] );
        if ( null === $matched ) {
            throw new RuntimeException( 'Sitemap export no longer matches runtime: ' . $path );
        }
        $routes[ $path ] = [
            'path' => $path, 'category' => 'sitemap', 'media_type' => 'rss' === $matched['route'] ? 'application/rss+xml' : 'application/xml',
            'label' => 'Sitemap (default slug)',
            'condition' => 'Enabled sitemap/provider; network index requires multisite',
            'static_mode' => 'network' === $matched['route'] ? 'dynamic' : 'all (single site only)',
        ];
    }
    $routes['/robots.txt'] = [
        'path' => '/robots.txt', 'category' => 'robots', 'media_type' => 'text/plain',
        'label' => 'WordPress virtual robots.txt with Cybermaps crawler policy',
        'condition' => 'WordPress virtual route; physical robots.txt takes precedence', 'static_mode' => 'dynamic',
    ];
    $device_path = \Cybermaps\MCP\OAuth\DeviceAuthorizationPage::PATH;
    $routes['/{key}.txt'] = [
        'path' => '/{key}.txt', 'category' => 'verification', 'media_type' => 'text/plain',
        'label' => 'IndexNow site-key verification', 'condition' => 'IndexNow enabled; the site key is generated when needed',
        'static_mode' => 'dynamic',
    ];
    $routes[ $device_path ] = [
        'path' => $device_path, 'category' => 'authorization', 'media_type' => 'text/html',
        'label' => 'Agent authorization', 'condition' => 'MCP user-claimed registration and WordPress login',
        'static_mode' => 'dynamic',
    ];
    ksort( $routes );
    return array_values( $routes );
}

/** Include source hashes so behavior changes also invalidate editorial reviews. */
function cybermaps_website_contract( array $manifest ): array {
    $sources = [];
    foreach ( [ 'src', 'assets' ] as $directory ) {
        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( CYBERMAPS_PLUGIN_DIR . $directory ) );
        foreach ( $files as $file ) {
            if ( ! $file->isFile() || $file->isLink() ) continue;
            $relative = substr( $file->getPathname(), strlen( CYBERMAPS_PLUGIN_DIR ) );
            $sources[ $relative ] = hash_file( 'sha256', $file->getPathname() );
        }
    }
    foreach ( [
        'cybermaps.php', 'uninstall.php', 'readme.txt', 'changelog.txt',
        'docs/documentation.md', 'docs/features.md', 'docs/comparison.md',
        'docs/ai-configuration.md', 'docs/llms-tldr-whitepaper.md',
    ] as $file ) {
        $sources[ $file ] = hash_file( 'sha256', CYBERMAPS_PLUGIN_DIR . $file );
    }
    ksort( $sources );
    return [
        'format_version' => 1,
        'version' => $manifest['version'],
        'requirements' => [ 'php' => $manifest['php_min'], 'wordpress' => $manifest['wp_min'] ],
        'limits' => $manifest['publication_limits'],
        'defaults' => $manifest['publication_defaults'],
        'routes' => $manifest['public_routes'],
        'route_count' => count( $manifest['public_routes'] ),
        'route_count_scope' => 'Supported public routes and route families, including aliases, robots, REST and authorization. Excludes admin screens, AJAX/admin-post actions, assets and legacy redirects. Concrete URL counts depend on settings and content.',
        'static_engine' => $manifest['static_engine'],
        'ai_configuration' => $manifest['ai_configuration'],
        'source_files' => $sources,
    ];
}
