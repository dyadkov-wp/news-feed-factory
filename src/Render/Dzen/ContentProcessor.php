<?php
/**
 * Dzen content processor.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Render\Dzen;

// DOM property names are camelCase (parentNode, firstChild, tagName, etc.).
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
/**
 * Sanitizes and normalizes post HTML for the Dzen RSS feed.
 *
 * Pure function: no WordPress dependencies.
 */
final class ContentProcessor {

	/**
	 * Tags removed together with their entire subtree.
	 *
	 * @var array<int, string>
	 */
	private const CUT_TAGS = array( 'script', 'style', 'iframe', 'form', 'input' );

	/**
	 * Tags allowed in the final Dzen output (ADR-ARCH-0005 § ContentProcessor, п.1).
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_TAGS = array(
		'p',
		'a',
		'b',
		'i',
		'u',
		's',
		'h1',
		'h2',
		'h3',
		'h4',
		'blockquote',
		'ul',
		'ol',
		'li',
		'img',
		'figure',
		'figcaption',
		'br',
	);

	/**
	 * Rename map for normalized inline tags.
	 *
	 * @var array<string, string>
	 */
	private const RENAME_MAP = array(
		'em'     => 'i',
		'strong' => 'b',
	);

	/**
	 * Normalize raw post HTML into a Dzen-safe fragment.
	 *
	 * @param string $html Raw HTML from the post content.
	 * @return string Normalized HTML ready for CDATA wrapping.
	 */
	public function process( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		// Fast path: no markup at all — nothing to sanitize, only CDATA-escape.
		if ( false === strpos( $html, '<' ) ) {
			return $this->escape_cdata( $html );
		}

		$dom = $this->load( $html );

		$this->cut( $dom, self::CUT_TAGS );
		$this->rename( $dom, self::RENAME_MAP );
		$this->unwrap_not_allowed( $dom );
		$this->unwrap_empty_figures( $dom );
		$this->unwrap_orphan_figcaptions( $dom );
		$this->wrap_imgs( $dom );
		$this->strip_attributes( $dom );

		$output = '';
		foreach ( $dom->childNodes as $child ) {
			$fragment = $dom->saveHTML( $child );
			if ( false !== $fragment ) {
				$output .= $fragment;
			}
		}

		if ( '' === trim( $output ) ) {
			return '';
		}

		return $this->escape_cdata( $output );
	}

	/**
	 * Parse HTML into a DOMDocument with our standard flags.
	 *
	 * @param string $html HTML fragment.
	 * @return \DOMDocument
	 */
	private function load( string $html ): \DOMDocument {
		$dom  = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );

		$dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		foreach ( iterator_to_array( $dom->childNodes ) as $child ) {
			if ( $child instanceof \DOMProcessingInstruction ) {
				$dom->removeChild( $child );
			}
		}

		return $dom;
	}

	/**
	 * Remove the given tags with their entire subtree.
	 *
	 * @param \DOMDocument       $dom  Document.
	 * @param array<int, string> $tags Tags to cut.
	 * @return void
	 */
	private function cut( \DOMDocument $dom, array $tags ): void {
		foreach ( $tags as $tag ) {
			foreach ( iterator_to_array( $dom->getElementsByTagName( $tag ) ) as $node ) {
				$parent = $node->parentNode;
				if ( null !== $parent ) {
					$parent->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Unwrap every element whose tag is not in the Dzen whitelist.
	 *
	 * Covers div, span, h5, h6 (ADR-ARCH-0005 Q7 semantic clarifications) and
	 * any other non-Dzen tag (table, section, article, aside, …) — ADR п.1
	 * "Allow only Dzen tags" is a whitelist contract. Runs iteratively because
	 * unwrapping exposes children that may themselves need to be unwrapped.
	 *
	 * @param \DOMDocument $dom Document.
	 * @return void
	 */
	private function unwrap_not_allowed( \DOMDocument $dom ): void {
		do {
			$changed = false;
			foreach ( iterator_to_array( $dom->getElementsByTagName( '*' ) ) as $el ) {
				if ( ! $el instanceof \DOMElement ) {
					continue;
				}
				if ( in_array( strtolower( $el->tagName ), self::ALLOWED_TAGS, true ) ) {
					continue;
				}
				$this->unwrap_node( $el );
				$changed = true;
			}
		} while ( $changed );
	}

	/**
	 * Unwrap a single node, moving its children to its parent.
	 *
	 * @param \DOMNode $node Node.
	 * @return void
	 */
	private function unwrap_node( \DOMNode $node ): void {
		$parent = $node->parentNode;
		if ( null === $parent ) {
			return;
		}
		$child = $node->firstChild;
		while ( null !== $child ) {
			$parent->insertBefore( $child, $node );
			$child = $node->firstChild;
		}
		$parent->removeChild( $node );
	}

	/**
	 * Unwrap every figure with neither img nor figcaption descendants.
	 *
	 * ADR-ARCH-0005 Q7: a figure is kept if it contains img **or** figcaption.
	 *
	 * @param \DOMDocument $dom Document.
	 * @return void
	 */
	private function unwrap_empty_figures( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'figure' ) ) as $figure ) {
			if ( ! $figure instanceof \DOMElement ) {
				continue;
			}
			if ( $figure->getElementsByTagName( 'img' )->length > 0
				|| $figure->getElementsByTagName( 'figcaption' )->length > 0 ) {
				continue;
			}
			$this->unwrap_node( $figure );
		}
	}

	/**
	 * Unwrap every figcaption whose parent is not a figure.
	 *
	 * @param \DOMDocument $dom Document.
	 * @return void
	 */
	private function unwrap_orphan_figcaptions( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'figcaption' ) ) as $cap ) {
			$parent = $cap->parentNode;
			if ( $parent instanceof \DOMElement && 'figure' === strtolower( $parent->tagName ) ) {
				continue;
			}
			$this->unwrap_node( $cap );
		}
	}

	/**
	 * Rename tags per the given map, preserving attributes and children.
	 *
	 * @param \DOMDocument          $dom Document.
	 * @param array<string, string> $map From-tag => to-tag.
	 * @return void
	 */
	private function rename( \DOMDocument $dom, array $map ): void {
		foreach ( $map as $from => $to ) {
			foreach ( iterator_to_array( $dom->getElementsByTagName( $from ) ) as $node ) {
				if ( ! $node instanceof \DOMElement ) {
					continue;
				}
				$parent = $node->parentNode;
				if ( null === $parent ) {
					continue;
				}
				$replacement = $dom->createElement( $to );
				foreach ( iterator_to_array( $node->attributes ) as $attr ) {
					$replacement->setAttribute( $attr->nodeName, $attr->nodeValue ?? '' );
				}
				$child = $node->firstChild;
				while ( null !== $child ) {
					$replacement->appendChild( $child );
					$child = $node->firstChild;
				}
				$parent->replaceChild( $replacement, $node );
			}
		}
	}

	/**
	 * Wrap every img whose direct parent is not a figure in a new figure.
	 *
	 * @param \DOMDocument $dom Document.
	 * @return void
	 */
	private function wrap_imgs( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'img' ) ) as $img ) {
			$parent = $img->parentNode;
			if ( null === $parent ) {
				continue;
			}
			if ( $parent instanceof \DOMElement && 'figure' === strtolower( $parent->tagName ) ) {
				continue;
			}
			$figure = $dom->createElement( 'figure' );
			$parent->replaceChild( $figure, $img );
			$figure->appendChild( $img );
		}
	}

	/**
	 * Remove style and on[a-z]* attributes from every element.
	 *
	 * @param \DOMDocument $dom Document.
	 * @return void
	 */
	private function strip_attributes( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( '*' ) ) as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}
			foreach ( iterator_to_array( $element->attributes ) as $attr ) {
				$name = strtolower( $attr->nodeName );
				if ( 'style' === $name || 1 === preg_match( '/^on[a-z]+$/', $name ) ) {
					$element->removeAttribute( $attr->nodeName );
				}
			}
		}
	}

	/**
	 * Escape CDATA close sequences for inclusion in the feed.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function escape_cdata( string $html ): string {
		return str_replace( ']]>', ']]]]><![CDATA[>', $html );
	}
}
