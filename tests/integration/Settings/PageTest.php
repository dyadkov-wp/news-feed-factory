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
 * Covers Page::register() hook wiring and Page::register_settings()
 * side effects.
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
		global $wp_settings_sections, $wp_settings_fields, $wp_registered_settings;

		unset( $wp_settings_sections['nwff_settings'] );
		unset( $wp_settings_fields['nwff_settings'] );
		unset( $wp_registered_settings['nwff_settings'] );

		parent::tearDown();
	}

	/**
	 * Register wires the add_page callback to admin_menu.
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_menu(): void {
		$page = new Page( new Store( array() ) );
		$page->register();

		$this->assertNotFalse( has_action( 'admin_menu', array( $page, 'add_page' ) ) );
	}

	/**
	 * Register wires the register_settings callback to admin_init.
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_init(): void {
		$page = new Page( new Store( array() ) );
		$page->register();

		$this->assertNotFalse( has_action( 'admin_init', array( $page, 'register_settings' ) ) );
	}

	/**
	 * Register_settings declares the nwff_settings option.
	 *
	 * @return void
	 */
	public function test_register_settings_declares_setting(): void {
		global $wp_registered_settings;

		$page = new Page( new Store( array() ) );
		$page->register_settings();

		$this->assertIsArray( $wp_registered_settings );
		$this->assertArrayHasKey( 'nwff_settings', $wp_registered_settings );
	}

	/**
	 * Register_settings adds the core section.
	 *
	 * @return void
	 */
	public function test_register_settings_adds_core_section(): void {
		global $wp_settings_sections;

		$page = new Page( new Store( array() ) );
		$page->register_settings();

		$this->assertIsArray( $wp_settings_sections );
		$this->assertArrayHasKey( 'nwff_settings', $wp_settings_sections );
		$this->assertArrayHasKey( 'nwff_core', $wp_settings_sections['nwff_settings'] );
	}

	/**
	 * Register_settings adds a section per renderer.
	 *
	 * @return void
	 */
	public function test_register_settings_adds_renderer_section(): void {
		global $wp_settings_sections;

		$store = new Store( array( 'yandex' => new FakeRenderer( 'yandex' ) ) );
		$page  = new Page( $store );
		$page->register_settings();

		$this->assertArrayHasKey( 'nwff_settings', $wp_settings_sections );
		$this->assertArrayHasKey( 'nwff_renderer_yandex', $wp_settings_sections['nwff_settings'] );
	}
}
