<?php
/**
 * Plugin Name:       AI Agent Monitor
 * Plugin URI:        https://github.com/roxnor/wp-ai-agent-monitor
 * Description:       AI observability for WordPress. See what AI agents do inside your site — in real time.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP: 7.2
 * Author:            Iqbal Hossain
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-agent-monitor
 *
 * @package AIAM
 */

declare( strict_types = 1 );

namespace AIAM;

defined( 'ABSPATH' ) || exit;

define( 'AIAM_VERSION', '0.1.0' );
define( 'AIAM_FILE', __FILE__ );
define( 'AIAM_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIAM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for the AIAM namespace.
 *
 * AIAM\Event_Store      -> includes/class-event-store.php
 * AIAM\Admin\Admin_Page -> admin/class-admin-page.php
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) : 'includes';
		$file  = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$path  = AIAM_DIR . $dir . '/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( Event_Store::class, 'install' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::instance()->boot();
