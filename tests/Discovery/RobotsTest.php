<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Robots;
use PHPUnit\Framework\TestCase;

final class RobotsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['cybermaps_mock_options'] = array();
        $GLOBALS['wp_hooks'] = array();
    }

	public function test_registers_delivery_guard_and_content_filter(): void {
        ( new Robots() )->register_hooks();

        $hooks = array();
        foreach ( $GLOBALS['wp_hooks'] as $hook ) {
            if ( ! is_array( $hook['callback'] ) || ! $hook['callback'][0] instanceof Robots ) {
                continue;
            }
            $hooks[ $hook['hook'] ] = array(
                'method'        => $hook['callback'][1],
                'priority'      => $hook['priority'],
                'accepted_args' => $hook['accepted_args'],
            );
        }

        $this->assertSame( 'guard_request', $hooks['wp']['method'] );
        $this->assertSame( 0, $hooks['wp']['priority'] );
        $this->assertSame( 'send_content_usage_header', $hooks['send_headers']['method'] );
        $this->assertSame( 20, $hooks['send_headers']['priority'] );
        $this->assertSame( 'filter_robots_txt', $hooks['robots_txt']['method'] );
		$this->assertSame( 2, $hooks['robots_txt']['accepted_args'] );
	}

	public function test_robots_guard_uses_the_shared_options_aware_publication_guard(): void {
		$method = new \ReflectionMethod( Robots::class, 'guard_request' );
		$lines  = file( (string) $method->getFileName() );
		$source = false === $lines
			? ''
			: implode(
				'',
				array_slice(
					$lines,
					$method->getStartLine() - 1,
					$method->getEndLine() - $method->getStartLine() + 1
				)
			);

		$this->assertStringContainsString( 'PublicationRequestGuard::enforce_active_route()', $source );
		$this->assertStringNotContainsString( 'ReadOnlyRequest::enforce()', $source );
	}

    public function test_private_site_always_returns_blanket_disallow(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(
                'inject_robots'       => '1',
                'enable_discovery_hub' => '1',
            ),
            'cybermaps_robots_manager' => array(
                'takeover_enabled' => true,
                'overrides'        => array(
                    'gptbot' => array( 'robots' => true ),
                ),
            ),
        );

        $output = ( new Robots() )->filter_robots_txt(
            "User-agent: *\nAllow: /\n",
            false
        );

        $this->assertSame( "User-agent: *\nDisallow: /\n", $output );
        $this->assertStringNotContainsString( 'Sitemap:', $output );
        $this->assertStringNotContainsString( 'Allow: /', $output );
    }

    public function test_per_bot_robots_overrides_are_inactive_without_takeover(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'takeover_enabled' => false,
                'overrides'        => array(
                    'gptbot' => array( 'robots' => false ),
                ),
            ),
        );

        $original = "User-agent: *\nDisallow: /private/\n";

        $this->assertSame(
            $original,
            ( new Robots() )->filter_robots_txt( $original, true )
        );
    }

    public function test_append_mode_manual_only_wraps_bare_rules_without_advertising_endpoints(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(
                'inject_robots'       => '0',
                'enable_discovery_hub' => '1',
            ),
            'cybermaps_robots_manager' => array(
                'takeover_enabled'  => false,
                'manual_directives' => "Disallow: /private/\nAllow: /private/preview/",
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( "User-agent: *\nDisallow: /core/\n", true );
        $groups = self::parse_groups( $output );

        $this->assertStringContainsString(
            "User-agent: *\nDisallow: /private/\nAllow: /private/preview/",
            $output
        );
        $this->assertSame(
            array( '/core/', '/private/' ),
            self::directive_values( $groups, '*', 'disallow' )
        );
        $this->assertSame(
            array( '/private/preview/' ),
            self::directive_values( $groups, '*', 'allow' )
        );
        $this->assertStringNotContainsString( 'Sitemap:', $output );
        $this->assertStringNotContainsString( 'CYBERMAPS PUBLICATION MAP', $output );
    }

    public function test_append_mode_signal_only_is_a_valid_group_without_advertising_endpoints(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(
                'inject_robots' => '0',
            ),
            'cybermaps_robots_manager' => array(
                'takeover_enabled' => false,
                'content_signals'  => array(
                    'search'   => 'yes',
                    'ai-train' => 'no',
                ),
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );
        $groups = self::parse_groups( $output );

        $this->assertSame(
            array( 'ai-train=no, search=yes' ),
            self::directive_values( $groups, '*', 'content-signal' )
        );
        $this->assertStringNotContainsString( 'Sitemap:', $output );
        $this->assertStringNotContainsString( 'CYBERMAPS PUBLICATION MAP', $output );
    }

    public function test_runtime_filters_and_canonically_orders_content_signals(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(
                'inject_robots' => '0',
            ),
            'cybermaps_robots_manager' => array(
                'takeover_enabled' => false,
                'content_signals'  => array(
                    'ai-input' => 'yes',
                    'unknown'  => 'yes',
                    'search'   => 'no',
                    'ai-train' => 'yes',
                    'invalid'  => "yes\nDisallow: /",
                ),
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );
        $groups = self::parse_groups( $output );

        $this->assertSame(
            array( 'ai-train=yes, search=no, ai-input=yes' ),
            self::directive_values( $groups, '*', 'content-signal' )
        );
        $this->assertStringNotContainsString( 'unknown=', $output );
        $this->assertStringNotContainsString( 'invalid=', $output );
    }

    public function test_content_usage_maps_signals_and_uses_the_longest_path_prefix(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'takeover_enabled'        => true,
                'content_usage_enabled'   => true,
                'content_signals'         => array( 'ai-train' => 'no', 'search' => 'yes', 'ai-input' => 'yes' ),
                'content_usage_overrides' => array(
                    '/docs/'        => array( 'ai-train' => 'yes' ),
                    '/docs/private/' => array( 'search' => 'no' ),
                ),
            ),
        );

        $robots = ( new Robots() )->filter_robots_txt( '', true );

        $this->assertStringContainsString( 'Content-Usage: train-ai=n, search=y', $robots );
        $this->assertStringContainsString( 'Content-Usage: /docs/ train-ai=y', $robots );
        $this->assertStringContainsString( 'Content-Usage: /docs/private/ search=n', $robots );
        $this->assertSame( 'search=n', ( new Robots() )->get_content_usage_header_for_path( '/docs/private/page/' ) );
        $this->assertSame( 'train-ai=y', ( new Robots() )->get_content_usage_header_for_path( '/docs/page/' ) );
        $this->assertSame( 'train-ai=n, search=y', ( new Robots() )->get_content_usage_header_for_path( '/other/' ) );
    }

    public function test_content_usage_is_disabled_by_default_and_never_maps_ai_input(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'content_signals' => array( 'ai-train' => 'no', 'ai-input' => 'yes' ),
            ),
        );

        $robots = ( new Robots() )->filter_robots_txt( '', true );

        $this->assertSame( '', ( new Robots() )->get_content_usage_header_for_path( '/' ) );
        $this->assertStringNotContainsString( 'Content-Usage:', $robots );
    }

    public function test_takeover_content_signal_header_is_neutral_and_non_legal(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'takeover_enabled' => true,
                'content_signals'  => array(
                    'ai-train' => 'no',
                ),
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );

        $this->assertStringContainsString(
            '# Content-Signal declares machine-readable publisher preferences.',
            $output
        );
        $this->assertStringNotContainsString( 'condition of accessing', strtolower( $output ) );
        $this->assertStringNotContainsString( 'you agree', strtolower( $output ) );
    }

    public function test_runtime_caps_manual_directives_from_direct_option_changes(): void {
        $manual = "User-agent: *\n# " . str_repeat( 'a', 32740 ) . "\n"
            . "Disallow: /must-not-be-emitted/\n";

        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'takeover_enabled'  => false,
                'manual_directives' => $manual,
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );

        $this->assertStringNotContainsString( '/must-not-be-emitted/', $output );
        $this->assertLessThan( 33000, strlen( $output ) );
    }

    public function test_runtime_ignores_malformed_structured_policy_values_without_warnings(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array( 'inject_robots' => '0' ),
            'cybermaps_robots_manager' => array(
                'takeover_enabled'  => true,
                'manual_directives' => array( 'Disallow: /malformed-array/' ),
                'overrides'         => array( 'gptbot' => 'not-an-override' ),
                'content_signals'   => array( 'ai-train' => array( 'no' ) ),
            ),
        );

        set_error_handler(
            static function ( int $severity, string $message, string $file, int $line ): never {
                throw new \ErrorException( $message, 0, $severity, $file, $line );
            }
        );
        try {
            $output = ( new Robots() )->filter_robots_txt( '', true );
        } finally {
            restore_error_handler();
        }

        $this->assertStringNotContainsString( 'Array', $output );
        $this->assertStringNotContainsString( 'Content-Signal:', $output );
        $this->assertStringNotContainsString( '/malformed-array/', $output );
    }

    public function test_append_mode_signal_and_manual_rules_remain_independently_valid(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(
                'inject_robots' => '0',
            ),
            'cybermaps_robots_manager' => array(
                'takeover_enabled'  => false,
                'manual_directives' => "Disallow: /models/\nAllow: /models/public/",
                'content_signals'   => array(
                    'ai-input' => 'yes',
                ),
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );
        $groups = self::parse_groups( $output );

        $this->assertSame(
            array( 'ai-input=yes' ),
            self::directive_values( $groups, '*', 'content-signal' )
        );
        $this->assertSame(
            array( '/models/' ),
            self::directive_values( $groups, '*', 'disallow' )
        );
        $this->assertSame(
            array( '/models/public/' ),
            self::directive_values( $groups, '*', 'allow' )
        );
        $this->assertStringNotContainsString( 'Sitemap:', $output );
    }

    public function test_append_mode_preserves_complete_explicit_manual_groups(): void {
        $manual = "User-agent: GPTBot\nDisallow: /training/\n\n"
            . "User-agent: ClaudeBot\nAllow: /\n";

        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'takeover_enabled'  => false,
                'manual_directives' => $manual,
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );
        $groups = self::parse_groups( $output );

        $this->assertStringContainsString( trim( $manual ), $output );
        $this->assertSame(
            array( '/training/' ),
            self::directive_values( $groups, 'GPTBot', 'disallow' )
        );
        $this->assertSame(
            array( '/' ),
            self::directive_values( $groups, 'ClaudeBot', 'allow' )
        );
    }

    public function test_append_mode_scopes_bare_rules_that_precede_an_explicit_group(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'takeover_enabled'  => false,
                'manual_directives' => "Disallow: /shared/\nUser-agent: GPTBot\nDisallow: /training/",
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );
        $groups = self::parse_groups( $output );

        $this->assertSame(
            array( '/shared/' ),
            self::directive_values( $groups, '*', 'disallow' )
        );
        $this->assertSame(
            array( '/training/' ),
            self::directive_values( $groups, 'GPTBot', 'disallow' )
        );
    }

    public function test_append_mode_inject_setting_advertises_sitemap_and_discovery(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(
                'inject_robots'       => '1',
                'enable_discovery_hub' => '1',
            ),
            'cybermaps_robots_manager' => array(
                'takeover_enabled' => false,
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( "User-agent: *\nDisallow: /core/\n", true );

        $this->assertStringContainsString( 'Sitemap: https://example.com/sitemap.xml', $output );
        $this->assertStringContainsString( 'Discovery:', $output );
        $this->assertStringContainsString( 'CYBERMAPS PUBLICATION MAP', $output );
    }

    public function test_takeover_allowed_crawlers_inherit_baseline_and_manual_wildcard_rules(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'takeover_enabled'  => true,
                'manual_directives' => 'Disallow: /publisher-private/',
                'overrides'         => array(
                    'gptbot'   => array( 'robots' => true ),
                    'claudebot' => array( 'robots' => false ),
                ),
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );
        $groups = self::parse_groups( $output );

        $this->assertStringNotContainsString( 'User-agent: GPTBot', $output );
        $this->assertSame(
            array( '/wp-admin/', '/publisher-private/' ),
            self::effective_directive_values( $groups, 'GPTBot', 'disallow' )
        );
        $this->assertSame(
            array( '/wp-admin/admin-ajax.php' ),
            self::effective_directive_values( $groups, 'GPTBot', 'allow' )
        );

        $this->assertStringContainsString(
            "User-agent: ClaudeBot\nUser-agent: Anthropic-AI\nDisallow: /",
            $output
        );
        $this->assertSame(
            array( '/' ),
            self::effective_directive_values( $groups, 'ClaudeBot', 'disallow' )
        );
        $this->assertSame(
            array( '/' ),
            self::effective_directive_values( $groups, 'Anthropic-AI', 'disallow' )
        );
    }

    public function test_takeover_emits_policy_only_tokens_without_treating_them_as_request_agents(): void {
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'       => array(),
            'cybermaps_robots_manager' => array(
                'takeover_enabled' => true,
                'overrides'        => array(
                    'google-extended' => array( 'robots' => false ),
                    'googlebot-news'  => array( 'robots' => false ),
                ),
            ),
        );

        $output = ( new Robots() )->filter_robots_txt( '', true );

        $this->assertStringContainsString( "User-agent: Google-Extended\nDisallow: /", $output );
        $this->assertStringContainsString( "User-agent: Googlebot-News\nDisallow: /", $output );
        $this->assertNull( \Cybermaps\Core\CrawlerRegistry::identify_bot( 'Google-Extended/1.0' ) );
        $this->assertNull( \Cybermaps\Core\CrawlerRegistry::identify_bot( 'Googlebot-News/2.1' ) );
    }

    /**
     * Parse robots groups and fail the test if a group directive is orphaned.
     *
     * @return array<int, array{agents: array<int, string>, directives: array<int, array{name: string, value: string}>}>
     */
    private static function parse_groups( string $robots ): array {
        $groups            = array();
        $agents            = array();
        $directives        = array();
        $group_has_records = false;

        $commit = static function () use ( &$groups, &$agents, &$directives, &$group_has_records ): void {
            if ( array() !== $agents ) {
                $groups[] = array(
                    'agents'     => $agents,
                    'directives' => $directives,
                );
            }
            $agents            = array();
            $directives        = array();
            $group_has_records = false;
        };

        foreach ( preg_split( '/\R/', $robots ) ?: array() as $line ) {
            $line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
            if ( '' === $line ) {
                $commit();
                continue;
            }

            if ( ! preg_match( '/^([a-z][a-z-]*)\s*:\s*(.*)$/i', $line, $matches ) ) {
                continue;
            }

            $name  = strtolower( $matches[1] );
            $value = trim( $matches[2] );
            if ( 'user-agent' === $name ) {
                if ( $group_has_records ) {
                    $commit();
                }
                $agents[] = $value;
                continue;
            }

            if ( in_array( $name, array( 'allow', 'disallow', 'content-signal' ), true ) ) {
                self::assertNotSame(
                    array(),
                    $agents,
                    sprintf( 'Orphan %s directive in generated robots.txt: %s', $name, $line )
                );
                $directives[] = array(
                    'name'  => $name,
                    'value' => $value,
                );
                $group_has_records = true;
            }
        }

        $commit();

        return $groups;
    }

    /**
     * @param array<int, array{agents: array<int, string>, directives: array<int, array{name: string, value: string}>}> $groups
     * @return array<int, string>
     */
    private static function directive_values( array $groups, string $agent, string $name ): array {
        $values = array();
        foreach ( $groups as $group ) {
            if ( ! in_array( $agent, $group['agents'], true ) ) {
                continue;
            }
            foreach ( $group['directives'] as $directive ) {
                if ( $name === $directive['name'] ) {
                    $values[] = $directive['value'];
                }
            }
        }

        return $values;
    }

    /**
     * Model the longest matching User-agent group rule used by major crawlers.
     *
     * @param array<int, array{agents: array<int, string>, directives: array<int, array{name: string, value: string}>}> $groups
     * @return array<int, string>
     */
    private static function effective_directive_values( array $groups, string $user_agent, string $name ): array {
        $matches        = array();
        $max_specificity = -1;
        foreach ( $groups as $group ) {
            foreach ( $group['agents'] as $agent ) {
                $specificity = '*' === $agent ? 0 : strlen( $agent );
                $matched     = '*' === $agent || false !== stripos( $user_agent, $agent );
                if ( ! $matched || $specificity < $max_specificity ) {
                    continue;
                }
                if ( $specificity > $max_specificity ) {
                    $matches         = array();
                    $max_specificity = $specificity;
                }
                $matches[] = $group;
                break;
            }
        }

        return self::directive_values_for_groups( $matches, $name );
    }

    /**
     * @param array<int, array{agents: array<int, string>, directives: array<int, array{name: string, value: string}>}> $groups
     * @return array<int, string>
     */
    private static function directive_values_for_groups( array $groups, string $name ): array {
        $values = array();
        foreach ( $groups as $group ) {
            foreach ( $group['directives'] as $directive ) {
                if ( $name === $directive['name'] ) {
                    $values[] = $directive['value'];
                }
            }
        }

        return $values;
    }
}
