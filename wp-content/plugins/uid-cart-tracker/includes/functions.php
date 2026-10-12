<?php
/**
 * Shared helpers.
 *
 * @package UID_Cart_Tracker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Full name of the custom log table.
 *
 * @return string
 */
function uct_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'uid_cart_referrals';
}

/**
 * Sanitize a raw (slashed) uid_temp value coming from a superglobal.
 *
 * @param mixed $raw Raw value from $_REQUEST / $_COOKIE.
 * @return string Clean code, or '' when invalid.
 */
function uct_sanitize_uid( $raw ) {
	if ( ! is_scalar( $raw ) ) {
		return '';
	}

	$uid = trim( sanitize_text_field( wp_unslash( (string) $raw ) ) );

	return function_exists( 'mb_substr' ) ? mb_substr( $uid, 0, UCT_MAX_LEN ) : substr( $uid, 0, UCT_MAX_LEN );
}
