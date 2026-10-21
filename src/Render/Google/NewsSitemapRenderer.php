<?php
/**
 * Google News sitemap renderer.
 *
 * @package NWFF
 */

declare( strict_types=1 );

namespace NWFF\Render\Google;

use DateTimeImmutable;
use NWFF\Render\RendererInterface;

/**
 * Emits a Google News XML sitemap.
 *
 * @see doc/adr/ADR-ARCH-0004-google-news-sitemap.md
 */
final class NewsSitemapRenderer implements RendererInterface {

	private const NAMESPACE_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	private const NAMESPACE_NEWS    = 'http://www.google.com/schemas/sitemap-news/0.9';
	private const HARD_LIMIT        = 1000;

	/**
	 * Optional settings provider. Null means "read get_option('nwff_settings')".
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $settings_reader;

	/**
	 * Optional logger. Null means "wp_trigger_error() if available, else no-op".
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $logger;

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $settings_reader Optional settings reader, for tests.
	 * @param \Closure|null $logger          Optional logger, for tests.
	 */
	public function __construct( ?\Closure $settings_reader = null, ?\Closure $logger = null ) {
		$this->settings_reader = $settings_reader;
		$this->logger          = $logger;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'google-news';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_type(): string {
		return 'application/xml; charset=UTF-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_normalization_config(): array {
		return array(
			'max_content_length' => null,
			'truncate_mode'      => 'none',
			'truncate_limit'     => 0,
			'allowed_html'       => null,
			'image_min_width'    => null,
			'image_min_height'   => null,
			'include_terms'      => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_schema(): array {
		return array(
			'enabled'               => array(
				'type'    => 'checkbox',
				'label'   => 'Enable Google News sitemap',
				'default' => false,
			),
			'news_publication_name' => array(
				'type'        => 'text',
				'label'       => 'Publication name (exact match with Google News)',
				'default'     => '',
				'description' => 'Falls back to the site title when empty.',
			),
			'news_language'         => array(
				'type'        => 'text',
				'label'       => 'Publication language (ISO 639-1, e.g. "ru")',
				'default'     => '',
				'description' => 'Falls back to the site language prefix, then "ru".',
			),
			'include_taxonomies'    => array(
				'type'        => 'text',
				'label'       => 'Include taxonomies',
				'default'     => '',
				'description' => 'Format: category:12,15|post_tag:100.',
			),
			'exclude_taxonomies'    => array(
				'type'        => 'text',
				'label'       => 'Exclude taxonomies',
				'default'     => '',
				'description' => 'Format: category:12,15|post_tag:100.',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $settings Renderer settings container.
	 */
	public function is_enabled( array $settings ): bool {
		$renderer = $settings['renderers'][ $this->get_slug() ] ?? array();

		return ! empty( $renderer['enabled'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $settings Renderer settings container.
	 */
	public function get_collector_args( array $settings ): array {
		$core     = $settings['core'] ?? array();
		$renderer = $settings['renderers'][ $this->get_slug() ] ?? array();

		return array(
			'depth_hours'      => (int) ( $core['depth_hours'] ?? 4 ),
			'limit'            => self::HARD_LIMIT,
			'taxonomy_include' => $this->parse_taxonomies( (string) ( $renderer['include_taxonomies'] ?? '' ) ),
			'taxonomy_exclude' => $this->parse_taxonomies( (string) ( $renderer['exclude_taxonomies'] ?? '' ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, array<string, mixed>> $items Normalized items.
	 * @param array<string, mixed>             $args  Collector args (unused by this renderer).
	 */
	public function render( array $items, array $args ): string {
		$renderer_settings = is_array( $args['settings'] ?? null )
			? $args['settings']
			: ( $this->read_settings()['renderers'][ $this->get_slug() ] ?? array() );

		$name     = $this->resolve_name( (string) ( $renderer_settings['news_publication_name'] ?? '' ) );
		$language = $this->resolve_language( (string) ( $renderer_settings['news_language'] ?? '' ) );

		if ( '' === $name ) {
			$this->log(
				'Google News sitemap: publication name is empty and site title is unavailable; emitting empty urlset.'
			);
			return $this->render_empty();
		}

		$urls = '';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = $this->render_url( $item, $name, $language );
			if ( null !== $url ) {
				$urls .= $url;
			}
		}

		return sprintf(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"%s\" xmlns:news=\"%s\">%s</urlset>",
			self::NAMESPACE_SITEMAP,
			self::NAMESPACE_NEWS,
			$urls
		);
	}

	/**
	 * Render an empty, namespaced `<urlset>`.
	 */
	private function render_empty(): string {
		return sprintf(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"%s\" xmlns:news=\"%s\"></urlset>",
			self::NAMESPACE_SITEMAP,
			self::NAMESPACE_NEWS
		);
	}

	/**
	 * Render a single `<url>` block. Returns null on malformed items.
	 *
	 * @param array<string, mixed> $item     Normalized item.
	 * @param string               $name     Publication name.
	 * @param string               $language Publication language.
	 */
	private function render_url( array $item, string $name, string $language ): ?string {
		$link = isset( $item['link'] ) && is_string( $item['link'] ) ? $item['link'] : '';
		if ( '' === $link ) {
			$this->log( 'Google News sitemap: item skipped, missing link.' );
			return null;
		}

		$title = isset( $item['title'] ) && is_string( $item['title'] ) ? $item['title'] : '';
		if ( '' === $title ) {
			$this->log( 'Google News sitemap: item skipped, missing title.' );
			return null;
		}

		$published = $item['published'] ?? null;
		if ( ! $published instanceof DateTimeImmutable ) {
			$this->log( 'Google News sitemap: item skipped, missing or invalid published.' );
			return null;
		}

		return sprintf(
			'<url><loc>%s</loc><news:news><news:publication><news:name>%s</news:name><news:language>%s</news:language></news:publication><news:publication_date>%s</news:publication_date><news:title>%s</news:title></news:news></url>',
			$this->escape( $link ),
			$this->escape( $name ),
			$this->escape( $language ),
			$published->format( 'c' ),
			$this->escape( $title )
		);
	}

	/**
	 * XML-escape a value. `ENT_XML1` is the XML escaper, not the HTML5 one.
	 *
	 * @param string $value Raw value.
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Log a diagnostic message.
	 *
	 * Uses the injected logger if present, then wp_trigger_error(), then no-op.
	 *
	 * @param string $message Message text.
	 */
	private function log( string $message ): void {
		if ( null !== $this->logger ) {
			( $this->logger )( $message );
			return;
		}
		if ( function_exists( 'wp_trigger_error' ) ) {
			wp_trigger_error( self::class . '::render', $message, E_USER_WARNING );
		}
	}

	/**
	 * Resolve the publication name, with get_bloginfo('name') as fallback.
	 *
	 * @param string $from_settings Configured name (may be empty).
	 */
	private function resolve_name( string $from_settings ): string {
		$name = trim( $from_settings );
		if ( '' !== $name ) {
			return $name;
		}
		if ( function_exists( 'get_bloginfo' ) ) {
			return (string) get_bloginfo( 'name' );
		}
		return '';
	}

	/**
	 * Resolve the publication language, with get_bloginfo('language') prefix and 'ru' as fallbacks.
	 *
	 * @param string $from_settings Configured language (may be empty).
	 */
	private function resolve_language( string $from_settings ): string {
		$language = trim( $from_settings );
		if ( '' !== $language ) {
			return $language;
		}
		if ( function_exists( 'get_bloginfo' ) ) {
			$prefix = substr( (string) get_bloginfo( 'language' ), 0, 2 );
			if ( '' !== $prefix ) {
				return $prefix;
			}
		}
		return 'ru';
	}

	/**
	 * Read settings: injected reader in tests, get_option() in production.
	 *
	 * @return array<string, mixed>
	 */
	private function read_settings(): array {
		if ( null !== $this->settings_reader ) {
			$settings = ( $this->settings_reader )();
			return is_array( $settings ) ? $settings : array();
		}
		if ( function_exists( 'get_option' ) ) {
			$raw = get_option( 'nwff_settings', array() );
			return is_array( $raw ) ? $raw : array();
		}
		return array();
	}

	/**
	 * Parse a taxonomies string like "category:12,15|post_tag:100".
	 *
	 * Mirrors the parser in YandexRenderer (TD-14: extract a shared helper
	 * once a third renderer needs it).
	 *
	 * @param string $raw Raw string from settings.
	 * @return array<string, array<int, int>>
	 */
	private function parse_taxonomies( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$result = array();
		foreach ( explode( '|', $raw ) as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk ) {
				continue;
			}
			$parts    = explode( ':', $chunk, 2 );
			$taxonomy = '' === $parts[0] ? 'category' : trim( $parts[0] );
			$ids_str  = $parts[1] ?? '';
			$ids      = array();
			foreach ( explode( ',', $ids_str ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( '' === $candidate || ! ctype_digit( $candidate ) ) {
					continue;
				}
				$id = (int) $candidate;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			if ( ! empty( $ids ) ) {
				$result[ $taxonomy ] = array_values( array_unique( $ids ) );
			}
		}
		return $result;
	}
}
