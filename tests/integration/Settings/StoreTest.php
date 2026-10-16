<?php
/**
 * Integration tests for the settings store.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Settings;

use NWFF\Settings\Store;
use WP_UnitTestCase;

require_once __DIR__ . '/FakeRenderer.php';

/**
 * Covers Store::renderers(), Store::get() and Store::feed_slug().
 *
 * @group integration
 */
final class StoreTest extends WP_UnitTestCase {

	/**
	 * Remove the settings option between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( 'nwff_settings' );
		parent::tearDown();
	}

	/**
	 * Renderers accessor returns the map passed to the constructor.
	 *
	 * @return void
	 */
	public function test_renderers_returns_map(): void {
		$renderer = new FakeRenderer( 'yandex' );
		$store    = new Store( array( 'yandex' => $renderer ) );

		$this->assertSame( array( 'yandex' => $renderer ), $store->renderers() );
	}

	/**
	 * Core defaults are present when the option is empty.
	 *
	 * @return void
	 */
	public function test_get_merges_core_defaults(): void {
		$store = new Store( array() );
		$all   = $store->get();

		$this->assertSame( 'feed', $all['core']['feed_slug'] );
		$this->assertSame( 4, $all['core']['depth_hours'] );
		$this->assertSame( 5, $all['core']['cache_ttl'] );
	}

	/**
	 * Renderer schema defaults are present when the option is empty.
	 *
	 * @return void
	 */
	public function test_get_merges_renderer_defaults(): void {
		$schema = array(
			'enabled' => array(
				'type'    => 'checkbox',
				'default' => false,
			),
			'limit'   => array(
				'type'    => 'number',
				'default' => 10,
			),
		);
		$store  = new Store( array( 'yandex' => new FakeRenderer( 'yandex', $schema ) ) );
		$all    = $store->get();

		$this->assertArrayHasKey( 'yandex', $all['renderers'] );
		$this->assertFalse( $all['renderers']['yandex']['enabled'] );
		$this->assertSame( 10, $all['renderers']['yandex']['limit'] );
	}

	/**
	 * Saved option values override schema defaults.
	 *
	 * @return void
	 */
	public function test_get_prefers_saved_values(): void {
		update_option(
			'nwff_settings',
			array(
				'core'      => array( 'depth_hours' => 12 ),
				'renderers' => array( 'yandex' => array( 'enabled' => true ) ),
			)
		);

		$schema = array(
			'enabled' => array(
				'type'    => 'checkbox',
				'default' => false,
			),
		);
		$store  = new Store( array( 'yandex' => new FakeRenderer( 'yandex', $schema ) ) );
		$all    = $store->get();

		$this->assertSame( 12, $all['core']['depth_hours'] );
		$this->assertTrue( $all['renderers']['yandex']['enabled'] );
		$this->assertSame( 'feed', $all['core']['feed_slug'] );
	}

	/**
	 * Feed slug defaults to "feed" when the option is empty.
	 *
	 * @return void
	 */
	public function test_feed_slug_default(): void {
		$store = new Store( array() );

		$this->assertSame( 'feed', $store->feed_slug() );
	}

	/**
	 * Feed slug returns the saved value.
	 *
	 * @return void
	 */
	public function test_feed_slug_from_option(): void {
		update_option( 'nwff_settings', array( 'core' => array( 'feed_slug' => 'news' ) ) );

		$store = new Store( array() );

		$this->assertSame( 'news', $store->feed_slug() );
	}

	/**
	 * Empty feed slug falls back to "feed".
	 *
	 * @return void
	 */
	public function test_feed_slug_empty_falls_back(): void {
		update_option( 'nwff_settings', array( 'core' => array( 'feed_slug' => '' ) ) );

		$store = new Store( array() );

		$this->assertSame( 'feed', $store->feed_slug() );
	}
}
