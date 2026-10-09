<?php
/**
 * Resolves the Yandex genre for a normalized feed item.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Render\Yandex;

/**
 * Computes yandex:genre per ADR-ARCH-0003.
 *
 * Priority: interview > article > lenta (tag) > lenta (length) > message.
 */
final class GenreResolver {

	private const GENRE_INTERVIEW = 'interview';
	private const GENRE_ARTICLE   = 'article';
	private const GENRE_LENTA     = 'lenta';
	private const GENRE_MESSAGE   = 'message';

	private const LENGTH_THRESHOLD = 80;

	/**
	 * Resolve the genre for a normalized feed item.
	 *
	 * @param array<string, mixed> $item     Normalized item with 'terms' and 'content'.
	 * @param array<string, mixed> $settings Yandex renderer settings.
	 * @return string One of: interview, article, lenta, message.
	 */
	public function resolve( array $item, array $settings ): string {
		$tag_ids = $this->collect_post_tag_ids(
			isset( $item['terms'] ) && is_array( $item['terms'] ) ? $item['terms'] : array()
		);

		$checks = array(
			self::GENRE_INTERVIEW => (int) ( $settings['tag_interview'] ?? 0 ),
			self::GENRE_ARTICLE   => (int) ( $settings['tag_article'] ?? 0 ),
			self::GENRE_LENTA     => (int) ( $settings['tag_lenta'] ?? 0 ),
		);

		foreach ( $checks as $genre => $tag_id ) {
			if ( $tag_id > 0 && in_array( $tag_id, $tag_ids, true ) ) {
				return $genre;
			}
		}

		if ( ! empty( $settings['by_length'] ) ) {
			$content = isset( $item['content'] ) && is_string( $item['content'] ) ? $item['content'] : '';
			if ( mb_strlen( $content ) < self::LENGTH_THRESHOLD ) {
				return self::GENRE_LENTA;
			}
		}

		return self::GENRE_MESSAGE;
	}

	/**
	 * Extract post_tag term IDs from a normalized terms list.
	 *
	 * Only terms with taxonomy = 'post_tag' are considered — see ADR-ARCH-0003.
	 *
	 * @param array<int, mixed> $terms Normalized terms.
	 * @return int[]
	 */
	private function collect_post_tag_ids( array $terms ): array {
		$ids = array();

		foreach ( $terms as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}
			if ( 'post_tag' !== ( $term['taxonomy'] ?? '' ) ) {
				continue;
			}
			$ids[] = (int) ( $term['id'] ?? 0 );
		}

		return $ids;
	}
}
