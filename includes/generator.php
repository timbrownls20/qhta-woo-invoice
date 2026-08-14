<?php
/**
 * WC_Order -> data -> Mustache -> HTML -> Dompdf -> PDF.
 *
 * The one rule this file exists to keep: **no arithmetic on money**. Every
 * figure printed is read off the order exactly as WooCommerce computed it. The
 * plugin's job is typesetting, not accounting — GST is configured in
 * WooCommerce -> Settings -> Tax and the order already knows the answer.
 *
 * @package QHTA_Woo_Invoice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the bundled Mustache and Dompdf.
 *
 * Deliberately lazy — called at generation time, not at plugin load. Generating
 * an invoice happens a handful of times a day; loading a PDF engine's
 * autoloader on every page view of the site to support that would be a poor
 * trade.
 *
 * The libraries are bundled rather than borrowed. qhta-pmpro-invoice-extensions
 * leans on another plugin's `pmpropdf_*` internals and the README there lists
 * what that costs; this plugin does not repeat it.
 *
 * @return bool True when both libraries are usable.
 */
function qhta_woo_invoice_load_libraries() {
	static $loaded = null;

	if ( null !== $loaded ) {
		return $loaded;
	}

	$autoload = QHTA_WOO_INVOICE_PATH . 'lib/autoload.php';

	if ( ! file_exists( $autoload ) ) {
		qhta_woo_invoice_log( 'Bundled libraries are missing — lib/ did not survive the deploy.' );
		$loaded = false;
		return $loaded;
	}

	require_once $autoload;

	$loaded = class_exists( \Mustache\Engine::class ) && class_exists( \Dompdf\Dompdf::class );

	if ( ! $loaded ) {
		qhta_woo_invoice_log( 'Bundled libraries loaded but Mustache or Dompdf is not available.' );
		return $loaded;
	}

	// Composer autoloaders are additive and first-registration wins, so if
	// another plugin on the site already declared Dompdf, that is the copy in
	// use — ours never gets a look in. Nothing can be done about it from here,
	// but a silent version mismatch is a miserable thing to debug, so say so.
	$in_use = ( new ReflectionClass( \Dompdf\Dompdf::class ) )->getFileName();
	if ( $in_use && 0 !== strpos( wp_normalize_path( $in_use ), wp_normalize_path( QHTA_WOO_INVOICE_PATH ) ) ) {
		qhta_woo_invoice_log( 'Another plugin supplied Dompdf (' . $in_use . '); the bundled copy is not in use.' );
	}

	return $loaded;
}

/**
 * The invoice template, as a Mustache string.
 *
 * Override first, default second. The override lives in the uploads directory
 * so template edits survive a plugin update — the same arrangement as the PMPro
 * invoice template, and the reason the site owner can restyle an invoice
 * without a release.
 *
 * @return string Template source, or '' when neither file is readable.
 */
function qhta_woo_invoice_template() {
	$override = qhta_woo_invoice_dir() . 'invoice.html';
	$default  = QHTA_WOO_INVOICE_PATH . 'templates/invoice.html';

	$path = ( file_exists( $override ) && is_readable( $override ) ) ? $override : $default;

	/**
	 * Filters the template file used to render invoices.
	 *
	 * @param string $path     Absolute path to the template.
	 * @param string $override Absolute path to the uploads override.
	 * @param string $default  Absolute path to the bundled default.
	 */
	$path = (string) apply_filters( 'qhta_woo_invoice_template_path', $path, $override, $default );

	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		qhta_woo_invoice_log( 'Invoice template not readable: ' . $path );
		return '';
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return (string) file_get_contents( $path );
}

/**
 * The logo, as a base64 data URI.
 *
 * A data URI rather than a URL because Dompdf runs with remote access disabled
 * (see qhta_woo_invoice_pdf()), and rather than a `file://` path because that
 * depends on the chroot and on the web server user being able to read back into
 * the plugin directory. Embedding the bytes has neither failure mode: the image
 * is in the HTML by the time Dompdf sees it.
 *
 * Returns '' when there is no logo, and the template's `{{#logo_url}}` section
 * then renders no `<img>` at all rather than a broken one.
 *
 * @return string
 */
function qhta_woo_invoice_logo_data_uri() {
	static $uri = null;

	if ( null !== $uri ) {
		return $uri;
	}

	// Resolved in the bootstrap, where the QHTA_INVOICE_LOGO constant and the
	// filter that overrides it live alongside the other configuration.
	$path = qhta_woo_invoice_logo_path();

	if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
		qhta_woo_invoice_log( 'Invoice logo not readable: ' . $path );
		$uri = '';
		return $uri;
	}

	$type = wp_check_filetype( $path );
	$mime = ! empty( $type['type'] ) ? $type['type'] : 'image/png';

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$bytes = file_get_contents( $path );

	$uri = ( false === $bytes ) ? '' : 'data:' . $mime . ';base64,' . base64_encode( $bytes );

	return $uri;
}

/**
 * Format an amount the way the store formats money, as plain text.
 *
 * wc_price() returns markup — nested spans, and a `&nbsp;` between symbol and
 * figure depending on the store's price format. Mustache escapes by default, so
 * handing that straight to a `{{placeholder}}` prints the tags. Stripping the
 * tags and decoding the entities gives a plain string that is safe to escape
 * and reads correctly in the PDF, which keeps every money placeholder an
 * ordinary `{{double}}` one.
 *
 * The order's own currency is passed explicitly rather than relying on the
 * store's current setting: an invoice reprinted after a currency change must
 * still say what the customer actually paid.
 *
 * @param float|string $amount Amount from the order.
 * @param WC_Order     $order  Order supplying the currency.
 * @return string
 */
function qhta_woo_invoice_money( $amount, $order ) {
	$html = wc_price( (float) $amount, array( 'currency' => $order->get_currency() ) );

	return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
}

/**
 * The billing address as display lines, name and company excluded.
 *
 * WooCommerce's own formatter is used so the address obeys the country's
 * conventions (which line the postcode sits on, whether the state is
 * abbreviated, whether the country is printed at all) rather than a hardcoded
 * Australian layout. Name and company are left out because the template prints
 * them on their own lines above; passing them here would duplicate both.
 *
 * Returns **plain text**, not HTML. WC_Countries::get_formatted_address() runs
 * its replacements through esc_html() before assembling them, so the lines come
 * back escaped and are decoded here. Escaping once, at the point of output, is
 * what keeps an address like "O'Connell Street" from reaching the PDF as
 * "O&#039;Connell Street" — which is what escaping an already-escaped string
 * produces, and which is invisible until somebody with an apostrophe in their
 * address buys something.
 *
 * @param WC_Order $order Order to read.
 * @return string[] Address lines, empties removed.
 */
function qhta_woo_invoice_address_lines( $order ) {
	$parts = array(
		'address_1' => $order->get_billing_address_1(),
		'address_2' => $order->get_billing_address_2(),
		'city'      => $order->get_billing_city(),
		'state'     => $order->get_billing_state(),
		'postcode'  => $order->get_billing_postcode(),
		'country'   => $order->get_billing_country(),
	);

	if ( function_exists( 'WC' ) && WC()->countries ) {
		$formatted = WC()->countries->get_formatted_address( $parts );
	} else {
		// WC_Countries unavailable — lay the address out plainly rather than
		// dropping it off a tax invoice. The country is left off deliberately:
		// WooCommerce's formatter omits it when it matches the store's own
		// country, and without WC_Countries there is no way to know what that
		// is. Printing it unconditionally puts a bare "AU" under every
		// Australian buyer's address.
		$formatted = implode(
			'<br/>',
			array_filter(
				array(
					$parts['address_1'],
					$parts['address_2'],
					trim( $parts['city'] . ' ' . $parts['state'] . ' ' . $parts['postcode'] ),
				),
				'strlen'
			)
		);
	}

	$lines = preg_split( '/<br\s*\/?>/i', $formatted );
	$lines = is_array( $lines ) ? $lines : array();

	$lines = array_map(
		static function ( $line ) {
			return trim( html_entity_decode( $line, ENT_QUOTES, 'UTF-8' ) );
		},
		$lines
	);

	return array_values( array_filter( $lines, 'strlen' ) );
}

/**
 * Build the template context for an order.
 *
 * Where the numbers come from, and why they add up:
 *
 *   - Item price is `$item->get_subtotal()` — the line total **before**
 *     discount, **excluding** tax. That is the same basis as
 *     `$order->get_subtotal()`, so the item rows sum to the Subtotal line
 *     exactly. Using the post-discount line total instead would leave a coupon
 *     order with a column that visibly does not add up.
 *   - A Discount row appears only when there is one, so
 *     `Subtotal - Discount + Tax = Total` holds on the page.
 *   - Everything else is read straight off the order.
 *
 * Only line items are itemised. This store sells virtual downloads with no
 * shipping and no fees; if either ever appears on an order, the Subtotal, Tax
 * and Total lines still come off the order and stay correct, but the item rows
 * will not enumerate them. Noted in the README as the thing to revisit.
 *
 * @param WC_Order $order Order to describe.
 * @return array
 */
function qhta_woo_invoice_data( $order ) {
	$gst = qhta_woo_invoice_gst_registered();

	// Paid date if there is one — that is the date the supply was paid for, and
	// what a customer matching this against their statement is looking for.
	$date   = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
	$format = (string) apply_filters( 'qhta_woo_invoice_date_format', 'F j, Y' );

	$items = array();
	foreach ( $order->get_items() as $item ) {
		$quantity = (int) $item->get_quantity();
		$amount   = is_callable( array( $item, 'get_subtotal' ) ) ? $item->get_subtotal() : $order->get_line_total( $item, false );

		$items[] = array(
			'name'     => $item->get_name(),
			'quantity' => $quantity,
			// Truthy only above one, so the template can print "x 2" without
			// printing "x 1" on every ordinary line.
			'multiple' => $quantity > 1,
			'price'    => qhta_woo_invoice_money( $amount, $order ),
		);
	}

	$lines    = qhta_woo_invoice_address_lines( $order );
	$discount = (float) $order->get_total_discount();
	$tax      = (float) $order->get_total_tax();

	// Is there GST worth printing? Rounded to the store's own price precision
	// first, so the test asks the question the reader asks — "does that line say
	// $0.00?" — rather than testing a float that can sit a fraction above zero
	// and still print as nothing.
	$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
	$has_tax  = $gst && abs( round( $tax, $decimals ) ) > 0;

	$data = array(
		'seller_name'    => qhta_woo_invoice_seller_name(),
		'abn'            => qhta_woo_invoice_abn(),

		// The heading follows the GST-registered switch so the escape hatch
		// constant actually changes the document rather than being wired to
		// nothing. A registered seller still issues a TAX INVOICE for a GST-free
		// sale, so the heading tracks registration and not the amount.
		'invoice_heading' => $gst ? __( 'TAX INVOICE', 'qhta-woo-invoice' ) : __( 'INVOICE', 'qhta-woo-invoice' ),
		'gst_registered'  => $gst,

		// The total label tracks registration, like the heading — QHTA's prices
		// are GST-inclusive, so a registered seller's total is an including-GST
		// total whether or not WooCommerce broke the component out onto its own
		// line. It says plain "Total" only when the seller is not registered,
		// where "including GST" would be false rather than merely unitemised.
		'total_label' => $gst ? __( 'Total including GST', 'qhta-woo-invoice' ) : __( 'Total', 'qhta-woo-invoice' ),

		// The order number is the invoice number. Australia asks for an
		// identifiable number, not a sequential one, and the order number is
		// already the reference the customer and the site both use.
		'invoice_number' => $order->get_order_number(),
		'date'           => $date ? wc_format_datetime( $date, $format ) : '',
		'payment_method' => $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method(),

		'buyer_name'    => $order->get_formatted_billing_full_name(),
		'buyer_company' => $order->get_billing_company(),
		// Raw HTML, so this one is a {{{triple}}} in the template. Each line is
		// escaped here before the <br> joins them, so it is escaped output with
		// the separators left alone — not unescaped output.
		'buyer_address' => implode( '<br>', array_map( 'esc_html', $lines ) ),
		// Same address as a section, for template authors who would rather loop
		// than trust a triple-stache: {{#buyer_address_lines}}{{line}}<br>{{/…}}
		'buyer_address_lines' => array_map(
			static function ( $line ) {
				return array( 'line' => $line );
			},
			$lines
		),
		'buyer_email' => $order->get_billing_email(),

		'items'    => $items,
		'subtotal' => qhta_woo_invoice_money( $order->get_subtotal(), $order ),
		// '' when there is no discount, which makes {{#discount}} falsy and the
		// row disappear rather than printing "-$0.00".
		'discount' => $discount > 0 ? qhta_woo_invoice_money( $discount, $order ) : '',
		// Kept populated even at zero, unlike discount, because a template may
		// legitimately want to print "GST: $0.00" to show a sale was GST-free.
		// `has_tax` is what the default template gates the row on.
		'has_tax'  => $has_tax,
		'tax'      => qhta_woo_invoice_money( $tax, $order ),
		'total'    => qhta_woo_invoice_money( $order->get_total(), $order ),

		'logo_url' => qhta_woo_invoice_logo_data_uri(),
	);

	/**
	 * Filters the template context for an invoice.
	 *
	 * @param array    $data  Placeholder values.
	 * @param WC_Order $order Order being invoiced.
	 */
	return (array) apply_filters( 'qhta_woo_invoice_data', $data, $order );
}

/**
 * Render an order to invoice HTML.
 *
 * Mustache rather than str_replace, for one concrete reason: the line-item
 * table is a loop, and a placeholder-substitution pass cannot express one.
 *
 * @param WC_Order $order Order to render.
 * @return string HTML, or '' on failure.
 */
function qhta_woo_invoice_html( $order ) {
	if ( ! qhta_woo_invoice_load_libraries() ) {
		return '';
	}

	$template = qhta_woo_invoice_template();

	if ( '' === $template ) {
		return '';
	}

	try {
		$mustache = new \Mustache\Engine(
			array(
				'charset'      => 'UTF-8',
				'entity_flags' => ENT_QUOTES,
				// Values here are plain strings and arrays; without this,
				// Mustache treats any string that happens to name a PHP function
				// as a callable to invoke.
				'strict_callables' => true,
			)
		);

		$html = $mustache->render( $template, qhta_woo_invoice_data( $order ) );
	} catch ( \Throwable $e ) {
		qhta_woo_invoice_log( 'Template render failed for order ' . $order->get_id() . ': ' . $e->getMessage() );
		return '';
	}

	/**
	 * Filters the rendered invoice HTML before it goes to the PDF engine.
	 *
	 * @param string   $html  Rendered HTML.
	 * @param WC_Order $order Order being invoiced.
	 */
	return (string) apply_filters( 'qhta_woo_invoice_html', $html, $order );
}

/**
 * Turn invoice HTML into PDF bytes.
 *
 * Dompdf is configured shut: no remote fetching, no PHP or JavaScript
 * execution, and only `data:` and `file:` URIs allowed. The template is an
 * editable file on the server, so it is trusted input in the ordinary sense —
 * but it is also the one part of this plugin a site owner is invited to change,
 * and a template that quietly fetched a URL every time an invoice rendered
 * would be a slow, surprising dependency on somebody else's uptime. The logo is
 * embedded as a data URI precisely so none of that is needed.
 *
 * The font cache is pointed at the (protected) uploads directory because the
 * bundled font directory lives under wp-content/plugins, which on a properly
 * configured host is not writable by the web server — and Dompdf writing there
 * would be lost on the next plugin update anyway.
 *
 * @param string   $html  Invoice HTML.
 * @param WC_Order $order Order being invoiced, for logging and filters.
 * @return string|false PDF bytes, or false on failure.
 */
function qhta_woo_invoice_pdf( $html, $order ) {
	if ( ! qhta_woo_invoice_load_libraries() ) {
		return false;
	}

	$font_cache = qhta_woo_invoice_dir() . 'fonts/';
	wp_mkdir_p( $font_cache );

	try {
		$options = new \Dompdf\Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setIsJavascriptEnabled( false );
		$options->setAllowedProtocols( array( 'data://', 'file://' ) );
		$options->setIsHtml5ParserEnabled( true );
		// Bundled with Dompdf and covers the characters an Australian invoice
		// can contain, which the built-in Helvetica does not.
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setDefaultPaperSize( 'A4' );
		$options->setDefaultPaperOrientation( 'portrait' );
		$options->setTempDir( get_temp_dir() );
		$options->setFontCache( $font_cache );
		// A `file://` reference in a hand-edited template can reach the plugin's
		// own assets and the invoice directory, and nothing else on the server.
		$options->setChroot( array( QHTA_WOO_INVOICE_PATH, qhta_woo_invoice_dir() ) );
		$options->setLogOutputFile( '' );

		/**
		 * Filters the Dompdf options before rendering.
		 *
		 * @param \Dompdf\Options $options Configured options object.
		 * @param WC_Order        $order   Order being invoiced.
		 */
		$options = apply_filters( 'qhta_woo_invoice_dompdf_options', $options, $order );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->render();

		$pdf = $dompdf->output();
	} catch ( \Throwable $e ) {
		qhta_woo_invoice_log( 'PDF render failed for order ' . $order->get_id() . ': ' . $e->getMessage() );
		return false;
	}

	return ( is_string( $pdf ) && '' !== $pdf ) ? $pdf : false;
}

/**
 * Generate (or regenerate) an order's invoice PDF.
 *
 * Idempotent by overwrite: calling it again produces a fresh PDF from the
 * current order and the current template, replacing whatever was there. That is
 * what makes the admin Regenerate button work, and it is why nothing else needs
 * to reason about staleness.
 *
 * @param int|WC_Order $order Order or order ID.
 * @return string|false Absolute path to the PDF, or false on failure.
 */
function qhta_woo_invoice_generate( $order ) {
	$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );

	if ( ! $order ) {
		return false;
	}

	$html = qhta_woo_invoice_html( $order );

	if ( '' === $html ) {
		return false;
	}

	$pdf = qhta_woo_invoice_pdf( $html, $order );

	if ( false === $pdf ) {
		return false;
	}

	return qhta_woo_invoice_save( $order, $pdf );
}

/**
 * Return the path to an order's invoice, generating it if it is not there.
 *
 * This is what every consumer calls — the download handler, the email
 * attachment, the admin buttons — so an order paid before this plugin existed,
 * or one whose file was cleaned up, still produces an invoice the first time
 * somebody asks for it instead of a dead link.
 *
 * @param int|WC_Order $order Order or order ID.
 * @return string|false Absolute path, or false when one cannot be produced.
 */
function qhta_woo_invoice_ensure( $order ) {
	$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );

	if ( ! $order ) {
		return false;
	}

	if ( qhta_woo_invoice_exists( $order ) ) {
		return qhta_woo_invoice_path( $order );
	}

	return qhta_woo_invoice_generate( $order );
}

/**
 * Generate the invoice when an order completes.
 *
 * Priority 5, and that is load-bearing. WooCommerce hooks its transactional
 * emails onto this same action at priority 10
 * (`WC_Emails::init_transactional_emails()`), so anything later than 10 would
 * build the customer's completed-order email before the PDF existed and mail it
 * without the attachment. Five leaves room to get in front of it.
 *
 * The attachment filter re-checks and generates on demand as well, so a missed
 * ordering here degrades to a slower email rather than a missing invoice — but
 * the ordering is still the intended path and should not be moved.
 *
 * Completed is the right trigger for this store: the products are virtual and
 * downloadable, so WooCommerce auto-completes them on payment and "completed"
 * and "paid" are the same moment.
 *
 * @param int $order_id Order that just completed.
 * @return void
 */
function qhta_woo_invoice_on_completed( $order_id ) {
	qhta_woo_invoice_generate( $order_id );
}
add_action( 'woocommerce_order_status_completed', 'qhta_woo_invoice_on_completed', 5 );
