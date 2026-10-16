<?php
/**
 * Integration tests for the settings page.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Settings;

use NWFF\Settings\Page;
use NWFF\Settings\Store;
use WP_UnitTestCase;

require_once __DIR__ . '/FakeRenderer.php';

/**
 * Covers Page::register() setting, section and field registration.
 *
 * @group integration
 */
final class PageTest extends WP_UnitTestCase {

	/**
	 * Clear settings globals between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wp_settings_sections, $wp_settings_fields;

		unset( $wp_settings_sections['nwff_settings'] );
		unset( $wp_settings_fields['nwff_settings'] );

		parent::tearDown();
	}

	/**
	 * Register adds the options page to admin_menu.
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_menu(): void {
		$page = new Page( new Store( array() ) );
		$page->register();

		$this->assertNotFalse( has_action( 'admin_menu' ) );
	}

	/**
	 * Register declares the nwff_settings option.
	 *
	 * @return void
	 */
	public function test_register_declares_setting(): void {
		global $wp_registered_settings;

		$page = new Page( new Store( array() ) );
		$page->register();

		// register_setting defers to admin_init; fire it explicitly.
		do_action( 'admin_init' );

		$this->assertArrayHasKey( 'nwff_settings', $wp_registered_settings );
	}

	/**
	 * Register adds the core section.
	 *
	 * @return void
	 */
	public function test_register_adds_core_section(): void {
		global $wp_settings_sections;

		$page = new Page( new Store( array() ) );
		$page->register();

		$this->assertArrayHasKey( 'nwff_settings', $wp_settings_sections );
		$this->assertArrayHasKey( 'nwff_core', $wp_settings_sections['nwff_settings'] );
	}

	/**
	 * Register adds a section per renderer.
	 *
	 * @return void
	 */
	public function test_register_adds_renderer_section(): void {
		global $wp_settings_sections;

		$store = new Store( array( 'yandex' => new FakeRenderer( 'yandex' ) ) );
		$page  = new Page( $store );
		$page->register();

		$this->assertArrayHasKey( 'nwff_renderer_yandex', $wp_settings_sections['nwff_settings'] );
	}
}
