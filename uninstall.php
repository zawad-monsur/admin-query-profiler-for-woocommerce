<?php
/**
 * Remove everything this plugin stored.
 *
 * The profiler keeps no options and no tables. Its only persistence is a
 * short-lived transient per user per screen, holding the previous scan so the
 * next one can be compared against it. Those expire on their own within the
 * hour, but leaving rows behind on uninstall is sloppy - and on a site with
 * many admins there can be a handful.
 *
 * @package AdminQueryProfilerForWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Transients live in the options table when there is no persistent object
// cache, which is the common case and the one this plugin exists to diagnose.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_aqp_prev_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_aqp_prev_' ) . '%'
	)
);

// With a persistent object cache the rows above will not exist, so also flush
// the group the transients were stored under.
wp_cache_flush();
