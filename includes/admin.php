<?php
/**
 * The admin side: view, download and regenerate an order's invoice.
 *
 * Registered against both the legacy `shop_order` post screen and the HPOS
 * `woocommerce_page_wc-orders` page, so the buttons are in the same place
 * whichever order storage the site is on. A screen that does not exist simply
 * never renders.
 *
 * @package QHTA_Woo_Invoice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens that show an order — legacy post table and HPOS page.
 *
 * WooCommerce is asked rather than told: under HPOS the screen is
 * `woocommerce_page_wc-orders` for a user who can see the WooCommerce menu and
 * `admin_page_wc-orders` for one who cannot, and hardcoding the first would
 * quietly drop the buttons for the second. The legacy `shop_order` post screen
 * is always included as well, so a site that switches storage does not need
 * this list revisited.
 *
 * @return string[]
 */
function qhta_woo_invoice_order_screens() {
	$screens = array( 'shop_order' );

	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$screens[] = wc_get_page_screen_id( 'shop-order' );
	} else {
		$screens[] = 'woocommerce_page_wc-orders';
	}

	return array_values( array_unique( array_filter( $screens ) ) );
}

/**
 * Style the two order-list buttons so their labels are readable.
 *
 * WooCommerce renders order actions as icon buttons: `.wc-action-button` is
 * 2em square with the text pushed off-screen, and the icon comes from a
 * `::after` glyph in its own font. A custom action with no glyph is therefore
 * an invisible button. Rather than guess at private codepoints in WooCommerce's
 * icon font — which would break the day it changes — the rules below put the
 * text back and let the button size to it.
 *
 * @param string $hook Current admin page.
 * @return void
 */
function qhta_woo_invoice_admin_assets( $hook ) {
	$screen = get_current_screen();

	if ( ! $screen || ! in_array( $screen->id, qhta_woo_invoice_order_screens(), true ) ) {
		return;
	}

	wp_enqueue_style(
		'qhta-woo-invoice-admin',
		QHTA_WOO_INVOICE_URL . 'assets/admin.css',
		array(),
		QHTA_WOO_INVOICE_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'qhta_woo_invoice_admin_assets' );

/**
 * The URL that regenerates an order's invoice.
 *
 * Nonced, unlike the download URL. This one changes state — it deletes a PDF
 * and rebuilds it — so it needs to be provably deliberate, and it is only ever
 * printed freshly on an admin screen where expiry is not a problem.
 *
 * @param WC_Order $order Order to rebuild.
 * @return string
 */
function qhta_woo_invoice_regenerate_url( $order ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'   => 'qhta_woo_invoice_regenerate',
				'order_id' => $order->get_id(),
			),
			admin_url( 'admin-post.php' )
		),
		'qhta_woo_invoice_regenerate_' . $order->get_id()
	);
}

/**
 * Add invoice buttons to the orders list.
 *
 * @param array    $actions Existing row actions.
 * @param WC_Order $order   Order for the row.
 * @return array
 */
function qhta_woo_invoice_admin_order_actions( $actions, $order ) {
	if ( ! current_user_can( 'manage_options' ) || ! is_a( $order, 'WC_Order' ) ) {
		return $actions;
	}

	if ( ! qhta_woo_invoice_is_invoiceable( $order ) ) {
		return $actions;
	}

	$actions['qhta_invoice'] = array(
		'url'    => qhta_woo_invoice_download_url( $order, 'inline' ),
		'name'   => __( 'Invoice', 'qhta-woo-invoice' ),
		'action' => 'qhta-invoice',
	);

	$actions['qhta_invoice_regenerate'] = array(
		'url'    => qhta_woo_invoice_regenerate_url( $order ),
		'name'   => __( 'Regenerate', 'qhta-woo-invoice' ),
		'action' => 'qhta-invoice-regenerate',
	);

	return $actions;
}
add_filter( 'woocommerce_admin_order_actions', 'qhta_woo_invoice_admin_order_actions', 10, 2 );

/**
 * Register the invoice box on the order edit screen.
 *
 * @return void
 */
function qhta_woo_invoice_add_meta_box() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	add_meta_box(
		'qhta-woo-invoice',
		__( 'Tax invoice', 'qhta-woo-invoice' ),
		'qhta_woo_invoice_render_meta_box',
		qhta_woo_invoice_order_screens(),
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'qhta_woo_invoice_add_meta_box' );

/**
 * Render the invoice box.
 *
 * The callback receives a WP_Post on the legacy screen and a WC_Order under
 * HPOS, hence the normalisation on the first line.
 *
 * @param WP_Post|WC_Order $post_or_order Whatever the screen handed us.
 * @return void
 */
function qhta_woo_invoice_render_meta_box( $post_or_order ) {
	$order = is_a( $post_or_order, 'WP_Post' ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;

	if ( ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	if ( ! qhta_woo_invoice_is_invoiceable( $order ) ) {
		echo '<p>' . esc_html__( 'No invoice yet — this order has not been paid.', 'qhta-woo-invoice' ) . '</p>';
		return;
	}

	$generated = (int) $order->get_meta( QHTA_WOO_INVOICE_META_TIME );

	if ( qhta_woo_invoice_exists( $order ) && $generated ) {
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: date and time the PDF was last written. */
					__( 'Generated %s.', 'qhta-woo-invoice' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated )
				)
			)
		);
	} else {
		echo '<p>' . esc_html__( 'Not generated yet — it will be built the first time it is opened.', 'qhta-woo-invoice' ) . '</p>';
	}

	echo '<p>';
	printf(
		'<a class="button" href="%s" target="_blank" rel="noopener">%s</a> ',
		esc_url( qhta_woo_invoice_download_url( $order, 'inline' ) ),
		esc_html__( 'View', 'qhta-woo-invoice' )
	);
	printf(
		'<a class="button" href="%s">%s</a> ',
		esc_url( qhta_woo_invoice_download_url( $order ) ),
		esc_html__( 'Download', 'qhta-woo-invoice' )
	);
	printf(
		'<a class="button" href="%s">%s</a>',
		esc_url( qhta_woo_invoice_regenerate_url( $order ) ),
		esc_html__( 'Regenerate', 'qhta-woo-invoice' )
	);
	echo '</p>';

	echo '<p class="description">' . esc_html__( 'Regenerate rebuilds the PDF from the current order and the current template. There is no confirmation step.', 'qhta-woo-invoice' ) . '</p>';
}

/**
 * Rebuild an order's invoice.
 *
 * Delete first, then generate. Not because generation would refuse to overwrite
 * — it will — but so that a render that fails half way leaves no file rather
 * than the old one, which would look like the regenerate had worked.
 *
 * @return void
 */
function qhta_woo_invoice_handle_regenerate() {
	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to regenerate invoices.', 'qhta-woo-invoice' ),
			esc_html__( 'Not allowed', 'qhta-woo-invoice' ),
			array( 'response' => 403 )
		);
	}

	check_admin_referer( 'qhta_woo_invoice_regenerate_' . $order_id );

	$order  = $order_id ? wc_get_order( $order_id ) : false;
	$result = 'missing';

	if ( $order ) {
		qhta_woo_invoice_delete( $order );
		$result = qhta_woo_invoice_generate( $order ) ? 'done' : 'failed';
	}

	$back = wp_get_referer();

	if ( ! $back ) {
		// No referer — land on the orders list, which is a different URL
		// depending on whether the site is on HPOS.
		$hpos = function_exists( 'wc_get_page_screen_id' ) && 'shop_order' !== wc_get_page_screen_id( 'shop-order' );
		$back = $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
	}

	// Strip any outcome from a previous round trip, or two notices stack up.
	$back = remove_query_arg( array( 'qhta_invoice_result', 'qhta_invoice_number' ), $back );

	wp_safe_redirect(
		add_query_arg(
			array(
				'qhta_invoice_result' => $result,
				'qhta_invoice_number' => $order ? rawurlencode( $order->get_order_number() ) : '',
			),
			$back
		)
	);
	exit;
}
add_action( 'admin_post_qhta_woo_invoice_regenerate', 'qhta_woo_invoice_handle_regenerate' );

/**
 * Report the outcome of a regenerate.
 *
 * @return void
 */
function qhta_woo_invoice_regenerate_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['qhta_invoice_result'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$result = sanitize_text_field( wp_unslash( $_GET['qhta_invoice_result'] ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$number = isset( $_GET['qhta_invoice_number'] ) ? sanitize_text_field( wp_unslash( $_GET['qhta_invoice_number'] ) ) : '';

	if ( 'done' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: order number. */
					__( 'Tax invoice for order %s has been regenerated.', 'qhta-woo-invoice' ),
					$number
				)
			)
		);
		return;
	}

	if ( 'failed' === $result ) {
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: order number. */
					__( 'Could not regenerate the tax invoice for order %s. See WooCommerce → Status → Logs (source: qhta-woo-invoice) for the reason.', 'qhta-woo-invoice' ),
					$number
				)
			)
		);
		return;
	}

	printf(
		'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
		esc_html__( 'Could not regenerate that tax invoice — the order could not be found.', 'qhta-woo-invoice' )
	);
}
add_action( 'admin_notices', 'qhta_woo_invoice_regenerate_notice' );
