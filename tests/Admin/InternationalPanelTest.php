<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\InternationalPanel;
use Cybermaps\Core\TranslationRegistry;
use PHPUnit\Framework\TestCase;

final class InternationalPanelTest extends TestCase {
	public function test_manual_translation_ui_supports_create_link_and_unlink_actions(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/InternationalPanel.php' );

		$this->assertStringContainsString( 'cybermaps_create_translation_group', $source );
		$this->assertStringContainsString( 'cybermaps_unlink_translation', $source );
		$this->assertStringContainsString( 'cybermaps_resume_translation_sync', $source );
		$this->assertStringContainsString( 'TranslationManager::SYNC_DISABLED_META', $source );
		$this->assertStringContainsString( '$this->registry->update_relationship(', $source );
		$this->assertStringContainsString( '$this->registry->delete_relationship(', $source );
		$this->assertStringContainsString( 'pause automatic translation sync', $source );
		$this->assertStringNotContainsString( 'placeholder="Leave empty for auto"', $source );
	}

	public function test_manual_translation_save_is_bounded_to_supported_post_types(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/InternationalPanel.php' );

		$this->assertStringContainsString( 'wp_is_post_revision( $post_id )', $source );
		$this->assertStringContainsString( 'wp_is_post_autosave( $post_id )', $source );
		$this->assertStringContainsString( 'PublicationPostTypes::contains', $source );
		$this->assertStringContainsString( "empty( \$settings['enable_translation_integrations'] )", $source );
		foreach (
			array(
				'cybermaps_international_nonce',
				'cybermaps_group_id',
				'cybermaps_unlink_translation',
				'cybermaps_create_translation_group',
				'cybermaps_resume_translation_sync',
			) as $control
		) {
			$this->assertStringContainsString( "is_scalar( \$_POST['" . $control . "'] )", $source );
		}
	}

	public function test_create_and_assign_translation_controls_use_the_selected_group(): void {
		$registry = new class() extends TranslationRegistry {
			public array $updates = array();

			public function update_relationship( $group_id, $site_id, $item_id, $lang, $type = 'post' ) {
				$this->updates[] = array( $group_id, $site_id, $item_id, $lang, $type );
				return (int) $group_id;
			}
		};
		$this->prepare_save();

		$_POST = array(
			'cybermaps_international_nonce' => wp_create_nonce( 'cybermaps_international_save' ),
			'cybermaps_create_translation_group' => '1',
		);
		( new InternationalPanel( $registry ) )->save_meta_box_data( 81 );

		self::assertSame( array( array( 0, 1, 81, 'en-US', 'post' ) ), $registry->updates );

		$_POST = array(
			'cybermaps_international_nonce' => wp_create_nonce( 'cybermaps_international_save' ),
			'cybermaps_group_id'            => '9001',
		);
		( new InternationalPanel( $registry ) )->save_meta_box_data( 81 );

		self::assertSame( array( 9001, 1, 81, 'en-US', 'post' ), $registry->updates[1] );
	}

	public function test_unlink_and_resume_controls_change_only_their_translation_sync_state(): void {
		$registry = new class() extends TranslationRegistry {
			public array $deletes = array();

			public function delete_relationship( $site_id, $item_id, $type = 'post' ) {
				$this->deletes[] = array( $site_id, $item_id, $type );
				return true;
			}
		};
		$this->prepare_save();
		$_POST = array(
			'cybermaps_international_nonce' => wp_create_nonce( 'cybermaps_international_save' ),
			'cybermaps_unlink_translation'  => '1',
		);
		( new InternationalPanel( $registry ) )->save_meta_box_data( 81 );

		self::assertSame( array( array( 1, 81, 'post' ) ), $registry->deletes );
		self::assertSame( '1', $GLOBALS['cybermaps_mock_post_meta'][81]['_cybermaps_translation_sync_disabled'] );

		$_POST = array(
			'cybermaps_international_nonce' => wp_create_nonce( 'cybermaps_international_save' ),
			'cybermaps_resume_translation_sync' => '1',
		);
		( new InternationalPanel( $registry ) )->save_meta_box_data( 81 );

		self::assertArrayNotHasKey( '_cybermaps_translation_sync_disabled', $GLOBALS['cybermaps_mock_post_meta'][81] );
	}

	public function test_block_editor_action_fails_closed_when_translation_integrations_are_disabled(): void {
		$this->prepare_save();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_translation_integrations'] = '0';
		$request = new class() {
			public function get_param( string $name ): int|string {
				return 'post_id' === $name ? 81 : 'resume';
			}
		};

		$response = ( new InternationalPanel() )->handle_editor_update( $request );

		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertArrayNotHasKey(
			'_cybermaps_translation_sync_disabled',
			$GLOBALS['cybermaps_mock_post_meta'][81] ?? array()
		);
	}

	private function prepare_save(): void {
		$_POST = array();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'enable_translation_integrations' => '1' ),
		);
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'edit_post' );
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'name' => 'post', 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_posts'] = array(
			81 => new \WP_Post( array( 'ID' => 81, 'post_type' => 'post' ) ),
		);
	}
}
