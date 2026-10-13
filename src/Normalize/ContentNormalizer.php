<?php
/**
 * Content normalizer facade.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Normalize;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Normalizes raw collected items into the feed-ready shape
 * (ADR-ARCH-0002).
 *
 * Accepts a fixed raw array and a normalization config, applies
 * pure transformations only. All WordPress-specific work —
 * WP_Query, wp_get_post_terms, wp_kses, ImageExtractor — runs in
 * Collector before this class is called.
 */
final class ContentNormalizer {

	private const EMPTY_DATETIME = '0000-00-00 00:00:00';
	private const MODE_NONE      = 'none';

	/**
	 * Plain-text converter for excerpts.
	 *
	 * @var HtmlSanitizer
	 */
	private HtmlSanitizer $sanitizer;

	/**
	 * Text truncator for channel format limits.
	 *
	 * @var Truncator
	 */
	private Truncator $truncator;

	/**
	 * Create the normalizer with its collaborators.
	 *
	 * @param HtmlSanitizer $sanitizer Plain-text converter.
	 * @param Truncator     $truncator Text truncator.
	 */
	public function __construct( HtmlSanitizer $sanitizer, Truncator $truncator ) {
		$this->sanitizer = $sanitizer;
		$this->truncator = $truncator;
	}

	/**
	 * Normalize a raw item.
	 *
	 * Returns null if any required field (id, link, title, published)
	 * is missing, empty, or unparseable.
	 *
	 * @param array<string, mixed> $raw    Raw item from Collector.
	 * @param array<string, mixed> $config Normalization config.
	 * @return array<string, mixed>|null Normalized item, or null to skip.
	 */
	public function normalize( array $raw, array $config ): ?array {
		$id    = $raw['id'] ?? null;
		$link  = $raw['link'] ?? null;
		$title = $raw['title'] ?? null;

		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}
		if ( ! is_string( $link ) || '' === $link ) {
			return null;
		}
		if ( ! is_string( $title ) || '' === $title ) {
			return null;
		}

		$published = $this->parse_published( $raw['published'] ?? null );
		if ( null === $published ) {
			return null;
		}

		$content = isset( $raw['content'] ) && is_string( $raw['content'] )
			? $raw['content']
			: '';

		$excerpt = $this->build_excerpt(
			isset( $raw['excerpt'] ) && is_string( $raw['excerpt'] ) ? $raw['excerpt'] : '',
			$config
		);

		$include_terms = ! empty( $config['include_terms'] );
		$terms         = ( $include_terms && isset( $raw['terms'] ) && is_array( $raw['terms'] ) )
			? $raw['terms']
			: array();

		return array(
			'id'           => $id,
			'link'         => $link,
			'title'        => trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			'content'      => $content,
			'excerpt'      => $excerpt,
			'published'    => $published,
			'image_url'    => isset( $raw['image_url'] ) && is_string( $raw['image_url'] ) ? $raw['image_url'] : null,
			'image_width'  => isset( $raw['image_width'] ) && is_int( $raw['image_width'] ) ? $raw['image_width'] : null,
			'image_height' => isset( $raw['image_height'] ) && is_int( $raw['image_height'] ) ? $raw['image_height'] : null,
			'terms'        => $terms,
		);
	}

	/**
	 * Parse the published string into a UTC DateTimeImmutable.
	 *
	 * @param mixed $value Raw published value.
	 * @return DateTimeImmutable|null Null if missing or invalid.
	 */
	private function parse_published( $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( '' === $value || self::EMPTY_DATETIME === $value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Build the plain-text excerpt: convert HTML, then truncate.
	 *
	 * @param string               $html   Raw rendered HTML.
	 * @param array<string, mixed> $config Normalization config.
	 * @return string
	 */
	private function build_excerpt( string $html, array $config ): string {
		if ( '' === $html ) {
			return '';
		}

		$plain = $this->sanitizer->to_plain( $html );

		$mode = isset( $config['truncate_mode'] ) && is_string( $config['truncate_mode'] )
			? $config['truncate_mode']
			: self::MODE_NONE;

		if ( self::MODE_NONE === $mode ) {
			return $plain;
		}

		$limit = isset( $config['truncate_limit'] ) && is_int( $config['truncate_limit'] )
			? $config['truncate_limit']
			: 0;

		return $this->truncator->truncate( $plain, $mode, $limit );
	}
}
