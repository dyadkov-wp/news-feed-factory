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

	/**
	 * Return the renderer's feed path override, or an empty string.
	 *
	 * @param string $renderer_slug Renderer slug.
	 * @return string Override path with surrounding slashes trimmed.
	 */
	public function feed_path_for( string $renderer_slug ): string {
		$all  = $this->get();
		$path = $all['renderers'][ $renderer_slug ]['feed_path'] ?? '';

		if ( ! is_string( $path ) ) {
			return '';
		}

		return trim( $path, '/' );
	}

	/**
	 * Resolve the effective feed path: override or renderer default.
	 *
	 * @param string $renderer_slug Renderer slug.
	 * @return string Relative feed path.
	 */
	public function resolve_feed_path( string $renderer_slug ): string {
		$override = $this->feed_path_for( $renderer_slug );

		if ( '' !== $override ) {
			return $override;
		}

		$renderers = $this->renderers();
		if ( ! isset( $renderers[ $renderer_slug ] ) ) {
			return '';
		}

		return $renderers[ $renderer_slug ]->get_default_feed_path( $this->feed_slug() );
	}

	/**
	 * Sanitize the settings input before it is saved.
	 *
	 * Whitelist driven: only keys present in the core schema and in
	 * each renderer's get_settings_schema() survive. Unknown keys
	 * (including renderer slugs not registered in the store) are
	 * dropped. Checkboxes absent from the input are stored as false,
	 * because unchecked boxes are not submitted by the browser.
	 *
	 * @param mixed $input Raw input from register_setting.
	 * @return array{core: array<string, mixed>, renderers: array<string, array<string, mixed>>}
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return array(
				'core'      => array(),
				'renderers' => array(),
			);
		}

		$core_in      = is_array( $input['core'] ?? null ) ? $input['core'] : array();
		$renderers_in = is_array( $input['renderers'] ?? null ) ? $input['renderers'] : array();

		$core_out = array();

		if ( array_key_exists( 'feed_slug', $core_in ) ) {
			$core_out['feed_slug'] = sanitize_title( (string) $core_in['feed_slug'] );
		}

		if ( array_key_exists( 'depth_hours', $core_in ) ) {
			$core_out['depth_hours'] = max( 1, min( 12, (int) $core_in['depth_hours'] ) );
		}

		if ( array_key_exists( 'cache_ttl', $core_in ) ) {
			$core_out['cache_ttl'] = max( 1, min( 5, (int) $core_in['cache_ttl'] ) );
		}

		$renderers_out = array();

		foreach ( $this->renderers as $slug => $renderer ) {
			if ( ! isset( $renderers_in[ $slug ] ) || ! is_array( $renderers_in[ $slug ] ) ) {
				continue;
			}

			$schema = $renderer->get_settings_schema();
			$values = $renderers_in[ $slug ];
			$clean  = array();

			foreach ( $schema as $key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$type = is_string( $field['type'] ?? null ) ? $field['type'] : 'text';

				if ( 'feed_path' === $key ) {
					$clean[ $key ] = $this->sanitize_feed_path( (string) ( $values[ $key ] ?? '' ) );
					continue;
				}

				if ( 'checkbox' === $type ) {
					$clean[ $key ] = ! empty( $values[ $key ] );
					continue;
				}

				if ( ! array_key_exists( $key, $values ) ) {
					continue;
				}

				$clean[ $key ] = $this->sanitize_field( $field, $values[ $key ] );
			}

			$renderers_out[ $slug ] = $clean;
		}

		return array(
			'core'      => $core_out,
			'renderers' => $renderers_out,
		);
	}

	/**
	 * Sanitize a single field value by its schema type.
	 *
	 * Checkboxes are handled before this method is called.
	 *
	 * @param array<string, mixed> $field Field schema.
	 * @param mixed                $value Raw value.
	 * @return int|string
	 */
	private function sanitize_field( array $field, $value ) {
		$type = is_string( $field['type'] ?? null ) ? $field['type'] : 'text';

		if ( 'number' === $type ) {
			$int = (int) $value;

			if ( isset( $field['min'] ) ) {
				$int = max( (int) $field['min'], $int );
			}
			if ( isset( $field['max'] ) ) {
				$int = min( (int) $field['max'], $int );
			}

			return $int;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize a feed path override.
	 *
	 * Allowed: ASCII letters, digits, "_", "-", "/". Surrounding
	 * slashes are trimmed. Anything else becomes an empty string
	 * (falls back to the renderer default).
	 *
	 * @param string $path Raw path.
	 * @return string Sanitized path or empty string.
	 */
	private function sanitize_feed_path( string $path ): string {
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		return 1 === preg_match( '#^[a-z0-9_\-/]+$#i', $path ) ? $path : '';
	}
}
