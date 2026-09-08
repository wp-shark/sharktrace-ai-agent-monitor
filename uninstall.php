<?php
/**
 * Removes all plugin data.
 *
 * @package AIAM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ai_agent_events' ) );

delete_option( 'aiam_db_version' );
delete_option( 'aiam_retention_days' );

wp_clear_scheduled_hook( 'aiam_daily_cleanup' );
