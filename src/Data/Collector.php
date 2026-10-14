<?php
/**
 * Feed collector.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Data;

use NWFF\Normalize\HtmlSanitizer;
use NWFF\Normalize\ImageExtractor;
use WP_Post;

/**
 * Collects posts and metadata into the raw item shape consumed by
 * ContentNormalizer (ADR-ARCH-0002).
 *
 * The only class that knows how to obtain data. All WordPress-
 * specific work — WP_Query, wp_kses, featured image, terms —
 * happens here; ContentNormalizer stays pure.
 */
final class Collector {

	/**
	 * Feed metadata repository.
	 *
	 * @var MetaRepository
	 */
	private MetaRepository $meta_repository;

	/**
	 * Featured image extractor.
	 *
	 * @var ImageExtractor
	 */
	private ImageExtractor $image_extractor;

	/**
	 * HTML sanitizer for content.
	 *
	 * @var HtmlSanitizer
	 */
	private HtmlSanitizer $html_sanitizer;

	/**
	 * Create the collector.
	 *
	 * @param MetaRepository $meta_repository Feed metadata repository.
	 * @param ImageExtractor $image_extractor Featured image extractor.
	 * @param HtmlSanitizer  $html_sanitizer  HTML sanitizer for content.
	 */
	public function __construct(
		MetaRepository $meta_repository,
		ImageExtractor $image_extractor,
		HtmlSanitizer $html_sanitizer
	) {
		$this->meta_repository = $meta_repository;
		$this->image_extractor = $image_extractor;
		$this->html_sanitizer  = $html_sanitizer;
	}

	/**
	 * Collect raw items.
	 *
	 * @param array<string, mixed> $args Collection args.
	 * @return array<int, array<string, mixed>> Raw items in contract shape.
	 */
	public function collect( array $args ): array {
		$args = $this->prepare_args( $args );
		$ids  = $this->query_post_ids( $args );
		if ( array() === $ids ) {
			return array();
		}

		$meta = $this->meta_repository->get_by_post_ids( $ids );

		$items = array();
		foreach ( $ids as $post_id ) {
			if ( ! empty( $meta[ $post_id ]['exclude_from_feeds'] ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$items[] = $this->build_raw_item( $post, $args['normalization_config'] );
		}

		return $items;
	}

	/**
	 * Apply defaults and sort taxonomy arrays for stable cache keys.
	 *
	 * @param array<string, mixed> $args Raw args.
	 * @return array<string, mixed>
	 */
	private function prepare_args( array $args ): array {
		$args = array_merge(
			array(
				'depth_hours'          => 4,
				'limit'                => 0,
				'taxonomy_include'     => array(),
				'taxonomy_exclude'     => array(),
				'normalization_config' => array(),
			),
			$args
		);

		$args['depth_hours'] = (int) $args['depth_hours'];
		$args['limit']       = (int) $args['limit'];

		$args['taxonomy_include'] = $this->sort_taxonomy_map( $args['taxonomy_include'] );
		$args['taxonomy_exclude'] = $this->sort_taxonomy_map( $args['taxonomy_exclude'] );

		return $args;
	}

	/**
	 * Sort a taxonomy => terms map by key for deterministic ordering.
	 *
	 * @param mixed $map Taxonomy map.
	 * @return array<string, int[]>
	 */
	private function sort_taxonomy_map( $map ): array {
		if ( ! is_array( $map ) ) {
			return array();
		}

		$clean = array();
		foreach ( $map as $taxonomy => $term_ids ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy ) {
				continue;
			}
			if ( ! is_array( $term_ids ) ) {
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
	 * Run WP_Query and return matched post IDs.
	 *
	 * @param array<string, mixed> $args Prepared args.
	 * @return int[]
	 */
	private function query_post_ids( array $args ): array {
		$query_args = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'posts_per_page'         => $args['limit'] > 0 ? $args['limit'] : -1,
		);

		if ( $args['depth_hours'] > 0 ) {
			$query_args['date_query'] = array(
				array(
					'column' => 'post_date_gmt',
					'after'  => gmdate( 'Y-m-d H:i:s', time() - $args['depth_hours'] * HOUR_IN_SECONDS ),
				),
			);
		}

		$tax_query = $this->build_tax_query(
			$args['taxonomy_include'],
			$args['taxonomy_exclude']
		);
		if ( array() !== $tax_query ) {
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Taxonomy filtering is the core feature; term_id-indexed queries on a news site are bounded by depth_hours.
			$query_args['tax_query'] = $tax_query;
		}

		$query = new \WP_Query( $query_args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Build a WP_Query tax_query clause list.
	 *
	 * @param array<string, int[]> $include_map Taxonomy map to require.
	 * @param array<string, int[]> $exclude_map Taxonomy map to exclude.
	 * @return array<int|string, mixed>
	 */
	private function build_tax_query( array $include_map, array $exclude_map ): array {
		$clauses = array();

		foreach ( $include_map as $taxonomy => $ids ) {
			if ( array() === $ids ) {
				continue;
			}
			$clauses[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $ids,
				'operator' => 'IN',
			);
		}

		foreach ( $exclude_map as $taxonomy => $ids ) {
			if ( array() === $ids ) {
				continue;
			}
			$clauses[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $ids,
				'operator' => 'NOT IN',
			);
		}

		if ( count( $clauses ) > 1 ) {
			$clauses['relation'] = 'AND';
		}

		return $clauses;
	}

	/**
	 * Build a raw item from a post and the normalization config.
	 *
	 * @param WP_Post              $post   Post.
	 * @param array<string, mixed> $config Normalization config.
	 * @return array<string, mixed>
	 */
	private function build_raw_item( WP_Post $post, array $config ): array {
		$allowed_html = $config['allowed_html'] ?? null;
		$allowed_html = is_array( $allowed_html ) ? $allowed_html : null;

		$image = $this->image_extractor->extract( (int) $post->ID, $config );

		$include_terms = ! empty( $config['include_terms'] );

		return array(
			'id'           => (string) get_permalink( $post ),
			'link'         => (string) get_permalink( $post ),
			'title'        => (string) get_the_title( $post ),
			'content'      => $this->html_sanitizer->sanitize( (string) $post->post_content, $allowed_html ),
			'excerpt'      => (string) $post->post_excerpt,
			'published'    => (string) $post->post_date_gmt,
			'image_url'    => $image['url'] ?? null,
			'image_width'  => $image['width'] ?? null,
			'image_height' => $image['height'] ?? null,
			'terms'        => $include_terms ? $this->collect_terms( (int) $post->ID ) : array(),
		);
	}

	/**
	 * Collect category and post_tag terms for a post.
	 *
	 * Categories first, then tags; each list ordered by name as
	 * returned by wp_get_post_terms() (ADR-ARCH-0002).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_terms( int $post_id ): array {
		$terms = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$post_terms = wp_get_post_terms(
				$post_id,
				$taxonomy,
				array(
					'orderby' => 'name',
					'order'   => 'ASC',
				)
			);
			if ( is_wp_error( $post_terms ) ) {
				continue;
			}

			foreach ( $post_terms as $term ) {
				$terms[] = array(
					'taxonomy' => $taxonomy,
					'id'       => (int) $term->term_id,
					'name'     => (string) $term->name,
					'slug'     => (string) $term->slug,
				);
			}
		}

		return $terms;
	}
}
