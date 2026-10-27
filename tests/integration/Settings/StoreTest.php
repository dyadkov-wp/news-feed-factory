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

	/**
	 * Non-array input sanitizes to an empty structure.
	 *
	 * @return void
	 */
	public function test_sanitize_non_array_returns_empty(): void {
		$store = new Store( array() );

		$this->assertSame(
			array(
				'core'      => array(),
				'renderers' => array(),
			),
			$store->sanitize( 'garbage' )
		);
	}

	/**
	 * Unknown renderer keys are dropped by the whitelist.
	 *
	 * @return void
	 */
	public function test_sanitize_drops_unknown_renderer_keys(): void {
		$schema = array(
			'enabled' => array(
				'type'    => 'checkbox',
				'default' => false,
			),
		);
		$store  = new Store( array( 'yandex' => new FakeRenderer( 'yandex', $schema ) ) );

		$result = $store->sanitize(
			array(
				'renderers' => array(
					'yandex' => array(
						'enabled' => true,
						'evil'    => 'injected',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'enabled', $result['renderers']['yandex'] );
		$this->assertArrayNotHasKey( 'evil', $result['renderers']['yandex'] );
	}

	/**
	 * Unknown renderer slugs are dropped entirely.
	 *
	 * @return void
	 */
	public function test_sanitize_drops_unknown_renderer_slug(): void {
		$store = new Store( array( 'yandex' => new FakeRenderer( 'yandex' ) ) );

		$result = $store->sanitize(
			array(
				'renderers' => array(
					'yandex'  => array(),
					'unknown' => array( 'foo' => 'bar' ),
				),
			)
		);

		$this->assertArrayHasKey( 'yandex', $result['renderers'] );
		$this->assertArrayNotHasKey( 'unknown', $result['renderers'] );
	}

	/**
	 * Checkbox values are cast to bool.
	 *
	 * @return void
	 */
	public function test_sanitize_checkbox_casts_to_bool(): void {
		$schema = array(
			'enabled' => array(
				'type'    => 'checkbox',
				'default' => false,
			),
		);
		$store  = new Store( array( 'yandex' => new FakeRenderer( 'yandex', $schema ) ) );

		$result = $store->sanitize(
			array(
				'renderers' => array(
					'yandex' => array( 'enabled' => '1' ),
				),
			)
		);

		$this->assertTrue( $result['renderers']['yandex']['enabled'] );
	}

	/**
	 * Number values are cast and clamped to min/max.
	 *
	 * @return void
	 */
	public function test_sanitize_number_clamps_to_bounds(): void {
		$schema = array(
			'tag_interview' => array(
				'type'    => 'number',
				'default' => 0,
				'min'     => 0,
				'max'     => 100,
			),
		);
		$store  = new Store( array( 'yandex' => new FakeRenderer( 'yandex', $schema ) ) );

		$too_high = $store->sanitize(
			array(
				'renderers' => array( 'yandex' => array( 'tag_interview' => '9999' ) ),
			)
		);
		$this->assertSame( 100, $too_high['renderers']['yandex']['tag_interview'] );

		$too_low = $store->sanitize(
			array(
				'renderers' => array( 'yandex' => array( 'tag_interview' => '-5' ) ),
			)
		);
		$this->assertSame( 0, $too_low['renderers']['yandex']['tag_interview'] );
	}

	/**
	 * Text values are sanitized against tags and control characters.
	 *
	 * @return void
	 */
	public function test_sanitize_text_strips_tags(): void {
		$schema = array(
			'include_taxonomies' => array(
				'type'    => 'text',
				'default' => '',
			),
		);
		$store  = new Store( array( 'yandex' => new FakeRenderer( 'yandex', $schema ) ) );

		$result = $store->sanitize(
			array(
				'renderers' => array(
					'yandex' => array( 'include_taxonomies' => '<script>x</script>category:12' ),
				),
			)
		);

		$this->assertSame( 'category:12', $result['renderers']['yandex']['include_taxonomies'] );
	}

	/**
	 * Feed slug passes through sanitize_title.
	 *
	 * @return void
	 */
	public function test_sanitize_feed_slug_uses_sanitize_title(): void {
		$store  = new Store( array() );
		$result = $store->sanitize(
			array(
				'core' => array( 'feed_slug' => 'My Feed!' ),
			)
		);

		$this->assertSame( 'my-feed', $result['core']['feed_slug'] );
	}

	/**
	 * Core depth and TTL are clamped to the allowed ranges.
	 *
	 * @return void
	 */
	public function test_sanitize_clamps_core_ranges(): void {
		$store  = new Store( array() );
		$result = $store->sanitize(
			array(
				'core' => array(
					'feed_slug'   => 'feed',
					'depth_hours' => 99,
					'cache_ttl'   => 0,
				),
			)
		);

		$this->assertSame( 12, $result['core']['depth_hours'] );
		$this->assertSame( 1, $result['core']['cache_ttl'] );
	}

	/**
	 * Returns an empty override when none is saved.
	 *
	 * @return void
	 */
	public function test_feed_path_for_returns_empty_when_not_set(): void {
		$store = new Store( array( 'dzen' => new FakeRenderer( 'dzen' ) ) );
		$this->assertSame( '', $store->feed_path_for( 'dzen' ) );
	}

	/**
	 * Returns the saved override.
	 *
	 * @return void
	 */
	public function test_feed_path_for_returns_override(): void {
		update_option(
			'nwff_settings',
			array( 'renderers' => array( 'dzen' => array( 'feed_path' => 'feed/zen' ) ) )
		);

		$store = new Store( array( 'dzen' => new FakeRenderer( 'dzen' ) ) );

		$this->assertSame( 'feed/zen', $store->feed_path_for( 'dzen' ) );
	}

	/**
	 * Returns the override when set.
	 *
	 * @return void
	 */
	public function test_resolve_feed_path_uses_override(): void {
		update_option(
			'nwff_settings',
			array( 'renderers' => array( 'dzen' => array( 'feed_path' => 'feed/zen' ) ) )
		);

		$store = new Store( array( 'dzen' => new FakeRenderer( 'dzen' ) ) );

		$this->assertSame( 'feed/zen', $store->resolve_feed_path( 'dzen' ) );
	}

	/**
	 * Falls back to the renderer default when the override is empty.
	 *
	 * @return void
	 */
	public function test_resolve_feed_path_uses_default_when_override_empty(): void {
		$store = new Store( array( 'dzen' => new FakeRenderer( 'dzen' ) ) );

		$this->assertSame( 'feed/dzen.xml', $store->resolve_feed_path( 'dzen' ) );
	}

	/**
	 * Invalid feed_path values sanitize to an empty string.
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_invalid_feed_path(): void {
		$schema = array(
			'feed_path' => array(
				'type'    => 'text',
				'default' => '',
			),
		);
		$store  = new Store( array( 'dzen' => new FakeRenderer( 'dzen', $schema ) ) );

		foreach ( array( '../etc', 'feed?x=1', 'feed zen', 'кириллица', 'a#b', 'feed%20zen' ) as $bad ) {
			$result = $store->sanitize(
				array( 'renderers' => array( 'dzen' => array( 'feed_path' => $bad ) ) )
			);
			$this->assertSame( '', $result['renderers']['dzen']['feed_path'], "Should reject: {$bad}" );
		}
	}

	/**
	 * Valid feed_path is trimmed of slashes and kept verbatim.
	 *
	 * @return void
	 */
	public function test_sanitize_accepts_valid_feed_path(): void {
		$schema = array(
			'feed_path' => array(
				'type'    => 'text',
				'default' => '',
			),
		);
		$store  = new Store( array( 'dzen' => new FakeRenderer( 'dzen', $schema ) ) );

		foreach ( array( 'feed/zen', 'feeds/dzen/v2', 'dzen' ) as $good ) {
			$result = $store->sanitize(
				array( 'renderers' => array( 'dzen' => array( 'feed_path' => $good ) ) )
			);
			$this->assertSame( $good, $result['renderers']['dzen']['feed_path'] );
		}

		$result = $store->sanitize(
			array( 'renderers' => array( 'dzen' => array( 'feed_path' => '/feed/zen/' ) ) )
		);
		$this->assertSame( 'feed/zen', $result['renderers']['dzen']['feed_path'] );
	}
}
