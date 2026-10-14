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
}
