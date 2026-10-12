<?php
/**
 * Plugin Name:       UID Cart Referral Tracker
 * Description:       Captures share codes from the URL (?uid_temp=STRING_ID), persists them in the visitor's localStorage and logs every add-to-cart made with a code into a custom table, viewable under WooCommerce > Cart Referrals. Built from scratch – no third-party referral/affiliate plugins.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Author:            Assessment Submission
 * License:           GPL-2.0-or-later
 * Text Domain:       uid-cart-tracker
 */

defined( 'ABSPATH' ) || exit;

define( 'UCT_VERSION', '1.0.0' );
define( 'UCT_DB_VERSION', '1.0.0' );
define( 'UCT_FILE', __FILE__ );
define( 'UCT_PATH', plugin_dir_path( __FILE__ ) );
define( 'UCT_URL', plugin_dir_url( __FILE__ ) );

define( 'UCT_PARAM', 'uid_temp' );                    // URL / request parameter + localStorage key.
define( 'UCT_NONCE_ACTION', 'uct_track_add_to_cart' ); // Must NOT start with "woocommerce" (WC rewrites guest nonce UIDs for those).
define( 'UCT_NONCE_FIELD', 'uct_nonce' );
define( 'UCT_COOKIE', 'uct_uid_temp' );              // Mirror of localStorage, used only for Store API / block add-to-cart.
define( 'UCT_MAX_LEN', 100 );

require_once UCT_PATH . 'includes/functions.php';
require_once UCT_PATH . 'includes/class-uct-install.php';
require_once UCT_PATH . 'includes/class-uct-tracker.php';

register_activation_hook( __FILE__, array( 'UCT_Install', 'activate' ) );

/**
 * Declare compatibility with WooCommerce HPOS and Cart/Checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		UCT_Install::maybe_upgrade();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'UID Cart Referral Tracker requires WooCommerce to be installed and active.', 'uid-cart-tracker' ) . '</p></div>';
				}
			);
			return;
		}

		UCT_Tracker::init();

		if ( is_admin() ) {
			require_once UCT_PATH . 'includes/class-uct-admin.php';
			UCT_Admin::init();
		}
	}
);
