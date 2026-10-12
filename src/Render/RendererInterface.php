<?php
/**
 * Renderer contract for feed formats.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Render;

/**
 * Contract every feed renderer implements (ADR-ARCH-0001).
 *
 * A renderer declares its identity (slug, content type), its
 * normalization requirements, and produces the feed body from
 * normalized items.
 */
interface RendererInterface {

	/**
	 * Machine slug for the renderer, e.g. "yandex".
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * HTTP Content-Type for the feed response.
	 *
	 * @return string
	 */
	public function get_content_type(): string;

	/**
	 * Normalization requirements for this renderer (ADR-ARCH-0002).
	 *
	 * @return array<string, mixed>
	 */
	public function get_normalization_config(): array;

	/**
	 * Produce the feed body.
	 *
	 * @param array<int, array<string, mixed>> $items Normalized items.
	 * @param array<string, mixed>             $args  Request arguments.
	 * @return string
	 */
	public function render( array $items, array $args ): string;
}
