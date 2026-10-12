<?php
/**
 * WooCommerce > Cart Referrals admin page.
 *
 * @package UID_Cart_Tracker
 */

defined( 'ABSPATH' ) || exit;

class UCT_Admin {

	const PAGE_SLUG = 'uct-cart-referrals';
	const CAP       = 'manage_woocommerce';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Cart Referrals', 'uid-cart-tracker' ),
			__( 'Cart Referrals', 'uid-cart-tracker' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Read and sanitize the current filters from the query string.
	 *
	 * @return array
	 */
	public static function get_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters, capability checked.
		$search    = isset( $_GET['s'] ) ? uct_sanitize_uid( $_GET['s'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$source    = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		// phpcs:enable

		$valid_date = '/^\d{4}-\d{2}-\d{2}$/';

		return array(
			's'         => $search,
			'source'    => in_array( $source, array( 'form', 'ajax', 'link', 'store_api' ), true ) ? $source : '',
			'date_from' => preg_match( $valid_date, $date_from ) ? $date_from : '',
			'date_to'   => preg_match( $valid_date, $date_to ) ? $date_to : '',
		);
	}

	/**
	 * Build a prepared WHERE clause for the given filters.
	 *
	 * @param array $filters From get_filters().
	 * @return string SQL starting with "WHERE".
	 */
	public static function build_where( array $filters ) {
		global $wpdb;

		$clauses = array( '1=1' );
		$params  = array();

		if ( '' !== $filters['s'] ) {
			$clauses[] = '(uid_temp LIKE %s OR product_name LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
			$params[]  = $like;
			$params[]  = $like;
		}
		if ( '' !== $filters['source'] ) {
			$clauses[] = 'source = %s';
			$params[]  = $filters['source'];
		}
		if ( '' !== $filters['date_from'] ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $filters['date_from'] . ' 00:00:00';
		}
		if ( '' !== $filters['date_to'] ) {
			$clauses[] = 'created_at <= %s';
			$params[]  = $filters['date_to'] . ' 23:59:59';
		}

		$where = 'WHERE ' . implode( ' AND ', $clauses );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.
		return $params ? $wpdb->prepare( $where, $params ) : $where;
	}

	/**
	 * Summary numbers + top codes for the current filters.
	 *
	 * @param string $where Prepared WHERE clause.
	 * @return array
	 */
	private static function get_summary( $where ) {
		global $wpdb;
		$table = uct_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is already prepared.
		$totals = $wpdb->get_row(
			"SELECT COUNT(*) AS events, COUNT(DISTINCT uid_temp) AS codes, COALESCE(SUM(quantity),0) AS qty, COUNT(DISTINCT order_id) AS orders FROM {$table} {$where}",
			ARRAY_A
		);
		$top    = $wpdb->get_results(
			"SELECT uid_temp, COUNT(*) AS events, SUM(quantity) AS qty, COUNT(DISTINCT order_id) AS orders, MAX(created_at) AS last_seen
			 FROM {$table} {$where} GROUP BY uid_temp ORDER BY events DESC LIMIT 5",
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'totals' => $totals ? $totals : array( 'events' => 0, 'codes' => 0, 'qty' => 0, 'orders' => 0 ),
			'top'    => $top ? $top : array(),
		);
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'uid-cart-tracker' ) );
		}

		require_once UCT_PATH . 'includes/class-uct-list-table.php';

		$filters = self::get_filters();
		$table   = new UCT_List_Table( $filters );
		$table->process_bulk_action();
		$table->prepare_items();

		$summary    = self::get_summary( self::build_where( $filters ) );
		$export_url = wp_nonce_url(
			add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'uct_action' => 'export' ), array_filter( $filters ) ), admin_url( 'admin.php' ) ),
			'uct_export'
		);
		$share_url  = add_query_arg( UCT_PARAM, 'TEST123', home_url( '/' ) );
		?>
		<div class="wrap uct-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cart Referrals', 'uid-cart-tracker' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'uid-cart-tracker' ); ?></a>
			<hr class="wp-header-end">

			<p class="description">
				<?php esc_html_e( 'Every add-to-cart made by a visitor who arrived through a shared link is logged here. Example share link:', 'uid-cart-tracker' ); ?>
				<code><?php echo esc_html( $share_url ); ?></code>
			</p>

			<style>
				.uct-cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}
				.uct-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;min-width:150px}
				.uct-card strong{display:block;font-size:24px;line-height:1.3}
				.uct-top{max-width:720px;margin-bottom:16px}
				.uct-source{display:inline-block;padding:2px 8px;border-radius:10px;background:#f0f0f1;font-size:12px}
				.uct-source-ajax{background:#d7f0ff}.uct-source-form{background:#e5f5e0}.uct-source-link{background:#fcf0d0}.uct-source-store_api{background:#ede4fa}
				.uct-filters input[type=date]{vertical-align:middle}
				.column-uid_temp code{font-size:13px}
			</style>

			<div class="uct-cards">
				<div class="uct-card"><span><?php esc_html_e( 'Add-to-cart events', 'uid-cart-tracker' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $summary['totals']['events'] ) ); ?></strong></div>
				<div class="uct-card"><span><?php esc_html_e( 'Unique codes', 'uid-cart-tracker' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $summary['totals']['codes'] ) ); ?></strong></div>
				<div class="uct-card"><span><?php esc_html_e( 'Items added', 'uid-cart-tracker' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $summary['totals']['qty'] ) ); ?></strong></div>
				<div class="uct-card"><span><?php esc_html_e( 'Orders placed', 'uid-cart-tracker' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $summary['totals']['orders'] ) ); ?></strong></div>
			</div>

			<?php if ( ! empty( $summary['top'] ) ) : ?>
				<h2><?php esc_html_e( 'Top referral codes', 'uid-cart-tracker' ); ?></h2>
				<table class="widefat striped uct-top">
					<thead><tr>
						<th><?php esc_html_e( 'Code (uid_temp)', 'uid-cart-tracker' ); ?></th>
						<th><?php esc_html_e( 'Events', 'uid-cart-tracker' ); ?></th>
						<th><?php esc_html_e( 'Items', 'uid-cart-tracker' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'uid-cart-tracker' ); ?></th>
						<th><?php esc_html_e( 'Last activity', 'uid-cart-tracker' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $summary['top'] as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row['uid_temp'] ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['events'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['qty'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['orders'] ) ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['last_seen'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="get" class="uct-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<?php $table->search_box( __( 'Search code / product', 'uid-cart-tracker' ), 'uct-search' ); ?>
				<div class="alignleft actions">
					<select name="source">
						<option value=""><?php esc_html_e( 'All sources', 'uid-cart-tracker' ); ?></option>
						<?php foreach ( UCT_List_Table::source_labels() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['source'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label><?php esc_html_e( 'From', 'uid-cart-tracker' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>"></label>
					<label><?php esc_html_e( 'To', 'uid-cart-tracker' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>"></label>
					<?php submit_button( __( 'Filter', 'uid-cart-tracker' ), '', 'filter_action', false ); ?>
				</div>
			</form>

			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Stream the filtered log as CSV (nonce + capability protected).
	 */
	public static function maybe_export_csv() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		if ( ! isset( $_GET['page'], $_GET['uct_action'] ) || self::PAGE_SLUG !== $_GET['page'] || 'export' !== $_GET['uct_action'] ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export this data.', 'uid-cart-tracker' ) );
		}
		check_admin_referer( 'uct_export' );

		global $wpdb;
		$table = uct_table_name();
		$where = self::build_where( self::get_filters() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared.
		$rows = $wpdb->get_results( "SELECT id, uid_temp, product_id, variation_id, product_name, product_sku, quantity, unit_price, source, user_id, order_id, ip_address, created_at FROM {$table} {$where} ORDER BY id DESC", ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=cart-referrals-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'ID', 'uid_temp', 'Product ID', 'Variation ID', 'Product', 'SKU', 'Quantity', 'Unit price', 'Source', 'User ID', 'Order ID', 'IP', 'Date' ) );
		foreach ( $rows as $row ) {
			// Neutralise spreadsheet formula injection.
			$row = array_map(
				static function ( $value ) {
					$value = (string) $value;
					return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
				},
				$row
			);
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
