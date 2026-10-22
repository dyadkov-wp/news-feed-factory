<?php
/**
 * Integration tests for the Dzen enclosure resolver.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Render\Dzen;

use NWFF\Render\Dzen\EnclosureResolver;
use WP_UnitTestCase;

/**
 * Covers EnclosureResolver::resolve() against real attachments.
 *
 * @group integration
 */
final class EnclosureResolverTest extends WP_UnitTestCase {

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
			self::markTestSkipped( 'GD extension is required for EnclosureResolver tests.' );
		}

		self::$image_file = sys_get_temp_dir() . '/nwff-enclosure-' . uniqid() . '.jpg';

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
	 * Create an attachment fixture and return its ID.
	 *
	 * @return int
	 */
	private function make_attachment(): int {
		$id = self::factory()->attachment->create_upload_object( self::$image_file );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		return (int) $id;
	}

	/**
	 * Build a minimal item array with image fields.
	 *
	 * @param int|null    $attachment_id Attachment ID.
	 * @param string|null $url           Image URL.
	 * @return array<string, mixed>
	 */
	private function item( ?int $attachment_id, ?string $url ): array {
		return array(
			'image_attachment_id' => $attachment_id,
			'image_url'           => $url,
		);
	}

	/**
	 * A featured image yields a well-formed enclosure.
	 *
	 * @return void
	 */
	public function test_featured_image_returns_enclosure(): void {
		$attachment_id = $this->make_attachment();
		$item          = $this->item( $attachment_id, 'http://example.com/a.jpg' );

		$result = ( new EnclosureResolver() )->resolve( $item );

		$this->assertIsArray( $result );
		$this->assertSame( 'http://example.com/a.jpg', $result['url'] );
		$this->assertSame( 'image/jpeg', $result['type'] );
		$this->assertGreaterThan( 0, $result['length'] );
	}

	/**
	 * No attachment ID → null (featured absent, no fallback, TD-05).
	 *
	 * @return void
	 */
	public function test_missing_attachment_id_returns_null(): void {
		$item = $this->item( null, 'http://example.com/a.jpg' );

		$this->assertNull( ( new EnclosureResolver() )->resolve( $item ) );
	}

	/**
	 * Attachment present but image URL missing → null (contract desync guard).
	 *
	 * @return void
	 */
	public function test_missing_image_url_returns_null(): void {
		$attachment_id = $this->make_attachment();
		$item          = $this->item( $attachment_id, null );

		$this->assertNull( ( new EnclosureResolver() )->resolve( $item ) );
	}

	/**
	 * Missing filesize in metadata falls back to 0.
	 *
	 * @return void
	 */
	public function test_missing_filesize_falls_back_to_zero(): void {
		$attachment_id = $this->make_attachment();

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) ) {
			unset( $metadata['filesize'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$item = $this->item( $attachment_id, 'http://example.com/a.jpg' );

		$result = ( new EnclosureResolver() )->resolve( $item );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['length'] );
	}

	/**
	 * Unrecognized file type → null, WARNING routed to the injected logger.
	 *
	 * @return void
	 */
	public function test_unrecognized_file_type_returns_null_and_logs(): void {
		$attachment_id = $this->make_attachment();
		$item          = $this->item( $attachment_id, 'http://example.com/a.jpg' );

		$messages = array();
		$logger   = static function ( string $message ) use ( &$messages ): void {
			$messages[] = $message;
		};

		add_filter( 'get_attached_file', '__return_false' );
		try {
			$result = ( new EnclosureResolver( $logger ) )->resolve( $item );
		} finally {
			remove_filter( 'get_attached_file', '__return_false' );
		}

		$this->assertNull( $result );
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'attachment #' . $attachment_id, $messages[0] );
		$this->assertStringContainsString( 'unrecognized file type', $messages[0] );
	}

	/**
	 * Two resolves with the same attachment hit get_post_metadata once.
	 *
	 * @return void
	 */
	public function test_metadata_is_cached_per_instance(): void {
		$attachment_id = $this->make_attachment();
		$item          = $this->item( $attachment_id, 'http://example.com/a.jpg' );
		$resolver      = new EnclosureResolver();

		$calls = 0;
		$spy   = static function ( $value, $post_id, $meta_key ) use ( &$calls, $attachment_id ) {
			if ( (int) $post_id === $attachment_id && '_wp_attachment_metadata' === $meta_key ) {
				++$calls;
			}
			return $value;
		};

		add_filter( 'get_post_metadata', $spy, 10, 3 );
		try {
			$resolver->resolve( $item );
			$resolver->resolve( $item );
		} finally {
			remove_filter( 'get_post_metadata', $spy, 10 );
		}

		$this->assertSame( 1, $calls );
	}
}
