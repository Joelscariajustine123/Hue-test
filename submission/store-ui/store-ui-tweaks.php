<?php
/**
 * Plugin Name: Store UI Tweaks (DeliveryAtHome theme layer)
 * Description: Marketplace-style layout for the demo store on top of Storefront: blue search header, category strip, banner carousel, horizontal deal rows, discount labels, Buy Now button, footer. Contains no referral tracking logic.
 * Version:     3.0.0
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ Header */

add_action(
	'wp',
	static function () {
		// Rebuild the Storefront header as a single blue row: logo | search | account | cart.
		remove_action( 'storefront_header', 'storefront_secondary_navigation', 30 );
		remove_action( 'storefront_header', 'storefront_header_container_close', 41 );
		remove_action( 'storefront_header', 'storefront_primary_navigation_wrapper', 42 );
		remove_action( 'storefront_header', 'storefront_primary_navigation', 50 );
		remove_action( 'storefront_header', 'storefront_header_cart', 60 );
		remove_action( 'storefront_header', 'storefront_primary_navigation_wrapper_close', 68 );
		add_action( 'storefront_header', 'dah_header_actions', 45 );
		add_action( 'storefront_header', 'storefront_header_container_close', 49 );
		add_action( 'storefront_header', 'dah_category_bar', 60 );

		// Homepage order: categories strip + carousel, banners (page content), then deal rows.
		remove_action( 'homepage', 'storefront_product_categories', 20 );
		remove_action( 'homepage', 'storefront_on_sale_products', 60 );
		add_action( 'homepage', 'storefront_on_sale_products', 25 );
		add_action( 'homepage', 'dah_home_top', 5 );
	}
);

/**
 * Account + cart links on the right of the header.
 */
function dah_header_actions() {
	$account = wc_get_page_permalink( 'myaccount' );
	$label   = is_user_logged_in() ? sprintf( 'Hi, %s', wp_get_current_user()->first_name ? wp_get_current_user()->first_name : wp_get_current_user()->display_name ) : 'Login';
	$count   = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	?>
	<div class="dah-actions">
		<a class="dah-login" href="<?php echo esc_url( $account ); ?>"><?php echo esc_html( $label ); ?></a>
		<a class="dah-cart" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
			<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM5.2 4l.4 2H21l-2 8H7.4l-.4-2h10.4l1-4H6L5 2H2v2h3.2z"/></svg>
			<span>Cart</span><b class="dah-count"><?php echo esc_html( $count ); ?></b>
		</a>
	</div>
	<?php
}

// Keep the cart count fresh after AJAX add-to-cart.
add_filter(
	'woocommerce_add_to_cart_fragments',
	static function ( $fragments ) {
		$fragments['b.dah-count'] = '<b class="dah-count">' . esc_html( WC()->cart->get_cart_contents_count() ) . '</b>';
		return $fragments;
	}
);

// Search placeholder.
add_filter(
	'get_product_search_form',
	static function ( $html ) {
		return str_replace( 'Search products&hellip;', 'Search for products, categories and more', $html );
	}
);

/**
 * Category bar under the header (icons on the homepage, text links elsewhere).
 */
function dah_category_bar() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);
	if ( is_wp_error( $terms ) ) {
		return;
	}
	$home  = is_front_page();
	$class = $home ? 'dah-cats dah-cats-icons' : 'dah-cats dah-cats-text';
	echo '<nav class="' . esc_attr( $class ) . '" aria-label="Product categories"><div class="col-full"><ul>';
	echo '<li><a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">';
	if ( $home ) {
		echo '<span class="dah-ic dah-ic-all">🛍️</span>';
	}
	echo '<span class="dah-cat-name">All products</span></a></li>';
	foreach ( $terms as $t ) {
		echo '<li><a href="' . esc_url( get_term_link( $t ) ) . '">';
		if ( $home ) {
			$img = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
			echo '<span class="dah-ic">' . ( $img ? wp_get_attachment_image( $img, 'thumbnail', false, array( 'alt' => '' ) ) : '' ) . '</span>';
		}
		echo '<span class="dah-cat-name">' . esc_html( $t->name ) . '</span></a></li>';
	}
	echo '</ul></div></nav>';
}

/* ---------------------------------------------------------------- Homepage */

/**
 * Banner carousel at the top of the homepage.
 */
function dah_home_top() {
	$up   = content_url( 'uploads' );
	$shop = wc_get_page_permalink( 'shop' );
	$cat  = static function ( $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		return $t ? get_term_link( $t ) : wc_get_page_permalink( 'shop' );
	};
	$slides = array(
		array( 's1', 'Winter edit', 'Hoodies from ₹3,359', 'Soft fleece, bold colours. Free delivery across India.', $cat( 'hoodies' ), array( '2019/01/hoodie-2.jpg', '2019/01/hoodie-with-logo-2.jpg' ) ),
		array( 's2', 'Everyday essentials', 'Up to 20% off', 'Wallets, backpacks, bottles and earbuds you will use daily.', $cat( 'essentials' ), array( '2026/10/WALLET-01.jpg', '2026/10/BAG-01.jpg' ) ),
		array( 's3', 'Accessories', 'Caps & beanies from ₹1,279', 'Finish the look. Cash on Delivery available.', $cat( 'accessories' ), array( '2019/01/cap-2.jpg', '2019/01/beanie-2.jpg' ) ),
	);
	?>
	<div class="dah-carousel" data-dah-carousel>
		<div class="dah-track">
			<?php foreach ( $slides as $i => $s ) : ?>
				<a class="dah-slide <?php echo esc_attr( $s[0] ); ?>" href="<?php echo esc_url( $s[4] ); ?>" aria-hidden="<?php echo $i ? 'true' : 'false'; ?>">
					<div class="dah-slide-copy">
						<small><?php echo esc_html( $s[1] ); ?></small>
						<h2><?php echo esc_html( $s[2] ); ?></h2>
						<p><?php echo esc_html( $s[3] ); ?></p>
						<span class="dah-slide-btn">Shop now</span>
					</div>
					<div class="dah-slide-art">
						<?php foreach ( $s[5] as $img ) : ?>
							<img src="<?php echo esc_url( $up . '/' . $img ); ?>" alt="" loading="<?php echo $i ? 'lazy' : 'eager'; ?>">
						<?php endforeach; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
		<button class="dah-nav dah-prev" type="button" aria-label="Previous slide">&#8249;</button>
		<button class="dah-nav dah-next" type="button" aria-label="Next slide">&#8250;</button>
		<div class="dah-dots"><?php foreach ( $slides as $i => $s ) : ?><button type="button" aria-label="Slide <?php echo (int) $i + 1; ?>"<?php echo $i ? '' : ' class="on"'; ?>></button><?php endforeach; ?></div>
	</div>
	<?php
}

// Section titles + horizontal deal rows.
$dah_sections = array(
	'storefront_on_sale_products_args'      => 'Deals of the Day',
	'storefront_recent_products_args'       => 'New arrivals',
	'storefront_featured_products_args'     => 'Top picks for you',
	'storefront_popular_products_args'      => 'Trending now',
	'storefront_best_selling_products_args' => 'Best sellers',
);
foreach ( $dah_sections as $dah_filter => $dah_title ) {
	add_filter(
		$dah_filter,
		static function ( $args ) use ( $dah_title ) {
			return array_merge( $args, array( 'limit' => 8, 'columns' => 4, 'title' => $dah_title ) );
		}
	);
}
$dah_view_all = array(
	'recent_products'       => '/shop/?orderby=date',
	'featured_products'     => '/shop/',
	'popular_products'      => '/shop/?orderby=rating',
	'on_sale_products'      => '/shop/',
	'best_selling_products' => '/shop/?orderby=popularity',
);
foreach ( $dah_view_all as $dah_key => $dah_url ) {
	add_action(
		'storefront_homepage_after_' . $dah_key . '_title',
		static function () use ( $dah_url ) {
			echo '<a class="dah-view-all" href="' . esc_url( home_url( $dah_url ) ) . '">View all</a>';
		}
	);
}

// Share-link generator card (demonstrates the uid_temp feature).
add_action(
	'homepage',
	static function () {
		?>
		<section class="dah-share">
			<div class="dah-share-copy">
				<h2>Share &amp; refer</h2>
				<p>Add <code>?uid_temp=YOURCODE</code> to any DeliveryAtHome link. Every product your friends add to their cart is credited to your code.</p>
			</div>
			<form id="uct-share-form" autocomplete="off">
				<input name="code" type="text" maxlength="40" placeholder="Your code, e.g. PRIYA2026" aria-label="Your share code">
				<button type="submit">Get my link</button>
				<div id="uct-share-result" hidden>
					<input id="uct-share-out" type="text" readonly aria-label="Your share link">
					<a id="uct-share-wa" class="dah-wa" target="_blank" rel="noopener">Share on WhatsApp</a>
					<span class="uct-copied" hidden>Copied ✓</span>
				</div>
			</form>
		</section>
		<?php
	},
	80
);

/* ------------------------------------------------------- Product listings */

add_filter( 'storefront_loop_columns', static function () { return 4; } );
add_filter( 'loop_shop_per_page', static function () { return 12; } );
add_filter( 'storefront_related_products_args', static function ( $args ) { return array_merge( $args, array( 'posts_per_page' => 4, 'columns' => 4 ) ); } );

/**
 * Real discount percentage for a product (0 when not on sale).
 *
 * @param WC_Product $product Product.
 * @return int
 */
function dah_discount( $product ) {
	if ( ! $product || ! $product->is_on_sale() ) {
		return 0;
	}
	if ( $product->is_type( 'variable' ) ) {
		$max = 0;
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->is_on_sale() && (float) $v->get_regular_price() > 0 ) {
				$max = max( $max, round( 100 - ( (float) $v->get_sale_price() / (float) $v->get_regular_price() * 100 ) ) );
			}
		}
		return (int) $max;
	}
	$r = (float) $product->get_regular_price();
	return $r > 0 ? (int) round( 100 - ( (float) $product->get_sale_price() / $r * 100 ) ) : 0;
}

// Green "% off" next to prices.
add_filter(
	'woocommerce_get_price_html',
	static function ( $html, $product ) {
		$off = dah_discount( $product );
		return $off > 0 ? $html . ' <span class="dah-off">' . esc_html( $off ) . '% off</span>' : $html;
	},
	10,
	2
);
add_filter( 'woocommerce_sale_flash', static function () { return ''; } );

/* ---------------------------------------------------------- Product page */

// "Buy now" button: adds to cart (tracked like any form add-to-cart) and goes straight to checkout.
add_action(
	'woocommerce_after_add_to_cart_button',
	static function () {
		global $product;
		if ( $product && $product->is_purchasable() && $product->is_in_stock() && ! $product->is_type( 'external' ) ) {
			$action = add_query_arg( 'dah_buy_now', '1', get_permalink( $product->get_id() ) );
			echo '<button type="submit" name="add-to-cart" value="' . esc_attr( $product->get_id() ) . '" formaction="' . esc_url( $action ) . '" class="button dah-buy-now">Buy now</button>';
		}
	}
);
add_filter(
	'woocommerce_add_to_cart_redirect',
	static function ( $url ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only switches the redirect target.
		return isset( $_REQUEST['dah_buy_now'] ) ? wc_get_checkout_url() : $url;
	}
);

add_action(
	'woocommerce_single_product_summary',
	static function () {
		echo '<ul class="dah-pdp-offers"><li><b>Free delivery</b> across India</li><li><b>Cash on Delivery</b> available</li><li><b>7-day</b> easy returns</li><li><b>Bank offer</b> use code WELCOME10 for 10% off</li></ul>';
	},
	35
);

/* ------------------------------------------------------------------ Footer */

add_filter( 'storefront_credit_link', '__return_false' );
add_filter( 'storefront_privacy_policy_link', '__return_false' );
add_filter(
	'storefront_copyright_text',
	static function () {
		return '&copy; ' . gmdate( 'Y' ) . ' DeliveryAtHome &middot; Demo store for share-link (uid_temp) add-to-cart tracking';
	}
);
add_action(
	'storefront_footer',
	static function () {
		$shop    = esc_url( wc_get_page_permalink( 'shop' ) );
		$account = esc_url( wc_get_page_permalink( 'myaccount' ) );
		$cart    = esc_url( wc_get_cart_url() );
		$cat     = static function ( $slug ) {
			$t = get_term_by( 'slug', $slug, 'product_cat' );
			return $t ? esc_url( get_term_link( $t ) ) : '#';
		};
		?>
		<div class="dah-footer">
			<div><h4>About</h4><ul><li>DeliveryAtHome</li><li>Everyday products delivered to your door</li><li>Thiruvananthapuram, Kerala</li></ul></div>
			<div><h4>Shop</h4><ul>
				<li><a href="<?php echo $shop; ?>">All products</a></li>
				<li><a href="<?php echo $cat( 'hoodies' ); ?>">Hoodies</a></li>
				<li><a href="<?php echo $cat( 'tshirts' ); ?>">T-shirts</a></li>
				<li><a href="<?php echo $cat( 'essentials' ); ?>">Essentials</a></li>
				<li><a href="<?php echo $cat( 'accessories' ); ?>">Accessories</a></li>
			</ul></div>
			<div><h4>Help</h4><ul>
				<li><a href="<?php echo $account; ?>">My account</a></li>
				<li><a href="<?php echo $cart; ?>">Cart</a></li>
				<li>Payments: Cash on Delivery</li>
				<li>Shipping: free across India</li>
			</ul></div>
			<div class="dah-footer-addr"><h4>Registered office</h4><p>DeliveryAtHome (demo)<br>MG Road, Thiruvananthapuram<br>Kerala 695001, India</p></div>
		</div>
		<?php
	},
	5
);

/* ------------------------------------------------------------ Assets + JS */

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'dah-font', 'https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,400;0,500;0,700;0,900;1,700;1,900&display=swap', array(), null );
		wp_register_script( 'dah-ui', false, array(), '3.0.0', true );
		wp_enqueue_script( 'dah-ui' );
		wp_add_inline_script(
			'dah-ui',
			"(function(){
				var c=document.querySelector('[data-dah-carousel]');
				if(c){
					var track=c.querySelector('.dah-track'),slides=c.querySelectorAll('.dah-slide'),dots=c.querySelectorAll('.dah-dots button'),i=0,timer;
					function go(n){i=(n+slides.length)%slides.length;track.style.transform='translateX(-'+(i*100)+'%)';
						slides.forEach(function(s,k){s.setAttribute('aria-hidden',k===i?'false':'true')});
						dots.forEach(function(d,k){d.classList.toggle('on',k===i)});}
					function auto(){clearInterval(timer);if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches){timer=setInterval(function(){go(i+1)},4500);}}
					c.querySelector('.dah-prev').addEventListener('click',function(){go(i-1);auto()});
					c.querySelector('.dah-next').addEventListener('click',function(){go(i+1);auto()});
					dots.forEach(function(d,k){d.addEventListener('click',function(){go(k);auto()})});
					c.addEventListener('mouseenter',function(){clearInterval(timer)});c.addEventListener('mouseleave',auto);
					auto();
				}
				var f=document.getElementById('uct-share-form');
				if(f){f.addEventListener('submit',function(ev){ev.preventDefault();
					var code=f.code.value.trim().replace(/[^A-Za-z0-9_\\-]/g,'').slice(0,40)||'MYCODE';
					var url=location.origin+'/?uid_temp='+encodeURIComponent(code);
					document.getElementById('uct-share-out').value=url;
					document.getElementById('uct-share-wa').href='https://wa.me/?text='+encodeURIComponent('Shop on DeliveryAtHome: '+url);
					document.getElementById('uct-share-result').hidden=false;
					if(navigator.clipboard){navigator.clipboard.writeText(url).then(function(){f.querySelector('.uct-copied').hidden=false}).catch(function(){});}
				});}
			})();"
		);
	}
);
