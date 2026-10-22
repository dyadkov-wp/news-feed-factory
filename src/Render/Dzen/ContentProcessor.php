<?php
/**
 * Dzen content processor.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Render\Dzen;

/**
 * Sanitizes and normalizes post HTML for the Dzen RSS feed.
 *
 * Pure function: no WordPress dependencies.
 */
final class ContentProcessor {

	/**
	 * Normalize raw post HTML into a Dzen-safe fragment.
	 *
	 * @param string $html Raw HTML from the post content.
	 * @return string Normalized HTML ready for CDATA wrapping.
	 *
	 * @throws \LogicException For non-empty input, until the implementation lands.
	 */
	public function process( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		throw new \LogicException( 'ContentProcessor::process() is not implemented yet.' );
	}
}
