<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove stored GitHub token.
delete_option( 'sudowp_hub_gh_token' );

// Clear scheduled cron event.
$timestamp = wp_next_scheduled( 'sudowp_hub_daily_update_check' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'sudowp_hub_daily_update_check' );
}
wp_clear_scheduled_hook( 'sudowp_hub_daily_update_check' );

// Remove all transients with the sudowp_ prefix.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_sudowp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sudowp_' ) . '%'
	)
);
