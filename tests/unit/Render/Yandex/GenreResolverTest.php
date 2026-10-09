<?php
/**
 * Unit tests for the Yandex genre resolver.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Render\Yandex;

use NWFF\Render\Yandex\GenreResolver;
use PHPUnit\Framework\TestCase;

/**
 * Covers GenreResolver::resolve() priority and length boundary.
 */
final class GenreResolverTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'tag_interview' => 0,
				'tag_article'   => 0,
				'tag_lenta'     => 0,
				'by_length'     => false,
			),
			$overrides
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function tag( int $id, string $taxonomy = 'post_tag' ): array {
		return array(
			'taxonomy' => $taxonomy,
			'id'       => $id,
			'name'     => 'Term ' . $id,
			'slug'     => 'term-' . $id,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function item( array $terms = array(), string $content = '' ): array {
		return array(
			'terms'   => $terms,
			'content' => $content,
		);
	}

	public function test_interview_tag_returns_interview(): void {
		$item = $this->item( array( $this->tag( 100 ) ) );

		$this->assertSame(
			'interview',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'tag_interview' => 100 ) ) )
		);
	}

	public function test_article_tag_returns_article(): void {
		$item = $this->item( array( $this->tag( 200 ) ) );

		$this->assertSame(
			'article',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'tag_article' => 200 ) ) )
		);
	}

	public function test_lenta_tag_returns_lenta(): void {
		$item = $this->item( array( $this->tag( 300 ) ) );

		$this->assertSame(
			'lenta',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'tag_lenta' => 300 ) ) )
		);
	}

	public function test_interview_wins_over_article_and_lenta(): void {
		$item = $this->item( array( $this->tag( 100 ), $this->tag( 200 ), $this->tag( 300 ) ) );

		$this->assertSame(
			'interview',
			( new GenreResolver() )->resolve(
				$item,
				$this->settings(
					array(
						'tag_interview' => 100,
						'tag_article'   => 200,
						'tag_lenta'     => 300,
					)
				)
			)
		);
	}

	public function test_article_wins_over_lenta(): void {
		$item = $this->item( array( $this->tag( 200 ), $this->tag( 300 ) ) );

		$this->assertSame(
			'article',
			( new GenreResolver() )->resolve(
				$item,
				$this->settings(
					array(
						'tag_article' => 200,
						'tag_lenta'   => 300,
					)
				)
			)
		);
	}

	public function test_zero_tag_id_skips_check(): void {
		$item = $this->item( array( $this->tag( 100 ) ) );

		$this->assertSame(
			'message',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'tag_interview' => 0 ) ) )
		);
	}

	public function test_category_with_matching_id_does_not_trigger(): void {
		$item = $this->item( array( $this->tag( 100, 'category' ) ) );

		$this->assertSame(
			'message',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'tag_interview' => 100 ) ) )
		);
	}

	public function test_by_length_short_content_is_lenta(): void {
		$item = $this->item( array(), 'Короткая заметка' );

		$this->assertSame(
			'lenta',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'by_length' => true ) ) )
		);
	}

	public function test_by_length_content_length_79_is_lenta(): void {
		$item = $this->item( array(), str_repeat( 'a', 79 ) );

		$this->assertSame(
			'lenta',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'by_length' => true ) ) )
		);
	}

	public function test_by_length_content_length_80_is_message(): void {
		$item = $this->item( array(), str_repeat( 'a', 80 ) );

		$this->assertSame(
			'message',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'by_length' => true ) ) )
		);
	}

	public function test_by_length_disabled_short_content_is_message(): void {
		$item = $this->item( array(), 'short' );

		$this->assertSame(
			'message',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'by_length' => false ) ) )
		);
	}

	public function test_empty_content_with_by_length_is_lenta(): void {
		$item = $this->item( array(), '' );

		$this->assertSame(
			'lenta',
			( new GenreResolver() )->resolve( $item, $this->settings( array( 'by_length' => true ) ) )
		);
	}

	public function test_default_is_message(): void {
		$item = $this->item( array(), str_repeat( 'a', 200 ) );

		$this->assertSame(
			'message',
			( new GenreResolver() )->resolve( $item, $this->settings() )
		);
	}
}
