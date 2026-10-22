<?php
/**
 * Featured image extractor for the content normalizer.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Normalize;

/**
 * Extracts a post's featured image and applies minimum dimensions
 * (ADR-ARCH-0002).
 */
final class ImageExtractor {

	/**
	 * Extract the featured image for a post.
	 *
	 * Returns null if the post has no featured image, the attachment
	 * is broken, or the image dimensions are below the configured
	 * minimums. Thresholds with value null or 0 are ignored.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $config  Normalization config.
	 * @return array{attachment_id: int, url: string, width: int, height: int}|null
	 */
	public function extract( int $post_id, array $config ): ?array {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return null;
		}

		$src = wp_get_attachment_image_src( (int) $thumbnail_id, 'full' );
		if ( ! is_array( $src ) || count( $src ) < 3 ) {
			return null;
		}

		$url    = (string) $src[0];
		$width  = (int) $src[1];
		$height = (int) $src[2];

		if ( '' === $url || $width <= 0 || $height <= 0 ) {
			return null;
		}

		$min_width  = $config['image_min_width'] ?? null;
		$min_height = $config['image_min_height'] ?? null;

		if ( is_int( $min_width ) && $min_width > 0 && $width < $min_width ) {
			return null;
		}
		if ( is_int( $min_height ) && $min_height > 0 && $height < $min_height ) {
			return null;
		}

		return array(
			'attachment_id' => (int) $thumbnail_id,
			'url'           => $url,
			'width'         => $width,
			'height'        => $height,
		);
	}
}
