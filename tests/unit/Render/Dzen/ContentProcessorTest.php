<?php
/**
 * Unit tests for the Dzen content processor.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Render\Dzen;

use NWFF\Render\Dzen\ContentProcessor;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API uses camelCase property names.
/**
 * Covers ContentProcessor::process() tag policy, normalization,
 * figure-wrapping, CDATA escaping and robustness.
 */
final class ContentProcessorTest extends TestCase {

	/**
	 * Parse HTML into a canonical nested array.
	 *
	 * Wraps the fragment in a synthetic #document node so that multiple
	 * top-level elements are all captured (DOMDocument::documentElement
	 * only exposes the first one).
	 *
	 * @param string $html HTML fragment.
	 * @return array<string, mixed>
	 */
	private function canonicalize( string $html ): array {
		$dom  = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		// Strip the XML processing instruction we prepended above.
		foreach ( iterator_to_array( $dom->childNodes ) as $child ) {
			if ( $child instanceof \DOMProcessingInstruction ) {
				$dom->removeChild( $child );
			}
		}

		$result = array(
			'tag'      => '#document',
			'attrs'    => array(),
			'children' => array(),
		);

		foreach ( $dom->childNodes as $child ) {
			$result['children'][] = $this->node_to_array( $child );
		}

		return $result;
	}

	/**
	 * Recursively convert a DOM node to an array.
	 *
	 * @param \DOMNode $node Node.
	 * @return array<string, mixed>
	 */
	private function node_to_array( \DOMNode $node ): array {
		$result = array(
			'tag'      => $node->nodeName,
			'attrs'    => array(),
			'children' => array(),
		);

		if ( $node instanceof \DOMElement ) {
			$attrs = array();
			foreach ( $node->attributes as $attr ) {
				$attrs[ $attr->nodeName ] = $attr->nodeValue ?? '';
			}
			ksort( $attrs );
			$result['attrs'] = $attrs;
		}

		foreach ( $node->childNodes as $child ) {
			$result['children'][] = $this->node_to_array( $child );
		}

		return $result;
	}

	/**
	 * Assert two HTML fragments are structurally identical.
	 *
	 * @param string $expected Expected HTML.
	 * @param string $actual   Actual HTML.
	 * @return void
	 */
	private function assertCanonical( string $expected, string $actual ): void {
		$this->assertSame(
			$this->canonicalize( $expected ),
			$this->canonicalize( $actual )
		);
	}

	/**
	 * Processor under test.
	 *
	 * @return ContentProcessor
	 */
	private function processor(): ContentProcessor {
		return new ContentProcessor();
	}

	// ---------------------------------------------------------------------
	// Whitelist: allowed tags and plain text are preserved.
	// ---------------------------------------------------------------------

	/**
	 * Allowed inline and block tags survive with their text.
	 *
	 * @return void
	 */
	public function test_allowed_tags_are_preserved(): void {
		$html = '<p>Text <b>bold</b> <i>italic</i> <u>under</u> <s>strike</s> '
			. '<a href="https://example.com" title="t">link</a> <br> '
			. '<blockquote>quote</blockquote> '
			. '<h1>h1</h1><h2>h2</h2><h3>h3</h3><h4>h4</h4> '
			. '<ul><li>u1</li></ul><ol><li>o1</li></ol></p>';

		$this->assertCanonical( $html, $this->processor()->process( $html ) );
	}

	/**
	 * Plain text without tags passes through unchanged.
	 *
	 * @return void
	 */
	public function test_plain_text_is_preserved(): void {
		$this->assertSame( 'Просто текст', $this->processor()->process( 'Просто текст' ) );
	}

	// ---------------------------------------------------------------------
	// Forbidden: script/style/iframe/form/input are cut with content.
	// ---------------------------------------------------------------------

	/**
	 * Script and style are removed together with their content.
	 *
	 * @return void
	 */
	public function test_script_and_style_are_cut_with_content(): void {
		$html = '<p>before</p><script>alert("x")</script><style>.a{}</style><p>after</p>';

		$this->assertCanonical( '<p>before</p><p>after</p>', $this->processor()->process( $html ) );
	}

	/**
	 * Iframe, form and input are removed together with their content.
	 *
	 * @return void
	 */
	public function test_iframe_form_input_are_cut_with_content(): void {
		$html = '<p>a</p><iframe src="x"></iframe><form><input value="v"></form><p>b</p>';

		$this->assertCanonical( '<p>a</p><p>b</p>', $this->processor()->process( $html ) );
	}

	// ---------------------------------------------------------------------
	// Unwrap: div, span, h5, h6, empty figure, figcaption-only figure.
	// ---------------------------------------------------------------------

	/**
	 * Div and span are unwrapped, their content is kept.
	 *
	 * @return void
	 */
	public function test_div_and_span_are_unwrapped(): void {
		$html = '<div><span>inside</span></div>';

		$this->assertCanonical( 'inside', $this->processor()->process( $html ) );
	}

	/**
	 * H5 and h6 are unwrapped, not renamed or kept.
	 *
	 * @return void
	 */
	public function test_h5_and_h6_are_unwrapped(): void {
		$html = '<h5>five</h5><h6>six</h6>';

		$this->assertCanonical( 'fivesix', $this->processor()->process( $html ) );
	}

	/**
	 * A figure with neither img nor figcaption is unwrapped.
	 *
	 * @return void
	 */
	public function test_empty_figure_is_unwrapped(): void {
		$html = '<figure>orphan text</figure>';

		$this->assertCanonical( 'orphan text', $this->processor()->process( $html ) );
	}

	/**
	 * A figure with only figcaption (no img) is unwrapped.
	 *
	 * @return void
	 */
	public function test_figcaption_only_figure_is_unwrapped(): void {
		$html = '<figure><figcaption>cap</figcaption></figure>';

		$this->assertCanonical( 'cap', $this->processor()->process( $html ) );
	}

	// ---------------------------------------------------------------------
	// Normalize: em → i, strong → b; i/b stay as is.
	// ---------------------------------------------------------------------

	/**
	 * Em is renamed to i.
	 *
	 * @return void
	 */
	public function test_em_is_renamed_to_i(): void {
		$this->assertCanonical( '<i>x</i>', $this->processor()->process( '<em>x</em>' ) );
	}

	/**
	 * Strong is renamed to b.
	 *
	 * @return void
	 */
	public function test_strong_is_renamed_to_b(): void {
		$this->assertCanonical( '<b>x</b>', $this->processor()->process( '<strong>x</strong>' ) );
	}

	/**
	 * I and b are left unchanged.
	 *
	 * @return void
	 */
	public function test_i_and_b_are_kept(): void {
		$html = '<i>i</i><b>b</b>';

		$this->assertCanonical( $html, $this->processor()->process( $html ) );
	}

	/**
	 * Rename keeps attributes and nested children.
	 *
	 * @return void
	 */
	public function test_rename_keeps_attributes_and_children(): void {
		$html = '<em class="x"><b>inner</b></em>';

		$this->assertCanonical( '<i class="x"><b>inner</b></i>', $this->processor()->process( $html ) );
	}

	// ---------------------------------------------------------------------
	// Figure wrapping.
	// ---------------------------------------------------------------------

	/**
	 * A single img without figure parent is wrapped in figure.
	 *
	 * @return void
	 */
	public function test_single_img_is_wrapped_in_figure(): void {
		$this->assertCanonical(
			'<figure><img src="a.jpg" alt="a"></figure>',
			$this->processor()->process( '<img src="a.jpg" alt="a">' )
		);
	}

	/**
	 * An img already inside figure is not double-wrapped.
	 *
	 * @return void
	 */
	public function test_img_inside_figure_is_not_double_wrapped(): void {
		$html = '<figure><img src="a.jpg"></figure>';

		$this->assertCanonical( $html, $this->processor()->process( $html ) );
	}

	/**
	 * Figcaption is preserved inside a non-empty figure.
	 *
	 * @return void
	 */
	public function test_figcaption_is_preserved(): void {
		$html = '<figure><img src="a.jpg"><figcaption>cap</figcaption></figure>';

		$this->assertCanonical( $html, $this->processor()->process( $html ) );
	}

	/**
	 * Multiple imgs are each wrapped in their own figure.
	 *
	 * @return void
	 */
	public function test_multiple_imgs_each_get_own_figure(): void {
		$html = '<img src="a.jpg"><img src="b.jpg">';

		$this->assertCanonical(
			'<figure><img src="a.jpg"></figure><figure><img src="b.jpg"></figure>',
			$this->processor()->process( $html )
		);
	}

	/**
	 * An img inside a link is wrapped while the link is preserved.
	 *
	 * @return void
	 */
	public function test_img_inside_link_is_wrapped(): void {
		$html = '<a href="https://example.com"><img src="a.jpg"></a>';

		$this->assertCanonical(
			'<a href="https://example.com"><figure><img src="a.jpg"></figure></a>',
			$this->processor()->process( $html )
		);
	}

	// ---------------------------------------------------------------------
	// CDATA escaping — raw string assertion, no canonicalize.
	// ---------------------------------------------------------------------

	/**
	 * A CDATA close sequence is escaped.
	 *
	 * @return void
	 */
	public function test_cdata_close_is_escaped(): void {
		$this->assertSame(
			'foo ]]]]><![CDATA[> bar',
			$this->processor()->process( 'foo ]]> bar' )
		);
	}

	/**
	 * No CDATA wrapper is added by the processor.
	 *
	 * @return void
	 */
	public function test_no_cdata_wrapper_is_added(): void {
		$out = $this->processor()->process( '<p>x</p>' );

		$this->assertStringNotContainsString( '<![CDATA[', $out );
		$this->assertStringNotContainsString( ']]>', $out );
	}

	// ---------------------------------------------------------------------
	// Attributes: allowed survive, event/style handlers are dropped.
	// ---------------------------------------------------------------------

	/**
	 * Href, title, src, alt, width and height are preserved.
	 *
	 * @return void
	 */
	public function test_allowed_attributes_are_preserved(): void {
		$html = '<a href="https://example.com" title="t">l</a>'
			. '<figure><img src="a.jpg" alt="alt" width="100" height="50"></figure>';

		$this->assertCanonical( $html, $this->processor()->process( $html ) );
	}

	/**
	 * Onclick and style attributes are removed.
	 *
	 * @return void
	 */
	public function test_event_and_style_attributes_are_removed(): void {
		$html = '<p onclick="evil()" style="color:red">x</p>';

		$this->assertCanonical( '<p>x</p>', $this->processor()->process( $html ) );
	}

	// ---------------------------------------------------------------------
	// Robustness.
	// ---------------------------------------------------------------------

	/**
	 * Nested invalid markup does not throw.
	 *
	 * @return void
	 */
	public function test_nested_invalid_markup_does_not_throw(): void {
		$html = '<div><p><span>broken<b>nest</div>';

		$out = $this->processor()->process( $html );

		$this->assertStringContainsString( 'broken', $out );
	}

	/**
	 * Empty string returns empty string.
	 *
	 * @return void
	 */
	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', $this->processor()->process( '' ) );
	}

	/**
	 * Whitespace-only input returns empty string.
	 *
	 * @return void
	 */
	public function test_whitespace_only_input_returns_empty_string(): void {
		$this->assertSame( '', $this->processor()->process( "  \n\t " ) );
	}

	/**
	 * Input consisting only of forbidden tags returns empty string.
	 *
	 * @return void
	 */
	public function test_only_forbidden_tags_returns_empty_string(): void {
		$this->assertSame( '', $this->processor()->process( '<script>x</script><style>y</style>' ) );
	}

	/**
	 * Processing is idempotent: second pass gives the same output.
	 *
	 * @return void
	 */
	public function test_processing_is_idempotent(): void {
		$html = '<div><em>x</em></div><img src="a.jpg">';

		$once  = $this->processor()->process( $html );
		$twice = $this->processor()->process( $once );

		$this->assertSame( $once, $twice );
	}
}
