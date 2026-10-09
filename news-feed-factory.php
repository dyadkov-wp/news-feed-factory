<?php
/**
 * Plugin bootstrap file.
 *
 * @package NewsFeedFactory
 *
 * @wordpress-plugin
 * Plugin Name:       News Feed Factory
 * Plugin URI:        https://github.com/dyadkov-wp/news-feed-factory
 * Description:       Generate news feeds in multiple formats (Yandex, Google, Dzen, generic RSS) from WordPress content.
 * Version:           0.1.0-dev
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Dyadkov WP
 * Author URI:        https://github.com/dyadkov-wp
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       news-feed-factory
 * Domain Path:       /languages
 */

declare( strict_types=1 );

namespace NWFF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NWFF_VERSION', '0.1.0-dev' );
define( 'NWFF_FILE', __FILE__ );
define( 'NWFF_DIR', plugin_dir_path( __FILE__ ) );
define( 'NWFF_URL', plugin_dir_url( __FILE__ ) );
define( 'NWFF_DB_VERSION', '1' );

/**
 * PSR-4 autoloader for the NWFF\ namespace.
 *
 * Composer is used only for development tooling. The dist archive
 * excludes /vendor, so runtime autoloading must not depend on Composer.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'NWFF\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = substr( $class_name, $length );
		$path     = NWFF_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);
