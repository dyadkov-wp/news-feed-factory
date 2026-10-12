<?php
/**
 * Unit tests for the HTML sanitizer's plain-text converter.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Normalize;

use NWFF\Normalize\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Covers HtmlSanitizer::to_plain() conversions.
 */
final class HtmlSanitizerTest extends TestCase {

	/**
	 * Sanitizer under test.
	 *
	 * @var HtmlSanitizer
	 */
	private HtmlSanitizer $sanitizer;

	/**
	 * Set up the sanitizer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sanitizer = new HtmlSanitizer();
	}

	/**
	 * A single paragraph is unwrapped to plain text.
	 *
	 * @return void
	 */
	public function test_single_paragraph_returns_text(): void {
		$this->assertSame(
			'Hello',
			$this->sanitizer->to_plain( '<p>Hello</p>' )
		);
	}

	/**
	 * Two paragraphs are joined with a double newline.
	 *
	 * @return void
	 */
	public function test_multiple_paragraphs_joined_with_double_newline(): void {
		$this->assertSame(
			"Alpha\n\nBeta",
			$this->sanitizer->to_plain( '<p>Alpha</p><p>Beta</p>' )
		);
	}

	/**
	 * Div is treated as a block element like p.
	 *
	 * @return void
	 */
	public function test_div_treated_as_block(): void {
		$this->assertSame(
			'Hello',
			$this->sanitizer->to_plain( '<div>Hello</div>' )
		);
	}

	/**
	 * A br tag becomes a single newline.
	 *
	 * @return void
	 */
	public function test_br_becomes_newline(): void {
		$this->assertSame(
			"Line1\nLine2",
			$this->sanitizer->to_plain( 'Line1<br>Line2' )
		);
	}

	/**
	 * Inline tags are stripped without affecting surrounding text.
	 *
	 * @return void
	 */
	public function test_strips_inline_tags(): void {
		$this->assertSame(
			'Hello World',
			$this->sanitizer->to_plain( '<p><b>Hello</b> <a href="#">World</a></p>' )
		);
	}

	/**
	 * HTML entities are decoded.
	 *
	 * @return void
	 */
	public function test_decodes_html_entities(): void {
		$this->assertSame(
			'a & b',
			$this->sanitizer->to_plain( '<p>a &amp; b</p>' )
		);
	}

	/**
	 * Trailing WordPress truncation marker is removed.
	 *
	 * @return void
	 */
	public function test_removes_trailing_truncation_marker(): void {
		$this->assertSame(
			'Some text',
			$this->sanitizer->to_plain( '<p>Some text [&hellip;]</p>' )
		);
	}

	/**
	 * Runs of three or more newlines collapse to a paragraph break.
	 *
	 * @return void
	 */
	public function test_collapses_extra_newlines(): void {
		$this->assertSame(
			"A\n\nB",
			$this->sanitizer->to_plain( "<p>A</p>\n\n\n\n<p>B</p>" )
		);
	}

	/**
	 * Internal spaces and tabs collapse to a single space.
	 *
	 * @return void
	 */
	public function test_collapses_spaces_within_paragraph(): void {
		$this->assertSame(
			'a b c',
			$this->sanitizer->to_plain( "<p>a    b\tc</p>" )
		);
	}

	/**
	 * Non-breaking space is converted to a regular space.
	 *
	 * @return void
	 */
	public function test_converts_nbsp_to_space(): void {
		$this->assertSame(
			'a b',
			$this->sanitizer->to_plain( '<p>a&nbsp;b</p>' )
		);
	}

	/**
	 * Empty paragraphs are removed.
	 *
	 * @return void
	 */
	public function test_removes_empty_paragraphs(): void {
		$this->assertSame(
			"A\n\nB",
			$this->sanitizer->to_plain( '<p>A</p><p></p><p>B</p>' )
		);
	}

	/**
	 * Surrounding whitespace is trimmed.
	 *
	 * @return void
	 */
	public function test_trims_surrounding_whitespace(): void {
		$this->assertSame(
			'Hello',
			$this->sanitizer->to_plain( '<p>  Hello  </p>' )
		);
	}

	/**
	 * Empty input yields an empty string.
	 *
	 * @return void
	 */
	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame(
			'',
			$this->sanitizer->to_plain( '' )
		);
	}

	/**
	 * Plain text without tags passes through unchanged.
	 *
	 * @return void
	 */
	public function test_plain_text_preserved(): void {
		$this->assertSame(
			'Hello world',
			$this->sanitizer->to_plain( 'Hello world' )
		);
	}
}
