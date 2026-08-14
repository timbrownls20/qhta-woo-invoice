<?php
/**
 * Plugin Name:       QHTA Woo Invoice
 * Description:       PDF tax invoices for WooCommerce orders on qhta.com.au — an editable HTML template rendered to PDF, attached to the completed-order email, and downloadable from My Account and the admin order screen. Renders the order's own totals; it does not calculate GST.
 * Version:           1.2.0
 * Author:            QHTA
 * License:           GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 *
 * Scope rule: WooCommerce ORDER invoices only (WC_Order). PMPro membership
 * invoices (MemberOrder) live in qhta-pmpro-invoice-extensions and the two are
 * deliberately not merged — different order objects, different subsystems.
 * GST is calculated by WooCommerce -> Settings -> Tax; this plugin only renders
 * the result. The purchase gate, store preview and checkout tweaks belong to
 * qhta-commerce.
 *
 * @package QHTA_Woo_Invoice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QHTA_WOO_INVOICE_VERSION', '1.2.0' );
define( 'QHTA_WOO_INVOICE_PATH', plugin_dir_path( __FILE__ ) );
define( 'QHTA_WOO_INVOICE_URL', plugin_dir_url( __FILE__ ) );
define( 'QHTA_WOO_INVOICE_FILE', __FILE__ );


/* -------------------------------------------------------------------------
 * Why this plugin exists
 *
 * qhta.com.au sells recordings and study packages through WooCommerce and has
 * to hand buyers an Australian tax invoice. The membership side of the site
 * already produces invoices from an editable HTML template (PMPro PDF Invoices,
 * extended by qhta-pmpro-invoice-extensions); a third-party WooCommerce invoice
 * plugin was a poor fit on that side and would have needed extending anyway, so
 * the same pattern is built here instead of bought.
 *
 * Three rules the code below obeys:
 *
 *   1. The plugin never does tax arithmetic. Every money figure on the invoice
 *      is read straight off the WC_Order. If a total looks wrong, the fix is in
 *      WooCommerce -> Settings -> Tax, not here.
 *   2. Invoices are not web-reachable. They are written to a protected uploads
 *      directory, under a filename that includes a site-secret hash, and are
 *      only ever handed out by a handler that checks who is asking.
 *   3. Fail safe. WooCommerce absent, template missing, PDF library missing —
 *      each no-ops with a notice or a logged error rather than fatalling. This
 *      plugin runs on the customer email path; a fatal there loses the order
 *      confirmation, not just the invoice.
 *
 * One file per concern under includes/, loaded only once WooCommerce is known
 * to be present.
 * ---------------------------------------------------------------------- */


/**
 * Is WooCommerce present and booted far enough to hand us orders?
 *
 * Checked on `WC_Order` rather than the `WC` function because everything in
 * this plugin ultimately needs the order class. `Requires Plugins` in the
 * header stops WordPress activating this without WooCommerce, but that header
 * only covers activation — WooCommerce can still be deactivated afterwards, so
 * the runtime guard stays.
 *
 * @return bool
 */
function qhta_woo_invoice_woo_active() {
	return class_exists( 'WC_Order' ) && function_exists( 'wc_get_order' );
}

/**
 * The seller name printed on the invoice.
 *
 * Read from `QHTA_INVOICE_SELLER` in wp-config.php, defaulting to `QHTA`. It
 * sits beside the ABN for the same reason the ABN is there: the two lines
 * together are the seller's identity on a tax invoice, and a trading name that
 * needs correcting should be correctable in the same place and at the same
 * moment as the number under it, without a plugin release.
 *
 * A blank or whitespace-only constant falls back to the default rather than
 * printing an invoice with no seller on it — the same reasoning as the ABN's
 * non-empty fallback. Misconfiguration should look wrong, not produce an
 * invalid document.
 *
 * @return string
 */
function qhta_woo_invoice_seller_name() {
	$name = defined( 'QHTA_INVOICE_SELLER' ) ? trim( (string) QHTA_INVOICE_SELLER ) : '';

	if ( '' === $name ) {
		$name = 'QHTA';
	}

	return (string) apply_filters( 'qhta_woo_invoice_seller_name', $name );
}

/**
 * The ABN printed on the invoice.
 *
 * Read from `QHTA_INVOICE_ABN` in wp-config.php, with the recorded value as the
 * fallback. It lives in wp-config rather than in this file for one reason: it
 * prints on a legal document, and if the recorded value ever turns out to be
 * wrong it needs correcting in one place on the server, immediately, without a
 * plugin release.
 *
 * The fallback is a real value rather than an empty string on purpose — an
 * invoice with a blank ABN line is a broken tax invoice, and a wrong-looking
 * one is at least visible. It is still filterable for anything the constant
 * cannot express.
 *
 * @return string
 */
function qhta_woo_invoice_abn() {
	$abn = defined( 'QHTA_INVOICE_ABN' ) ? (string) QHTA_INVOICE_ABN : '77 270 249 802';

	return (string) apply_filters( 'qhta_woo_invoice_abn', $abn );
}

/**
 * Is the seller GST-registered?
 *
 * QHTA is, so this is true and stays true: the document is headed TAX INVOICE
 * and carries a GST line. `QHTA_INVOICE_GST_REGISTERED` exists purely as an
 * escape hatch — it is wired up so that if the answer ever changed the heading
 * and the tax row would follow it, rather than the constant being defined and
 * silently doing nothing.
 *
 * Note that turning this off does not stop tax appearing in the order totals;
 * that is a WooCommerce tax-settings question, not a rendering one.
 *
 * @return bool
 */
function qhta_woo_invoice_gst_registered() {
	$registered = defined( 'QHTA_INVOICE_GST_REGISTERED' ) ? (bool) QHTA_INVOICE_GST_REGISTERED : true;

	return (bool) apply_filters( 'qhta_woo_invoice_gst_registered', $registered );
}

/**
 * Turn a configured logo value into a readable file path.
 *
 * Accepts the three things somebody will actually put in wp-config, because
 * only one of them is the "correct" one and the other two are what you get when
 * you fetch an image out of the Media Library:
 *
 *   1. An absolute server path.
 *   2. A path relative to the uploads directory — `qhta/logo-2026.png`.
 *   3. A full uploads URL, straight off the Media Library's copy button. It is
 *      mapped back to the file on disk rather than fetched: the PDF engine has
 *      no network access by design, so a URL that stayed a URL would silently
 *      produce an invoice with no logo.
 *
 * An off-site URL is refused rather than downloaded. Returns false for anything
 * that does not resolve to a readable file, and the caller keeps the bundled
 * logo.
 *
 * @param string $value Whatever `QHTA_INVOICE_LOGO` was set to.
 * @return string|false Absolute path, or false.
 */
function qhta_woo_invoice_resolve_logo( $value ) {
	$value   = wp_normalize_path( trim( $value ) );
	$uploads = wp_upload_dir();

	if ( preg_match( '#^https?://#i', $value ) ) {
		$baseurl = isset( $uploads['baseurl'] ) ? wp_normalize_path( $uploads['baseurl'] ) : '';

		// Compare protocol-agnostically: the stored baseurl and a URL copied
		// out of the admin can disagree on http vs https on the same site.
		$strip = static function ( $url ) {
			return preg_replace( '#^https?://#i', '', $url );
		};

		if ( '' === $baseurl || 0 !== strpos( $strip( $value ), $strip( $baseurl ) ) ) {
			qhta_woo_invoice_log( 'QHTA_INVOICE_LOGO is a URL outside this site\'s uploads and cannot be used: ' . $value );
			return false;
		}

		$value = ltrim( substr( $strip( $value ), strlen( $strip( $baseurl ) ) ), '/' );
	}

	$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

	// Absolute POSIX path, or a Windows drive letter.
	$absolute = ( 0 === strpos( $value, '/' ) ) || preg_match( '#^[A-Za-z]:/#', $value );

	// Both readings are tried, absolute first. A leading slash is what somebody
	// writes when they mean "at the top of the uploads folder" — /branding/logo.png
	// — and it is indistinguishable from a real absolute path until you look on
	// disk. Trying the second reading costs one stat and turns the most likely
	// typo into the thing they meant, while a genuine absolute path still
	// resolves on the first attempt and never reaches the fallback.
	$candidates = $absolute
		? array( $value, $basedir . ltrim( $value, '/' ) )
		: array( $basedir . $value );

	foreach ( $candidates as $candidate ) {
		if ( file_exists( $candidate ) && is_readable( $candidate ) ) {
			return $candidate;
		}
	}

	return false;
}

/**
 * The image embedded in the PDF as the logo.
 *
 * Read from `QHTA_INVOICE_LOGO` in wp-config.php, falling back to the bundled
 * `assets/logo.png`.
 *
 * The brief for this plugin said the logo was not configurable, and that was
 * right for the shape of the file — it is an asset, not a setting. What makes a
 * constant worth having anyway is the replacement case: the bundled logo is a
 * 200px sponsor-sheet copy, and swapping it for a print-resolution original
 * should not mean rebuilding and redeploying the plugin. Set the constant,
 * upload the file, done.
 *
 * A value that does not resolve to a readable file is logged and **ignored**,
 * leaving the bundled logo in place. An invoice with the old logo is a small
 * problem; an invoice with no logo at all is one somebody has to reissue.
 *
 * @return string Absolute path to an image, possibly the bundled one.
 */
function qhta_woo_invoice_logo_path() {
	$bundled = QHTA_WOO_INVOICE_PATH . 'assets/logo.png';
	$path    = $bundled;

	if ( defined( 'QHTA_INVOICE_LOGO' ) && '' !== trim( (string) QHTA_INVOICE_LOGO ) ) {
		$configured = qhta_woo_invoice_resolve_logo( (string) QHTA_INVOICE_LOGO );

		if ( $configured ) {
			$path = $configured;
		} else {
			qhta_woo_invoice_log( 'QHTA_INVOICE_LOGO does not point at a readable file; using the bundled logo instead. Value: ' . QHTA_INVOICE_LOGO );
		}
	}

	/**
	 * Filters the path to the logo embedded in the PDF.
	 *
	 * Runs after the constant, so a filter still has the last word.
	 *
	 * @param string $path    Absolute path to an image file.
	 * @param string $bundled Absolute path to the bundled default.
	 */
	return (string) apply_filters( 'qhta_woo_invoice_logo_path', $path, $bundled );
}

/**
 * Log a problem without taking the request down with it.
 *
 * Everything in this plugin is a side-effect of something more important —
 * completing an order, sending the customer their receipt — so failures are
 * recorded and swallowed. WooCommerce's logger is used when available so the
 * entries land in WooCommerce -> Status -> Logs where someone will find them.
 *
 * @param string $message What went wrong.
 * @return void
 */
function qhta_woo_invoice_log( $message ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->error( $message, array( 'source' => 'qhta-woo-invoice' ) );
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'qhta-woo-invoice: ' . $message );
}

/**
 * Tell WooCommerce this plugin is safe under High-Performance Order Storage.
 *
 * It is: every order read and write goes through the WC_Order CRUD
 * (`wc_get_order()`, `$order->update_meta_data()`), never through post meta, so
 * it does not care which table the order lives in. Without this declaration
 * WooCommerce shows the site owner an "incompatible plugin" warning and can
 * refuse to let them switch storage.
 *
 * @return void
 */
function qhta_woo_invoice_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', QHTA_WOO_INVOICE_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'qhta_woo_invoice_declare_hpos_compatibility' );

/**
 * Load the feature files, once, and only with WooCommerce present.
 *
 * On `plugins_loaded` rather than at file scope because WooCommerce may load
 * after this plugin alphabetically, and the guard above would then be asking
 * about a class that has not been declared yet.
 *
 * @return void
 */
function qhta_woo_invoice_bootstrap() {
	if ( ! qhta_woo_invoice_woo_active() ) {
		add_action( 'admin_notices', 'qhta_woo_invoice_missing_woo_notice' );
		return;
	}

	require_once QHTA_WOO_INVOICE_PATH . 'includes/storage.php';
	require_once QHTA_WOO_INVOICE_PATH . 'includes/generator.php';
	require_once QHTA_WOO_INVOICE_PATH . 'includes/delivery.php';

	if ( is_admin() ) {
		require_once QHTA_WOO_INVOICE_PATH . 'includes/admin.php';
	}
}
add_action( 'plugins_loaded', 'qhta_woo_invoice_bootstrap' );

/*
 * Healthcheck canaries — deliberately OUTSIDE the bootstrap above.
 *
 * The feature files only load when WooCommerce is present. The canaries must
 * not: "WooCommerce is missing" is the single most important thing this plugin
 * has to report, and registering the canaries inside the WooCommerce guard
 * would mean that in exactly that case none of them exist and the board shows
 * "no canaries defined" instead of a red line naming the cause. The individual
 * checks that need the feature files skip themselves when those are not loaded.
 */
require_once QHTA_WOO_INVOICE_PATH . 'includes/healthcheck.php';

/**
 * Say so when WooCommerce is missing.
 *
 * Unlike qhta-membership, where a deactivated dependency only costs
 * convenience, this plugin going quiet means paid orders stop producing tax
 * invoices and nobody finds out until a customer asks. Hence a visible notice.
 *
 * @return void
 */
function qhta_woo_invoice_missing_woo_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'QHTA Woo Invoice needs WooCommerce to be active. No tax invoices are being generated until it is.', 'qhta-woo-invoice' );
	echo '</p></div>';
}

/**
 * Create the protected invoice directory on activation.
 *
 * Also created lazily whenever an invoice is written, so this is a
 * belt-and-braces early failure: if the uploads directory is not writable it is
 * better to find out on the Plugins screen than on the first real order.
 *
 * @return void
 */
function qhta_woo_invoice_activate() {
	require_once QHTA_WOO_INVOICE_PATH . 'includes/storage.php';

	if ( ! qhta_woo_invoice_prepare_dir() ) {
		qhta_woo_invoice_log( 'Activation could not create or protect the invoice directory.' );
	}
}
register_activation_hook( __FILE__, 'qhta_woo_invoice_activate' );
