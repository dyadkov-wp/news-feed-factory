<?php
/**
 * Integration tests for the Dzen feed endpoint.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Feed;

use NWFF\Cache\TransientCache;
use NWFF\Data\Collector;
use NWFF\Data\MetaRepository;
use NWFF\Feed\Endpoint;
use NWFF\Normalize\ContentNormalizer;
use NWFF\Normalize\HtmlSanitizer;
use NWFF\Normalize\ImageExtractor;
use NWFF\Normalize\Truncator;
use NWFF\Plugin;
use SimpleXMLElement;
use WP_UnitTestCase;

/**
 * Covers Endpoint::serve( 'dzen' ) against the real DzenRenderer (ADR-ARCH-0005).
 *
 * Helpers make_post() and parse() are duplicated from Feed/EndpointTest.php
 * pending TD-30 (feed endpoint test refactor). Remove after TD-30 lands.
 *
 * @group integration
 */
final class DzenEndpointTest extends WP_UnitTestCase {

	/**
	 * Endpoint under test.
	 *
	 * @var Endpoint
	 */
	private Endpoint $endpoint;

	/**
	 * Merged settings with the Dzen renderer enabled.
	 *
	 * Note: `enabled => true` is explicit — the schema default is false.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Set up the endpoint and the Dzen-specific settings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settings = array(
			'core'      => array(
				'feed_slug'   => 'feed',
				'depth_hours' => 4,
				'cache_ttl'   => 5,
			),
			'renderers' => array(
				'dzen' => array(
					'enabled'            => true,
					'content_type'       => 'news_only',
					'include_taxonomies' => '',
					'exclude_taxonomies' => '',
				),
			),
		);

		$this->endpoint = new Endpoint(
			Plugin::renderers(),
			new Collector( new MetaRepository(), new ImageExtractor(), new HtmlSanitizer() ),
			new ContentNormalizer( new HtmlSanitizer(), new Truncator() ),
			new TransientCache()
		);
	}

	/**
	 * Create a published post.
	 *
	 * TODO(TD-30): extract to AbstractFeedEndpointTest.
	 *
	 * @param int $age_seconds Age of the post, in seconds. Default one hour.
	 * @return int Post ID.
	 */
	private function make_post( int $age_seconds = HOUR_IN_SECONDS ): int {
		return (int) self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $age_seconds ),
				'post_content'  => '<p>Body</p>',
				'post_title'    => 'Title',
				'post_excerpt'  => 'Teaser',
			)
		);
	}

	/**
	 * Parse a rendered feed.
	 *
	 * TODO(TD-30): extract to AbstractFeedEndpointTest.
	 *
	 * @param string $xml Rendered XML.
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
	 * The dzen slug returns RSS 2.0 with correct content type and channel metadata.
	 *
	 * @return void
	 */
	public function test_dzen_slug_returns_rss_with_content_type(): void {
		$this->make_post();

		$response = $this->endpoint->serve( 'dzen', $this->settings );

		$this->assertIsArray( $response );
		$this->assertSame( 'application/rss+xml; charset=UTF-8', $response['content_type'] );

		$rss = $this->parse( $response['body'] );
		$this->assertSame( 'rss', $rss->getName() );
		$this->assertSame( '2.0', (string) $rss['version'] );
		$this->assertSame( get_bloginfo( 'name' ), (string) $rss->channel->title );
		$this->assertSame( home_url( '/' ), (string) $rss->channel->link );
		$this->assertSame( get_bloginfo( 'description' ), (string) $rss->channel->description );

		$expected_lang = substr( (string) get_bloginfo( 'language' ), 0, 2 );
		$this->assertSame(
			'' !== $expected_lang ? $expected_lang : 'ru',
			(string) $rss->channel->language
		);
	}

	/**
	 * The feed declares the `content` namespace used by content:encoded.
	 *
	 * @return void
	 */
	public function test_dzen_feed_has_content_namespace(): void {
		$this->make_post();

		$response = $this->endpoint->serve( 'dzen', $this->settings );

		$this->assertIsArray( $response );
		$rss        = $this->parse( $response['body'] );
		$namespaces = $rss->getNamespaces( true );

		$this->assertArrayHasKey( 'content', $namespaces );
		$this->assertSame( 'http://purl.org/rss/1.0/modules/content/', $namespaces['content'] );
	}

	/**
	 * Verifies guid carries isPermaLink="true" and matches the item link.
	 *
	 * @return void
	 */
	public function test_dzen_guid_is_permalink_true(): void {
		$this->make_post();

		$response = $this->endpoint->serve( 'dzen', $this->settings );

		$this->assertIsArray( $response );
		$rss = $this->parse( $response['body'] );

		$this->assertCount( 1, $rss->channel->item );
		$item = $rss->channel->item[0];

		$this->assertNotEmpty( (string) $item->link );
		$this->assertSame( 'true', (string) $item->guid['isPermaLink'] );
		$this->assertSame( (string) $item->link, (string) $item->guid );
	}

	/**
	 * Verifies contentType element lives inside item and carries news_only.
	 *
	 * @return void
	 */
	public function test_dzen_content_type_element_is_news_only(): void {
		$this->make_post();

		$response = $this->endpoint->serve( 'dzen', $this->settings );

		$this->assertIsArray( $response );
		$rss = $this->parse( $response['body'] );

		$this->assertCount( 1, $rss->channel->item );
		$item = $rss->channel->item[0];
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- contentType is the Dzen XML element name (dzen.ru/help/ru/export-content/export.html), not a PHP property.
		$this->assertSame( 'news_only', (string) $item->contentType );
	}

	/**
	 * An empty Dzen feed returns valid RSS without items, not null.
	 *
	 * @return void
	 */
	public function test_empty_dzen_feed_returns_valid_rss_without_items(): void {
		$response = $this->endpoint->serve( 'dzen', $this->settings );

		$this->assertIsArray( $response );
		$this->assertSame( 'application/rss+xml; charset=UTF-8', $response['content_type'] );

		$rss = $this->parse( $response['body'] );
		$this->assertSame( 'rss', $rss->getName() );
		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * A disabled Dzen renderer returns null (Plugin maps this to 404).
	 *
	 * @return void
	 */
	public function test_disabled_dzen_returns_null(): void {
		$this->settings['renderers']['dzen']['enabled'] = false;

		$this->assertNull( $this->endpoint->serve( 'dzen', $this->settings ) );
	}

	/**
	 * The second call returns the same body from the cache (byte-for-byte).
	 *
	 * @return void
	 */
	public function test_cache_returns_same_body(): void {
		$this->make_post();

		$first  = $this->endpoint->serve( 'dzen', $this->settings );
		$second = $this->endpoint->serve( 'dzen', $this->settings );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first['body'], $second['body'] );
	}
}
