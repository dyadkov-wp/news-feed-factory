<?php
/**
 * Text truncator for channel format limits.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Normalize;

/**
 * Truncates plain text by channel format rules (ADR-ARCH-0002).
 *
 * Input is plain text with paragraphs separated by "\n\n". HTML is
 * removed earlier by HtmlSanitizer::to_plain(); this class does not
 * parse or strip tags. Never appends an ellipsis.
 */
final class Truncator {

	private const MODE_NONE            = 'none';
	private const MODE_CHARS           = 'chars';
	private const MODE_WORDS           = 'words';
	private const MODE_FIRST_PARAGRAPH = 'first_paragraph';

	private const PARAGRAPH_SEPARATOR = "\n\n";

	/**
	 * Truncate text by mode and limit.
	 *
	 * @param string $text  Plain text with "\n\n" paragraph breaks.
	 * @param string $mode  One of: none, chars, words, first_paragraph.
	 * @param int    $limit Length limit; units depend on mode.
	 * @return string Truncated text.
	 */
	public function truncate( string $text, string $mode, int $limit ): string {
		if ( self::MODE_FIRST_PARAGRAPH === $mode ) {
			return $this->first_paragraph( $text, $limit );
		}

		if ( $limit <= 0 ) {
			return $text;
		}

		return match ( $mode ) {
			self::MODE_CHARS => $this->truncate_by_chars( $text, $limit ),
			self::MODE_WORDS => $this->truncate_by_words( $text, $limit ),
			self::MODE_NONE  => $text,
			default          => $text,
		};
	}

	/**
	 * Return the first paragraph, optionally trimmed by chars.
	 *
	 * @param string $text  Plain text with "\n\n" breaks.
	 * @param int    $limit Max chars; 0 or negative returns whole paragraph.
	 * @return string
	 */
	private function first_paragraph( string $text, int $limit ): string {
		$parts = explode( self::PARAGRAPH_SEPARATOR, $text, 2 );
		$first = $parts[0];

		if ( $limit <= 0 || mb_strlen( $first ) <= $limit ) {
			return $first;
		}

		return $this->truncate_by_chars( $first, $limit );
	}

	/**
	 * Truncate to limit chars, keeping only complete words.
	 *
	 * @param string $text  Source text.
	 * @param int    $limit Max length in characters.
	 * @return string
	 */
	private function truncate_by_chars( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words || array() === $words ) {
			return '';
		}

		$result = '';
		foreach ( $words as $word ) {
			$candidate = '' === $result ? $word : $result . ' ' . $word;
			if ( mb_strlen( $candidate ) > $limit ) {
				return $result;
			}
			$result = $candidate;
		}

		return $result;
	}

	/**
	 * Keep the first N words.
	 *
	 * @param string $text  Source text.
	 * @param int    $limit Max words.
	 * @return string
	 */
	private function truncate_by_words( string $text, int $limit ): string {
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words || array() === $words ) {
			return '';
		}

		return implode( ' ', array_slice( $words, 0, $limit ) );
	}
}
