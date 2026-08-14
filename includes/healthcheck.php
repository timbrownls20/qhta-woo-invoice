<?php
/**
 * Healthcheck canaries for qhta-woo-invoice.
 *
 * Registered on qhta-healthcheck's `qhta_healthcheck_checks` filter.
 *
 * This plugin fails silently by design — WooCommerce absent, template missing,
 * PDF library missing each no-op with a logged error rather than fatalling,
 * because it runs on the customer email path and a fatal there loses the order
 * confirmation, not just the invoice. That design is why it needs watching from
 * outside, and why these canaries belong in the same repository as the code
 * doing the failing-soft.
 *
 * @package QHTA_Woo_Invoice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register this plugin's canaries.
 *
 * @param array $checks Slug => list of check specs.
 * @return array
 */
function qhta_woo_invoice_healthcheck_checks( $checks ) {
	$checks['qhta-woo-invoice'] = array_merge(
		isset( $checks['qhta-woo-invoice'] ) ? (array) $checks['qhta-woo-invoice'] : array(),
		array(
			array(
				'id'       => 'woo-order-api',
				'label'    => __( 'WooCommerce order API', 'qhta-woo-invoice' ),
				'why'      => __( 'The plugin no-ops entirely without WC_Order. Silent by design — a fatal on the order-email path would lose the order confirmation, not just the invoice — which is exactly why it needs watching from outside.', 'qhta-woo-invoice' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_classes( 'WC_Order' );
				},
			),
			array(
				'id'       => 'pdf-libraries',
				'label'    => __( 'Dompdf and Mustache loadable', 'qhta-woo-invoice' ),
				'why'      => __( 'The bundled libraries render the PDF. A deploy that misses lib/ leaves invoicing broken in a way only the error log records — the customer email still sends, just without an attachment.', 'qhta-woo-invoice' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_classes( array( 'Mustache\\Engine', 'Dompdf\\Dompdf' ) );
				},
			),
			array(
				'id'       => 'invoice-template',
				'label'    => __( 'Invoice template readable', 'qhta-woo-invoice' ),
				'why'      => __( 'The template is the invoice. The uploads override is checked first so edits survive plugin updates — this reports which of the two is actually in use, so a surprise "the invoice went back to the old design" has an answer.', 'qhta-woo-invoice' ),
				'severity' => 'critical',
				'test'     => function () {
					// The feature files only load when WooCommerce is present, so
					// this helper being undefined means WooCommerce is missing —
					// which the order-API canary above is already reporting in
					// red. Skip rather than throw a second, less informative
					// alarm about the same root cause.
					if ( ! function_exists( 'qhta_woo_invoice_template' ) ) {
						return qhta_healthcheck_skip( __( 'WooCommerce is not active, so the invoice files are not loaded.', 'qhta-woo-invoice' ) );
					}

					// Resolved through the plugin's own lookup, so the filter and
					// the override precedence cannot drift from what the canary
					// believes.
					$path = qhta_woo_invoice_template();

					if ( '' === (string) $path ) {
						return qhta_healthcheck_fail( __( 'No readable invoice template — neither the uploads override nor the plugin default resolved.', 'qhta-woo-invoice' ) );
					}

					return qhta_healthcheck_assert_file( $path, __( 'Invoice template', 'qhta-woo-invoice' ) );
				},
			),
			array(
				'id'       => 'invoice-dir-protected',
				'label'    => __( 'Invoice directory exists and is protected', 'qhta-woo-invoice' ),
				'why'      => __( 'Invoices are customer tax documents written into uploads. The .htaccess deny and the index.php are the first line; the filename hash is the second. A directory recreated by a restore, or by the plugin on a host that ignores .htaccess, can lose the first without anybody noticing.', 'qhta-woo-invoice' ),
				'severity' => 'critical',
				'test'     => function () {
					if ( ! function_exists( 'qhta_woo_invoice_dir' ) ) {
						return qhta_healthcheck_skip( __( 'WooCommerce is not active, so the invoice files are not loaded.', 'qhta-woo-invoice' ) );
					}

					$dir = qhta_woo_invoice_dir();

					if ( ! is_dir( $dir ) ) {
						return qhta_healthcheck_fail(
							sprintf(
								/* translators: %s: path. */
								__( 'Invoice directory does not exist: %s', 'qhta-woo-invoice' ),
								qhta_healthcheck_relative_path( $dir )
							)
						);
					}

					$missing = array();

					foreach ( array( '.htaccess', 'index.php' ) as $guard ) {
						if ( ! file_exists( $dir . $guard ) ) {
							$missing[] = $guard;
						}
					}

					if ( $missing ) {
						return qhta_healthcheck_fail(
							sprintf(
								/* translators: %s: comma-separated file names. */
								__( 'Directory is not protected — missing %s', 'qhta-woo-invoice' ),
								implode( ', ', $missing )
							)
						);
					}

					if ( ! wp_is_writable( $dir ) ) {
						return qhta_healthcheck_fail( __( 'Directory is not writable, so no new invoice can be generated.', 'qhta-woo-invoice' ) );
					}

					return qhta_healthcheck_pass( qhta_healthcheck_relative_path( $dir ) );
				},
			),
			array(
				'id'       => 'email-attachment-hook',
				'label'    => __( 'woocommerce_email_attachments', 'qhta-woo-invoice' ),
				'why'      => __( 'The only path by which a customer ever receives their invoice unprompted. Detached, the completed-order email still sends and nobody complains until an accountant asks for a tax invoice.', 'qhta-woo-invoice' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_hooked( 'woocommerce_email_attachments', 'qhta_woo_invoice_email_attachments' );
				},
			),
			array(
				'id'       => 'generate-on-completed',
				'label'    => __( 'Generate on order completion', 'qhta-woo-invoice' ),
				'why'      => __( 'woocommerce_order_status_completed at priority 5 is what builds the PDF before the email goes out. If it stops firing the attachment hook finds no file and quietly attaches nothing.', 'qhta-woo-invoice' ),
				'severity' => 'critical',
				'test'     => function () {
					return qhta_healthcheck_assert_hooked( 'woocommerce_order_status_completed', 'qhta_woo_invoice_on_completed' );
				},
			),
			array(
				'id'       => 'download-handler',
				'label'    => __( 'Invoice download handler', 'qhta-woo-invoice' ),
				'why'      => __( 'admin-post handlers, logged-in and out, are how My Account and the admin order screen hand a PDF over. Missing, both buttons 400 with no explanation.', 'qhta-woo-invoice' ),
				'severity' => 'critical',
				'test'     => function () {
					$in = qhta_healthcheck_assert_hooked( 'admin_post_qhta_woo_invoice_download', 'qhta_woo_invoice_handle_download' );

					if ( ! $in['ok'] ) {
						return $in;
					}

					return qhta_healthcheck_assert_hooked( 'admin_post_nopriv_qhta_woo_invoice_download', 'qhta_woo_invoice_handle_download' );
				},
			),
			array(
				'id'       => 'hpos-declared',
				'label'    => __( 'HPOS compatibility declared', 'qhta-woo-invoice' ),
				'why'      => __( 'FeaturesUtil is how the plugin tells WooCommerce it is HPOS-safe. If the class moves, WooCommerce marks the plugin incompatible and can refuse to enable HPOS — or disable the plugin\'s features on an HPOS store.', 'qhta-woo-invoice' ),
				'severity' => 'warning',
				'test'     => function () {
					return qhta_healthcheck_assert_classes( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' );
				},
			),
			array(
				'id'       => 'invoices-being-written',
				'label'    => __( 'Recent orders carry an invoice', 'qhta-woo-invoice' ),
				'why'      => __( 'The end-to-end canary: the invoice meta key on recent completed orders proves invoices are actually being produced, not merely that every part looks present. Read through wc_get_orders() so it is right under HPOS too.', 'qhta-woo-invoice' ),
				'severity' => 'warning',
				'test'     => function () {
					if ( ! defined( 'QHTA_WOO_INVOICE_META_FILE' ) ) {
						return qhta_healthcheck_skip( __( 'WooCommerce is not active, so the invoice files are not loaded.', 'qhta-woo-invoice' ) );
					}

					return qhta_healthcheck_assert_order_meta_in_use( QHTA_WOO_INVOICE_META_FILE, 'completed' );
				},
			),
			array(
				'id'       => 'order-actions',
				'label'    => __( 'Invoice buttons on order lists', 'qhta-woo-invoice' ),
				'why'      => __( 'The customer-facing and admin-facing download buttons. Losing them does not stop invoicing, but it removes the only self-service way to get a copy.', 'qhta-woo-invoice' ),
				'severity' => 'warning',
				'test'     => function () {
					$mine = qhta_healthcheck_assert_hooked( 'woocommerce_my_account_my_orders_actions', 'qhta_woo_invoice_my_orders_actions' );

					if ( ! $mine['ok'] ) {
						return $mine;
					}

					return qhta_healthcheck_assert_hooked( 'woocommerce_admin_order_actions', 'qhta_woo_invoice_admin_order_actions' );
				},
			),
		)
	);

	return $checks;
}
add_filter( 'qhta_healthcheck_checks', 'qhta_woo_invoice_healthcheck_checks' );
