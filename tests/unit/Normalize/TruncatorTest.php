<?php
/**
 * Unit tests for the text truncator.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Normalize;

use NWFF\Normalize\Truncator;
use PHPUnit\Framework\TestCase;

/**
 * Covers Truncator::truncate() across modes, boundaries and edge cases.
 */
final class TruncatorTest extends TestCase {

	/**
	 * Truncator under test.
	 *
	 * @var Truncator
	 */
	private Truncator $truncator;

	/**
	 * Set up the truncator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->truncator = new Truncator();
	}

	/**
	 * Mode none returns text unchanged regardless of limit.
	 *
	 * @return void
	 */
	public function test_none_returns_text_unchanged(): void {
		$this->assertSame(
			'hello world',
			$this->truncator->truncate( 'hello world', 'none', 5 )
		);
	}

	/**
	 * Mode none ignores zero limit.
	 *
	 * @return void
	 */
	public function test_none_ignores_zero_limit(): void {
		$this->assertSame(
			'hello world',
			$this->truncator->truncate( 'hello world', 'none', 0 )
		);
	}

	/**
	 * Chars mode trims to limit on word boundary.
	 *
	 * @return void
	 */
	public function test_chars_trims_on_word_boundary(): void {
		$this->assertSame(
			'hello',
			$this->truncator->truncate( 'hello world', 'chars', 7 )
		);
	}

	/**
	 * Chars mode returns text unchanged when shorter than limit.
	 *
	 * @return void
	 */
	public function test_chars_keeps_short_text(): void {
		$this->assertSame(
			'hello',
			$this->truncator->truncate( 'hello', 'chars', 100 )
		);
	}

	/**
	 * Chars mode with zero limit returns text unchanged.
	 *
	 * @return void
	 */
	public function test_chars_zero_limit_keeps_text(): void {
		$this->assertSame(
			'hello world',
			$this->truncator->truncate( 'hello world', 'chars', 0 )
		);
	}

	/**
	 * Chars mode returns empty string when first word exceeds limit.
	 *
	 * @return void
	 */
	public function test_chars_first_word_too_long_returns_empty(): void {
		$this->assertSame(
			'',
			$this->truncator->truncate( 'verylongword short', 'chars', 5 )
		);
	}

	/**
	 * Words mode keeps only the first N words.
	 *
	 * @return void
	 */
	public function test_words_keeps_first_n_words(): void {
		$this->assertSame(
			'hello world',
			$this->truncator->truncate( 'hello world foo bar', 'words', 2 )
		);
	}

	/**
	 * Words mode with zero limit returns text unchanged.
	 *
	 * @return void
	 */
	public function test_words_zero_limit_keeps_text(): void {
		$this->assertSame(
			'hello world foo',
			$this->truncator->truncate( 'hello world foo', 'words', 0 )
		);
	}

	/**
	 * Words mode with limit above word count returns text unchanged.
	 *
	 * @return void
	 */
	public function test_words_limit_above_count_keeps_text(): void {
		$this->assertSame(
			'hello world',
			$this->truncator->truncate( 'hello world', 'words', 10 )
		);
	}

	/**
	 * First paragraph mode returns the first block.
	 *
	 * @return void
	 */
	public function test_first_paragraph_returns_first_block(): void {
		$this->assertSame(
			'alpha beta',
			$this->truncator->truncate( "alpha beta\n\ngamma delta", 'first_paragraph', 0 )
		);
	}

	/**
	 * First paragraph mode with limit trims within the first block.
	 *
	 * @return void
	 */
	public function test_first_paragraph_trims_within_block(): void {
		$this->assertSame(
			'alpha',
			$this->truncator->truncate( "alpha beta gamma\n\nnext", 'first_paragraph', 7 )
		);
	}

	/**
	 * First paragraph mode returns whole text when no paragraph break.
	 *
	 * @return void
	 */
	public function test_first_paragraph_without_break_returns_text(): void {
		$this->assertSame(
			'alpha beta',
			$this->truncator->truncate( 'alpha beta', 'first_paragraph', 0 )
		);
	}

	/**
	 * Truncator does not strip HTML — it expects plain text.
	 *
	 * @return void
	 */
	public function test_does_not_strip_html_tags(): void {
		$this->assertSame(
			'<p>hello</p>',
			$this->truncator->truncate( '<p>hello</p>', 'none', 0 )
		);
	}

	/**
	 * Truncator does not append an ellipsis.
	 *
	 * @return void
	 */
	public function test_does_not_append_ellipsis(): void {
		$this->assertSame(
			'hello',
			$this->truncator->truncate( 'hello world', 'chars', 6 )
		);
	}
}
