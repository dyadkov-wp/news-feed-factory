<?php
/**
 * Renderer contract for feed formats.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Render;

/**
 * Contract every feed renderer implements (ADR-ARCH-0001,
 * ADR-ARCH-0006).
 *
 * A renderer declares its identity (slug, content type), its
 * normalization requirements, its settings schema, whether it is
 * enabled, its collector args, and produces the feed body.
 */
interface RendererInterface {

	/**
	 * Machine slug for the renderer, e.g. "yandex".
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Default feed path for the given core feed slug.
	 *
	 * Pure function: no WordPress calls.
	 *
	 * @param string $feed_slug Core feed slug (e.g. "feed").
	 * @return string Relative path (e.g. "feed/dzen.xml").
	 */
	public function get_default_feed_path( string $feed_slug ): string;

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

	/**
	 * Settings schema for this renderer (ADR-ARCH-0006).
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings_schema(): array;

	/**
	 * Checks whether the renderer is enabled.
	 *
	 * @param array<string, mixed> $settings Merged settings.
	 * @return bool
	 */
	public function is_enabled( array $settings ): bool;

	/**
	 * Collector args for this renderer (ADR-ARCH-0006).
	 *
	 * Covers only "which posts" — depth, limit and taxonomy
	 * filters. The normalization config is layered on by Endpoint.
	 *
	 * @param array<string, mixed> $settings Merged settings.
	 * @return array<string, mixed>
	 */
	public function get_collector_args( array $settings ): array;
}
