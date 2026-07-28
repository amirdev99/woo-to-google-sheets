<?php
/**
 * Uninstall routine — runs ONLY when the user deletes the plugin.
 *
 * This is the destructive cleanup that deactivation deliberately avoids: drop
 * the queue table, delete our options, and clear the cron event. Kept minimal
 * and self-contained because WordPress loads this file in a bare context.
 *
 * @package WTG
 */

// If this file is not invoked by WordPress' uninstall process, do nothing.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Values below intentionally mirror the plugin's constants. We avoid loading
// the plugin's classes here because uninstall runs in a minimal environment.
$table          = $wpdb->prefix . 'wtg_sync_log';
$options        = array( 'wtg_settings', 'wtg_db_version' );
$cron_hooks     = array( 'wtg_process_sync_queue', 'wtg_process_sync_queue_now' );

// 1. Drop the queue table. Table name is our own constant, not user input.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

// 2. Delete our options.
foreach ( $options as $option ) {
	delete_option( $option );
}

// 3. Clear any scheduled cron events.
foreach ( $cron_hooks as $cron_hook ) {
	wp_clear_scheduled_hook( $cron_hook );
}
