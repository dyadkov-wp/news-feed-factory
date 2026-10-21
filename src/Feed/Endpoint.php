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

	private const DEFAULT_CACHE_TTL_MINUTES = 5;

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
	 * Returns null when the slug is unknown or the renderer is
	 * disabled. Endpoint layers the normalization config onto the
	 * collector args (ADR-ARCH-0006): the renderer declares only
	 * "which posts", normalization is separate.
	 *
	 * @param string               $slug     Renderer slug.
	 * @param array<string, mixed> $settings Merged settings array.
	 * @return array{body: string, content_type: string}|null Null if the slug is unknown or the renderer is disabled.
	 */
	public function serve( string $slug, array $settings ): ?array {
		if ( ! isset( $this->renderers[ $slug ] ) ) {
			return null;
		}

		$renderer = $this->renderers[ $slug ];
		if ( ! $renderer->is_enabled( $settings ) ) {
			return null;
		}

		$args                         = $renderer->get_collector_args( $settings );
		$args['normalization_config'] = $renderer->get_normalization_config();

		$key = $this->cache_key( $slug, $args );

		$cached = $this->cache->get( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return array(
				'body'         => $cached,
				'content_type' => $renderer->get_content_type(),
			);
		}

		$items = $this->collect_and_normalize( $args );

		$renderers_settings = is_array( $settings['renderers'] ?? null ) ? $settings['renderers'] : array();
		$renderer_settings  = is_array( $renderers_settings[ $slug ] ?? null ) ? $renderers_settings[ $slug ] : array();

		$body = $renderer->render(
			$items,
			array(
				'channel'  => $this->channel(),
				'settings' => $renderer_settings,
			)
		);

		$this->cache->set( $key, $body, $this->ttl_seconds( $settings ) );

		return array(
			'body'         => $body,
			'content_type' => $renderer->get_content_type(),
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
	 * Return the cache TTL in seconds, from the core settings.
	 *
	 * @param array<string, mixed> $settings Merged settings.
	 * @return int
	 */
	private function ttl_seconds( array $settings ): int {
		$core = is_array( $settings['core'] ?? null ) ? $settings['core'] : array();
		$ttl  = (int) ( $core['cache_ttl'] ?? self::DEFAULT_CACHE_TTL_MINUTES );

		if ( $ttl <= 0 ) {
			$ttl = self::DEFAULT_CACHE_TTL_MINUTES;
		}

		return $ttl * MINUTE_IN_SECONDS;
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

		return TransientCache::FEED_PREFIX . md5( $encoded );
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
		$language = substr( (string) get_bloginfo( 'language' ), 0, 2 );

		return array(
			'title'       => (string) get_bloginfo( 'name' ),
			'link'        => (string) home_url( '/' ),
			'description' => (string) get_bloginfo( 'description' ),
			'language'    => '' !== $language ? $language : 'ru',
		);
	}
}
