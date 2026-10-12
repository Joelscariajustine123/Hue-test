<?php
/**
 * Runs when the plugin is deleted from Plugins > Installed Plugins.
 * Drops the custom table and the schema version option.
 *
 * @package UID_Cart_Tracker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$uct_table = $wpdb->prefix . 'uid_cart_referrals';

$wpdb->query( "DROP TABLE IF EXISTS {$uct_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
delete_option( 'uct_db_version' );
