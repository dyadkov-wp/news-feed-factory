<?php
/**
 * Feed endpoint.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Feed;

use NWFF\Cache\TransientCache;
use NWFF\Data\Collector;
use NWFF\Normalize\ContentNormalizer;
use NWFF\Render\RendererInterface;

/**
 * Turns a renderer slug into a feed response (ADR-ARCH-0001).
 *
 * Pure request handler: returns body and content type, does not
 * echo, does not exit. The HTTP layer lives in Plugin.
 */
final class Endpoint {

	private const CACHE_PREFIX = 'nwff_feed_';
	private const CACHE_TTL    = 300;

	private const DEFAULT_DEPTH_HOURS = 4;

	/**
	 * Renderer map keyed by slug.
	 *
	 * @var array<string, RendererInterface>
	 */
	private array $renderers;

	/**
	 * Data collector.
	 *
	 * @var Collector
	 */
	private Collector $collector;

	/**
	 * Content normalizer.
	 *
	 * @var ContentNormalizer
	 */
	private ContentNormalizer $normalizer;

	/**
	 * Transient cache.
	 *
	 * @var TransientCache
	 */
	private TransientCache $cache;

	/**
	 * Create the endpoint.
	 *
	 * @param array<string, RendererInterface> $renderers  Renderer map.
	 * @param Collector                        $collector  Data collector.
	 * @param ContentNormalizer                $normalizer Content normalizer.
	 * @param TransientCache                   $cache      Transient cache.
	 */
	public function __construct(
		array $renderers,
		Collector $collector,
		ContentNormalizer $normalizer,
		TransientCache $cache
	) {
		$this->renderers  = $renderers;
		$this->collector  = $collector;
		$this->normalizer = $normalizer;
		$this->cache      = $cache;
	}

	/**
	 * Serve a feed for the given slug.
	 *
	 * @param string $slug Renderer slug.
	 * @return array{body: string, content_type: string}|null Null if the slug is unknown.
	 */
	public function serve( string $slug ): ?array {
		if ( ! isset( $this->renderers[ $slug ] ) ) {
			return null;
		}

		$renderer = $this->renderers[ $slug ];
		$args     = $this->build_args( $renderer );
		$key      = $this->cache_key( $slug, $args );

		$cached = $this->cache->get( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return array(
				'body'         => $cached,
				'content_type' => $renderer->get_content_type(),
			);
		}

		$items = $this->collect_and_normalize( $args );

		$body = $renderer->render(
			$items,
			array(
				'channel'  => $this->channel(),
				'settings' => array(),
			)
		);

		$this->cache->set( $key, $body, self::CACHE_TTL );

		return array(
			'body'         => $body,
			'content_type' => $renderer->get_content_type(),
		);
	}

	/**
	 * Build Collector args for the given renderer.
	 *
	 * @param RendererInterface $renderer Renderer.
	 * @return array<string, mixed>
	 */
	private function build_args( RendererInterface $renderer ): array {
		return array(
			'depth_hours'          => self::DEFAULT_DEPTH_HOURS,
			'limit'                => 0,
			'taxonomy_include'     => array(),
			'taxonomy_exclude'     => array(),
			'normalization_config' => $renderer->get_normalization_config(),
		);
	}

	/**
	 * Collect raw items and normalize them.
	 *
	 * @param array<string, mixed> $args Collector args.
	 * @return array<int, array<string, mixed>> Normalized items.
	 */
	private function collect_and_normalize( array $args ): array {
		$raw   = $this->collector->collect( $args );
		$items = array();

		foreach ( $raw as $row ) {
			$item = $this->normalizer->normalize( $row, $args['normalization_config'] );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Build a stable cache key for the given slug and args.
	 *
	 * Taxonomy maps are sorted by key and term ID so that the same
	 * semantics always produce the same key.
	 *
	 * @param string               $slug Renderer slug.
	 * @param array<string, mixed> $args Collector args.
	 * @return string
	 */
	private function cache_key( string $slug, array $args ): string {
		$normalized = array(
			'slug'             => $slug,
			'depth_hours'      => $args['depth_hours'],
			'limit'            => $args['limit'],
			'taxonomy_include' => $this->sort_taxonomy( $args['taxonomy_include'] ),
			'taxonomy_exclude' => $this->sort_taxonomy( $args['taxonomy_exclude'] ),
		);

		$encoded = wp_json_encode( $normalized );
		if ( ! is_string( $encoded ) ) {
			$encoded = '';
		}

		return self::CACHE_PREFIX . md5( $encoded );
	}

	/**
	 * Sort a taxonomy map by key and term IDs.
	 *
	 * @param mixed $map Taxonomy map.
	 * @return array<string, int[]>
	 */
	private function sort_taxonomy( $map ): array {
		if ( ! is_array( $map ) ) {
			return array();
		}

		$clean = array();
		foreach ( $map as $taxonomy => $term_ids ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $term_ids ) ) {
				continue;
			}
			$ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
			sort( $ids );
			$clean[ $taxonomy ] = $ids;
		}

		ksort( $clean );

		return $clean;
	}

	/**
	 * Build channel metadata from site settings.
	 *
	 * @return array<string, string>
	 */
	private function channel(): array {
		return array(
			'title'       => (string) get_bloginfo( 'name' ),
			'link'        => (string) home_url( '/' ),
			'description' => (string) get_bloginfo( 'description' ),
			'language'    => (string) get_bloginfo( 'language' ),
		);
	}
}
