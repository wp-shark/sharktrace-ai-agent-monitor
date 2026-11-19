<?php
/**
 * Removes all plugin data.
 *
 * @package STAIAM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'staiam_events' ) );

delete_option( 'staiam_db_version' );
delete_option( 'staiam_retention_days' );

wp_clear_scheduled_hook( 'staiam_daily_cleanup' );

// Leftovers from the release that shipped as "AI Agent Monitor".
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ai_agent_events' ) );

delete_option( 'aiam_db_version' );
delete_option( 'aiam_retention_days' );

wp_clear_scheduled_hook( 'aiam_daily_cleanup' );
