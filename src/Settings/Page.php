<?php
/**
 * Settings page.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

namespace NWFF\Settings;

/**
 * Registers the plugin settings page (ADR-ARCH-0006).
 *
 * Wires the Settings API: one page, one option, one section per
 * renderer declared by Store.
 */
final class Page {

	private const OPTION_GROUP = 'nwff_settings_group';
	private const OPTION_NAME  = 'nwff_settings';
	private const PAGE_SLUG    = 'nwff_settings';

	/**
	 * Settings store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Create the page.
	 *
	 * @param Store $store Settings store.
	 */
	public function __construct( Store $store ) {
		$this->store = $store;
	}

	/**
	 * Register hooks, the option, sections and fields.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the option, sections and fields on admin_init.
	 *
	 * Hooked to admin_init so register_setting() runs before
	 * options.php handles a POST.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$this->add_sections();
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array( 'sanitize_callback' => array( $this->store, 'sanitize' ) )
		);
	}

	/**
	 * Add the options page.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			'News Feed Factory',
			'News Feed Factory',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the core section plus one section per renderer.
	 *
	 * @return void
	 */
	private function add_sections(): void {
		add_settings_section(
			'nwff_core',
			'Core settings',
			'__return_null',
			self::PAGE_SLUG
		);

		$this->add_core_fields();

		foreach ( $this->store->renderers() as $slug => $renderer ) {
			$section_id = 'nwff_renderer_' . $slug;

			add_settings_section(
				$section_id,
				ucfirst( $slug ),
				'__return_null',
				self::PAGE_SLUG
			);

			foreach ( $renderer->get_settings_schema() as $key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				add_settings_field(
					'nwff_' . $slug . '_' . $key,
					(string) ( $field['label'] ?? $key ),
					array( $this, 'render_field' ),
					self::PAGE_SLUG,
					$section_id,
					array(
						'field' => $field,
						'path'  => array( 'renderers', (string) $slug, (string) $key ),
					)
				);
			}
		}
	}

	/**
	 * Register the three core fields.
	 *
	 * @return void
	 */
	private function add_core_fields(): void {
		$fields = array(
			'feed_slug'   => array(
				'type'  => 'text',
				'label' => 'Feed slug',
			),
			'depth_hours' => array(
				'type'  => 'number',
				'label' => 'Depth (hours)',
				'min'   => 1,
				'max'   => 12,
			),
			'cache_ttl'   => array(
				'type'  => 'number',
				'label' => 'Cache TTL (minutes)',
				'min'   => 1,
				'max'   => 5,
			),
		);

		foreach ( $fields as $key => $field ) {
			add_settings_field(
				'nwff_core_' . $key,
				(string) $field['label'],
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'nwff_core',
				array(
					'field' => $field,
					'path'  => array( 'core', $key ),
				)
			);
		}
	}

	/**
	 * Render a single field by its schema type.
	 *
	 * @param array<string, mixed> $args Field args with 'field' and 'path'.
	 * @return void
	 */
	public function render_field( array $args ): void {
		$field = is_array( $args['field'] ?? null ) ? $args['field'] : array();
		$path  = is_array( $args['path'] ?? null ) ? $args['path'] : array();

		$settings = $this->store->get();
		$value    = $settings;

		foreach ( $path as $step ) {
			if ( ! is_array( $value ) || ! array_key_exists( $step, $value ) ) {
				$value = null;
				break;
			}
			$value = $value[ $step ];
		}

		$name = self::OPTION_NAME . '[' . implode(
			'][',
			array_map( 'strval', $path )
		) . ']';

		$type = is_string( $field['type'] ?? null ) ? $field['type'] : 'text';

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<input type="checkbox" name="%s" value="1" %s />',
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a WP-generated attribute string.
				);
				break;
			case 'number':
				printf(
					'<input type="number" name="%s" value="%s"%s%s />',
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' ),
					isset( $field['min'] ) ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '',
					isset( $field['max'] ) ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : ''
				);
				break;
			case 'text':
			default:
				printf(
					'<input type="text" name="%s" value="%s" class="regular-text" />',
					esc_attr( $name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' )
				);
				break;
		}

		if ( ! empty( $field['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( (string) $field['description'] )
			);
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
