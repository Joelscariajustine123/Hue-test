<?php
/**
 * Frontend script loading + add-to-cart logging.
 *
 * @package UID_Cart_Tracker
 */

defined( 'ABSPATH' ) || exit;

class UCT_Tracker {

	const SESSION_KEY = 'uct_uid_temp';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// Fires for EVERY successful add-to-cart: single product form POST, archive AJAX
		// (wc-ajax=add_to_cart), ?add-to-cart= links and Store API (block) requests.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'log_add_to_cart' ), 10, 6 );

		// Link logged rows to the order when the visitor checks out (classic + block checkout).
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'link_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'link_order' ), 10, 1 );

		// Show the referral code on the admin order screen.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_order_meta' ) );
	}

	/**
	 * Enqueue the capture/persist script on every frontend page.
	 */
	public static function enqueue_scripts() {
		wp_enqueue_script(
			'uct-tracker',
			UCT_URL . 'assets/js/uct-tracker.js',
			array( 'jquery' ),
			UCT_VERSION,
			true
		);

		$config = array(
			'param'      => UCT_PARAM,
			'storageKey' => UCT_PARAM,
			'nonceField' => UCT_NONCE_FIELD,
			'nonce'      => wp_create_nonce( UCT_NONCE_ACTION ),
			'cookieName' => UCT_COOKIE,
			'cookieDays' => 30,
			'maxLength'  => UCT_MAX_LEN,
		);

		wp_add_inline_script( 'uct-tracker', 'window.uctConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Read the uid_temp sent by the browser for the current add-to-cart request.
	 *
	 * 1. Request parameter (form POST, AJAX POST, ?add-to-cart link) – injected by the JS
	 *    from localStorage and accepted only with a valid nonce (blocks CSRF-forged attribution).
	 * 2. Fallback: first-party cookie mirrored from localStorage by the same JS. Used for
	 *    Store API/block requests whose JSON body we cannot extend.
	 *
	 * @return string
	 */
	public static function get_request_uid() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce is verified right here.
		if ( isset( $_REQUEST[ UCT_PARAM ] ) ) {
			$nonce = isset( $_REQUEST[ UCT_NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ UCT_NONCE_FIELD ] ) ) : '';

			if ( wp_verify_nonce( $nonce, UCT_NONCE_ACTION ) ) {
				$uid = uct_sanitize_uid( $_REQUEST[ UCT_PARAM ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in uct_sanitize_uid().
				if ( '' !== $uid ) {
					return $uid;
				}
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[UID Cart Tracker] uid_temp ignored: nonce verification failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		// phpcs:enable

		if ( isset( $_COOKIE[ UCT_COOKIE ] ) ) {
			return uct_sanitize_uid( $_COOKIE[ UCT_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in uct_sanitize_uid().
		}

		return '';
	}

	/**
	 * Work out how the product was added.
	 *
	 * @return string form|ajax|link|store_api
	 */
	private static function detect_source() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'store_api';
		}
		if ( wp_doing_ajax() ) { // WC defines DOING_AJAX for wc-ajax endpoints.
			return 'ajax';
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return 'POST' === $method ? 'form' : 'link';
	}

	/**
	 * Log an add-to-cart event.
	 *
	 * @param string $cart_item_key  Cart item key.
	 * @param int    $product_id     Product ID.
	 * @param int    $quantity       Quantity added.
	 * @param int    $variation_id   Variation ID.
	 * @param array  $variation      Variation attributes.
	 * @param array  $cart_item_data Extra cart item data.
	 */
	public static function log_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		global $wpdb;

		$uid = self::get_request_uid();

		/**
		 * Only add-to-carts that carry a share code are logged by default.
		 * Return true from this filter to log every add-to-cart (uid_temp stored as '').
		 */
		if ( '' === $uid && ! apply_filters( 'uct_log_without_uid', false ) ) {
			return;
		}

		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$quantity     = max( 1, absint( $quantity ) );
		$product      = wc_get_product( $variation_id ? $variation_id : $product_id );

		$product_name = $product ? wp_strip_all_tags( $product->get_name() ) : '';
		$product_sku  = $product ? (string) $product->get_sku() : '';
		$unit_price   = $product ? (float) $product->get_price() : 0.0;

		$session_key = '';
		if ( WC()->session ) {
			$session_key = (string) WC()->session->get_customer_id();
			WC()->session->set( self::SESSION_KEY, $uid ); // Remember the code for order meta.
		}

		$ip         = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$referer    = wp_get_raw_referer();
		$referer    = $referer ? esc_url_raw( $referer ) : '';

		$table = uct_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, values prepared.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(uid_temp, product_id, variation_id, product_name, product_sku, quantity, unit_price, cart_item_key, source, user_id, session_key, ip_address, user_agent, referer_url, created_at, created_at_gmt)
				VALUES (%s, %d, %d, %s, %s, %d, %f, %s, %s, %d, %s, %s, %s, %s, %s, %s)",
				$uid,
				$product_id,
				$variation_id,
				mb_substr( $product_name, 0, 255 ),
				mb_substr( $product_sku, 0, 100 ),
				$quantity,
				$unit_price,
				substr( sanitize_key( $cart_item_key ), 0, 64 ),
				self::detect_source(),
				get_current_user_id(),
				substr( $session_key, 0, 100 ),
				substr( sanitize_text_field( $ip ), 0, 45 ),
				mb_substr( $user_agent, 0, 255 ),
				substr( $referer, 0, 255 ),
				current_time( 'mysql' ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * When an order is placed, store the code on the order and link the matching log rows.
	 *
	 * @param int|WC_Order $order Order ID (classic checkout) or order object (Store API).
	 */
	public static function link_order( $order ) {
		global $wpdb;

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || ! WC()->session ) {
			return;
		}

		$uid = uct_sanitize_uid( WC()->session->get( self::SESSION_KEY, '' ) );
		if ( '' === $uid ) {
			return;
		}

		$order->update_meta_data( '_uid_temp', $uid );
		$order->save();

		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product_ids[] = absint( $item->get_product_id() );
		}
		$product_ids = array_values( array_filter( array_unique( $product_ids ) ) );
		if ( empty( $product_ids ) ) {
			return;
		}

		$table        = uct_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$params       = array_merge( array( $order->get_id(), (string) WC()->session->get_customer_id() ), $product_ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET order_id = %d WHERE session_key = %s AND order_id IS NULL AND product_id IN ({$placeholders})",
				$params
			)
		);

		WC()->session->set( self::SESSION_KEY, null );
	}

	/**
	 * Display the referral code in the order edit screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_order_meta( $order ) {
		$uid = $order->get_meta( '_uid_temp' );
		if ( $uid ) {
			echo '<p class="form-field form-field-wide"><strong>' . esc_html__( 'Referral code (uid_temp):', 'uid-cart-tracker' ) . '</strong><br><code style="white-space:nowrap">' . esc_html( $uid ) . '</code></p>';
		}
	}
}
