<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\Tabs\Robots\RobotsFields;
use Cybermaps\Core\CrawlerRegistry;

final class RobotsFieldsAccessibilityTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_robots_manager' => array(
				'overrides'         => array(),
				'manual_directives' => '',
				'content_signals'   => array(),
			),
		);
	}

	public function test_every_per_bot_rpm_control_has_a_deterministic_associated_label(): void {
		ob_start();
		RobotsFields::render_crawler_matrix();
		$html = (string) ob_get_clean();

		foreach ( CrawlerRegistry::get_policy_bots() as $bot_id => $bot ) {
			$id = 'cybermaps-bot-rpm-' . sanitize_key( (string) $bot_id );
			$this->assertStringContainsString( 'for="' . $id . '"', $html, (string) $bot->name );
			$this->assertStringContainsString( 'id="' . $id . '" type="number"', $html, (string) $bot->name );
			$this->assertStringContainsString(
				'name="cybermaps_robots_manager[overrides][' . $bot_id . '][tpm]"',
				$html,
				(string) $bot->name
			);
		}
		$this->assertSame( count( CrawlerRegistry::get_policy_bots() ), substr_count( $html, 'min="0" max="10000" step="1"' ) );
	}

	public function test_manual_directives_textarea_preserves_name_and_has_an_associated_label(): void {
		ob_start();
		RobotsFields::render_crawler_matrix();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'for="cybermaps-manual-directives"', $html );
		$this->assertStringContainsString(
			'id="cybermaps-manual-directives" name="cybermaps_robots_manager[manual_directives]"',
			$html
		);
		$this->assertStringContainsString( 'maxlength="32768"', $html );
	}

	public function test_crawler_matrix_omits_empty_registry_categories(): void {
		ob_start();
		RobotsFields::render_crawler_matrix();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-cat="unregistered-bot"', $html );
		$this->assertStringNotContainsString( 'data-cat="other"', $html );
	}

	public function test_crawler_matrix_distinguishes_policy_only_tokens_and_user_fetches(): void {
		ob_start();
		RobotsFields::render_crawler_matrix();
		$html = (string) ob_get_clean();

		$this->assertSame( 3, substr_count( $html, '>policy only</span>' ) );
		$this->assertStringContainsString( 'Robots token: <code>Google-Extended</code>', $html );
		$this->assertStringContainsString( 'data-cat="ai-user"', $html );
		$this->assertStringContainsString(
			'name="cybermaps_robots_manager[overrides][chatgpt-user][llm]"',
			$html
		);
	}

	public function test_manifest_target_inputs_are_rendered_only_for_supported_ai_categories(): void {
		ob_start();
		RobotsFields::render_crawler_matrix();
		$html = (string) ob_get_clean();

		foreach ( CrawlerRegistry::get_policy_bots() as $bot_id => $bot ) {
			$input_name = 'name="cybermaps_robots_manager[overrides][' . $bot_id . '][llm]"';
			if ( CrawlerRegistry::supports_manifest_target( $bot ) ) {
				$this->assertStringContainsString( $input_name, $html, $bot_id );
				continue;
			}

			$this->assertStringNotContainsString( $input_name, $html, $bot_id );
		}
	}

	public function test_every_content_signal_select_preserves_name_and_has_an_associated_label(): void {
		ob_start();
		RobotsFields::render_content_signals_field();
		$html = (string) ob_get_clean();

		foreach ( array( 'ai-train', 'search', 'ai-input' ) as $signal ) {
			$id = 'cybermaps-content-signal-' . $signal;
			$this->assertStringContainsString( 'for="' . $id . '"', $html );
			$this->assertStringContainsString(
				'id="' . $id . '" name="cybermaps_robots_manager[content_signals][' . $signal . ']"',
				$html
			);
		}
	}

	public function test_content_usage_controls_have_bounded_path_fields_and_opt_in_toggle(): void {
		ob_start();
		RobotsFields::render_content_usage_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="cybermaps_robots_manager[content_usage_enabled]"', $html );
		$this->assertStringContainsString( 'name="cybermaps_robots_manager[content_usage_overrides][0][path]"', $html );
		$this->assertStringContainsString( 'maxlength="2048"', $html );
		$this->assertStringContainsString( 'data-max="50"', $html );
	}

	public function test_category_master_toggles_reflect_their_saved_child_state(): void {
		ob_start();
		RobotsFields::render_crawler_matrix();
		ob_end_clean();

		$scripts = implode(
			"\n",
			$GLOBALS['cybermaps_mock_added_inline_scripts']['cybermaps-command-center']['after'] ?? array()
		);

		$this->assertStringContainsString( 'function syncMasterToggles()', $scripts );
		$this->assertStringContainsString( '$master.prop("indeterminate"', $scripts );
		$this->assertStringContainsString( 'syncMasterToggles();', $scripts );
		$this->assertStringContainsString( 'tr[data-cat=\\"ai-user\\"] .bot-llm-toggle', $scripts );
	}

	public function test_crawler_categories_expose_collapsed_granular_controls(): void {
		ob_start();
		RobotsFields::render_crawler_matrix();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="cybermaps-category-disclosure"', $html );
		$this->assertStringContainsString( 'hidden aria-expanded="true"', $html );
		$this->assertStringContainsString( 'class="cybermaps-crawler-detail-row"', $html );
		$this->assertStringContainsString( 'class="cybermaps-crawler-category-group"', $html );
		$this->assertStringContainsString( 'class="cybermaps-crawler-category-details"', $html );

		$scripts = implode(
			"\n",
			$GLOBALS['cybermaps_mock_added_inline_scripts']['cybermaps-command-center']['after'] ?? array()
		);
		$this->assertStringContainsString( 'addClass("is-collapsible")', $scripts );
		$this->assertStringContainsString( '.attr("aria-expanded", "false")', $scripts );
		$this->assertStringContainsString( '.removeAttr("hidden")', $scripts );
		$this->assertStringContainsString( '.find(".cybermaps-bulk-action").prop("disabled", false)', $scripts );
		$this->assertStringContainsString( 'toggleClass("is-expanded", ! expanded)', $scripts );

		$styles = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/assets/css/admin-command-center.css'
		);
		$this->assertStringContainsString(
			".cybermaps-category-disclosure[hidden] {\n    display: none;\n}",
			$styles
		);
	}
}
