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
use NWFF\Render\DzenRenderer;
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
	 * The renderer map contains the Dzen renderer.
	 *
	 * @return void
	 */
	public function test_renderers_contains_dzen(): void {
		$renderers = Plugin::renderers();

		$this->assertArrayHasKey( 'dzen', $renderers );
		$this->assertInstanceOf( DzenRenderer::class, $renderers['dzen'] );
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
		$this->assertArrayHasKey( '^feed/([^/]+)\.xml/?$', $wp_rewrite->extra_rules_top );
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
	/**
	 * Renderers accessor returns the same instances across calls.
	 *
	 * @return void
	 */
	public function test_renderers_returns_same_instances(): void {
		$first  = Plugin::renderers();
		$second = Plugin::renderers();

		$this->assertSame( $first['yandex'], $second['yandex'] );
	}

	/**
	 * Init registers the settings-update hook.
	 *
	 * @return void
	 */
	public function test_init_registers_update_option_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'update_option_nwff_settings', array( Plugin::class, 'on_settings_updated' ) )
		);
	}

	/**
	 * Updating feed_slug flushes rewrite rules.
	 *
	 * Seeds rewrite_rules with a sentinel, updates the feed slug,
	 * and asserts the sentinel is gone — flush_rewrite_rules always
	 * deletes the option.
	 *
	 * @return void
	 */
	public function test_settings_update_with_changed_slug_flushes(): void {
		update_option( 'nwff_settings', array( 'core' => array( 'feed_slug' => 'old' ) ) );
		update_option( 'rewrite_rules', array( 'sentinel' => 'sentinel' ) );

		update_option( 'nwff_settings', array( 'core' => array( 'feed_slug' => 'new' ) ) );

		$this->assertNotSame(
			array( 'sentinel' => 'sentinel' ),
			get_option( 'rewrite_rules' )
		);
	}

	/**
	 * Updating an unrelated setting keeps rewrite rules intact.
	 *
	 * @return void
	 */
	public function test_settings_update_with_same_slug_keeps_rules(): void {
		update_option( 'nwff_settings', array( 'core' => array( 'feed_slug' => 'feed' ) ) );
		update_option( 'rewrite_rules', array( 'sentinel' => 'sentinel' ) );

		update_option(
			'nwff_settings',
			array(
				'core' => array(
					'feed_slug'   => 'feed',
					'depth_hours' => 8,
				),
			)
		);

		$this->assertSame(
			array( 'sentinel' => 'sentinel' ),
			get_option( 'rewrite_rules' )
		);
	}

	/**
	 * Init registers the cron cleanup hook.
	 *
	 * @return void
	 */
	public function test_init_registers_cleanup_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'nwff_cleanup_meta', array( \NWFF\Install\Cron::class, 'cleanup' ) )
		);
	}

	/**
	 * Init registers the meta-box hook.
	 *
	 * @return void
	 */
	public function test_init_registers_meta_boxes_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'add_meta_boxes', array( \NWFF\Admin\PostFlags::class, 'add_metabox' ) )
		);
	}

	/**
	 * Init registers the PostFlags save hook.
	 *
	 * @return void
	 */
	public function test_init_registers_post_flags_save_hook(): void {
		Plugin::init();

		$this->assertNotFalse(
			has_action( 'save_post_post', array( \NWFF\Admin\PostFlags::class, 'save' ) )
		);
	}

	/**
	 * Override feed_path registers its own rewrite rule.
	 *
	 * @return void
	 */
	public function test_rewrite_registers_override_path(): void {
		global $wp_rewrite;

		// Pretty permalinks required for rewrite rules to be generated.
		// @phpstan-ignore-next-line -- set_permalink_structure() lives in WP_UnitTestCase_Base, outside the WP stubs.
		$this->set_permalink_structure( '/%postname%/' );

		update_option(
			'nwff_settings',
			array(
				'core'      => array( 'feed_slug' => 'feed' ),
				'renderers' => array(
					'dzen' => array( 'feed_path' => 'feed/zen' ),
				),
			)
		);

		$wp_rewrite->init();
		Plugin::register_rewrite_rules();

		$wp_rewrite->rules = $wp_rewrite->rewrite_rules();
		update_option( 'rewrite_rules', $wp_rewrite->rules );

		// flush_rewrite_rules() defers when wp_loaded has not fired (unit tests).
		// Regenerate synchronously so the test can assert on the stored rules.
		$wp_rewrite->rules = $wp_rewrite->rewrite_rules();
		update_option( 'rewrite_rules', $wp_rewrite->rules );

		$rules = get_option( 'rewrite_rules' );

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( '^feed/zen/?$', $rules );
		$this->assertSame( 'index.php?nwff_feed_slug=dzen', $rules['^feed/zen/?$'] );
	}

	/**
	 * Default path keeps working when override is set.
	 *
	 * @return void
	 */
	public function test_rewrite_still_serves_default_path(): void {
		global $wp_rewrite;

		// Pretty permalinks required for rewrite rules to be generated.
		// @phpstan-ignore-next-line -- set_permalink_structure() lives in WP_UnitTestCase_Base, outside the WP stubs.
		$this->set_permalink_structure( '/%postname%/' );

		update_option(
			'nwff_settings',
			array(
				'core'      => array( 'feed_slug' => 'feed' ),
				'renderers' => array(
					'dzen' => array( 'feed_path' => 'feed/zen' ),
				),
			)
		);

		$wp_rewrite->init();
		Plugin::register_rewrite_rules();

		// flush_rewrite_rules() defers when wp_loaded has not fired (unit tests).
		// Regenerate synchronously so the test can assert on the stored rules.
		$wp_rewrite->rules = $wp_rewrite->rewrite_rules();
		update_option( 'rewrite_rules', $wp_rewrite->rules );

		$rules = get_option( 'rewrite_rules' );

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( '^feed/([^/]+)\.xml/?$', $rules );
	}
}
