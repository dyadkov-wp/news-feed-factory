<?php
/**
 * PHPStan bootstrap: defines plugin constants without calling
 * WordPress functions. Values are placeholders — only names matter
 * for static analysis.
 *
 * @package NewsFeedFactory
 */

declare( strict_types=1 );

define( 'NWFF_VERSION', '0.1.0-dev' );
define( 'NWFF_FILE', __FILE__ );
define( 'NWFF_DIR', __DIR__ . '/' );
define( 'NWFF_URL', 'https://example.com/' );
define( 'NWFF_DB_VERSION', '1' );
