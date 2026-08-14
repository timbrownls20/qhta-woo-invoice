# QHTA Woo Invoice

WordPress plugin for **qhta.com.au** that turns a paid WooCommerce order into a
branded **PDF tax invoice**: rendered from an editable HTML template, attached
to the customer's completed-order email, and downloadable from **My Account**
and the **admin order screen**.

Built in-house rather than bought. A third-party invoice plugin was a poor fit
on the PMPro side of this site and would have needed extending anyway, so this
mirrors the pattern already in use there — an editable HTML template rendered to
PDF — with WooCommerce orders as the subject.

**It does not calculate GST.** Every figure on the invoice is read off the
`WC_Order` exactly as WooCommerce computed it. If a total looks wrong, the fix
is in WooCommerce → Settings → Tax.

## Scope

Two invoice systems on this site, deliberately separate — they operate on
different order objects and are not merged:

| Plugin | Remit |
|---|---|
| `qhta-pmpro-invoice-extensions` | **PMPro** membership invoices (`MemberOrder`, PMPro PDF Invoices) |
| **qhta-woo-invoice** (this one) | **WooCommerce** order tax invoices (`WC_Order`) |

Out of scope, with where it belongs instead:

- Membership invoices → `qhta-pmpro-invoice-extensions`.
- Calculating GST or tax → WooCommerce → Settings → Tax.
- The purchase gate, store preview and checkout tweaks → `qhta-commerce`.
- Theme presentation → `qhta-theme-extras`.

## Requirements

- WordPress 6.0+ with **WooCommerce** active.
- **PHP 8.0+.** Higher than the 6.0/7.4 the sibling plugins declare, and it is
  the bundled libraries that set it — see [Bundled libraries](#bundled-libraries).
- Nothing else. Mustache and Dompdf ship inside the plugin; there is no build
  step and no Composer on the server.

The header carries `Requires Plugins: woocommerce`, which stops WordPress
activating this without WooCommerce — but only on WP 6.5 and later, and only at
activation. WooCommerce being switched off afterwards is handled at runtime
instead: every feature no-ops and an admin notice says so.

## Configuration

Four constants, all in `wp-config.php`, all optional in the sense that the
plugin works without them — and one of which you should set anyway.

```php
define( 'QHTA_INVOICE_ABN', '77 270 249 802' ); // set and VERIFY here
define( 'QHTA_INVOICE_SELLER', 'QHTA' );        // trading name on the invoice
define( 'QHTA_INVOICE_LOGO', 'branding/qhta-logo.png' ); // replaces the bundled logo

// GST-registered is true and stays true. This is an escape hatch, not a switch
// anybody is expected to touch:
// define( 'QHTA_INVOICE_GST_REGISTERED', true );
```

Every one of them is ignored — with a line in the log — if it is blank or
unusable, leaving the built-in value in place. A misconfigured constant should
make the invoice look stale, never make it invalid.

**ABN.** Read from `QHTA_INVOICE_ABN`, falling back to `77 270 249 802`. It
lives in wp-config rather than in the code for one reason: it prints on a legal
document, and if the recorded value ever turns out to be wrong it needs
correcting in one place on the server, immediately, without a plugin release.
The fallback is a real value rather than a blank, because an invoice with an
empty ABN line is a broken tax invoice while a wrong-looking one is at least
visible.

**Seller name.** Read from `QHTA_INVOICE_SELLER`, defaulting to `QHTA`. It sits
beside the ABN for the same reason the ABN is there: those two lines together
are the seller's identity on a tax invoice, and a trading name that needs
correcting should be correctable in the same place, at the same moment, as the
number under it.

**GST registration.** QHTA is registered, so the document is headed `TAX
INVOICE` and carries a Tax row on any order with GST on it. The constant exists
so that if the answer ever changed the document would follow it — set it to
`false` and the heading becomes `INVOICE` and the Tax row never appears. It does
not change what WooCommerce charges.

Note that this is a different question from whether a *particular order* carries
GST: the heading and `{{total_label}}` follow registration, while the Tax row
follows the amount. A registered seller still issues a tax invoice for a GST-free
sale — see [How the numbers add up](#how-the-numbers-add-up).

**Logo.** `assets/logo.png` is bundled and embedded in the PDF as a data URI.
`QHTA_INVOICE_LOGO` replaces it without touching the plugin, which is what makes
swapping the low-resolution bundled copy for a print-quality original a matter
of uploading a file. It accepts the three things you might reasonably paste in,
because only one of them is the "correct" one and the other two are what you get
from the Media Library:

```php
define( 'QHTA_INVOICE_LOGO', 'branding/qhta-logo.png' );                       // relative to uploads
define( 'QHTA_INVOICE_LOGO', '/var/www/qhta/assets/logo.png' );                // absolute server path
define( 'QHTA_INVOICE_LOGO', 'https://qhta.com.au/wp-content/uploads/…png' );  // Media Library URL
```

A URL under this site's uploads is **mapped back to the file on disk**, not
fetched — the PDF engine has no network access by design, so a URL left as a URL
would silently produce an invoice with no logo. A URL on any other host is
refused for the same reason. A value with a leading slash is tried as an
absolute path first and then as an uploads path, so `/branding/logo.png` works
whichever you meant. Anything that does not resolve to a readable file is logged
and ignored, and the bundled logo stays: an invoice with the old logo is a small
problem, one with no logo is a reissue.

The `qhta_woo_invoice_logo_path` and `qhta_woo_invoice_seller_name` filters
still run, after the constants, so code has the last word over configuration.

## The template

The default lives at `templates/invoice.html` inside the plugin. To change it,
**copy it to**:

```
wp-content/uploads/qhta-woo-invoice/invoice.html
```

The override wins, and it survives plugin updates — the plugin directory is
replaced wholesale on update, the uploads directory is not. Editing the bundled
copy works until the next update, then silently reverts.

It is rendered with **Mustache**, so the line-item table can be a loop (a
`str_replace` pass cannot express one), and then with **Dompdf**.

### Values

| Placeholder | What it is |
|---|---|
| `{{seller_name}}` | From `QHTA_INVOICE_SELLER`, default `QHTA` |
| `{{abn}}` | From `QHTA_INVOICE_ABN` |
| `{{invoice_heading}}` | `TAX INVOICE`, or `INVOICE` if GST registration is off |
| `{{invoice_number}}` | The WooCommerce order number |
| `{{date}}` | Paid date, else created date, as `F j, Y` |
| `{{payment_method}}` | e.g. `Credit card (Stripe)` |
| `{{buyer_name}}` | Formatted billing name |
| `{{buyer_company}}` | Billing company, `''` when there is none |
| `{{{buyer_address}}}` | Billing address lines joined with `<br>` — **triple-stache**, see below |
| `{{buyer_email}}` | Billing email |
| `{{subtotal}}` `{{discount}}` `{{tax}}` `{{total}}` | Formatted money, plain text |
| `{{total_label}}` | `Total including GST`, or `Total` when the seller is not GST-registered |
| `{{logo_url}}` | The logo as a base64 `data:` URI |
| `{{has_tax}}` | Flag: there is GST worth printing. Wraps the Tax row |
| `{{gst_registered}}` | Flag: the seller is GST-registered at all |

Sections: `{{#items}}` with `{{name}}`, `{{quantity}}`, `{{multiple}}` (truthy
only above one) and `{{price}}`; and `{{#buyer_address_lines}}` with `{{line}}`.

### Three things that will bite you editing it

1. **An HTML comment is not a Mustache comment.** Mustache parses the whole
   file, comments included. A section tag written as an example inside `<!-- -->`
   is a real unclosed section and the render fails outright. This was hit while
   building the default template; it is why the placeholder names in its header
   comment are written without braces.
2. **`buyer_address` is the one triple-stache.** Its lines are escaped by the
   plugin and joined with `<br>`, so it carries markup on purpose. Everything
   else is plain text and uses ordinary escaping double-staches. If you would
   rather have nothing unescaped, loop `{{#buyer_address_lines}}` instead.
3. **Dompdf has no network access**, deliberately. An `<img src="http…">` or an
   external stylesheet will not load — silently, since the render still
   succeeds. The logo arrives already embedded, which is why none of that is
   needed. Only `data:` and `file:` URIs are allowed, and `file:` is confined to
   the plugin directory and the invoice directory.

Empty values render nothing rather than a blank line: optional buyer lines are
wrapped in sections, and the Discount row only appears when there is a discount.

## How the numbers add up

This matters because a tax invoice whose column does not sum is worse than no
invoice at all.

- **Item price** is the line **subtotal**: before discount, excluding tax. That
  is the same basis as WooCommerce's own `get_subtotal()`, so the item rows sum
  to the Subtotal line exactly. Using the post-discount line total instead —
  the obvious choice — leaves any coupon order with a column that visibly does
  not add up.
- **Discount** appears as its own row only when there is one, so
  `Subtotal − Discount + Tax = Total` holds on the page.
- **The Tax row disappears when there is no GST** rather than printing `$0.00`.
  "No GST" means the amount rounds to zero at the store's own price precision,
  so the test matches what the reader would see. `{{tax}}` stays populated
  either way, so a custom template can still print `GST: $0.00` deliberately.
- **`{{total_label}}` reads `Total including GST`** on every invoice this site
  issues, because it follows registration rather than the itemised amount — the
  same switch as the heading. QHTA's prices are GST-inclusive, so the total does
  include GST whether or not WooCommerce broke the component out onto its own
  row, and the label is the only place the document says so once the Tax row is
  gone. It drops to plain `Total` when `QHTA_INVOICE_GST_REGISTERED` is `false`,
  which is the case where "including GST" would be untrue rather than merely
  unitemised.
- **Subtotal, Tax and Total** come straight from `get_subtotal()`,
  `get_total_tax()` and `get_total()`.
- Money is formatted with `wc_price()` in the **order's own** currency, not the
  store's current setting, so a reprint after a currency change still says what
  the customer paid.

Only line items are itemised. This store sells virtual downloads with no
shipping and no fees, so there is nothing else on an order. If either ever
appears, the Subtotal / Tax / Total lines still come off the order and stay
correct, but the item rows will not enumerate them — see [Open items](#open-items).

## Where invoices are stored, and who can read them

```
wp-content/uploads/qhta-woo-invoice/
├── .htaccess                            deny all
├── index.php                            silence
├── invoice.html                         your template override, if any
├── fonts/                               Dompdf's font cache
└── tax-invoice-<order number>-<hash>.pdf
```

Never a public URL. Three layers, in increasing order of how much they matter:

1. **`.htaccess` denies the directory.** On Apache, nothing under it is
   web-reachable.
2. **The filename carries a hash** derived from the site's `AUTH` salts, so on
   **nginx** — where `.htaccess` is inert and layer 1 does nothing at all — the
   URL still cannot be guessed from an order number.
3. **Every download goes through an ownership check.** This is the real gate.
   Access is granted if the order belongs to the requesting user, or they have
   `manage_options`. Nothing else. A logged-out request is sent to log in and
   returned afterwards rather than refused, so a customer with a perfectly good
   invoice is not told "forbidden".

Deliberately **not** `current_user_can( 'view_order' )`: that meta capability is
mapped by WooCommerce and picks up whatever any other plugin has filtered into
its shop-manager roles. An invoice carries the buyer's name, address and
purchase history; the question worth asking is "is this your document?".

Rotating the site's salts changes the filename hash. Existing PDFs are orphaned
rather than lost — the order still records the old name, and anything not found
is regenerated from the order, which is the source of truth anyway.

## When invoices are generated

On `woocommerce_order_status_completed`, at **priority 5**. That priority is
load-bearing: WooCommerce hooks its transactional emails onto the same action at
priority 10, so anything later would build the customer's completed-order email
before the PDF existed and mail it without the attachment.

Completed is the right trigger here because the products are virtual and
downloadable, so WooCommerce auto-completes them on payment — "completed" and
"paid" are the same moment.

Generation is **idempotent by overwrite**: it rebuilds from the current order
and the current template, replacing whatever was there. Nothing else has to
reason about staleness, and it is what makes Regenerate work.

Anything that needs an invoice also generates one on demand if it is missing —
the download handler, the email attachment, the admin buttons. An order paid
before this plugin existed produces an invoice the first time somebody asks for
it, rather than a dead link.

## Delivery

**Email.** Attached to the **customer completed order** email only. One document
at the point the purchase is done. Attaching it to processing or on-hold mails
would send the same PDF repeatedly; attaching it to the admin notification would
mail a customer's address and purchase history to the shop inbox for no reason.
Filter `qhta_woo_invoice_email_ids` to change that.

If generation fails, the attachment is quietly dropped and the order email still
sends. Losing the invoice is bad; losing the customer's order confirmation is
worse.

**My Account.** A **Download invoice** action on each row of My Account →
Orders, for orders that are paid or completed. There is no tax invoice for a
purchase that has not happened.

That link carries **no nonce**, and that is a decision rather than an omission.
A nonce protects against a request being made without the user's intent, which
for a read-only download of their own document buys nothing — while WordPress
nonces expire in 12–24 hours, and an expired link printed in My Account reads as
a broken invoice. The ownership check is the protection. Regeneration, which
changes state, does carry a nonce.

**Admin.** On the orders list, **Invoice** (opens in the browser) and
**Regenerate** buttons; on the order edit screen, a **Tax invoice** box with
View / Download / Regenerate and the date the PDF was last written. Everything
is gated on `manage_options`, and Regenerate is nonced. There is no confirmation
step — it deletes the PDF and rebuilds it immediately, then reports the outcome.

Both are registered against the legacy `shop_order` screen and the HPOS
`woocommerce_page_wc-orders` page, so they appear whichever order storage the
site is using. The plugin declares HPOS compatibility because it is compatible:
every order read and write goes through the `WC_Order` CRUD, never post meta.

## Australian tax invoice compliance

A tax invoice for a sale of this size needs: the words "Tax invoice", the
seller's identity and ABN, the date, a description of what was sold, and the GST
amount or a statement that the total includes GST. The buyer's identity is only
required at $1,000 and above.

All of it is on the document. The buyer's name and address are printed
regardless of amount, because checkout keeps a full billing address anyway and
it costs nothing to include.

There is **no bank details block**. Stripe has already collected the payment;
printing account numbers on a paid receipt invites somebody to pay twice.

Invoice numbers are order numbers. Australia asks for an identifiable number,
not a sequential one, and the order number is already the reference both the
customer and the site use.

**The tax itself is not this plugin's doing.** Confirm the recordings' tax class
in WooCommerce → Settings → Tax as part of the GST setup.

## Bundled libraries

`lib/` is committed. There is no build step and Composer never runs on the
server.

| Library | Version | Licence | Why |
|---|---|---|---|
| `dompdf/dompdf` | 3.1.x | LGPL-2.1 | HTML → PDF |
| `mustache/mustache` | 3.2.x | MIT | The `{{#items}}` loop |
| plus Dompdf's own dependencies | | LGPL / MIT | font-lib, svg-lib, css-parser, html5 |

**Bundled, not borrowed.** `qhta-pmpro-invoice-extensions` leans on another
plugin's undocumented `pmpropdf_*` internals and its README lists what that
costs; this plugin does not repeat it.

`composer.json` and `composer.lock` are kept in the repo as the record of what
`lib/` contains and how to refresh it, and are excluded from the deploy zip. To
update: install Composer locally and run `composer update` — the vendor
directory is configured as `lib/`.

`lib/` is 689 files, of which 318 are `thecodingmachine/safe` — not used
directly here, but required by `sabberworm/php-css-parser`, which Dompdf
requires. `.gitattributes` marks the whole directory vendored and generated so
GitHub keeps it out of language stats, collapses it in pull requests and skips
it in blame. It is not marked `-diff`: a dependency bump is the one time
somebody needs to actually read those files.

Two consequences worth knowing:

- **PHP 8.0+ is a library floor, not a preference.** Mustache 3 and Dompdf 3
  both require it. Mustache 2 would have kept 7.4 but emits deprecation notices
  on PHP 8.4, which the site runs on; the older Dompdf 2 line is a security
  patch behind. WordPress honours `Requires PHP` and refuses activation with a
  clear message rather than fatalling, so declaring the real floor is the
  fail-safe option.
- **A Composer autoloader is additive and first registration wins.** If another
  plugin on the site declares Dompdf before this one does, *that* copy is what
  renders, and nothing here can prevent it. The generator detects the case and
  writes a line to the log, because a silent version mismatch is a miserable
  thing to debug.

## Extension points

Every one of these is a filter; none needs the plugin edited.

| Filter | Changes |
|---|---|
| `qhta_woo_invoice_abn` | The ABN, beyond what the constant can express |
| `qhta_woo_invoice_seller_name` | The seller name, after `QHTA_INVOICE_SELLER` |
| `qhta_woo_invoice_gst_registered` | The heading, the Tax row and the total label |
| `qhta_woo_invoice_template_path` | Which template file is used |
| `qhta_woo_invoice_logo_path` | Which image is embedded, after `QHTA_INVOICE_LOGO` |
| `qhta_woo_invoice_date_format` | The invoice date format |
| `qhta_woo_invoice_data` | The whole template context, before rendering |
| `qhta_woo_invoice_html` | The rendered HTML, before the PDF engine |
| `qhta_woo_invoice_dompdf_options` | The Dompdf configuration |
| `qhta_woo_invoice_can_access` | Who may download an invoice |
| `qhta_woo_invoice_is_invoiceable` | Which orders offer one |
| `qhta_woo_invoice_email_ids` | Which emails carry the attachment |

Plus the action `qhta_woo_invoice_saved` (`$path`, `$order`), after a PDF is
written.

## Structure

```
qhta-woo-invoice/
├── qhta-woo-invoice.php     header, config helpers, guards, bootstrap
├── includes/
│   ├── storage.php          protected directory, ownership check, download handler
│   ├── generator.php        WC_Order -> data -> Mustache -> HTML -> Dompdf -> PDF
│   ├── delivery.php         email attachment, My Account link
│   └── admin.php            order-list buttons, meta box, regenerate
├── templates/invoice.html   default template (override in uploads)
├── assets/logo.png          embedded in the PDF
├── assets/admin.css         icons for the two order-list buttons
├── lib/                     vendored Mustache + Dompdf
└── scripts/build-zip.sh     deploy zip, with a version-agreement guard
```

`admin.php` is only loaded in the admin. The libraries are only loaded when an
invoice is actually being rendered — loading a PDF engine's autoloader on every
page view to support a handful of invoices a day would be a poor trade.

## Install

```
./scripts/build-zip.sh
```

then wp-admin → Plugins → Add New → Upload Plugin. The script refuses to build
if the plugin header and `QHTA_WOO_INVOICE_VERSION` disagree, or if `lib/`,
the template or the logo are missing — a plugin that installs and then fails on
the first paid order is the wrong way to find out.

The zip is around 5MB, most of which is the DejaVu font family Dompdf bundles.
If the host's upload limit refuses it, copy the folder into
`wp-content/plugins/` over SFTP instead.

Then set `QHTA_INVOICE_ABN` in `wp-config.php` and check the tax class. The
build script prints the full deployment checklist and smoke test.

## Notes

- **`woocommerce_order_status_completed` at priority 5 is load-bearing.**
  WooCommerce's transactional emails hook the same action at 10. Do not move it.
  The attachment filter regenerates on demand as a safety net, so a mistake
  there degrades to a slower email rather than a missing invoice — but the
  ordering is the intended path.
- **Nothing in this plugin does arithmetic on money** beyond summing what
  WooCommerce already computed per line. That is the whole design constraint.
- **The PDF is written to a temporary name and renamed into place.** A download
  landing mid-regeneration gets the old invoice or the new one, never half a
  file.
- **Regenerate deletes first, then generates.** Not because generation would
  refuse to overwrite — it will — but so a render that fails half way leaves no
  file rather than the previous one, which would look like it had worked.
- **Failures are logged and swallowed.** Everything here is a side effect of
  something more important: completing an order, sending a receipt. Look in
  WooCommerce → Status → Logs, source `qhta-woo-invoice`.
- **Dompdf's font cache is pointed at the uploads directory**, not at the
  bundled font directory under `wp-content/plugins`, which on a properly
  configured host is not writable — and would be wiped by the next update if it
  were.
- **Licensing is inconsistent and worth a decision.** The plugin header declares
  `GPL-2.0-or-later` per house convention; the repo's `LICENSE` file is MIT,
  inherited from the repo scaffold exactly as in `qhta-commerce` and
  `qhta-membership`. It matters slightly more here because one bundled library
  (`php-svg-lib`) is LGPL-3.0-or-later, which is compatible with
  GPL-2.0-**or-later** but not with GPL-2.0-only. Nothing is broken; the two
  files should simply be made to agree.

## Open items

Confirm these before relying on the output.

- ~~**The ABN.**~~ **Checked 9 August 2026** against the ABR register:
  `77 270 249 802` is **QLD HISTORY TEACHERS ASSOC INC**, active since 1 Nov
  1999, **GST-registered since 1 Jul 2000**, QLD 4005. So the number is right
  and `QHTA_INVOICE_GST_REGISTERED` being fixed at `true` is right with it.
  Re-check at <https://abr.business.gov.au/ABN/View?abn=77270249802> if the
  entity is ever restructured.
- **The seller name.** The invoice prints `QHTA`; the registered entity is
  *QLD History Teachers Assoc Inc*. A tax invoice needs the seller's identity
  **and** ABN, and both are present, so an abbreviation is defensible — but the
  registered name is the safer thing on a legal document, and it is one line in
  wp-config now:
  `define( 'QHTA_INVOICE_SELLER', "Queensland History Teachers' Association Inc." );`
  Someone who knows how QHTA wants to be named should make that call.
- **The recordings' tax class** in WooCommerce → Settings → Tax matches the
  intended GST treatment. This is *not* settled by the ABN check above — being
  GST-registered says the association charges GST, not that these particular
  products are configured to. The invoice will faithfully print whatever the
  order produces.

  **This got quieter, and that is worth knowing.** A `$0.00` Tax row used to be
  a visible symptom of a misconfigured tax class. Now the row is simply absent,
  so a wrongly GST-free product produces an invoice that looks deliberate rather
  than broken. Check one real order's totals against WooCommerce → Tax before
  the first invoice goes out; nothing on the document will tell you.
- **The logo file.** `assets/logo.png` was taken from the conference plugin's
  sponsor images — it is the right logo, at 200×155, which is on the small side
  for print. If a higher-resolution original exists, upload it and point
  `QHTA_INVOICE_LOGO` at it; no release needed.
- **Fees and shipping are not itemised.** There are none on this store today.
  If either appears, the totals stay correct but the item rows will not list
  them, and the item table should be widened to include them.

## Phase 2

- **Credit notes on refund** — issue a labelled credit-note PDF when an order is
  refunded. Not in 1.0: refunds already revoke access through `qhta-commerce`'s
  gate, so nothing is left dangling by omitting it.
