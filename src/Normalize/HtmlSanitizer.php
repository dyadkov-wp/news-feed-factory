<?php
/**
 * HTML sanitizer for the content normalizer.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Normalize;

/**
 * Converts HTML to plain text and sanitizes markup (ADR-ARCH-0002).
 *
 * The sanitize() method will be added in a later cycle. This class
 * currently exposes only the plain-text conversion used by Truncator
 * for excerpt fields.
 */
final class HtmlSanitizer {

	/**
	 * Convert rendered HTML to plain text with paragraph breaks.
	 *
	 * Block tags (p, div) become "\n\n". A br tag becomes "\n".
	 * Other tags are stripped. Entities are decoded once. A trailing
	 * WordPress truncation marker ([...] / [...]) is removed.
	 *
	 * @param string $html Source HTML.
	 * @return string Plain text with "\n\n" between paragraphs.
	 */
	public function to_plain( string $html ): string {
		// 1. Decode entities.
		$text = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 2. Non-breaking space → regular space.
		$text = preg_replace( '/\x{00A0}/u', ' ', $text );

		// 3. Block tags → paragraph breaks.
		$text = preg_replace( '#</?(?:p|div)\b[^>]*>#i', "\n\n", $text );

		// 4. br → single newline.
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );

		// 5. Strip remaining tags.
		$text = preg_replace( '#<[^>]+>#', '', $text );

		// 6. Remove a trailing truncation marker.
		$text = preg_replace( '/\s*\[(?:…|\.\.\.)\]\s*$/u', '', $text );

		// 7. Collapse three or more newlines to a paragraph break.
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		// 8. Trim each paragraph, collapse internal spaces, drop empties.
		$paragraphs = explode( "\n\n", $text );
		$clean      = array();
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( preg_replace( '/[ \t]+/', ' ', $paragraph ) );
			if ( '' !== $paragraph ) {
				$clean[] = $paragraph;
			}
		}

		return implode( "\n\n", $clean );
	}
}
