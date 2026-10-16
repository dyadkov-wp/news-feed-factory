<?php
/**
 * Integration tests for the post metabox.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Admin;

use NWFF\Admin\PostFlags;
use NWFF\Data\MetaRepository;
use WP_UnitTestCase;

/**
 * Covers PostFlags::register(), PostFlags::render() and
 * PostFlags::save().
 *
 * @group integration
 */
final class PostFlagsTest extends WP_UnitTestCase {

	/**
	 * Clean POST state and current user between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$_POST['nwff_post_flags_nonce'],
			$_POST['nwff_post_flags_present'],
			$_POST['nwff_post_flags']
		);
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create a post and return its ID.
	 *
	 * @return int
	 */
	private function make_post(): int {
		return (int) self::factory()->post->create();
	}

	/**
	 * Log in an editor and seed $_POST as if the metabox was submitted.
	 *
	 * @param bool $ticked Whether the checkbox was ticked.
	 * @return void
	 */
	private function simulate_metabox_submission( bool $ticked ): void {
		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$_POST['nwff_post_flags_nonce']   = wp_create_nonce( 'nwff_save_post_flags' );
		$_POST['nwff_post_flags_present'] = '1';

		if ( $ticked ) {
			$_POST['nwff_post_flags'] = array( 'include' => '1' );
		}
	}

	/**
	 * Return the exclude flag stored for a post, or null if absent.
	 *
	 * @param int $post_id Post ID.
	 * @return bool|null
	 */
	private function stored_exclude( int $post_id ): ?bool {
		$rows = ( new MetaRepository() )->get_by_post_ids( array( $post_id ) );

		return $rows[ $post_id ]['exclude_from_feeds'] ?? null;
	}

	/**
	 * Reset the metabox registry global.
	 *
	 * Writing to $GLOBALS['wp_meta_boxes'] is normally forbidden by
	 * WPCS (WordPress.WP.GlobalVariablesOverride.Prohibited) to
	 * protect production code. Integration tests deliberately reset
	 * the global before exercising metabox registration, so the
	 * assignment is centralised here with a scoped ignore rather
	 * than repeated at every call site.
	 *
	 * @return void
	 */
	private function reset_meta_boxes(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test reset; see method docblock.
		$GLOBALS['wp_meta_boxes'] = array();
	}

	/**
	 * Register adds the metabox for the post type.
	 *
	 * @return void
	 */
	public function test_register_adds_metabox_for_post(): void {
		global $wp_meta_boxes;

		$this->reset_meta_boxes();

		PostFlags::register();

		do_action( 'add_meta_boxes', 'post' );

		$this->assertArrayHasKey( 'nwff_post_flags', $wp_meta_boxes['post']['side']['default'] );
	}

	/**
	 * Render outputs a checkbox, a nonce and the presence marker.
	 *
	 * @return void
	 */
	public function test_render_outputs_expected_markup(): void {
		$post = get_post( $this->make_post() );

		ob_start();
		PostFlags::render( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'nwff_post_flags_nonce', $html );
		$this->assertStringContainsString( 'nwff_post_flags_present', $html );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( "checked='checked'", $html );
	}

	/**
	 * Render leaves the checkbox unchecked when the post is excluded.
	 *
	 * @return void
	 */
	public function test_render_unchecked_when_excluded(): void {
		$post_id = $this->make_post();
		( new MetaRepository() )->save( $post_id, true );

		$post = get_post( $post_id );

		ob_start();
		PostFlags::render( $post );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( "checked='checked'", $html );
	}

	/**
	 * Saving with the checkbox ticked stores exclude = 0.
	 *
	 * @return void
	 */
	public function test_save_include_checked_stores_exclude_false(): void {
		$post_id = $this->make_post();
		$this->simulate_metabox_submission( true );

		PostFlags::save( $post_id );

		$this->assertFalse( $this->stored_exclude( $post_id ) );
	}

	/**
	 * Saving with the checkbox clear stores exclude = 1.
	 *
	 * @return void
	 */
	public function test_save_include_unchecked_stores_exclude_true(): void {
		$post_id = $this->make_post();
		$this->simulate_metabox_submission( false );

		PostFlags::save( $post_id );

		$this->assertTrue( $this->stored_exclude( $post_id ) );
	}

	/**
	 * Save without a nonce is a no-op.
	 *
	 * @return void
	 */
	public function test_save_without_nonce_is_noop(): void {
		$post_id = $this->make_post();
		$editor  = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$_POST['nwff_post_flags_present'] = '1';

		PostFlags::save( $post_id );

		$this->assertNull( $this->stored_exclude( $post_id ) );
	}

	/**
	 * Save without the presence marker is a no-op.
	 *
	 * Guards against REST, Quick Edit and other paths where the
	 * metabox form is not posted.
	 *
	 * @return void
	 */
	public function test_save_without_present_marker_is_noop(): void {
		$post_id = $this->make_post();
		$editor  = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		PostFlags::save( $post_id );

		$this->assertNull( $this->stored_exclude( $post_id ) );
	}

	/**
	 * Save without edit_post capability is a no-op.
	 *
	 * @return void
	 */
	public function test_save_without_capability_is_noop(): void {
		$post_id = $this->make_post();

		$subscriber = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['nwff_post_flags_nonce']   = wp_create_nonce( 'nwff_save_post_flags' );
		$_POST['nwff_post_flags_present'] = '1';
		$_POST['nwff_post_flags']         = array( 'include' => '1' );

		PostFlags::save( $post_id );

		$this->assertNull( $this->stored_exclude( $post_id ) );
	}
}
