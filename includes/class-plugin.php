<?php
/**
 * Plugin container and bootstrap.
 *
 * @package AIAM
 */

declare( strict_types = 1 );

namespace AIAM;

use AIAM\Admin\Admin_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the subsystems together.
 */
class Plugin {

	public const RETENTION_OPTION = 'aiam_retention_days';
	public const CLEANUP_HOOK     = 'aiam_daily_cleanup';

	private static ?Plugin $instance = null;

	/**
	 * Shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Start the plugin.
	 */
	public function boot(): void {
		// Collect as early as possible so nothing is missed.
		( new Event_Collector() )->register();
		( new Rest_Api() )->register();

		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'run_cleanup' ) );

		if ( is_admin() ) {
			( new Admin_Page() )->register();
		}
	}

	/**
	 * Create the table when the plugin was dropped in without activation, or after a schema bump.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( Event_Store::DB_VERSION_OPTION ) !== Event_Store::DB_VERSION ) {
			Event_Store::install();
		}
	}

	/**
	 * Make sure the retention cron is registered.
	 */
	public function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Drop events past the retention window.
	 */
	public function run_cleanup(): void {
		Event_Store::purge( self::retention_days() );
	}

	/**
	 * Configured retention window in days.
	 */
	public static function retention_days(): int {
		$days = (int) get_option( self::RETENTION_OPTION, 30 );

		return $days > 0 ? $days : 30;
	}

	/**
	 * Clear scheduled work on deactivation. Stored events are kept.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CLEANUP_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
		}
	}
}
