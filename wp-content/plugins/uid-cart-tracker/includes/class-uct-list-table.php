<?php
/**
 * List table for the Cart Referrals admin page.
 *
 * @package UID_Cart_Tracker
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class UCT_List_Table extends WP_List_Table {

	/**
	 * Active filters.
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * Constructor.
	 *
	 * @param array $filters Sanitized filters from UCT_Admin::get_filters().
	 */
	public function __construct( array $filters ) {
		$this->filters = $filters;
		parent::__construct(
			array(
				'singular' => 'referral',
				'plural'   => 'referrals',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Human labels for the source column.
	 *
	 * @return array
	 */
	public static function source_labels() {
		return array(
			'ajax'      => __( 'AJAX (shop/archive)', 'uid-cart-tracker' ),
			'form'      => __( 'Form submit (single product)', 'uid-cart-tracker' ),
			'link'      => __( 'Add-to-cart link', 'uid-cart-tracker' ),
			'store_api' => __( 'Block / Store API', 'uid-cart-tracker' ),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'id'         => __( 'ID', 'uid-cart-tracker' ),
			'uid_temp'   => __( 'Tracking code (uid_temp)', 'uid-cart-tracker' ),
			'product'    => __( 'Product', 'uid-cart-tracker' ),
			'quantity'   => __( 'Qty', 'uid-cart-tracker' ),
			'unit_price' => __( 'Unit price', 'uid-cart-tracker' ),
			'source'     => __( 'Added via', 'uid-cart-tracker' ),
			'customer'   => __( 'Customer', 'uid-cart-tracker' ),
			'order_id'   => __( 'Order', 'uid-cart-tracker' ),
			'created_at' => __( 'Date / time', 'uid-cart-tracker' ),
		);
	}

	/**
	 * Sortable columns (whitelisted, mapped to real DB columns).
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'         => array( 'id', false ),
			'uid_temp'   => array( 'uid_temp', false ),
			'product'    => array( 'product_name', false ),
			'quantity'   => array( 'quantity', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'uid-cart-tracker' ) );
	}

	/**
	 * Handle bulk delete (nonce + capability verified).
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		if ( ! current_user_can( UCT_Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to delete these entries.', 'uid-cart-tracker' ) );
		}

		$ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;
		$table        = uct_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );

		/* translators: %d: number of rows deleted. */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d entry deleted.', '%d entries deleted.', (int) $deleted, 'uid-cart-tracker' ), (int) $deleted ) ) . '</p></div>';
	}

	/**
	 * Query the rows for the current page.
	 */
	public function prepare_items() {
		global $wpdb;

		$table    = uct_table_name();
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$where    = UCT_Admin::build_where( $this->filters );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sorting args only, whitelisted below.
		$allowed = array( 'id', 'uid_temp', 'product_name', 'quantity', 'created_at' );
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'id';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where prepared, orderby/order whitelisted.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
				$per_page,
				( $paged - 1 ) * $per_page
			),
			ARRAY_A
		);
		// phpcs:enable

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'uid_temp' );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Message when there are no rows.
	 */
	public function no_items() {
		esc_html_e( 'No referral add-to-cart events recorded yet. Open the shop with ?uid_temp=YOUR_CODE and add a product to the cart.', 'uid-cart-tracker' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', absint( $item['id'] ) );
	}

	/**
	 * Tracking code column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_uid_temp( $item ) {
		$filter_url = add_query_arg( array( 'page' => UCT_Admin::PAGE_SLUG, 's' => $item['uid_temp'] ), admin_url( 'admin.php' ) );
		return sprintf(
			'<a href="%s" title="%s"><code>%s</code></a>',
			esc_url( $filter_url ),
			esc_attr__( 'Show all events for this code', 'uid-cart-tracker' ),
			esc_html( '' !== $item['uid_temp'] ? $item['uid_temp'] : '—' )
		);
	}

	/**
	 * Product column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_product( $item ) {
		$name = '' !== $item['product_name'] ? $item['product_name'] : sprintf( '#%d', $item['product_id'] );
		$edit = get_edit_post_link( (int) $item['product_id'] );
		$html = $edit ? sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( $edit ), esc_html( $name ) ) : '<strong>' . esc_html( $name ) . '</strong>';

		$meta = array( sprintf( 'ID: %d', (int) $item['product_id'] ) );
		if ( (int) $item['variation_id'] ) {
			$meta[] = sprintf( 'Variation: %d', (int) $item['variation_id'] );
		}
		if ( '' !== $item['product_sku'] ) {
			$meta[] = 'SKU: ' . $item['product_sku'];
		}

		return $html . '<br><small>' . esc_html( implode( ' · ', $meta ) ) . '</small>';
	}

	/**
	 * Source column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		$labels = self::source_labels();
		$label  = isset( $labels[ $item['source'] ] ) ? $labels[ $item['source'] ] : $item['source'];
		return sprintf( '<span class="uct-source uct-source-%s">%s</span>', esc_attr( $item['source'] ), esc_html( $label ) );
	}

	/**
	 * Customer column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_customer( $item ) {
		$user = (int) $item['user_id'] ? get_userdata( (int) $item['user_id'] ) : false;
		$who  = $user ? esc_html( $user->display_name ) : esc_html__( 'Guest', 'uid-cart-tracker' );
		return $who . ( '' !== $item['ip_address'] ? '<br><small>' . esc_html( $item['ip_address'] ) . '</small>' : '' );
	}

	/**
	 * Order column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_order_id( $item ) {
		if ( empty( $item['order_id'] ) ) {
			return '<span aria-hidden="true">—</span>';
		}
		$order = wc_get_order( (int) $item['order_id'] );
		if ( ! $order ) {
			return esc_html( '#' . (int) $item['order_id'] );
		}
		return sprintf( '<a href="%s">#%s</a><br><small>%s</small>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ), esc_html( wc_get_order_status_name( $order->get_status() ) ) );
	}

	/**
	 * Date column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$local = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] );
		$ago   = human_time_diff( strtotime( $item['created_at_gmt'] . ' UTC' ), time() );
		/* translators: %s: human readable time difference. */
		return esc_html( $local ) . '<br><small>' . esc_html( sprintf( __( '%s ago', 'uid-cart-tracker' ), $ago ) ) . '</small>';
	}

	/**
	 * Fallback for simple columns.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
			case 'quantity':
				return esc_html( (string) (int) $item[ $column_name ] );
			case 'unit_price':
				return wp_kses_post( wc_price( (float) $item['unit_price'] ) );
			default:
				return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
		}
	}
}
