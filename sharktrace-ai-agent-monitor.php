<?php
/**
 * Plugin Name:       SharkTrace AI Agent Monitor
 * Plugin URI:        https://github.com/wp-shark/sharktrace-ai-agent-monitor
 * Description:       AI observability for WordPress. See what AI agents do inside your site â€” in real time.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP: 7.2
 * Author:            Iqbal Hossain
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sharktrace-ai-agent-monitor
 *
 * @package STAIAM
 */

declare( strict_types = 1 );

namespace STAIAM;

defined( 'ABSPATH' ) || exit;

define( 'STAIAM_VERSION', '0.1.0' );
define( 'STAIAM_FILE', __FILE__ );
define( 'STAIAM_DIR', plugin_dir_path( __FILE__ ) );
define( 'STAIAM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for the STAIAM namespace.
 *
 * STAIAM\Event_Store      -> includes/class-event-store.php
 * STAIAM\Admin\Admin_Page -> admin/class-admin-page.php
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
		$path  = STAIAM_DIR . $dir . '/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( Event_Store::class, 'install' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::instance()->boot();
