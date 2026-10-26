<?php
/**
 * Integration tests for the feed collector.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Data;

use NWFF\Data\Collector;
use NWFF\Data\MetaRepository;
use NWFF\Normalize\HtmlSanitizer;
use NWFF\Normalize\ImageExtractor;
use WP_UnitTestCase;

/**
 * Covers Collector::collect() against real WP_Query, wp_kses,
 * taxonomy and attachment APIs.
 *
 * @group integration
 */
final class CollectorTest extends WP_UnitTestCase {
	/**
	 * Collector under test.
	 *
	 * @var Collector
	 */
	private Collector $collector;

	/**
	 * Set up the collector with real collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Emulate a modern production theme. HTML5 caption support is standard
		// since WP 5.0, ensuring <figure>/<figcaption> output instead of legacy <div>.
		add_theme_support( 'html5', array( 'caption' ) );

		$this->collector = new Collector(
			new MetaRepository(),
			new ImageExtractor(),
			new HtmlSanitizer()
		);
	}

	/**
	 * Build args with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function args( array $overrides = array() ): array {
		return array_merge(
			array(
				'depth_hours'          => 4,
				'limit'                => 0,
				'taxonomy_include'     => array(),
				'taxonomy_exclude'     => array(),
				'normalization_config' => array(
					'include_terms'    => false,
					'allowed_html'     => null,
					'image_min_width'  => null,
					'image_min_height' => null,
				),
			),
			$overrides
		);
	}

	/**
	 * Create a published post with the given age in hours.
	 *
	 * @param int                  $hours_ago Age in hours.
	 * @param array<string, mixed> $extra     Extra post args.
	 * @return int Post ID.
	 */
	private function make_post( int $hours_ago = 0, array $extra = array() ): int {
		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_status'   => 'publish',
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $hours_ago * HOUR_IN_SECONDS ),
					'post_content'  => '<p>Body</p>',
					'post_title'    => 'Title',
					'post_excerpt'  => '<p>Teaser</p>',
				),
				$extra
			)
		);
	}

	/**
	 * Insert a nwff_feed_meta row.
	 *
	 * @param int $post_id Post ID.
	 * @param int $exclude exclude_from_feeds value.
	 * @return void
	 */
	private function insert_meta( int $post_id, int $exclude ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test helper: direct insert into the plugin's custom table; no WP API exists for that.
		$wpdb->insert(
			$wpdb->prefix . 'nwff_feed_meta',
			array(
				'post_id'            => $post_id,
				'exclude_from_feeds' => $exclude,
				'updated_at'         => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * No matching posts yield an empty array.
	 *
	 * @return void
	 */
	public function test_empty_query_returns_empty_array(): void {
		$this->assertSame( array(), $this->collector->collect( $this->args() ) );
	}

	/**
	 * A recent published post is collected.
	 *
	 * @return void
	 */
	public function test_recent_post_is_collected(): void {
		$post_id = $this->make_post( 1 );
		$result  = $this->collector->collect( $this->args() );
		$this->assertCount( 1, $result );
		$this->assertSame( get_permalink( $post_id ), $result[0]['link'] );
	}

	/**
	 * A post older than depth_hours is skipped.
	 *
	 * @return void
	 */
	public function test_post_older_than_depth_is_skipped(): void {
		$this->make_post( 6 );
		$this->assertSame( array(), $this->collector->collect( $this->args( array( 'depth_hours' => 4 ) ) ) );
	}

	/**
	 * Drafts are not collected.
	 *
	 * @return void
	 */
	public function test_draft_posts_are_skipped(): void {
		$this->make_post( 1, array( 'post_status' => 'draft' ) );
		$this->assertSame( array(), $this->collector->collect( $this->args() ) );
	}

	/**
	 * The raw item shape matches the contract.
	 *
	 * @return void
	 */
	public function test_raw_shape_matches_contract(): void {
		$this->make_post( 1 );
		$result = $this->collector->collect( $this->args() );
		$this->assertCount( 1, $result );
		$item = $result[0];
		foreach ( array( 'id', 'link', 'title', 'content', 'excerpt', 'published', 'author', 'image_url', 'image_width', 'image_height', 'terms' ) as $key ) {
			$this->assertArrayHasKey( $key, $item, "Missing key: {$key}" );
		}
		$this->assertIsString( $item['id'] );
		$this->assertIsString( $item['link'] );
		$this->assertIsString( $item['title'] );
		$this->assertIsString( $item['content'] );
		$this->assertIsString( $item['excerpt'] );
		$this->assertIsString( $item['published'] );
		$this->assertIsString( $item['author'] );
		$this->assertNull( $item['image_url'] );
		$this->assertNull( $item['image_width'] );
		$this->assertNull( $item['image_height'] );
		$this->assertSame( array(), $item['terms'] );
	}

	/**
	 * Limit caps the number of items.
	 *
	 * @return void
	 */
	public function test_limit_caps_items(): void {
		$this->make_post( 1 );
		$this->make_post( 1 );
		$this->make_post( 1 );
		$result = $this->collector->collect( $this->args( array( 'limit' => 2 ) ) );
		$this->assertCount( 2, $result );
	}

	/**
	 * Restricts to matching terms for taxonomy_include.
	 *
	 * @return void
	 */
	public function test_taxonomy_include_filters_posts(): void {
		$term     = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
			)
		);
		$included = $this->make_post( 1 );
		$excluded = $this->make_post( 1 );
		wp_set_object_terms( $included, array( $term ), 'category' );
		$result = $this->collector->collect(
			$this->args( array( 'taxonomy_include' => array( 'category' => array( $term ) ) ) )
		);
		$this->assertCount( 1, $result );
		$this->assertSame( get_permalink( $included ), $result[0]['link'] );
		$this->assertNotSame( get_permalink( $excluded ), $result[0]['link'] );
	}

	/**
	 * Removes matching posts for taxonomy_exclude.
	 *
	 * @return void
	 */
	public function test_taxonomy_exclude_filters_posts(): void {
		$term     = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Sponsored',
			)
		);
		$kept     = $this->make_post( 1 );
		$excluded = $this->make_post( 1 );
		wp_set_object_terms( $excluded, array( $term ), 'category' );
		$result = $this->collector->collect(
			$this->args( array( 'taxonomy_exclude' => array( 'category' => array( $term ) ) ) )
		);
		$this->assertCount( 1, $result );
		$this->assertSame( get_permalink( $kept ), $result[0]['link'] );
	}

	/**
	 * Order of taxonomy keys does not affect the result set.
	 *
	 * @return void
	 */
	public function test_taxonomy_order_does_not_change_result(): void {
		$cat     = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'A',
			)
		);
		$tag     = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'B',
			)
		);
		$post_id = $this->make_post( 1 );
		wp_set_object_terms( $post_id, array( $cat ), 'category' );
		wp_set_object_terms( $post_id, array( $tag ), 'post_tag' );
		$args_a   = $this->args(
			array(
				'taxonomy_include' => array(
					'category' => array( $cat ),
					'post_tag' => array( $tag ),
				),
			)
		);
		$args_b   = $this->args(
			array(
				'taxonomy_include' => array(
					'post_tag' => array( $tag ),
					'category' => array( $cat ),
				),
			)
		);
		$result_a = $this->collector->collect( $args_a );
		$result_b = $this->collector->collect( $args_b );
		$this->assertSame(
			array_column( $result_a, 'link' ),
			array_column( $result_b, 'link' )
		);
	}

	/**
	 * Posts flagged exclude_from_feeds are skipped.
	 *
	 * @return void
	 */
	public function test_excluded_posts_are_skipped(): void {
		$kept     = $this->make_post( 1 );
		$excluded = $this->make_post( 1 );
		$this->insert_meta( $excluded, 1 );
		$result = $this->collector->collect( $this->args() );
		$this->assertCount( 1, $result );
		$this->assertSame( get_permalink( $kept ), $result[0]['link'] );
	}

	/**
	 * Populates terms when include_terms is true.
	 *
	 * @return void
	 */
	public function test_include_terms_false_returns_empty_terms(): void {
		$term    = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
			)
		);
		$post_id = $this->make_post( 1 );
		wp_set_object_terms( $post_id, array( $term ), 'category' );
		$result = $this->collector->collect( $this->args() );
		$this->assertSame( array(), $result[0]['terms'] );
	}

	/**
	 * Yields empty terms when include_terms is false.
	 *
	 * @return void
	 */
	public function test_include_terms_true_populates_terms(): void {
		$term    = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
			)
		);
		$post_id = $this->make_post( 1 );
		wp_set_object_terms( $post_id, array( $term ), 'category' );
		$config = array(
			'include_terms'    => true,
			'allowed_html'     => null,
			'image_min_width'  => null,
			'image_min_height' => null,
		);
		$result = $this->collector->collect( $this->args( array( 'normalization_config' => $config ) ) );
		$this->assertNotEmpty( $result[0]['terms'] );
		$first = $result[0]['terms'][0];
		$this->assertSame( 'category', $first['taxonomy'] );
		$this->assertSame( $term, $first['id'] );
		$this->assertSame( 'News', $first['name'] );
	}

	/**
	 * Featured image populates image_* fields.
	 *
	 * @return void
	 */
	public function test_featured_image_populates_image_fields(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD required.' );
		}
		$file = sys_get_temp_dir() . '/nwff-collector-' . uniqid() . '.jpg';
		$img  = imagecreatetruecolor( 1200, 800 );
		imagejpeg( $img, $file, 90 );
		imagedestroy( $img );
		$post_id       = $this->make_post( 1 );
		$attachment_id = self::factory()->attachment->create_upload_object( $file, $post_id );
		set_post_thumbnail( $post_id, $attachment_id );
		$result = $this->collector->collect( $this->args() );
		$this->assertIsString( $result[0]['image_url'] );
		$this->assertSame( 1200, $result[0]['image_width'] );
		$this->assertSame( 800, $result[0]['image_height'] );
		wp_delete_file( $file );
	}

	/**
	 * Missing featured image leaves image_* null.
	 *
	 * @return void
	 */
	public function test_missing_featured_image_returns_null_fields(): void {
		$this->make_post( 1 );
		$result = $this->collector->collect( $this->args() );
		$this->assertNull( $result[0]['image_url'] );
		$this->assertNull( $result[0]['image_width'] );
		$this->assertNull( $result[0]['image_height'] );
	}

	/**
	 * Sanitizes content via wp_kses before normalization.
	 *
	 * @return void
	 */
	public function test_content_is_sanitized(): void {
		$this->make_post(
			1,
			array( 'post_content' => '<p>Hello</p><script>alert(1)</script>' )
		);
		$result = $this->collector->collect( $this->args() );
		$this->assertStringContainsString( '<p>Hello</p>', $result[0]['content'] );
		$this->assertStringNotContainsString( '<script', $result[0]['content'] );
	}

	/**
	 * Populates title, link, id and published from WP post fields.
	 *
	 * @return void
	 */
	public function test_item_fields_source(): void {
		$post_id = $this->make_post( 1, array( 'post_title' => 'My Title' ) );
		$result  = $this->collector->collect( $this->args() );
		$item    = $result[0];
		$this->assertSame( 'My Title', $item['title'] );
		$this->assertSame( get_permalink( $post_id ), $item['link'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['published'] );
	}

	/**
	 * Author is populated from the post's author display name.
	 *
	 * @return void
	 */
	public function test_author_populated_from_post_author(): void {
		$user_id = (int) self::factory()->user->create( array( 'display_name' => 'Ivan Ivanov' ) );
		$this->make_post( 1, array( 'post_author' => $user_id ) );
		$result = $this->collector->collect( $this->args() );
		$this->assertSame( 'Ivan Ivanov', $result[0]['author'] );
	}

	/**
	 * A [caption] shortcode is expanded to <figure><figcaption> before sanitize.
	 *
	 * @return void
	 */
	public function test_caption_shortcode_is_rendered_as_figure(): void {
		$this->make_post(
			1,
			array(
				'post_content' => '[caption id="attachment_1" align="aligncenter" width="300"]<img src="https://example.com/a.jpg" alt="" width="300" height="200" />Test photo[/caption]',
			)
		);
		$items = $this->collector->collect( $this->args() );
		$this->assertNotEmpty( $items );
		$content = $items[0]['content'];
		$this->assertStringContainsString( '<figure', $content );
		$this->assertStringContainsString( '<figcaption', $content );
		$this->assertStringContainsString( 'Test photo', $content );
		$this->assertStringNotContainsString( '[caption', $content );
		$this->assertStringNotContainsString( '[/caption]', $content );
	}

	/**
	 * Unknown shortcodes are stripped, not rendered.
	 *
	 * @return void
	 */
	public function test_unknown_shortcode_is_stripped(): void {
		// Register a dummy shortcode so the core WP strip_shortcodes() function can detect and remove it.
		// In production, the plugin itself (e.g., TagDiv) does this.
		add_shortcode( 'td_block_1', '__return_empty_string' );

		$this->make_post(
			1,
			array(
				'post_content' => '<p>Before</p>[td_block_1 title="X"]<p>After</p>',
			)
		);
		$items = $this->collector->collect( $this->args() );

		// Clean up after ourselves (though WP_UnitTestCase tearDown also resets $shortcode_tags).
		remove_shortcode( 'td_block_1' );

		$this->assertNotEmpty( $items );
		$content = $items[0]['content'];
		$this->assertStringNotContainsString( '[td_block_1', $content );
		$this->assertStringContainsString( 'Before', $content );
		$this->assertStringContainsString( 'After', $content );
	}
}
