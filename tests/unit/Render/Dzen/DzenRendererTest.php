<?php
/**
 * Unit tests for DzenRenderer.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Render\Dzen;

use DateTimeImmutable;
use DateTimeZone;
use NWFF\Normalize\Truncator;
use NWFF\Render\Dzen\ContentProcessor;
use NWFF\Render\Dzen\EnclosureResolver;
use NWFF\Render\DzenRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Covers DzenRenderer::render() output, contract methods, skip
 * policy, and the render-time budget guard.
 */
final class DzenRendererTest extends TestCase {

	/**
	 * Renderer under test.
	 *
	 * @var DzenRenderer
	 */
	private DzenRenderer $renderer;

	/**
	 * Build a fresh renderer per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->renderer = $this->make_renderer();
	}

	/**
	 * Build a renderer with optional test seams.
	 *
	 * @param \Closure|null $logger        Warning sink.
	 * @param int|null      $render_budget Budget override.
	 * @return DzenRenderer
	 */
	private function make_renderer( ?\Closure $logger = null, ?int $render_budget = null ): DzenRenderer {
		return new DzenRenderer(
			new ContentProcessor(),
			new EnclosureResolver(),
			new Truncator(),
			$logger,
			$render_budget
		);
	}

	/**
	 * Build a valid normalized item.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function make_item( array $overrides = array() ): array {
		$item = array(
			'id'                  => 'https://example.com/article',
			'title'               => 'Test article',
			'link'                => 'https://example.com/article',
			'content'             => '<p>Article body</p>',
			'excerpt'             => 'Excerpt',
			'published'           => new DateTimeImmutable( '2026-10-21 12:34:56', new DateTimeZone( 'UTC' ) ),
			'terms'               => array(
				array(
					'taxonomy' => 'category',
					'id'       => 1,
					'name'     => 'News',
					'slug'     => 'news',
				),
			),
			'author'              => 'Ivan Petrov',
			'image_url'           => null,
			'image_attachment_id' => null,
		);

		return array_merge( $item, $overrides );
	}

	/**
	 * Build valid $args.
	 *
	 * @param array<string, mixed> $overrides Arg overrides.
	 * @return array<string, mixed>
	 */
	private function make_args( array $overrides = array() ): array {
		$args = array(
			'channel'  => array(
				'title'       => 'Test Site',
				'link'        => 'https://example.com',
				'description' => 'Test description',
				'language'    => 'ru',
			),
			'settings' => array( 'content_type' => 'news_only' ),
		);

		return array_merge( $args, $overrides );
	}

	// --- A. Identity / contract ---

	/**
	 * Slug is "dzen".
	 *
	 * @return void
	 */
	public function test_get_slug_returns_dzen(): void {
		$this->assertSame( 'dzen', $this->renderer->get_slug() );
	}

	/**
	 * Content-Type is RSS XML.
	 *
	 * @return void
	 */
	public function test_get_content_type_returns_rss_xml(): void {
		$this->assertStringContainsString( 'application/rss+xml', $this->renderer->get_content_type() );
	}

	/**
	 * Normalization config exposes all seven keys with expected values.
	 *
	 * @return void
	 */
	public function test_get_normalization_config_has_all_seven_keys(): void {
		$config = $this->renderer->get_normalization_config();
		$this->assertSame( 500_000, $config['max_content_length'] );
		$this->assertSame( 'none', $config['truncate_mode'] );
		$this->assertSame( 0, $config['truncate_limit'] );
		$this->assertNull( $config['allowed_html'] );
		$this->assertSame( 480, $config['image_min_width'] );
		$this->assertSame( 320, $config['image_min_height'] );
		$this->assertTrue( $config['include_terms'] );
	}

	/**
	 * Settings schema declares the enabled checkbox.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_declares_enabled(): void {
		$schema = $this->renderer->get_settings_schema();
		$this->assertArrayHasKey( 'enabled', $schema );
		$this->assertSame( 'checkbox', $schema['enabled']['type'] );
		$this->assertFalse( $schema['enabled']['default'] );
	}

	/**
	 * Settings schema declares content_type with news_only default.
	 *
	 * @return void
	 */
	public function test_get_settings_schema_declares_content_type(): void {
		$schema = $this->renderer->get_settings_schema();
		$this->assertArrayHasKey( 'content_type', $schema );
		$this->assertSame( 'news_only', $schema['content_type']['default'] );
	}

	/**
	 * Returns false from is_enabled() when settings are absent.
	 *
	 * @return void
	 */
	public function test_is_enabled_false_when_settings_absent(): void {
		$this->assertFalse( $this->renderer->is_enabled( array() ) );
	}

	/**
	 * Returns true from is_enabled() when the renderer section is enabled.
	 *
	 * @return void
	 */
	public function test_is_enabled_true_when_enabled(): void {
		$settings = array( 'renderers' => array( 'dzen' => array( 'enabled' => true ) ) );
		$this->assertTrue( $this->renderer->is_enabled( $settings ) );
	}

	// --- B. get_collector_args ---

	/**
	 * Collector args take depth_hours from core settings.
	 *
	 * @return void
	 */
	public function test_collector_args_depth_hours_from_core(): void {
		$settings = array( 'core' => array( 'depth_hours' => 12 ) );
		$args     = $this->renderer->get_collector_args( $settings );
		$this->assertSame( 12, $args['depth_hours'] );
	}

	/**
	 * Defaults depth_hours to 4 when the core setting is absent.
	 *
	 * @return void
	 */
	public function test_collector_args_depth_hours_defaults_to_4(): void {
		$args = $this->renderer->get_collector_args( array() );
		$this->assertSame( 4, $args['depth_hours'] );
	}

	/**
	 * Collector limit equals MAX_ITEMS (per-feed cap).
	 *
	 * @return void
	 */
	public function test_collector_args_limit_equals_max_items(): void {
		$args = $this->renderer->get_collector_args( array() );
		$this->assertSame( DzenRenderer::MAX_ITEMS, $args['limit'] );
	}

	/**
	 * Include/exclude taxonomy strings are parsed into ID maps.
	 *
	 * @return void
	 */
	public function test_collector_args_parses_include_exclude_taxonomies(): void {
		$settings = array(
			'renderers' => array(
				'dzen' => array(
					'include_taxonomies' => 'category:12,15|post_tag:100',
					'exclude_taxonomies' => 'category:99',
				),
			),
		);

		$args = $this->renderer->get_collector_args( $settings );

		$this->assertSame(
			array(
				'category' => array( 12, 15 ),
				'post_tag' => array( 100 ),
			),
			$args['taxonomy_include']
		);
		$this->assertSame( array( 'category' => array( 99 ) ), $args['taxonomy_exclude'] );
	}

	// --- C. Channel and $args ---

	/**
	 * Reads content_type from renderer settings, not a global.
	 *
	 * @return void
	 */
	public function test_render_uses_renderer_section_from_args_settings(): void {
		$args = $this->make_args( array( 'settings' => array( 'content_type' => 'news' ) ) );
		$xml  = $this->renderer->render( array( $this->make_item() ), $args );
		$this->assertStringContainsString( '<contentType>news</contentType>', $xml );
	}

	/**
	 * Missing content_type in settings falls back to news_only.
	 *
	 * @return void
	 */
	public function test_render_defaults_content_type_to_news_only(): void {
		$args = $this->make_args( array( 'settings' => array() ) );
		$xml  = $this->renderer->render( array( $this->make_item() ), $args );
		$this->assertStringContainsString( '<contentType>news_only</contentType>', $xml );
	}

	/**
	 * Channel title, link, description and language land in XML.
	 *
	 * @return void
	 */
	public function test_render_emits_channel_fields(): void {
		$xml = $this->renderer->render( array(), $this->make_args() );
		$this->assertStringContainsString( '<title>Test Site</title>', $xml );
		$this->assertStringContainsString( '<link>https://example.com</link>', $xml );
		$this->assertStringContainsString( '<description>Test description</description>', $xml );
		$this->assertStringContainsString( '<language>ru</language>', $xml );
	}

	/**
	 * The content: namespace is declared on the rss root.
	 *
	 * @return void
	 */
	public function test_render_emits_content_namespace(): void {
		$xml = $this->renderer->render( array(), $this->make_args() );
		$this->assertStringContainsString( 'xmlns:content="http://purl.org/rss/1.0/modules/content/"', $xml );
	}

	// --- D. Item schema ---

	/**
	 * Short titles pass through unchanged.
	 *
	 * @return void
	 */
	public function test_item_title_pass_through_when_under_limit(): void {
		$item = $this->make_item( array( 'title' => 'Short title' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringContainsString( '<title>Short title</title>', $xml );
	}

	/**
	 * Titles over 200 chars are truncated by Truncator, fitting the limit.
	 *
	 * Uses a multi-word title: Truncator does not split individual words,
	 * so a single 250-char word would collapse to an empty string.
	 *
	 * @return void
	 */
	public function test_item_title_truncated_when_over_200_chars(): void {
		$long_title = str_repeat( 'abcde ', 60 );
		$item       = $this->make_item( array( 'title' => $long_title ) );
		$xml        = $this->renderer->render( array( $item ), $this->make_args() );

		preg_match( '/<item>.*?<title>(.*?)<\/title>/s', $xml, $matches );

		$this->assertNotEmpty( $matches[1] );
		$this->assertLessThan( strlen( $long_title ), mb_strlen( $matches[1] ) );
		$this->assertLessThanOrEqual( 200, mb_strlen( $matches[1] ) );
	}

	/**
	 * Missing title renders an empty title element.
	 *
	 * @return void
	 */
	public function test_item_title_missing_renders_empty_title(): void {
		$item = $this->make_item( array( 'title' => '' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringContainsString( '<title></title>', $xml );
	}

	/**
	 * Link and guid are equal; guid is a permalink.
	 *
	 * @return void
	 */
	public function test_item_link_and_guid_are_equal(): void {
		$item = $this->make_item( array( 'link' => 'https://example.com/unique' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringContainsString( '<link>https://example.com/unique</link>', $xml );
		$this->assertStringContainsString( '<guid isPermaLink="true">https://example.com/unique</guid>', $xml );
	}

	/**
	 * Formats pubDate per RFC 822.
	 *
	 * @return void
	 */
	public function test_item_pubdate_is_rfc822(): void {
		$published = new DateTimeImmutable( '2026-10-21 12:34:56', new DateTimeZone( 'UTC' ) );
		$item      = $this->make_item( array( 'published' => $published ) );
		$xml       = $this->renderer->render( array( $item ), $this->make_args() );

		$expected = $published->format( 'D, d M Y H:i:s O' );
		$this->assertStringContainsString( "<pubDate>{$expected}</pubDate>", $xml );
	}

	/**
	 * Wraps processed HTML in CDATA within content:encoded.
	 *
	 * @return void
	 */
	public function test_item_content_encoded_wrapped_in_cdata(): void {
		$item = $this->make_item( array( 'content' => '<p>Hello</p>' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringContainsString(
			'<content:encoded><![CDATA[<p>Hello</p>]]></content:encoded>',
			$xml
		);
	}

	/**
	 * Reflects ContentProcessor output inside content:encoded.
	 *
	 * @return void
	 */
	public function test_item_content_encoded_uses_processed_content(): void {
		$item = $this->make_item( array( 'content' => '<p><em>Hello</em></p><script>alert(1)</script>' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringContainsString( '<![CDATA[<p><i>Hello</i></p>]]>', $xml );
		$this->assertStringNotContainsString( '<script>', $xml );
	}

	/**
	 * Escapes CDATA terminator inside plain-text content exactly once.
	 *
	 * Uses a plain-text input: HTML input is normalized by saveHTML(),
	 * which already escapes ">" inside text nodes to "&gt;" before the
	 * CDATA escape step runs (ADR-ARCH-0005 § CDATA-escape exception).
	 *
	 * @return void
	 */
	public function test_item_cdata_escape_not_double_encoded(): void {
		$item = $this->make_item( array( 'content' => 'foo ]]> bar' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );

		$this->assertStringContainsString(
			'<![CDATA[foo ]]]]><![CDATA[> bar]]>',
			$xml
		);
		$this->assertSame( 1, substr_count( $xml, ']]]]><![CDATA[>' ) );
	}

	/**
	 * Documents that saveHTML() escapes ">" in HTML text nodes before
	 * the CDATA escape step, so "]]>" never survives into CDATA.
	 *
	 * @return void
	 */
	public function test_item_html_content_has_no_cdata_terminator(): void {
		$item = $this->make_item( array( 'content' => '<p>foo ]]> bar</p>' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );

		$this->assertStringContainsString( '<![CDATA[<p>foo ]]&gt; bar</p>]]>', $xml );
		$this->assertStringNotContainsString( ']]]]><![CDATA[>', $xml );
	}

	/**
	 * Category is the first term's name, regardless of taxonomy.
	 *
	 * @return void
	 */
	public function test_item_category_is_first_term_name(): void {
		$item = $this->make_item(
			array(
				'terms' => array(
					array(
						'taxonomy' => 'post_tag',
						'id'       => 100,
						'name'     => 'First Tag',
						'slug'     => 'first-tag',
					),
					array(
						'taxonomy' => 'category',
						'id'       => 5,
						'name'     => 'Second Cat',
						'slug'     => 'second-cat',
					),
				),
			)
		);
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringContainsString( '<category>First Tag</category>', $xml );
		$this->assertStringNotContainsString( '<category>Second Cat</category>', $xml );
	}

	/**
	 * No category is emitted when terms is empty.
	 *
	 * @return void
	 */
	public function test_item_category_absent_when_terms_empty(): void {
		$item = $this->make_item( array( 'terms' => array() ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringNotContainsString( '<category>', $xml );
	}

	/**
	 * Non-empty author is emitted.
	 *
	 * @return void
	 */
	public function test_item_author_emitted_when_present(): void {
		$item = $this->make_item( array( 'author' => 'Ivan Petrov' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringContainsString( '<author>Ivan Petrov</author>', $xml );
	}

	/**
	 * Empty author is omitted.
	 *
	 * @return void
	 */
	public function test_item_author_absent_when_empty(): void {
		$item = $this->make_item( array( 'author' => '' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringNotContainsString( '<author>', $xml );
	}

	/**
	 * No enclosure when the resolver returns null.
	 *
	 * @return void
	 */
	public function test_item_enclosure_absent_when_no_attachment(): void {
		$item = $this->make_item(
			array(
				'image_attachment_id' => null,
				'image_url'           => null,
			)
		);
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringNotContainsString( '<enclosure ', $xml );
	}

	// --- E. contentType ---

	/**
	 * Renders content_type "news" once per item.
	 *
	 * @return void
	 */
	public function test_content_type_news_renders_in_item(): void {
		$args = $this->make_args( array( 'settings' => array( 'content_type' => 'news' ) ) );
		$xml  = $this->renderer->render( array( $this->make_item() ), $args );
		$this->assertSame( 1, substr_count( $xml, '<contentType>news</contentType>' ) );
	}

	/**
	 * Renders content_type "blogs_only" verbatim.
	 *
	 * @return void
	 */
	public function test_content_type_blogs_only_renders_in_item(): void {
		$args = $this->make_args( array( 'settings' => array( 'content_type' => 'blogs_only' ) ) );
		$xml  = $this->renderer->render( array( $this->make_item() ), $args );
		$this->assertStringContainsString( '<contentType>blogs_only</contentType>', $xml );
	}

	// --- F. Skip policy ---

	/**
	 * Empty content skips the item.
	 *
	 * @return void
	 */
	public function test_item_skipped_when_content_empty(): void {
		$item = $this->make_item( array( 'content' => '' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringNotContainsString( '<item>', $xml );
	}

	/**
	 * Processor returning '' (e.g. only forbidden tags) skips the item.
	 *
	 * @return void
	 */
	public function test_item_skipped_when_processor_returns_empty(): void {
		$item = $this->make_item( array( 'content' => '<script>alert(1)</script>' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringNotContainsString( '<item>', $xml );
	}

	/**
	 * Non-DateTimeImmutable published skips the item.
	 *
	 * @return void
	 */
	public function test_item_skipped_when_published_not_datetime(): void {
		$item = $this->make_item( array( 'published' => null ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringNotContainsString( '<item>', $xml );
	}

	/**
	 * Missing link skips the item.
	 *
	 * @return void
	 */
	public function test_item_skipped_when_link_missing(): void {
		$item = $this->make_item( array( 'link' => '' ) );
		$xml  = $this->renderer->render( array( $item ), $this->make_args() );
		$this->assertStringNotContainsString( '<item>', $xml );
	}

	/**
	 * All items skipped yields a valid empty RSS document.
	 *
	 * @return void
	 */
	public function test_all_items_skipped_yields_valid_empty_rss(): void {
		$items = array(
			$this->make_item( array( 'content' => '' ) ),
			$this->make_item( array( 'link' => '' ) ),
		);
		$xml   = $this->renderer->render( $items, $this->make_args() );

		$this->assertStringContainsString( '<rss ', $xml );
		$this->assertStringContainsString( '</channel>', $xml );
		$this->assertStringNotContainsString( '<item>', $xml );
	}

	/**
	 * Skip warning includes the permalink for diagnosis.
	 *
	 * @return void
	 */
	public function test_skip_warning_contains_permalink(): void {
		$warnings = array();
		$logger   = function ( string $message ) use ( &$warnings ): void {
			$warnings[] = $message;
		};

		$renderer = $this->make_renderer( $logger );
		$item     = $this->make_item(
			array(
				'content' => '',
				'link'    => 'https://example.com/abc',
			)
		);
		$renderer->render( array( $item ), $this->make_args() );

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'https://example.com/abc', $warnings[0] );
	}

	// --- G. Budget guard ---

	/**
	 * Items under budget are all kept.
	 *
	 * @return void
	 */
	public function test_budget_guard_keeps_all_items_when_under_budget(): void {
		$renderer = $this->make_renderer( null, 1_000_000 );
		$xml      = $renderer->render( array( $this->make_item() ), $this->make_args() );
		$this->assertSame( 1, substr_count( $xml, '<item>' ) );
	}

	/**
	 * When over budget, the renderer keeps a contiguous prefix.
	 *
	 * @return void
	 */
	public function test_budget_guard_stops_at_previous_and_keeps_prefix(): void {
		$items = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$items[] = $this->make_item(
				array( 'link' => sprintf( 'https://example.com/article-%05d', $i ) )
			);
		}

		$single_xml = $this->renderer->render( array( $items[0] ), $this->make_args() );
		preg_match( '/<item>.*?<\/item>/s', $single_xml, $matches );
		$item_size = strlen( $matches[0] );

		$renderer = $this->make_renderer( null, 2 * $item_size + 10 );
		$xml      = $renderer->render( $items, $this->make_args() );

		$this->assertSame( 2, substr_count( $xml, '<item>' ) );
		$this->assertStringContainsString( 'article-00000', $xml );
		$this->assertStringContainsString( 'article-00001', $xml );
		$this->assertStringNotContainsString( 'article-00002', $xml );
	}

	/**
	 * Budget guard logs kept/total/bytes at truncation time.
	 *
	 * Break happens on the 3rd iteration: $total is already 3 when the
	 * log fires (pre-increment), so the message reads "of 3", not "of 5".
	 *
	 * @return void
	 */
	public function test_budget_guard_logs_warning_with_kept_total_bytes(): void {
		$warnings = array();
		$logger   = function ( string $message ) use ( &$warnings ): void {
			$warnings[] = $message;
		};

		$items = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$items[] = $this->make_item(
				array( 'link' => sprintf( 'https://example.com/article-%05d', $i ) )
			);
		}

		$single_xml = $this->renderer->render( array( $items[0] ), $this->make_args() );
		preg_match( '/<item>.*?<\/item>/s', $single_xml, $matches );
		$item_size = strlen( $matches[0] );
		$budget    = 2 * $item_size + 10;

		$renderer = $this->make_renderer( $logger, $budget );
		$renderer->render( $items, $this->make_args() );

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'kept 2 of 3 items', $warnings[0] );
		$this->assertStringContainsString( "budget {$budget}", $warnings[0] );
	}

	/**
	 * A single item that exceeds the budget yields an empty feed.
	 *
	 * @return void
	 */
	public function test_budget_guard_single_oversized_item_yields_empty_feed(): void {
		$renderer = $this->make_renderer( null, 10 );
		$xml      = $renderer->render( array( $this->make_item() ), $this->make_args() );

		$this->assertStringContainsString( '<rss ', $xml );
		$this->assertStringContainsString( '</channel>', $xml );
		$this->assertStringNotContainsString( '<item>', $xml );
	}

	/**
	 * All items skipped — no budget warning, only skip warnings.
	 *
	 * @return void
	 */
	public function test_budget_guard_no_warning_when_all_items_skipped(): void {
		$warnings = array();
		$logger   = function ( string $message ) use ( &$warnings ): void {
			$warnings[] = $message;
		};

		$renderer = $this->make_renderer( $logger, 10 );
		$items    = array(
			$this->make_item( array( 'content' => '' ) ),
			$this->make_item( array( 'link' => '' ) ),
		);
		$renderer->render( $items, $this->make_args() );

		$this->assertCount( 2, $warnings );
		foreach ( $warnings as $message ) {
			$this->assertStringNotContainsString( 'kept', $message );
			$this->assertStringNotContainsString( 'budget', $message );
		}
	}

	// --- H. Logger ---

	/**
	 * Logger receives every warning in render order.
	 *
	 * @return void
	 */
	public function test_logger_receives_all_warnings_in_order(): void {
		$warnings = array();
		$logger   = function ( string $message ) use ( &$warnings ): void {
			$warnings[] = $message;
		};

		$renderer = $this->make_renderer( $logger, 1 );

		$items = array(
			$this->make_item( array( 'link' => '' ) ),
			$this->make_item( array( 'content' => '' ) ),
			$this->make_item(),
		);
		$renderer->render( $items, $this->make_args() );

		$this->assertCount( 3, $warnings );
		$this->assertStringContainsString( 'missing link', $warnings[0] );
		$this->assertStringContainsString( 'empty content', $warnings[1] );
		$this->assertStringContainsString( 'kept 0 of 3 items', $warnings[2] );
	}
}
