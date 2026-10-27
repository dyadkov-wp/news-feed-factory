<?php
/**
 * Integration tests for the DzenRenderer allowed_html whitelist.
 *
 * @package NewsFeedFactory
 *
 * @group integration
 */

declare( strict_types=1 );

namespace NWFF\Tests\Integration\Render\Dzen;

use NWFF\Normalize\HtmlSanitizer;
use NWFF\Normalize\Truncator;
use NWFF\Render\Dzen\ContentProcessor;
use NWFF\Render\Dzen\EnclosureResolver;
use NWFF\Render\DzenRenderer;
use WP_UnitTestCase;

/**
 * Covers DzenRenderer::get_normalization_config()['allowed_html'] against
 * figure/figcaption/img cleanup.
 *
 * @group integration
 */
final class FigureCleanupTest extends WP_UnitTestCase {

	/**
	 * Build a DzenRenderer with real (constructor-less) dependencies.
	 *
	 * @return DzenRenderer
	 */
	private function make_renderer(): DzenRenderer {
		return new DzenRenderer(
			new ContentProcessor(),
			new EnclosureResolver(),
			new Truncator()
		);
	}

	/**
	 * Dzen whitelist strips engine attributes from figure/img/figcaption,
	 * keeps src/alt/width/height, and does not strip p.
	 *
	 * @return void
	 */
	public function test_dzen_whitelist_strips_engine_attrs_from_figure_img_figcaption(): void {
		$renderer = $this->make_renderer();
		$config   = $renderer->get_normalization_config();

		$this->assertIsArray( $config['allowed_html'], 'allowed_html must be a non-null array.' );

		$input = '<p>Абзац.</p>'
			. '<figure id="attachment_1" aria-describedby="caption-attachment-1" class="wp-caption alignnone">'
			. '<img class="size-full wp-image-1" src="https://example.test/i.jpg" alt="alt" width="1920" height="1080">'
			. '<figcaption id="caption-attachment-1" class="wp-caption-text">Подпись</figcaption>'
			. '</figure>';

		$out = ( new HtmlSanitizer() )->sanitize( $input, $config['allowed_html'] );

		// figure — без id/class/aria-describedby.
		$this->assertStringNotContainsString( 'aria-describedby', $out );
		$this->assertDoesNotMatchRegularExpression( '/<figure\b[^>]*\bclass=/', $out );
		$this->assertDoesNotMatchRegularExpression( '/<figure\b[^>]*\bid=/', $out );
		$this->assertMatchesRegularExpression( '/<figure\s*>/', $out );

		// img — только src/alt/width/height.
		$this->assertStringContainsString( ' src="https://example.test/i.jpg"', $out );
		$this->assertStringContainsString( ' alt="alt"', $out );
		$this->assertStringContainsString( ' width="1920"', $out );
		$this->assertStringContainsString( ' height="1080"', $out );
		$this->assertDoesNotMatchRegularExpression( '/<img\b[^>]*\bclass=/', $out );

		// figcaption — без id/class, текст сохранён.
		$this->assertDoesNotMatchRegularExpression( '/<figcaption\b[^>]*\bid=/', $out );
		$this->assertDoesNotMatchRegularExpression( '/<figcaption\b[^>]*\bclass=/', $out );
		$this->assertStringContainsString( 'Подпись', $out );

		// Контрольный ассерт: <p> не выпал.
		$this->assertStringContainsString( '<p>Абзац.</p>', $out );
	}
}
