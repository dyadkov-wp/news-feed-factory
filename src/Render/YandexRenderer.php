<?php
/**
 * Yandex feed renderer.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Render;

use DateTimeImmutable;
use NWFF\Normalize\Truncator;
use NWFF\Render\Yandex\GenreResolver;

/**
 * Renders a Yandex "Свежее и актуальное" RSS feed (ADR-ARCH-0003).
 *
 * Thin: takes normalized items, produces XML. All content shaping
 * happened earlier (ADR-ARCH-0002). Genre resolution delegates to
 * GenreResolver.
 */
final class YandexRenderer implements RendererInterface {

	private const SLUG         = 'yandex';
	private const CONTENT_TYPE = 'application/rss+xml; charset=UTF-8';

	private const TITLE_MAX_LENGTH = 200;

	private const YANDEX_NAMESPACE = 'http://news.yandex.ru';
	private const MEDIA_NAMESPACE  = 'http://search.yahoo.com/mrss/';

	private const TAXONOMY_DEFAULT = 'category';

	/**
	 * Genre resolver.
	 *
	 * @var GenreResolver
	 */
	private GenreResolver $genre_resolver;

	/**
	 * Text truncator for the 200-char title limit.
	 *
	 * @var Truncator
	 */
	private Truncator $truncator;

	/**
	 * Create the renderer.
	 *
	 * @param GenreResolver $genre_resolver Genre resolver.
	 * @param Truncator     $truncator      Text truncator.
	 */
	public function __construct( GenreResolver $genre_resolver, Truncator $truncator ) {
		$this->genre_resolver = $genre_resolver;
		$this->truncator      = $truncator;
	}

	/**
	 * Machine slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * HTTP Content-Type.
	 *
	 * @return string
	 */
	public function get_content_type(): string {
		return self::CONTENT_TYPE;
	}

	/**
	 * Normalization requirements for this renderer.
	 *
	 * @return array<string, mixed>
	 */
	public function get_normalization_config(): array {
		return array(
			'max_content_length' => null,
			'truncate_mode'      => 'none',
			'truncate_limit'     => 0,
			'allowed_html'       => null,
			'image_min_width'    => null,
			'image_min_height'   => null,
			'include_terms'      => true,
		);
	}

	/**
	 * Settings schema for this renderer (ADR-ARCH-0006).
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings_schema(): array {
		return array(
			'enabled'            => array(
				'type'    => 'checkbox',
				'label'   => 'Enable Yandex feed',
				'default' => false,
			),
			'tag_interview'      => array(
				'type'    => 'number',
				'label'   => 'Interview tag ID',
				'default' => 0,
				'min'     => 0,
			),
			'tag_article'        => array(
				'type'    => 'number',
				'label'   => 'Article tag ID',
				'default' => 0,
				'min'     => 0,
			),
			'tag_lenta'          => array(
				'type'    => 'number',
				'label'   => 'Lenta tag ID',
				'default' => 0,
				'min'     => 0,
			),
			'by_length'          => array(
				'type'    => 'checkbox',
				'label'   => 'Detect lenta by content length',
				'default' => true,
			),
			'include_taxonomies' => array(
				'type'        => 'text',
				'label'       => 'Include taxonomies',
				'default'     => '',
				'description' => 'Format: category:12,15|post_tag:100.',
			),
			'exclude_taxonomies' => array(
				'type'        => 'text',
				'label'       => 'Exclude taxonomies',
				'default'     => '',
				'description' => 'Format: category:12,15|post_tag:100.',
			),
		);
	}

	/**
	 * Checks whether the Yandex renderer is enabled.
	 *
	 * @param array<string, mixed> $settings Merged settings.
	 * @return bool
	 */
	public function is_enabled( array $settings ): bool {
		$renderers = is_array( $settings['renderers'] ?? null ) ? $settings['renderers'] : array();
		$renderer  = is_array( $renderers[ self::SLUG ] ?? null ) ? $renderers[ self::SLUG ] : array();

		return ! empty( $renderer['enabled'] );
	}

	/**
	 * Returns the collector args for the Yandex feed.
	 *
	 * Covers only "which posts": depth, limit and taxonomy
	 * filters. Normalization config is layered on by Endpoint
	 * (ADR-ARCH-0006).
	 *
	 * @param array<string, mixed> $settings Merged settings.
	 * @return array<string, mixed>
	 */
	public function get_collector_args( array $settings ): array {
		$core      = is_array( $settings['core'] ?? null ) ? $settings['core'] : array();
		$renderers = is_array( $settings['renderers'] ?? null ) ? $settings['renderers'] : array();
		$renderer  = is_array( $renderers[ self::SLUG ] ?? null ) ? $renderers[ self::SLUG ] : array();

		return array(
			'depth_hours'      => (int) ( $core['depth_hours'] ?? 4 ),
			'limit'            => 0,
			'taxonomy_include' => $this->parse_taxonomies( $renderer['include_taxonomies'] ?? '' ),
			'taxonomy_exclude' => $this->parse_taxonomies( $renderer['exclude_taxonomies'] ?? '' ),
		);
	}

	/**
	 * Produce the RSS body from normalized items.
	 *
	 * @param array<int, array<string, mixed>> $items Normalized items.
	 * @param array<string, mixed>             $args  Renderer args.
	 * @return string
	 */
	public function render( array $items, array $args ): string {
		$channel  = is_array( $args['channel'] ?? null ) ? $args['channel'] : array();
		$settings = is_array( $args['settings'] ?? null ) ? $args['settings'] : array();

		$body = '';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$body .= $this->render_item( $item, $settings );
		}

		return $this->render_document( $channel, $body );
	}

	/**
	 * Parse a taxonomy filter string into a taxonomy => IDs map.
	 *
	 * Format: "category:12,15|post_tag:100". Entries without a
	 * taxonomy prefix default to "category". Values are accepted
	 * only if they are all digits and cast to an int greater than
	 * zero; anything else is dropped silently. A taxonomy with no
	 * surviving IDs is omitted. Empty input yields an empty array.
	 *
	 * @param mixed $raw Raw filter string.
	 * @return array<string, int[]>
	 */
	private function parse_taxonomies( $raw ): array {
		if ( ! is_string( $raw ) ) {
			return array();
		}

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

			if ( false !== strpos( $chunk, ':' ) ) {
				$parts    = explode( ':', $chunk, 2 );
				$taxonomy = trim( $parts[0] );
				$ids_raw  = $parts[1];
			} else {
				$taxonomy = self::TAXONOMY_DEFAULT;
				$ids_raw  = $chunk;
			}

			if ( '' === $taxonomy ) {
				continue;
			}

			$ids = array();
			foreach ( explode( ',', $ids_raw ) as $id_raw ) {
				if ( ! ctype_digit( $id_raw ) ) {
					continue;
				}
				$id = (int) $id_raw;
				if ( $id <= 0 ) {
					continue;
				}
				$ids[] = $id;
			}

			if ( array() === $ids ) {
				continue;
			}

			$result[ $taxonomy ] = array_values( array_unique( $ids ) );
		}

		return $result;
	}

	/**
	 * Wrap the channel metadata and item body into a full RSS document.
	 *
	 * @param array<string, mixed> $channel Channel metadata.
	 * @param string               $body    Rendered item elements.
	 * @return string
	 */
	private function render_document( array $channel, string $body ): string {
		$title       = $this->escape_xml( $this->channel_field( $channel, 'title' ) );
		$link        = $this->escape_xml( $this->channel_field( $channel, 'link' ) );
		$description = $this->escape_xml( $this->channel_field( $channel, 'description' ) );
		$language    = $this->escape_xml( $this->channel_field( $channel, 'language' ) );
		$yandex_ns   = self::YANDEX_NAMESPACE;
		$media_ns    = self::MEDIA_NAMESPACE;

		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:yandex="{$yandex_ns}" xmlns:media="{$media_ns}">
  <channel>
    <title>{$title}</title>
    <link>{$link}</link>
    <description>{$description}</description>
    <language>{$language}</language>
{$body}  </channel>
</rss>
XML;
	}

	/**
	 * Return a string channel field, or an empty string if absent.
	 *
	 * @param array<string, mixed> $channel Channel metadata.
	 * @param string               $key     Field key.
	 * @return string
	 */
	private function channel_field( array $channel, string $key ): string {
		$value = $channel[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Render a single item element, or an empty string if it is skipped.
	 *
	 * @param array<string, mixed> $item     Normalized item.
	 * @param array<string, mixed> $settings Yandex settings for GenreResolver.
	 * @return string
	 */
	private function render_item( array $item, array $settings ): string {
		$title = isset( $item['title'] ) && is_string( $item['title'] ) ? trim( $item['title'] ) : '';
		if ( '' === $title ) {
			return '';
		}

		$link = isset( $item['link'] ) && is_string( $item['link'] ) ? $item['link'] : '';
		if ( '' === $link ) {
			return '';
		}

		$published = $item['published'] ?? null;
		if ( ! $published instanceof DateTimeImmutable ) {
			return '';
		}

		$title = $this->truncator->truncate( $title, 'chars', self::TITLE_MAX_LENGTH );

		$content  = isset( $item['content'] ) && is_string( $item['content'] ) ? $item['content'] : '';
		$excerpt  = isset( $item['excerpt'] ) && is_string( $item['excerpt'] ) ? $item['excerpt'] : '';
		$author   = isset( $item['author'] ) && is_string( $item['author'] ) ? $item['author'] : '';
		$category = $this->first_category( $item );
		$genre    = $this->genre_resolver->resolve( $item, $settings );

		$parts   = array();
		$parts[] = '    <item>';
		$parts[] = '      <title>' . $this->escape_xml( $title ) . '</title>';
		$parts[] = '      <link>' . $this->escape_xml( $link ) . '</link>';
		$parts[] = '      <pubDate>' . $this->escape_xml( $published->format( 'D, d M Y H:i:s O' ) ) . '</pubDate>';

		if ( '' !== $author ) {
			$parts[] = '      <author>' . $this->escape_xml( $author ) . '</author>';
		}

		if ( '' !== $category ) {
			$parts[] = '      <category>' . $this->escape_xml( $category ) . '</category>';
		}

		if ( '' !== $excerpt ) {
			$parts[] = '      <description>' . $this->escape_xml( $excerpt ) . '</description>';
		}

		$parts[] = '      <yandex:genre>' . $this->escape_xml( $genre ) . '</yandex:genre>';
		$parts[] = '      <yandex:full-text><![CDATA[' . $this->cdata_escape( $content ) . ']]></yandex:full-text>';
		$parts[] = '    </item>';

		return implode( "\n", $parts ) . "\n";
	}

	/**
	 * Return the name of the first category term, or an empty string.
	 *
	 * @param array<string, mixed> $item Normalized item.
	 * @return string
	 */
	private function first_category( array $item ): string {
		if ( ! isset( $item['terms'] ) || ! is_array( $item['terms'] ) ) {
			return '';
		}

		foreach ( $item['terms'] as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}
			if ( 'category' !== ( $term['taxonomy'] ?? '' ) ) {
				continue;
			}
			if ( isset( $term['name'] ) && is_string( $term['name'] ) ) {
				return $term['name'];
			}
		}

		return '';
	}

	/**
	 * Escape a string for XML text content or an attribute value.
	 *
	 * Uses htmlspecialchars with ENT_XML1 — the correct XML escaper.
	 * esc_html is designed for HTML5 output in a browser and requires
	 * WordPress to be loaded. Using it here would make the renderer
	 * untestable under the unit bootstrap (ADR-PROC-0004,
	 * ADR-PROC-0005).
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function escape_xml( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Escape a terminator inside CDATA content.
	 *
	 * A literal "]]>" inside CDATA would close the section early.
	 * Splitting it across two CDATA sections is the standard fix.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function cdata_escape( string $text ): string {
		return str_replace( ']]>', ']]]]><![CDATA[>', $text );
	}
}
