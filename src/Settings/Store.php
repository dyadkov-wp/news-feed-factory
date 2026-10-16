<?php
/**
 * Settings store.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Settings;

use NWFF\Render\RendererInterface;

/**
 * Owns the renderer map and reads plugin settings (ADR-ARCH-0006).
 *
 * The single source of truth for the renderer list and the merged
 * settings array. Read-only: writes go through Settings\Page,
 * added in a later cycle.
 */
final class Store {

	/**
	 * Core field defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const CORE_DEFAULTS = array(
		'feed_slug'   => 'feed',
		'depth_hours' => 4,
		'cache_ttl'   => 5,
	);

	/**
	 * Option name.
	 */
	private const OPTION = 'nwff_settings';

	/**
	 * Renderer map keyed by slug.
	 *
	 * @var array<string, RendererInterface>
	 */
	private array $renderers;

	/**
	 * Create the store.
	 *
	 * @param array<string, RendererInterface> $renderers Renderer map.
	 */
	public function __construct( array $renderers ) {
		$this->renderers = $renderers;
	}

	/**
	 * Return the renderer map.
	 *
	 * @return array<string, RendererInterface>
	 */
	public function renderers(): array {
		return $this->renderers;
	}

	/**
	 * Return the merged settings array.
	 *
	 * Saved values override schema defaults; missing keys fall back
	 * to defaults. Unknown keys already present in the option are
	 * passed through unchanged (forward compat on downgrade). The
	 * method never writes to the option.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$saved = get_option( self::OPTION );
		$saved = is_array( $saved ) ? $saved : array();

		$saved_core      = is_array( $saved['core'] ?? null ) ? $saved['core'] : array();
		$saved_renderers = is_array( $saved['renderers'] ?? null ) ? $saved['renderers'] : array();

		$renderers_out = array();
		foreach ( $this->renderers as $slug => $renderer ) {
			$schema   = $renderer->get_settings_schema();
			$defaults = array();
			foreach ( $schema as $key => $field ) {
				if ( is_array( $field ) && array_key_exists( 'default', $field ) ) {
					$defaults[ $key ] = $field['default'];
				}
			}

			$saved_values           = is_array( $saved_renderers[ $slug ] ?? null ) ? $saved_renderers[ $slug ] : array();
			$renderers_out[ $slug ] = array_merge( $defaults, $saved_values );
		}

		return array(
			'core'      => array_merge( self::CORE_DEFAULTS, $saved_core ),
			'renderers' => $renderers_out,
		);
	}

	/**
	 * Return the configured feed slug, defaulting to "feed".
	 *
	 * @return string
	 */
	public function feed_slug(): string {
		$all  = $this->get();
		$slug = $all['core']['feed_slug'] ?? '';

		if ( ! is_string( $slug ) || '' === $slug ) {
			return 'feed';
		}

		return $slug;
	}
}
