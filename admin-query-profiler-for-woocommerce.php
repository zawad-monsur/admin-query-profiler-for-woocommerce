<?php
/**
 * Plugin Name:       Admin Query Profiler for WooCommerce
 * Plugin URI:        https://github.com/
 * Description:       Finds out which plugin is making your WooCommerce admin slow, by attributing every database query to the plugin that caused it.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Zawad Monsur
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admin-query-profiler-for-woocommerce
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 *
 * @package AdminQueryProfilerForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'AQP_VERSION', '0.1.0' );
define( 'AQP_PLUGIN_FILE', __FILE__ );
define( 'AQP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AQP_PLUGIN_DIR . 'includes/class-aqp-profiler.php';

/**
 * Boot the profiler.
 *
 * The profiler itself does nothing until a request is explicitly armed - see
 * AQP_Profiler::should_profile(). Loading it is cheap; it only registers two
 * hooks until someone presses the button.
 */
function aqp_bootstrap() {
	AQP_Profiler::init();
}
add_action( 'plugins_loaded', 'aqp_bootstrap', -1 );

/**
 * Warn, but keep working, when WooCommerce is missing.
 *
 * The profiler is useful on any admin list screen, so refusing to load without
 * WooCommerce would be unhelpfully strict - but the whole point is WooCommerce
 * order screens, so say so.
 */
function aqp_maybe_warn_no_woocommerce() {
	if ( class_exists( 'WooCommerce' ) ) {
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	esc_html_e(
		'Admin Query Profiler is built for WooCommerce order screens. WooCommerce is not active, so the profiler will still run but has little to measure.',
		'admin-query-profiler-for-woocommerce'
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'aqp_maybe_warn_no_woocommerce' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 *
 * Without this WooCommerce shows an "incompatible plugin" warning on any store
 * running HPOS - which is exactly the audience this plugin is for.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);
