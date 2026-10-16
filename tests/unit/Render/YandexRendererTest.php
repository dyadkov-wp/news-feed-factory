<?php
/**
 * Unit tests for the Yandex renderer.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Tests\Unit\Render;

use DateTimeImmutable;
use DateTimeZone;
use NWFF\Normalize\Truncator;
use NWFF\Render\Yandex\GenreResolver;
use NWFF\Render\YandexRenderer;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Covers YandexRenderer::render() and its accessors.
 */
final class YandexRendererTest extends TestCase {

	/**
	 * Renderer under test.
	 *
	 * @var YandexRenderer
	 */
	private YandexRenderer $renderer;

	/**
	 * Set up the renderer with real collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new YandexRenderer(
			new GenreResolver(),
			new Truncator()
		);
	}

	/**
	 * Build a normalized item with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => 'https://example.com/post/1',
				'link'         => 'https://example.com/post/1',
				'title'        => 'Hello world',
				'content'      => '<p>Full body</p>',
				'excerpt'      => 'Short teaser',
				'published'    => new DateTimeImmutable( '2026-10-13 12:00:00', new DateTimeZone( 'UTC' ) ),
				'image_url'    => null,
				'image_width'  => null,
				'image_height' => null,
				'terms'        => array(),
				'author'       => '',
			),
			$overrides
		);
	}

	/**
	 * Build Yandex settings with overrides.
	 *
	 * @param array<string, mixed> $overrides Settings to override.
	 * @return array<string, mixed>
	 */
	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'enabled'       => true,
				'tag_interview' => 0,
				'tag_article'   => 0,
				'tag_lenta'     => 0,
				'by_length'     => false,
			),
			$overrides
		);
	}

	/**
	 * Build render args.
	 *
	 * @param array<string, mixed> $settings Yandex settings.
	 * @return array<string, mixed>
	 */
	private function args( array $settings = array() ): array {
		return array(
			'settings' => $this->settings( $settings ),
			'channel'  => array(
				'title'       => 'Example site',
				'link'        => 'https://example.com/',
				'description' => 'Latest news',
				'language'    => 'ru',
			),
		);
	}

	/**
	 * Parse rendered output as RSS.
	 *
	 * @param string $xml Rendered feed.
	 * @return SimpleXMLElement
	 */
	private function parse( string $xml ): SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$element  = simplexml_load_string( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->assertInstanceOf( SimpleXMLElement::class, $element );

		return $element;
	}

	/**
	 * Slug accessor returns yandex.
	 *
	 * @return void
	 */
	public function test_slug_is_yandex(): void {
		$this->assertSame( 'yandex', $this->renderer->get_slug() );
	}

	/**
	 * Content type accessor returns RSS with UTF-8 charset.
	 *
	 * @return void
	 */
	public function test_content_type(): void {
		$this->assertSame(
			'application/rss+xml; charset=UTF-8',
			$this->renderer->get_content_type()
		);
	}

	/**
	 * Normalization config matches ADR-ARCH-0003.
	 *
	 * @return void
	 */
	public function test_normalization_config(): void {
		$config = $this->renderer->get_normalization_config();

		$this->assertNull( $config['max_content_length'] );
		$this->assertSame( 'none', $config['truncate_mode'] );
		$this->assertSame( 0, $config['truncate_limit'] );
		$this->assertNull( $config['allowed_html'] );
		$this->assertNull( $config['image_min_width'] );
		$this->assertNull( $config['image_min_height'] );
		$this->assertTrue( $config['include_terms'] );
	}

	/**
	 * Empty item list still produces valid RSS.
	 *
	 * @return void
	 */
	public function test_empty_items_produce_valid_rss(): void {
		$xml = $this->renderer->render( array(), $this->args() );
		$rss = $this->parse( $xml );

		$this->assertSame( 'rss', $rss->getName() );
		$this->assertSame( '2.0', (string) $rss['version'] );
		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * Channel metadata is emitted from args.
	 *
	 * @return void
	 */
	public function test_channel_metadata(): void {
		$xml = $this->renderer->render( array(), $this->args() );
		$rss = $this->parse( $xml );

		$this->assertSame( 'Example site', (string) $rss->channel->title );
		$this->assertSame( 'https://example.com/', (string) $rss->channel->link );
		$this->assertSame( 'Latest news', (string) $rss->channel->description );
		$this->assertSame( 'ru', (string) $rss->channel->language );
	}

	/**
	 * Item title, link, description and pubDate are emitted.
	 *
	 * @return void
	 */
	public function test_item_core_fields(): void {
		$xml = $this->renderer->render( array( $this->item() ), $this->args() );
		$rss = $this->parse( $xml );

		$this->assertCount( 1, $rss->channel->item );
		$item = $rss->channel->item[0];

		$this->assertSame( 'Hello world', (string) $item->title );
		$this->assertSame( 'https://example.com/post/1', (string) $item->link );
		$this->assertSame( 'Short teaser', (string) $item->description );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- SimpleXML exposes RSS element pubDate as a camelCase property.
		$pub_date = (string) $item->pubDate;

		$this->assertMatchesRegularExpression(
			'/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/',
			$pub_date
		);
	}

	/**
	 * Carries the sanitized content in yandex:full-text CDATA.
	 *
	 * @return void
	 */
	public function test_full_text_cdata(): void {
		$xml = $this->renderer->render( array( $this->item() ), $this->args() );
		$rss = $this->parse( $xml );
		$rss->registerXPathNamespace( 'yandex', 'http://news.yandex.ru' );

		$full = $rss->xpath( '//yandex:full-text' );
		$this->assertIsArray( $full );
		$this->assertNotEmpty( $full );
		$this->assertSame( '<p>Full body</p>', (string) $full[0] );
	}

	/**
	 * A CDATA terminator inside content is escaped.
	 *
	 * @return void
	 */
	public function test_cdata_terminator_is_escaped(): void {
		$xml = $this->renderer->render(
			array( $this->item( array( 'content' => 'a ]]> b' ) ) ),
			$this->args()
		);
		$rss = $this->parse( $xml );
		$rss->registerXPathNamespace( 'yandex', 'http://news.yandex.ru' );

		$full = $rss->xpath( '//yandex:full-text' );
		$this->assertIsArray( $full );
		$this->assertSame( 'a ]]> b', (string) $full[0] );
	}

	/**
	 * Title is XML-escaped.
	 *
	 * @return void
	 */
	public function test_title_is_xml_escaped(): void {
		$xml = $this->renderer->render(
			array( $this->item( array( 'title' => 'A & B < C' ) ) ),
			$this->args()
		);
		$rss = $this->parse( $xml );

		$this->assertSame( 'A & B < C', (string) $rss->channel->item[0]->title );
	}

	/**
	 * Title is truncated to 200 characters.
	 *
	 * @return void
	 */
	public function test_title_truncated_to_200_chars(): void {
		$long = str_repeat( 'a', 250 );
		$xml  = $this->renderer->render(
			array( $this->item( array( 'title' => $long ) ) ),
			$this->args()
		);
		$rss  = $this->parse( $xml );

		$title = (string) $rss->channel->item[0]->title;
		$this->assertLessThanOrEqual( 200, mb_strlen( $title ) );
	}

	/**
	 * Omits description when excerpt is empty.
	 *
	 * @return void
	 */
	public function test_description_omitted_when_excerpt_empty(): void {
		$xml = $this->renderer->render(
			array( $this->item( array( 'excerpt' => '' ) ) ),
			$this->args()
		);
		$rss = $this->parse( $xml );

		$this->assertCount( 0, $rss->channel->item[0]->description );
	}

	/**
	 * Takes category from the first category term.
	 *
	 * @return void
	 */
	public function test_category_from_first_category_term(): void {
		$terms = array(
			array(
				'taxonomy' => 'category',
				'id'       => 12,
				'name'     => 'Politics',
				'slug'     => 'politics',
			),
			array(
				'taxonomy' => 'category',
				'id'       => 13,
				'name'     => 'World',
				'slug'     => 'world',
			),
		);

		$xml = $this->renderer->render(
			array( $this->item( array( 'terms' => $terms ) ) ),
			$this->args()
		);
		$rss = $this->parse( $xml );

		$this->assertSame( 'Politics', (string) $rss->channel->item[0]->category );
	}

	/**
	 * Omits category when only non-category terms exist.
	 *
	 * @return void
	 */
	public function test_category_omitted_without_category_terms(): void {
		$terms = array(
			array(
				'taxonomy' => 'post_tag',
				'id'       => 100,
				'name'     => 'Interview',
				'slug'     => 'interview',
			),
		);

		$xml = $this->renderer->render(
			array( $this->item( array( 'terms' => $terms ) ) ),
			$this->args()
		);
		$rss = $this->parse( $xml );

		$this->assertCount( 0, $rss->channel->item[0]->category );
	}

	/**
	 * Omits author when empty.
	 *
	 * @return void
	 */
	public function test_author_omitted_when_empty(): void {
		$xml = $this->renderer->render( array( $this->item() ), $this->args() );
		$rss = $this->parse( $xml );

		$this->assertCount( 0, $rss->channel->item[0]->author );
	}

	/**
	 * Emits author when present.
	 *
	 * @return void
	 */
	public function test_author_emitted_when_present(): void {
		$xml = $this->renderer->render(
			array( $this->item( array( 'author' => 'Иван Иванов' ) ) ),
			$this->args()
		);
		$rss = $this->parse( $xml );

		$this->assertSame( 'Иван Иванов', (string) $rss->channel->item[0]->author );
	}

	/**
	 * Emits yandex:genre via GenreResolver.
	 *
	 * @return void
	 */
	public function test_genre_from_tag(): void {
		$terms = array(
			array(
				'taxonomy' => 'post_tag',
				'id'       => 100,
				'name'     => 'Interview',
				'slug'     => 'interview',
			),
		);

		$xml = $this->renderer->render(
			array( $this->item( array( 'terms' => $terms ) ) ),
			$this->args( array( 'tag_interview' => 100 ) )
		);
		$rss = $this->parse( $xml );
		$rss->registerXPathNamespace( 'yandex', 'http://news.yandex.ru' );

		$genre = $rss->xpath( '//yandex:genre' );
		$this->assertIsArray( $genre );
		$this->assertNotEmpty( $genre );
		$this->assertSame( 'interview', (string) $genre[0] );
	}

	/**
	 * Item with missing required fields is skipped.
	 *
	 * @return void
	 */
	public function test_item_with_empty_title_is_skipped(): void {
		$xml = $this->renderer->render(
			array( $this->item( array( 'title' => '' ) ) ),
			$this->args()
		);
		$rss = $this->parse( $xml );

		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * Item without a DateTime published is skipped.
	 *
	 * @return void
	 */
	public function test_item_without_datetime_is_skipped(): void {
		$xml = $this->renderer->render(
			array( $this->item( array( 'published' => 'not a date' ) ) ),
			$this->args()
		);
		$rss = $this->parse( $xml );

		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * Build the full settings array with the Yandex section.
	 *
	 * @param array<string, mixed> $renderer_overrides Yandex field overrides.
	 * @param array<string, mixed> $core_overrides     Core field overrides.
	 * @return array<string, mixed>
	 */
	private function renderer_settings( array $renderer_overrides = array(), array $core_overrides = array() ): array {
		return array(
			'core'      => array_merge(
				array(
					'feed_slug'   => 'feed',
					'depth_hours' => 4,
					'cache_ttl'   => 5,
				),
				$core_overrides
			),
			'renderers' => array(
				'yandex' => array_merge(
					array(
						'enabled'            => true,
						'tag_interview'      => 0,
						'tag_article'        => 0,
						'tag_lenta'          => 0,
						'by_length'          => false,
						'include_taxonomies' => '',
						'exclude_taxonomies' => '',
					),
					$renderer_overrides
				),
			),
		);
	}

	/**
	 * Settings schema covers every key the collector reads.
	 *
	 * @return void
	 */
	public function test_settings_schema_has_required_keys(): void {
		$schema = $this->renderer->get_settings_schema();

		foreach ( array( 'enabled', 'tag_interview', 'tag_article', 'tag_lenta', 'by_length', 'include_taxonomies', 'exclude_taxonomies' ) as $key ) {
			$this->assertArrayHasKey( $key, $schema, "Missing schema key: {$key}" );
		}

		foreach ( $schema as $key => $field ) {
			$this->assertArrayHasKey( 'type', $field, "Schema field {$key} has no type." );
			$this->assertArrayHasKey( 'label', $field, "Schema field {$key} has no label." );
		}
	}

	/**
	 * Missing enabled key disables the renderer.
	 *
	 * @return void
	 */
	public function test_is_enabled_false_when_key_missing(): void {
		$settings = array(
			'core'      => array(),
			'renderers' => array( 'yandex' => array() ),
		);

		$this->assertFalse( $this->renderer->is_enabled( $settings ) );
	}

	/**
	 * Explicit enabled = true enables the renderer.
	 *
	 * @return void
	 */
	public function test_is_enabled_true_when_set(): void {
		$this->assertTrue( $this->renderer->is_enabled( $this->renderer_settings( array( 'enabled' => true ) ) ) );
	}

	/**
	 * Explicit enabled = false disables the renderer.
	 *
	 * @return void
	 */
	public function test_is_enabled_false_when_disabled(): void {
		$this->assertFalse( $this->renderer->is_enabled( $this->renderer_settings( array( 'enabled' => false ) ) ) );
	}

	/**
	 * Collector args carry the core depth and empty taxonomy filters.
	 *
	 * @return void
	 */
	public function test_collector_args_defaults(): void {
		$args = $this->renderer->get_collector_args( $this->renderer_settings() );

		$this->assertSame( 4, $args['depth_hours'] );
		$this->assertSame( 0, $args['limit'] );
		$this->assertSame( array(), $args['taxonomy_include'] );
		$this->assertSame( array(), $args['taxonomy_exclude'] );
		$this->assertArrayNotHasKey( 'normalization_config', $args );
	}

	/**
	 * Collector args read depth from the core section.
	 *
	 * @return void
	 */
	public function test_collector_args_depth_from_core(): void {
		$args = $this->renderer->get_collector_args(
			$this->renderer_settings( array(), array( 'depth_hours' => 8 ) )
		);

		$this->assertSame( 8, $args['depth_hours'] );
	}

	/**
	 * Include taxonomy filter parses to a taxonomy map.
	 *
	 * @return void
	 */
	public function test_collector_args_parses_include_taxonomies(): void {
		$args = $this->renderer->get_collector_args(
			$this->renderer_settings( array( 'include_taxonomies' => 'category:12,15|post_tag:100' ) )
		);

		$this->assertSame(
			array(
				'category' => array( 12, 15 ),
				'post_tag' => array( 100 ),
			),
			$args['taxonomy_include']
		);
	}

	/**
	 * Filter without a taxonomy prefix defaults to category.
	 *
	 * @return void
	 */
	public function test_collector_args_defaults_to_category_prefix(): void {
		$args = $this->renderer->get_collector_args(
			$this->renderer_settings( array( 'include_taxonomies' => '12,15' ) )
		);

		$this->assertSame( array( 'category' => array( 12, 15 ) ), $args['taxonomy_include'] );
	}

	/**
	 * Exclude taxonomy filter parses to a taxonomy map.
	 *
	 * @return void
	 */
	public function test_collector_args_parses_exclude_taxonomies(): void {
		$args = $this->renderer->get_collector_args(
			$this->renderer_settings( array( 'exclude_taxonomies' => 'post_tag:100' ) )
		);

		$this->assertSame( array( 'post_tag' => array( 100 ) ), $args['taxonomy_exclude'] );
	}

	/**
	 * Garbage in the filter string is dropped silently.
	 *
	 * @return void
	 */
	public function test_collector_args_drops_invalid_term_values(): void {
		$args = $this->renderer->get_collector_args(
			$this->renderer_settings( array( 'include_taxonomies' => 'category:12,abc,-1,0,15,1.5, 12' ) )
		);

		$this->assertSame( array( 'category' => array( 12, 15 ) ), $args['taxonomy_include'] );
	}
}
