<?php
/**
 * Getting the invoice to the customer: the order email, and My Account.
 *
 * @package QHTA_Woo_Invoice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which WooCommerce emails carry the invoice.
 *
 * The completed-order email only. The customer gets one document at the point
 * the purchase is done; attaching it to processing, on-hold or invoice-request
 * mails would send the same PDF several times, and attaching it to an admin
 * notification would mail a customer's address and purchase history to the shop
 * inbox for no reason.
 *
 * @return string[] WooCommerce email IDs.
 */
function qhta_woo_invoice_email_ids() {
	return (array) apply_filters( 'qhta_woo_invoice_email_ids', array( 'customer_completed_order' ) );
}

/**
 * Attach the invoice PDF to the customer's completed-order email.
 *
 * Generation already ran on `woocommerce_order_status_completed` at priority 5,
 * before WooCommerce queued this email at 10 — so in the ordinary case the file
 * is on disk and this is a lookup. qhta_woo_invoice_ensure() is used anyway
 * because this filter also fires when an admin resends the email by hand from
 * the order screen, sometimes for an order that predates the plugin.
 *
 * A failure here returns the attachments untouched. The customer gets their
 * order email without the invoice, which is a great deal better than
 * WooCommerce failing to send it at all.
 *
 * @param string[] $attachments Attachment paths.
 * @param string   $email_id    WooCommerce email ID.
 * @param mixed    $object      The email's subject — a WC_Order for order mails.
 * @return string[]
 */
function qhta_woo_invoice_email_attachments( $attachments, $email_id, $object = null ) {
	if ( ! in_array( $email_id, qhta_woo_invoice_email_ids(), true ) ) {
		return $attachments;
	}

	if ( ! is_a( $object, 'WC_Order' ) ) {
		return $attachments;
	}

	$path = qhta_woo_invoice_ensure( $object );

	if ( $path ) {
		$attachments[] = $path;
	}

	return $attachments;
}
add_filter( 'woocommerce_email_attachments', 'qhta_woo_invoice_email_attachments', 10, 3 );

/**
 * Should this order offer an invoice to the customer?
 *
 * Paid is the test, not completed. An order can be paid and sitting in
 * processing — a manual review, a payment method that settles late — and its
 * invoice is a perfectly valid document at that point. Completed is also
 * accepted for orders marked complete by hand without a recorded payment date.
 *
 * @param WC_Order $order Order to test.
 * @return bool
 */
function qhta_woo_invoice_is_invoiceable( $order ) {
	$invoiceable = $order->is_paid() || $order->has_status( 'completed' );

	/**
	 * Filters whether an order gets an invoice link.
	 *
	 * @param bool     $invoiceable Whether to offer one.
	 * @param WC_Order $order       Order in question.
	 */
	return (bool) apply_filters( 'qhta_woo_invoice_is_invoiceable', $invoiceable, $order );
}

/**
 * Add a "Download invoice" action to My Account -> Orders.
 *
 * Sits alongside WooCommerce's own View / Pay / Cancel actions on each row, so
 * a customer finds it where they already look for anything to do with an order.
 * Unpaid orders get nothing — there is no tax invoice for a purchase that has
 * not happened.
 *
 * @param array    $actions Row actions, keyed by slug.
 * @param WC_Order $order   Order for the row.
 * @return array
 */
function qhta_woo_invoice_my_orders_actions( $actions, $order ) {
	if ( ! is_a( $order, 'WC_Order' ) || ! qhta_woo_invoice_is_invoiceable( $order ) ) {
		return $actions;
	}

	$actions['qhta-invoice'] = array(
		'url'  => qhta_woo_invoice_download_url( $order ),
		'name' => __( 'Download invoice', 'qhta-woo-invoice' ),
		// Supplied rather than left to WooCommerce, which otherwise builds one
		// by appending the order number to the button text — "Download invoice
		// order number 4821".
		'aria-label' => sprintf(
			/* translators: %s: order number. */
			__( 'Download the tax invoice for order %s', 'qhta-woo-invoice' ),
			$order->get_order_number()
		),
	);

	return $actions;
}
add_filter( 'woocommerce_my_account_my_orders_actions', 'qhta_woo_invoice_my_orders_actions', 10, 2 );
