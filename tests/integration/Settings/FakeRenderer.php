<?php
/**
 * Fake renderer for Settings-layer tests.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Settings;

use NWFF\Render\RendererInterface;

/**
 * Minimal RendererInterface implementation for Store tests.
 *
 * Named class rather than anonymous: CONTRIBUTING.md forbids
 * anonymous doubles. Carries a fixed slug and schema; everything
 * else is a no-op.
 */
final class FakeRenderer implements RendererInterface {

	/**
	 * Renderer slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Settings schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * Constructor.
	 *
	 * @param string               $slug   Renderer slug.
	 * @param array<string, mixed> $schema Settings schema.
	 */
	public function __construct( string $slug, array $schema = array() ) {
		$this->slug   = $slug;
		$this->schema = $schema;
	}

	/**
	 * Machine slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Content type.
	 *
	 * @return string
	 */
	public function get_content_type(): string {
		return 'text/plain';
	}

	/**
	 * Normalization config.
	 *
	 * @return array<string, mixed>
	 */
	public function get_normalization_config(): array {
		return array();
	}

	/**
	 * Settings schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings_schema(): array {
		return $this->schema;
	}

	/**
	 * Enabled predicate.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return bool
	 */
	public function is_enabled( array $settings ): bool {
		return ! empty( $settings['renderers'][ $this->slug ]['enabled'] );
	}

	/**
	 * Collector args.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	public function get_collector_args( array $settings ): array {
		return array();
	}

	/**
	 * Render.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @param array<string, mixed>             $args  Args.
	 * @return string
	 */
	public function render( array $items, array $args ): string {
		return '';
	}
}
