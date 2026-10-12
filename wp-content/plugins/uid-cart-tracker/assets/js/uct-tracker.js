/**
 * UID Cart Referral Tracker – frontend.
 *
 * A. Capture & persist: reads ?uid_temp=STRING_ID and stores it in localStorage,
 *    so it survives navigation across every page of the site.
 * B. On add-to-cart, reads uid_temp from localStorage and sends it (with a nonce) to WooCommerce:
 *    - single product form submit        -> hidden inputs injected into the form
 *    - shop/archive AJAX buttons          -> added to the request via the 'adding_to_cart' event
 *    - any other jQuery add-to-cart AJAX  -> $.ajaxPrefilter safety net
 *    - non-AJAX "?add-to-cart=ID" links   -> query args appended to the link
 *    - block / Store API add-to-cart      -> first-party cookie mirrored from localStorage
 */
(function (window, document) {
	'use strict';

	var cfg         = window.uctConfig || {};
	var PARAM       = cfg.param || 'uid_temp';
	var KEY         = cfg.storageKey || 'uid_temp';
	var NONCE_FIELD = cfg.nonceField || 'uct_nonce';
	var NONCE       = cfg.nonce || '';
	var COOKIE      = cfg.cookieName || 'uct_uid_temp';
	var COOKIE_DAYS = parseInt(cfg.cookieDays, 10) || 30;
	var MAX_LEN     = parseInt(cfg.maxLength, 10) || 100;

	/* ---------------------------------------------------------------- helpers */

	function storageAvailable() {
		try {
			var t = '__uct_test__';
			window.localStorage.setItem(t, t);
			window.localStorage.removeItem(t);
			return true;
		} catch (e) {
			return false;
		}
	}

	var HAS_STORAGE = storageAvailable();

	// Client-side clean-up; the server sanitizes again with sanitize_text_field().
	function clean(value) {
		if (typeof value !== 'string') {
			return '';
		}
		value = value.replace(/[\u0000-\u001F\u007F<>"'`\\]/g, '').trim();
		return value.substring(0, MAX_LEN);
	}

	function writeCookie(value) {
		var secure = window.location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = COOKIE + '=' + encodeURIComponent(value) +
			'; path=/; max-age=' + (COOKIE_DAYS * 86400) + '; SameSite=Lax' + secure;
	}

	function readCookie() {
		var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
		try {
			return match ? clean(decodeURIComponent(match[1])) : '';
		} catch (e) {
			return '';
		}
	}

	function getUid() {
		if (HAS_STORAGE) {
			try {
				return clean(window.localStorage.getItem(KEY) || '');
			} catch (e) { /* fall through */ }
		}
		return readCookie(); // Private mode / storage blocked.
	}

	function setUid(value) {
		if (HAS_STORAGE) {
			try {
				window.localStorage.setItem(KEY, value);
				window.localStorage.setItem(KEY + '_captured_at', new Date().toISOString());
			} catch (e) { /* ignore quota errors */ }
		}
		writeCookie(value);
	}

	/* ------------------------------------------------ A. capture & persist */

	function captureFromUrl() {
		var value = '';
		try {
			value = new URLSearchParams(window.location.search).get(PARAM) || '';
		} catch (e) {
			var m = window.location.search.match(new RegExp('[?&]' + PARAM + '=([^&#]*)'));
			value = m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
		}
		value = clean(value);
		if (value) {
			setUid(value); // Latest shared link wins.
		}
	}

	captureFromUrl();

	// Keep the cookie mirror in sync with localStorage on every page view.
	(function syncCookie() {
		var uid = getUid();
		if (uid) {
			writeCookie(uid);
		}
	}());

	/* ------------------------------- B1. single-product (normal) form submit */

	function setHiddenField(form, name, value) {
		var input = form.querySelector('input[type="hidden"][name="' + name + '"]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			form.appendChild(input);
		}
		input.value = value;
	}

	function isAddToCartForm(form) {
		return form.classList.contains('cart') ||
			!!form.querySelector('[name="add-to-cart"]');
	}

	function injectIntoForm(form) {
		var uid = getUid();
		if (!uid || !isAddToCartForm(form)) {
			return;
		}
		setHiddenField(form, PARAM, uid);
		setHiddenField(form, NONCE_FIELD, NONCE);
	}

	// Capture phase: runs before themes that serialize the form for their own AJAX submit.
	document.addEventListener('submit', function (e) {
		if (e.target && e.target.tagName === 'FORM') {
			injectIntoForm(e.target);
		}
	}, true);

	// Also prepare forms up front (covers scripts that submit forms programmatically).
	document.addEventListener('DOMContentLoaded', function () {
		var forms = document.querySelectorAll('form.cart');
		for (var i = 0; i < forms.length; i++) {
			injectIntoForm(forms[i]);
		}
	});

	/* --------------------------------- B2. non-AJAX "?add-to-cart=ID" links */

	document.addEventListener('click', function (e) {
		var link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
		var uid  = getUid();
		if (!link || !uid) {
			return;
		}
		var url;
		try {
			url = new URL(link.href, window.location.href);
		} catch (err) {
			return;
		}
		if (url.origin !== window.location.origin || !url.searchParams.has('add-to-cart')) {
			return;
		}
		url.searchParams.set(PARAM, uid);
		url.searchParams.set(NONCE_FIELD, NONCE);
		link.href = url.toString();
	}, true);

	/* ------------------------------------------ B3. WooCommerce AJAX add-to-cart */

	var $ = window.jQuery;
	if ($) {
		// Shop/archive buttons: WooCommerce passes the request data object by reference.
		$(document.body).on('adding_to_cart', function (event, $button, data) {
			var uid = getUid();
			if (uid && data && typeof data === 'object') {
				data[PARAM]       = uid;
				data[NONCE_FIELD] = NONCE;
			}
		});

		// Safety net for any other jQuery-based add-to-cart request (themes, quick-view, etc.).
		$.ajaxPrefilter(function (options) {
			var uid  = getUid();
			var url  = String(options.url || '');
			var data = options.data;

			if (!uid) {
				return;
			}

			var isAddToCart = url.indexOf('add_to_cart') !== -1 ||
				(typeof data === 'string' && (data.indexOf('add-to-cart=') !== -1 || data.indexOf('action=woocommerce_add_to_cart') !== -1)) ||
				(window.FormData && data instanceof window.FormData && data.has('add-to-cart'));

			if (!isAddToCart) {
				return;
			}

			if (typeof data === 'string') {
				if (data.indexOf(PARAM + '=') === -1) {
					options.data = data + (data ? '&' : '') +
						encodeURIComponent(PARAM) + '=' + encodeURIComponent(uid) + '&' +
						encodeURIComponent(NONCE_FIELD) + '=' + encodeURIComponent(NONCE);
				}
			} else if (window.FormData && data instanceof window.FormData) {
				if (!data.has(PARAM)) {
					data.append(PARAM, uid);
					data.append(NONCE_FIELD, NONCE);
				}
			} else if (!data) {
				options.data = encodeURIComponent(PARAM) + '=' + encodeURIComponent(uid) + '&' +
					encodeURIComponent(NONCE_FIELD) + '=' + encodeURIComponent(NONCE);
			}
		});
	}

	// Small public API, handy for debugging in the browser console.
	window.uctTracker = { get: getUid, set: function (v) { v = clean(String(v || '')); if (v) { setUid(v); } } };

}(window, document));
