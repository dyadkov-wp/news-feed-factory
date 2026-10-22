<?php
/**
 * Enclosure resolver for the Dzen renderer.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Render\Dzen;

/**
 * Resolves an <enclosure> entry from a normalized item's featured image.
 *
 * Not pure: calls WP attachment APIs. Tests are integration-level.
 */
final class EnclosureResolver {

	/**
	 * Per-instance cache of wp_get_attachment_metadata() results.
	 *
	 * @var array<int, array<string, mixed>|false>
	 */
	private array $metadata_cache = array();

	/**
	 * Build the enclosure payload for an item, or null when unavailable.
	 *
	 * @param array<string, mixed> $item Normalized item.
	 * @return array{url: string, type: string, length: int}|null
	 */
	public function resolve( array $item ): ?array {
		$attachment_id = $item['image_attachment_id'] ?? null;
		$url           = $item['image_url'] ?? null;

		if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			return null;
		}
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		$type = wp_check_filetype( (string) get_attached_file( $attachment_id ) )['type'];
		if ( false === $type || '' === $type ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WARNING logged per ADR-ARCH-0005.
			error_log(
				sprintf(
					'News Feed Factory: enclosure skipped for attachment #%d — unrecognized file type.',
					$attachment_id
				)
			);
			return null;
		}

		return array(
			'url'    => $url,
			'type'   => $type,
			'length' => $this->filesize( $attachment_id ),
		);
	}

	/**
	 * Fetch filesize from cached attachment metadata, with 0 fallback.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	private function filesize( int $attachment_id ): int {
		if ( ! array_key_exists( $attachment_id, $this->metadata_cache ) ) {
			$this->metadata_cache[ $attachment_id ] = wp_get_attachment_metadata( $attachment_id );
		}

		$metadata = $this->metadata_cache[ $attachment_id ];
		if ( ! is_array( $metadata ) ) {
			return 0;
		}

		$filesize = $metadata['filesize'] ?? 0;

		return is_int( $filesize ) && $filesize > 0 ? $filesize : 0;
	}
}
