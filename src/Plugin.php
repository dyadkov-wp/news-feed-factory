<?php
/**
 * Plugin bootstrap class.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF;

use NWFF\Cache\TransientCache;
use NWFF\Data\Collector;
use NWFF\Data\MetaRepository;
use NWFF\Feed\Endpoint;
use NWFF\Normalize\ContentNormalizer;
use NWFF\Normalize\HtmlSanitizer;
use NWFF\Normalize\ImageExtractor;
use NWFF\Normalize\Truncator;
use NWFF\Render\Yandex\GenreResolver;
use NWFF\Render\YandexRenderer;

/**
 * Registers plugin hooks and wires collaborators (ADR-ARCH-0001).
 *
 * Stateless: everything is static. The renderer map is the single
 * extension point for new formats.
 */
final class Plugin {

	private const QUERY_VAR    = 'nwff_feed_slug';
	private const DEFAULT_SLUG = 'feed';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_rewrite_rules' ) );
		add_action( 'template_redirect', array( self::class, 'handle_feed_request' ) );
	}

	/**
	 * Return the renderer map keyed by slug.
	 *
	 * @return array<string, \NWFF\Render\RendererInterface>
	 */
	public static function renderers(): array {
		return array(
			'yandex' => new YandexRenderer( new GenreResolver(), new Truncator() ),
		);
	}

	/**
	 * Register feed rewrite rules and the feed query var.
	 *
	 * @return void
	 */
	public static function register_rewrite_rules(): void {
		$slug = preg_quote( self::feed_slug(), '/' );

		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^/]+)' );
		add_rewrite_rule(
			'^' . $slug . '/([^/]+)\.xml$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Serve a feed on template_redirect.
	 *
	 * Returns 404 when the slug is unknown. Echoes the response body
	 * and exits, so the theme is never loaded.
	 *
	 * @return void
	 */
	public static function handle_feed_request(): void {
		$slug = get_query_var( self::QUERY_VAR );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return;
		}

		$response = self::make_endpoint()->serve( $slug );

		if ( null === $response ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		header( 'Content-Type: ' . $response['content_type'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body is XML already escaped by the renderer.
		echo $response['body'];
		exit;
	}

	/**
	 * Return the configured feed slug, defaulting to "feed".
	 *
	 * @return string
	 */
	private static function feed_slug(): string {
		$settings = get_option( 'nwff_settings' );

		if ( ! is_array( $settings ) ) {
			return self::DEFAULT_SLUG;
		}

		$slug = $settings['feed_slug'] ?? self::DEFAULT_SLUG;

		if ( ! is_string( $slug ) || '' === $slug ) {
			return self::DEFAULT_SLUG;
		}

		return $slug;
	}

	/**
	 * Build the feed endpoint with all collaborators wired.
	 *
	 * @return Endpoint
	 */
	private static function make_endpoint(): Endpoint {
		$sanitizer = new HtmlSanitizer();

		return new Endpoint(
			self::renderers(),
			new Collector( new MetaRepository(), new ImageExtractor(), $sanitizer ),
			new ContentNormalizer( $sanitizer, new Truncator() ),
			new TransientCache()
		);
	}
}
