<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove stored GitHub token.
delete_option( 'sudowp_hub_gh_token' );

// Remove all transients with the sudowp_ prefix.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_sudowp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sudowp_' ) . '%'
	)
);
