<?php
/**
 * Database table creation / upgrades.
 *
 * @package UID_Cart_Tracker
 */

defined( 'ABSPATH' ) || exit;

class UCT_Install {

	/**
	 * Activation callback.
	 */
	public static function activate() {
		self::create_table();
		update_option( 'uct_db_version', UCT_DB_VERSION, false );
	}

	/**
	 * Re-run dbDelta when the schema version changes (e.g. plugin updated without re-activation).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'uct_db_version' ) !== UCT_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Create / update the log table.
	 *
	 * Columns:
	 *  - uid_temp        Share code captured from ?uid_temp= (via localStorage).
	 *  - product_id      Parent product ID.
	 *  - variation_id    Variation ID (0 for simple products).
	 *  - product_name    Product name snapshot at the time of the event.
	 *  - product_sku     SKU snapshot.
	 *  - quantity        Quantity added in this action.
	 *  - unit_price      Price snapshot (store currency).
	 *  - cart_item_key   WooCommerce cart item key.
	 *  - source          How the item was added: form | ajax | link | store_api.
	 *  - user_id         Logged-in user (0 = guest).
	 *  - session_key     WooCommerce customer/session ID (used to link orders).
	 *  - ip_address / user_agent / referer_url   Request context.
	 *  - order_id        Filled in when the session checks out with this product.
	 *  - created_at      Site-local timestamp; created_at_gmt = UTC timestamp.
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = uct_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uid_temp varchar(100) NOT NULL DEFAULT '',
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_name varchar(255) NOT NULL DEFAULT '',
			product_sku varchar(100) NOT NULL DEFAULT '',
			quantity int(11) unsigned NOT NULL DEFAULT 1,
			unit_price decimal(19,4) NOT NULL DEFAULT 0,
			cart_item_key varchar(64) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'form',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			session_key varchar(100) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			referer_url varchar(255) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY uid_temp (uid_temp),
			KEY product_id (product_id),
			KEY session_key (session_key),
			KEY order_id (order_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
