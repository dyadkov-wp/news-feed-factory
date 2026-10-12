<?php
/**
 * Integration tests for ImageExtractor.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Normalize;

use NWFF\Normalize\ImageExtractor;
use WP_UnitTestCase;

/**
 * Covers ImageExtractor::extract() against real attachments.
 *
 * @group integration
 */
final class ImageExtractorTest extends WP_UnitTestCase {

	/**
	 * Extractor under test.
	 *
	 * @var ImageExtractor
	 */
	private ImageExtractor $extractor;

	/**
	 * Path to the shared fixture image.
	 *
	 * @var string
	 */
	private static string $image_file = '';

	/**
	 * Create a 1200x800 fixture JPEG once for the whole class.
	 *
	 * @return void
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			self::markTestSkipped( 'GD extension is required for ImageExtractor tests.' );
		}

		self::$image_file = sys_get_temp_dir() . '/nwff-fixture-' . uniqid() . '.jpg';

		$image = imagecreatetruecolor( 1200, 800 );
		if ( false === $image ) {
			self::markTestSkipped( 'Failed to create fixture image.' );
		}

		imagejpeg( $image, self::$image_file, 90 );
		imagedestroy( $image );
	}

	/**
	 * Remove the fixture image.
	 *
	 * @return void
	 */
	public static function tear_down_after_class(): void {
		if ( '' !== self::$image_file && file_exists( self::$image_file ) ) {
			wp_delete_file( self::$image_file );
		}
		parent::tear_down_after_class();
	}

	/**
	 * Set up the extractor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->extractor = new ImageExtractor();
	}

	/**
	 * A post without a featured image yields null.
	 *
	 * @return void
	 */
	public function test_no_thumbnail_returns_null(): void {
		$post_id = self::factory()->post->create();

		$this->assertNull( $this->extractor->extract( $post_id, array() ) );
	}

	/**
	 * A featured image yields url, width and height.
	 *
	 * @return void
	 */
	public function test_featured_image_returns_url_and_dimensions(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( self::$image_file, $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = $this->extractor->extract( $post_id, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'width', $result );
		$this->assertArrayHasKey( 'height', $result );
		$this->assertSame( 1200, $result['width'] );
		$this->assertSame( 800, $result['height'] );
		$this->assertStringContainsString( '.jpg', $result['url'] );
	}

	/**
	 * An image below image_min_width is rejected.
	 *
	 * @return void
	 */
	public function test_image_below_min_width_is_rejected(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( self::$image_file, $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = $this->extractor->extract( $post_id, array( 'image_min_width' => 2000 ) );

		$this->assertNull( $result );
	}

	/**
	 * An image at exactly image_min_width is kept.
	 *
	 * @return void
	 */
	public function test_image_at_min_width_is_kept(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( self::$image_file, $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = $this->extractor->extract( $post_id, array( 'image_min_width' => 1200 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1200, $result['width'] );
	}

	/**
	 * An image below image_min_height is rejected.
	 *
	 * @return void
	 */
	public function test_image_below_min_height_is_rejected(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( self::$image_file, $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = $this->extractor->extract( $post_id, array( 'image_min_height' => 1000 ) );

		$this->assertNull( $result );
	}

	/**
	 * Both thresholds are checked independently.
	 *
	 * @return void
	 */
	public function test_both_min_dimensions_checked(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( self::$image_file, $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = $this->extractor->extract(
			$post_id,
			array(
				'image_min_width'  => 1000,
				'image_min_height' => 1000,
			)
		);

		$this->assertNull( $result );
	}

	/**
	 * Missing or null thresholds do not filter the image.
	 *
	 * @return void
	 */
	public function test_null_thresholds_keep_image(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( self::$image_file, $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = $this->extractor->extract(
			$post_id,
			array(
				'image_min_width'  => null,
				'image_min_height' => null,
			)
		);

		$this->assertIsArray( $result );
	}

	/**
	 * Zero thresholds are treated as "no filter".
	 *
	 * @return void
	 */
	public function test_zero_thresholds_keep_image(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( self::$image_file, $post_id );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = $this->extractor->extract(
			$post_id,
			array(
				'image_min_width'  => 0,
				'image_min_height' => 0,
			)
		);

		$this->assertIsArray( $result );
	}
}
