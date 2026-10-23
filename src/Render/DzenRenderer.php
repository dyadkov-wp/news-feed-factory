<?php
/**
 * Dzen feed renderer.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Render;

use DateTimeImmutable;
use NWFF\Normalize\Truncator;
use NWFF\Render\Dzen\ContentProcessor;
use NWFF\Render\Dzen\EnclosureResolver;

/**
 * Renders a Dzen RSS 2.0 feed (ADR-ARCH-0005).
 *
 * Thin: takes normalized items, produces XML. Content shaping is
 * delegated to ContentProcessor; enclosure resolution to
 * EnclosureResolver. Enforces a per-feed byte budget and skips
 * items with no usable content.
 */
final class DzenRenderer implements RendererInterface {

	public const SLUG          = 'dzen';
	public const MAX_ITEMS     = 300;
	public const RENDER_BUDGET = 9_500_000;

	private const CONTENT_TYPE         = 'application/rss+xml; charset=UTF-8';
	private const CONTENT_NAMESPACE    = 'http://purl.org/rss/1.0/modules/content/';
	private const TITLE_MAX_LENGTH     = 200;
	private const TAXONOMY_DEFAULT     = 'category';
	private const CONTENT_TYPES        = array( 'news_only', 'news', 'blogs_only' );
	private const DEFAULT_CONTENT_TYPE = 'news_only';

	/**
	 * Content processor.
	 *
	 * @var ContentProcessor
	 */
	private ContentProcessor $content_processor;

	/**
	 * Enclosure resolver.
	 *
	 * @var EnclosureResolver
	 */
	private EnclosureResolver $enclosure_resolver;

	/**
	 * Text truncator for the 200-char title limit.
	 *
	 * @var Truncator
	 */
	private Truncator $truncator;

	/**
	 * Optional logger. Null means "wp_trigger_error() if available, else no-op".
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $logger;

	/**
	 * Per-feed render budget in bytes.
	 *
	 * @var int
	 */
	private int $render_budget;

	/**
	 * Create the renderer.
	 *
	 * @param ContentProcessor  $content_processor  Content processor.
	 * @param EnclosureResolver $enclosure_resolver Enclosure resolver.
	 * @param Truncator         $truncator          Text truncator.
	 * @param \Closure|null     $logger             Optional logger, for tests.
	 * @param int|null          $render_budget      Per-feed budget override, for tests.
	 */
	public function __construct(
		ContentProcessor $content_processor,
		EnclosureResolver $enclosure_resolver,
		Truncator $truncator,
		?\Closure $logger = null,
		?int $render_budget = null
	) {
		$this->content_processor  = $content_processor;
		$this->enclosure_resolver = $enclosure_resolver;
		$this->truncator          = $truncator;
		$this->logger             = $logger;
		$this->render_budget      = $render_budget ?? self::RENDER_BUDGET;
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
			'max_content_length' => 500_000,
			'truncate_mode'      => 'none',
			'truncate_limit'     => 0,
			'allowed_html'       => null,
			'image_min_width'    => 480,
			'image_min_height'   => 320,
			'include_terms'      => true,
		);
	}

	/**
	 * Settings schema for this renderer.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings_schema(): array {
		return array(
			'enabled'            => array(
				'type'    => 'checkbox',
				'label'   => 'Enable Dzen feed',
				'default' => false,
			),
			'content_type'       => array(
				'type'        => 'text',
				'label'       => 'Content type',
				'default'     => 'news_only',
				'description' => 'One of: news_only, news, blogs_only.',
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
	 * Checks whether the renderer is enabled.
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
	 * Returns the collector args for the Dzen feed.
	 *
	 * Covers only "which posts": depth, limit and taxonomy filters.
	 * Normalization config is layered on by Endpoint (ADR-ARCH-0006).
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
			'limit'            => self::MAX_ITEMS,
			'taxonomy_include' => $this->parse_taxonomies( $renderer['include_taxonomies'] ?? '' ),
			'taxonomy_exclude' => $this->parse_taxonomies( $renderer['exclude_taxonomies'] ?? '' ),
		);
	}

	/**
	 * Produce the RSS body from normalized items.
	 *
	 * Enforces a per-feed byte budget: on overflow the tail is dropped
	 * (newest items are kept). Items with no usable content are skipped
	 * with a WARNING.
	 *
	 * @param array<int, array<string, mixed>> $items Normalized items.
	 * @param array<string, mixed>             $args  Renderer args.
	 * @return string
	 */
	public function render( array $items, array $args ): string {
		$channel      = is_array( $args['channel'] ?? null ) ? $args['channel'] : array();
		$settings     = is_array( $args['settings'] ?? null ) ? $args['settings'] : array();
		$content_type = $this->resolve_content_type( $settings );

		$budget      = $this->render_budget;
		$body        = '';
		$current_len = 0;
		$kept        = 0;
		$total       = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			++$total;
			$rendered = $this->render_item( $item, $content_type );
			if ( null === $rendered ) {
				continue;
			}

			$rendered_len = strlen( $rendered );
			if ( $current_len + $rendered_len > $budget ) {
				$this->log(
					sprintf(
						'Dzen feed truncated at render budget: kept %d of %d items, %d bytes (budget %d).',
						$kept,
						$total,
						$current_len,
						$budget
					)
				);
				break;
			}

			$body        .= $rendered;
			$current_len += $rendered_len;
			++$kept;
		}

		return $this->render_document( $channel, $body );
	}

	/**
	 * Render one item, or null if it must be skipped.
	 *
	 * @param array<string, mixed> $item         Normalized item.
	 * @param string               $content_type Validated content_type value.
	 * @return string|null
	 */
	private function render_item( array $item, string $content_type ): ?string {
		$link = $item['link'] ?? null;
		if ( ! is_string( $link ) || '' === $link ) {
			$this->log( 'Dzen item skipped: missing link.' );
			return null;
		}

		$published = $item['published'] ?? null;
		if ( ! $published instanceof DateTimeImmutable ) {
			$this->log(
				sprintf(
					'Dzen item skipped: invalid published (link: %s).',
					$link
				)
			);
			return null;
		}

		$content = $item['content'] ?? null;
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			$this->log(
				sprintf(
					'Dzen item skipped: empty content (link: %s).',
					$link
				)
			);
			return null;
		}

		$processed = $this->content_processor->process( $content );
		if ( '' === $processed ) {
			$this->log(
				sprintf(
					'Dzen item skipped: empty content (link: %s).',
					$link
				)
			);
			return null;
		}

		$raw_title = $item['title'] ?? '';
		$title     = is_string( $raw_title )
			? $this->truncator->truncate( $raw_title, 'chars', self::TITLE_MAX_LENGTH )
			: '';

		$enclosure = $this->enclosure_resolver->resolve( $item );

		return sprintf(
			"  <item>\n"
			. "    <title>%s</title>\n"
			. "    <link>%s</link>\n"
			. "    <pubDate>%s</pubDate>\n"
			. "    <guid isPermaLink=\"true\">%s</guid>\n"
			. '%s'
			. '%s'
			. '%s'
			. "    <contentType>%s</contentType>\n"
			. "    <content:encoded><![CDATA[%s]]></content:encoded>\n"
			. "  </item>\n",
			$this->escape_xml( $title ),
			$this->escape_xml( $link ),
			$this->escape_xml( $published->format( 'D, d M Y H:i:s O' ) ),
			$this->escape_xml( $link ),
			$this->render_category( $item ),
			$this->render_author( $item ),
			$this->render_enclosure( $enclosure ),
			$this->escape_xml( $content_type ),
			$processed
		);
	}

	/**
	 * Render the category element from the item's first term, or ''.
	 *
	 * @param array<string, mixed> $item Normalized item.
	 * @return string
	 */
	private function render_category( array $item ): string {
		$terms = $item['terms'] ?? null;
		if ( ! is_array( $terms ) || ! isset( $terms[0] ) ) {
			return '';
		}

		$first = $terms[0];
		if ( ! is_array( $first ) ) {
			return '';
		}

		$name = $first['name'] ?? null;
		if ( ! is_string( $name ) || '' === $name ) {
			return '';
		}

		return '    <category>' . $this->escape_xml( $name ) . "</category>\n";
	}

	/**
	 * Render the author element, or '' when the item has no author.
	 *
	 * @param array<string, mixed> $item Normalized item.
	 * @return string
	 */
	private function render_author( array $item ): string {
		$author = $item['author'] ?? null;
		if ( ! is_string( $author ) || '' === $author ) {
			return '';
		}

		return '    <author>' . $this->escape_xml( $author ) . "</author>\n";
	}

	/**
	 * Render the enclosure element from a resolved payload, or ''.
	 *
	 * @param array{url: string, type: string, length: int}|null $enclosure Resolved enclosure.
	 * @return string
	 */
	private function render_enclosure( ?array $enclosure ): string {
		if ( null === $enclosure ) {
			return '';
		}

		return sprintf(
			"    <enclosure url=\"%s\" type=\"%s\" length=\"%d\"/>\n",
			$this->escape_xml( $enclosure['url'] ),
			$this->escape_xml( $enclosure['type'] ),
			$enclosure['length']
		);
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
		$content_ns  = self::CONTENT_NAMESPACE;

		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="{$content_ns}">
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
	 * Resolve the content_type setting to a valid value, with fallback.
	 *
	 * @param array<string, mixed> $settings Renderer settings section.
	 * @return string
	 */
	private function resolve_content_type( array $settings ): string {
		$raw = $settings['content_type'] ?? '';
		if ( ! is_string( $raw ) || ! in_array( $raw, self::CONTENT_TYPES, true ) ) {
			return self::DEFAULT_CONTENT_TYPE;
		}

		return $raw;
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
	 * Escape a value for XML text or attribute context.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function escape_xml( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
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
}
