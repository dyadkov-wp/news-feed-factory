<?php
/**
 * Integration tests for the plugin bootstrap class.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration;

use NWFF\Cache\TransientCache;
use NWFF\Plugin;
use NWFF\Render\YandexRenderer;
use WP_UnitTestCase;

/**
 * Covers Plugin::renderers() and hook registration.
 *
 * @group integration
 */
final class PluginTest extends WP_UnitTestCase {

	/**
	 * The renderer map contains the Yandex renderer.
	 *
	 * @return void
	 */
	public function test_renderers_contains_yandex(): void {
		$renderers = Plugin::renderers();

		$this->assertArrayHasKey( 'yandex', $renderers );
		$this->assertInstanceOf( YandexRenderer::class, $renderers['yandex'] );
	}

	/**
	 * Init registers the rewrite-rule hook.
	 *
	 * @return void
	 */
	public function test_init_registers_init_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'init', array( Plugin::class, 'register_rewrite_rules' ) )
		);
	}

	/**
	 * Init registers the template-redirect hook.
	 *
	 * @return void
	 */
	public function test_init_registers_template_redirect_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'template_redirect', array( Plugin::class, 'handle_feed_request' ) )
		);
	}

	/**
	 * Register_rewrite_rules adds a rule to WP_Rewrite.
	 *
	 * @return void
	 */
	public function test_register_rewrite_rules_adds_rule(): void {
		Plugin::register_rewrite_rules();

		global $wp_rewrite;
		$this->assertArrayHasKey( '^feed/([^/]+)\.xml$', $wp_rewrite->extra_rules_top );
	}
	/**
	 * Init registers the save_post_post cache-invalidation hook.
	 *
	 * @return void
	 */
	public function test_init_registers_save_post_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'save_post_post', array( Plugin::class, 'on_save_post' ) )
		);
	}

	/**
	 * Init registers the deleted_post cache-invalidation hook.
	 *
	 * @return void
	 */
	public function test_init_registers_deleted_post_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'deleted_post', array( Plugin::class, 'on_deleted_post' ) )
		);
	}

	/**
	 * Init registers the set_object_terms cache-invalidation hook.
	 *
	 * @return void
	 */
	public function test_init_registers_set_object_terms_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'set_object_terms', array( Plugin::class, 'on_set_object_terms' ) )
		);
	}

	/**
	 * Updating a post drops the feed cache.
	 *
	 * @return void
	 */
	public function test_save_post_invalidates_cache(): void {
		$post_id = (int) self::factory()->post->create();

		set_transient( TransientCache::FEED_PREFIX . 'probe', 'value', 300 );
		$this->assertSame( 'value', get_transient( TransientCache::FEED_PREFIX . 'probe' ) );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated',
			)
		);

		$this->assertFalse( get_transient( TransientCache::FEED_PREFIX . 'probe' ) );
	}

	/**
	 * Deleting a post drops the feed cache.
	 *
	 * @return void
	 */
	public function test_deleted_post_invalidates_cache(): void {
		$post_id = (int) self::factory()->post->create();

		set_transient( TransientCache::FEED_PREFIX . 'probe', 'value', 300 );

		wp_delete_post( $post_id, true );

		$this->assertFalse( get_transient( TransientCache::FEED_PREFIX . 'probe' ) );
	}

	/**
	 * Assigning terms to a post drops the feed cache.
	 *
	 * @return void
	 */
	public function test_set_object_terms_invalidates_cache(): void {
		$post_id = (int) self::factory()->post->create();

		set_transient( TransientCache::FEED_PREFIX . 'probe', 'value', 300 );

		wp_set_object_terms( $post_id, array( 'breaking' ), 'post_tag' );

		$this->assertFalse( get_transient( TransientCache::FEED_PREFIX . 'probe' ) );
	}

	/**
	 * Deleting a non-post does not drop the feed cache.
	 *
	 * @return void
	 */
	public function test_deleted_post_of_non_post_type_keeps_cache(): void {
		$page_id = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );

		set_transient( TransientCache::FEED_PREFIX . 'probe', 'value', 300 );

		wp_delete_post( $page_id, true );

		$this->assertSame( 'value', get_transient( TransientCache::FEED_PREFIX . 'probe' ) );
	}

	/**
	 * Deleting a post removes its feed-meta row.
	 *
	 * @return void
	 */
	public function test_deleted_post_removes_meta_row(): void {
		global $wpdb;

		$post_id = (int) self::factory()->post->create();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test helper: direct insert into the plugin's custom table; no WP API exists for that.
		$wpdb->insert(
			$wpdb->prefix . 'nwff_feed_meta',
			array(
				'post_id'            => $post_id,
				'exclude_from_feeds' => 1,
				'updated_at'         => current_time( 'mysql', true ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper: reading back the plugin's custom table after wp_delete_post.
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nwff_feed_meta WHERE post_id = {$post_id}" );
		$this->assertSame( 1, $before );

		wp_delete_post( $post_id, true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper: verifying the row is gone after wp_delete_post.
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nwff_feed_meta WHERE post_id = {$post_id}" );
		$this->assertSame( 0, $after );
	}

	/**
	 * Activate persists the rewrite_rules option.
	 *
	 * @return void
	 */
	public function test_activate_flushes_rewrite_rules(): void {
		delete_option( 'rewrite_rules' );

		Plugin::activate();

		$this->assertNotFalse( get_option( 'rewrite_rules' ) );
	}
}
