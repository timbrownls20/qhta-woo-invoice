# Changelog

## 1.2.1 — 15 August 2026

### Changed
- **The total line now reads "Total including GST" on every invoice**, not only on orders where
  WooCommerce itemised a GST amount. `total_label` follows the GST-registered switch again — the same
  one the heading uses — rather than following `has_tax`.

  1.1.0 tied it to the amount on the reasoning that "Total including GST" over an order carrying no
  GST is a false statement. That reasoning assumed a zero tax figure meant a GST-free sale. On this
  store it does not: prices are GST-inclusive, so the total genuinely includes GST even when the
  order's tax lines don't break it out, and the old rule was hiding a true statement rather than
  suppressing a false one. With the `$0.00` Tax row removed in 1.1.0, the label is now the only place
  on the page that says GST is in the number at all.

  Plain `Total` remains for `QHTA_INVOICE_GST_REGISTERED = false`, which is the case where the claim
  would actually be untrue. `has_tax` is unchanged and still gates the Tax row.

  **Existing PDFs do not change.** They are rendered once and kept on disk, so an invoice issued
  before this release keeps the old wording until it is re-issued from Memberships → Orders →
  Regenerate.

### Fixed
- **Two canaries shipped in 1.2.0 were red on a healthy site.** Both were faults in the checks, not
  in what they were checking — invoicing was working the whole time.

  - **"Invoice template readable"** called `qhta_woo_invoice_template()`, which returns the template's
    *source*, and handed it to a file check — so the canary reported the entire template as a missing
    filename. The resolution is now split: `qhta_woo_invoice_template_path()` returns the path and is
    what the canary asks, `qhta_woo_invoice_template()` reads that path. One resolver still, so the
    canary cannot drift from what the renderer opens.
  - **"Dompdf and Mustache loadable"** asserted the two classes exist, but the bundled autoloader is
    registered lazily at generation time — so on an ordinary admin page load nothing had registered
    it and the classes were correctly unloadable. It calls `qhta_woo_invoice_load_libraries()` first
    now, which is also the more honest test: that loader is the thing that notices a deploy without
    `lib/`, which is what the canary exists to catch.

  A check that fails during ordinary operation is not a check — same reasoning as the output-directory
  canary removed from qhta-pmpro-invoice-extensions in 1.2.3. These two were worth fixing rather than
  removing, because both do fail for real reasons too.

## 1.2.0 — 12 August 2026

### Added
- **Healthcheck canaries**, in `includes/healthcheck.php` — ten checks registered on
  `qhta-healthcheck`'s `qhta_healthcheck_checks` filter: `WC_Order`, the bundled Dompdf and Mustache
  classes, the invoice template (resolved through `qhta_woo_invoice_template()`, so it reports which
  of override or default is actually in use), the invoice directory's existence/`.htaccess`/
  `index.php`/writability, the email-attachment filter, the completed-order generation hook, both
  download handlers, the HPOS declaration, the two order-list buttons, and an end-to-end check that
  recent completed orders actually carry an invoice.

  That last one reads through `wc_get_orders()` rather than `wp_postmeta`, so it is correct under
  HPOS — asking postmeta would return zero on a perfectly healthy store, which is exactly the false
  alarm that teaches people to ignore a monitoring screen.

  This plugin fails soft everywhere by design, because it runs on the customer email path and a fatal
  there loses the order confirmation rather than just the invoice. That design is why it needs
  watching from outside, and why these canaries ship with it.

### Changed
- The canary registration is loaded at **file scope, deliberately outside `qhta_woo_invoice_bootstrap()`**.
  The feature files only load when WooCommerce is present; the canaries must not. "WooCommerce is
  missing" is the single most important thing this plugin has to report, and registering inside the
  WooCommerce guard would mean that in exactly that case no canaries exist and the board shows "no
  canaries defined" instead of a red line naming the cause. The three checks that need the feature
  files skip themselves when those are absent.

## 1.1.0 — 11 August 2026

Configuration that was hardcoded in 1.0.0, and one change to what the document
says when an order carries no GST.

Both constants exist for the same reason the ABN one does: these values print on
a legal document, and correcting one should not require a plugin release.

### Added
- **`QHTA_INVOICE_SELLER`** in `wp-config.php`, defaulting to `QHTA`. It sits
  beside the ABN because the two lines together are the seller's identity on a
  tax invoice: a trading name that needs correcting should be correctable in the
  same place, at the same moment, as the number under it. A blank or
  whitespace-only value falls back to the default rather than printing an
  invoice with no seller on it.
- **`QHTA_INVOICE_LOGO`** in `wp-config.php`, replacing the bundled
  `assets/logo.png`. The original brief called the logo not configurable, and
  that was right about its *shape* — it is an asset, not a setting. What earns
  it a constant is the replacement case: the bundled file is a 200px
  sponsor-sheet copy, and swapping it for a print-resolution original should be
  an upload, not a plugin release.

  It accepts all three things somebody will realistically paste in, because only
  one is the "correct" form and the other two are what the Media Library hands
  you: an uploads-relative path, an absolute server path, and a full uploads
  URL. A URL under this site is **mapped back to the file on disk rather than
  fetched** — the PDF engine has no network access by design, so a URL left as a
  URL would silently produce an invoice with no logo — and a URL on another host
  is refused for the same reason. A leading slash is tried as an absolute path
  and then as an uploads path, because `/branding/logo.png` is what you write
  when you mean the top of the uploads folder and it is indistinguishable from a
  real absolute path until you look on disk. Anything unresolvable is logged and
  ignored, leaving the bundled logo: an invoice with the old logo is a small
  problem, one with no logo is a reissue.
- **Every constant is ignored when it is unusable**, with a line in the log, and
  the built-in value stays. Now applied uniformly across the ABN, the seller name
  and the logo: a misconfigured constant should make the invoice look stale,
  never make it invalid. The filters still run *after* the constants, so code
  keeps the last word over configuration.
- `{{has_tax}}` in the template context, and `qhta_woo_invoice_resolve_logo()`
  for the path/URL handling behind `QHTA_INVOICE_LOGO`.

### Changed
- **The Tax row is omitted when there is no GST**, rather than printing a
  `$0.00` line that says nothing. "No GST" means the amount rounds to zero at the
  store's own price precision, so the test asks the question the reader asks —
  "does that line say $0.00?" — instead of testing a float that can sit a
  fraction above zero and still print as nothing.

  **`{{total_label}}` moves with it**, dropping to plain `Total`. The two are not
  separable: "Total including GST" over an order carrying no GST is a false
  statement on a legal document, and the `$0.00` row was the only thing on the
  page contradicting it. Removing the row alone would have made a wrong claim
  invisible instead of merely inconsistent.

  The **heading stays `TAX INVOICE`**, because that follows registration, not the
  amount — a registered seller still issues a tax invoice for a GST-free sale.
  `{{tax}}` also stays populated at zero, unlike `{{discount}}`, so a custom
  template can still print `GST: $0.00` deliberately; the new `{{has_tax}}` flag
  is what the default template gates the row on.

  One consequence to know: a `$0.00` Tax row used to be a visible symptom of a
  misconfigured tax class, and now there is no symptom. Checking the recordings'
  tax class in WooCommerce → Settings → Tax moved from "worth doing" to "the only
  way you will find out".

### Notes
- **The ABN was verified between these two releases.** Checked against the ABR
  register on 9 August 2026: `77 270 249 802` is QLD HISTORY TEACHERS ASSOC INC,
  active since 1 Nov 1999 and GST-registered since 1 Jul 2000. That independently
  confirms the fixed `true` behind `QHTA_INVOICE_GST_REGISTERED`.

  It also raised the open question `QHTA_INVOICE_SELLER` now answers: the
  registered entity name is "QLD History Teachers Assoc Inc" while the invoice
  prints "QHTA". Both an identity and an ABN are required and both are present,
  so the abbreviation is defensible — but the constant is there if the registered
  name is wanted.
- **`.gitattributes` marks `lib/` vendored and generated**, so GitHub keeps 689
  files nobody here wrote out of the language statistics, collapses them in pull
  requests and skips them in blame. Deliberately not `-diff`: a dependency bump
  is the one time somebody needs to read them.
- Nothing in the storage, delivery, access or admin paths changed. An invoice
  generated by 1.0.0 stays valid and is not reissued; the new behaviour applies
  the next time one is generated or regenerated.

## 1.0.0 — 9 August 2026

Initial release. PDF tax invoices for WooCommerce orders on qhta.com.au, built
in-house rather than bought: a third-party invoice plugin was a poor fit on the
PMPro side of this site and would have needed extending anyway, so this mirrors
the pattern already working there — an editable HTML template rendered to PDF —
with `WC_Order` as the subject instead of `MemberOrder`.

### Added
- **Plugin bootstrap**, `QHTA_WOO_INVOICE_VERSION`, one file per concern under
  `includes/`, and a runtime WooCommerce guard with an admin notice. The notice
  is louder than `qhta-membership`'s equivalent on purpose: a deactivated
  dependency there costs convenience, whereas here it means paid orders quietly
  stop producing tax invoices and nobody finds out until a customer asks.
- **Invoice generation** on `woocommerce_order_status_completed`, **priority 5**
  — see the note below on why that number matters. Completed is the right
  trigger for this store because the products are virtual and downloadable, so
  WooCommerce auto-completes them on payment and "completed" and "paid" are the
  same moment. Idempotent by overwrite, which is what makes Regenerate work and
  what lets everything else stop reasoning about staleness.
- **An editable HTML template**, `templates/invoice.html`, overridable at
  `wp-content/uploads/qhta-woo-invoice/invoice.html`. The override wins and
  survives plugin updates, which replace the plugin directory wholesale.
  Rendered with Mustache — the line-item table is a loop and a `str_replace`
  pass cannot express one — and then with Dompdf.
- **The layout**: logo top right, `TAX INVOICE`, seller and ABN, order-number
  invoice #, date, payment method, buyer block, Item/Price table, and
  Subtotal / Tax / Total including GST. **No bank details block** — Stripe has
  already collected the payment, and printing account numbers on a paid receipt
  invites somebody to pay twice.
- **`QHTA_INVOICE_ABN`** in `wp-config.php`, with `77 270 249 802` as the
  fallback. In wp-config rather than in code because it prints on a legal
  document: if the recorded value is ever wrong it needs correcting in one place
  on the server, immediately, without a release. The fallback is a real value
  rather than a blank, because an invoice with an empty ABN line is a broken tax
  invoice while a wrong-looking one is at least visible.
- **`QHTA_INVOICE_GST_REGISTERED`** as an escape hatch, wired up rather than
  merely accepted. QHTA is registered and this stays true; if it ever changed,
  the heading, the Tax row and the total label all follow it. A constant that
  silently did nothing would be worse than no constant.
- **Protected storage** at `wp-content/uploads/qhta-woo-invoice/` with an
  `.htaccess` deny (both Apache 2.2 and 2.4 syntax) and an `index.php`, plus a
  filename hash keyed on the site's `AUTH` salts. The hash is not decoration: on
  nginx `.htaccess` is inert and the deny does nothing at all, and the hash is
  the only thing standing between an order number and a stranger's invoice.
- **An ownership-checked download handler** — the order is yours, or you have
  `manage_options`, and there is no third way. A logged-out request is sent to
  log in and returned afterwards rather than refused.
- **Attachment to the customer completed-order email** only. One document at the
  point the purchase is done. A generation failure drops the attachment and lets
  the email send: losing the invoice is bad, losing the order confirmation is
  worse.
- **A "Download invoice" action** on My Account → Orders, for paid or completed
  orders.
- **Admin View / Download / Regenerate**, both as buttons on the orders list and
  as a "Tax invoice" box on the order edit screen, gated on `manage_options`
  with a nonce on Regenerate. Registered against the legacy `shop_order` screen
  and the HPOS `woocommerce_page_wc-orders` page, so they appear whichever order
  storage the site is on. Mirrors the useful part of the PMPro regenerate
  plugin.
- **HPOS compatibility declared**, because it is true — every order read and
  write goes through the `WC_Order` CRUD, never post meta.
- **Mustache and Dompdf vendored** into `lib/`, no build step, no Composer on
  the server, no dependency on another plugin's renderer.
- **Twelve filters and one action**, so the ABN, seller name, template, logo,
  date format, context, HTML, PDF options, access rule, invoiceability and email
  scope can all move without editing the plugin.
- `scripts/build-zip.sh`, with the same version-agreement guard as
  `qhta-commerce` and `qhta-membership`, plus a check that `lib/`, the template
  and the logo are actually in the tree. A plugin that installs and then fails
  on the first paid order is the wrong way to discover a missing dependency.

### Notes
- **Priority 5 on `woocommerce_order_status_completed` is load-bearing.**
  `WC_Emails::init_transactional_emails()` hooks its emails onto the same action
  at priority 10, so anything later would build the customer's completed-order
  email before the PDF existed and mail it without the attachment. The
  attachment filter regenerates on demand as a safety net, so a mistake here
  degrades to a slower email rather than a missing invoice — but the ordering is
  the intended path and should not be moved.
- **The item price is the line *subtotal*, not the line total, and this is the
  subtlest decision in the plugin.** WooCommerce's `get_subtotal()` is
  pre-discount and ex-tax, while `get_line_total()` is post-discount. Printing
  the line total — the obvious choice — would leave any coupon order with an
  item column that visibly does not sum to the Subtotal row underneath it. Using
  the line subtotal, and adding a Discount row when there is one, makes
  `Subtotal − Discount + Tax = Total` hold on the page with no arithmetic
  invented here.
- **No tax is calculated anywhere in this plugin.** Every figure is read off the
  order as WooCommerce computed it. If a total looks wrong the fix is in
  WooCommerce → Settings → Tax, and confirming the recordings' tax class is part
  of the GST setup rather than part of this release.
- **Money is formatted in the order's own currency**, not the store's current
  setting, so a reprint after a currency change still says what the customer
  paid. `wc_price()` returns markup, so it is stripped and entity-decoded to
  plain text — otherwise every money placeholder would have to be an unescaped
  triple-stache.
- **The billing address is escaped exactly once, and getting there needed a
  decode.** `WC_Countries::get_formatted_address()` runs every replacement
  through `esc_html()` before assembling the lines, so what it returns is
  already escaped. Escaping it again — the obvious thing to do before putting it
  in a template — turns "O'Connell Street" into "O&#039;Connell Street" on the
  printed invoice, and nothing reveals it until somebody with an apostrophe in
  their address buys something. The lines are decoded back to plain text at the
  source and escaped once at output, which also keeps the
  `buyer_address_lines` section correct, since Mustache escapes `{{line}}` on
  its own.
- **The WooCommerce internals this plugin depends on were read, not assumed** —
  hook priorities, the actions-column markup and its CSS, the my-account action
  array shape, `get_subtotal()` vs `get_total_discount()` semantics, and the
  HPOS screen IDs were each checked against WooCommerce 11.0.0's source while
  building. Three of them were wrong as first written. This is the deliberate
  opposite of `qhta-pmpro-invoice-extensions`, whose README has to say that an
  assumption was "inherited from the code, not re-verified".
- **An HTML comment is not a Mustache comment**, and this cost a debugging round
  during the build. Mustache parses the whole file, comments included, so an
  example section tag written inside `<!-- -->` is a real unclosed section and
  the render fails outright. The default template's header comment writes
  placeholder names without braces for exactly this reason, and the README says
  so before anyone edits an override.
- **The download link deliberately carries no nonce.** A nonce guards against a
  request made without the user's intent, which for a read-only download of
  their own document buys nothing — while nonces expire in 12–24 hours, and an
  expired link sitting in My Account reads as a broken invoice. The ownership
  check is the protection. Regenerate changes state and is nonced.
- **Access is not `current_user_can( 'view_order' )`.** That meta capability is
  mapped by WooCommerce and picks up whatever any other plugin has filtered into
  its shop-manager roles — a wider and more moveable surface than an invoice
  carrying a buyer's name, address and purchase history deserves.
- **Dompdf is configured shut**: no remote fetching, no PHP, no JavaScript, only
  `data:` and `file:` URIs, and `file:` chrooted to the plugin and invoice
  directories. The logo is embedded as a base64 data URI precisely so none of
  that is needed. The visible consequence is that a template referencing a
  remote image renders without it, silently — flagged in the README because it
  looks like a bug the first time.
- **Dompdf's font cache is pointed at the uploads directory**, not the bundled
  font directory under `wp-content/plugins`, which is not writable on a properly
  configured host and would be wiped by the next update if it were.
- **PHP 8.0 is the floor, higher than the 7.4 the sibling plugins declare**, and
  it comes from the libraries rather than from preference. Mustache 3 and Dompdf
  3 both require it; Mustache 2 would have kept 7.4 but emits deprecation
  notices on PHP 8.4, and the Dompdf 2 line is a security patch behind.
  WordPress honours `Requires PHP` and refuses activation with a clear message
  rather than fatalling, so declaring the real floor is the fail-safe choice.
- **A Composer autoloader is additive and first registration wins.** If another
  plugin declares Dompdf first, that copy renders and nothing here can prevent
  it. The generator detects the case and logs it, because a silent version
  mismatch is a miserable thing to debug.
- **The PDF is written to a temp name and renamed into place**, so a download
  landing mid-regeneration gets the old file or the new one, never half of one.
  Regenerate deletes before generating so a half-failed render leaves nothing
  rather than the previous invoice, which would look like it had worked.
- **Only line items are itemised.** This store sells virtual downloads with no
  shipping and no fees. If either appears the Subtotal, Tax and Total lines
  still come off the order and stay correct, but the rows will not enumerate
  them. Listed as an open item rather than solved speculatively.
- **The custom order-list buttons ship their own CSS**, and it is two rules
  rather than the dozen the first attempt needed. WooCommerce renders order
  actions as 2em icon squares with the label pushed off-screen and the glyph
  supplied by a per-action `::after`; a custom action has no glyph and renders
  as a blank square. Trying to put the *text* back meant out-specifying four
  chained WooCommerce selectors and an `!important`, all of which would need
  re-tuning whenever those rules moved. Supplying the two missing glyphs
  instead — Dashicons `\f190` and `\f463`, matching the specificity WooCommerce
  uses for its own actions — inherits its font, sizing and positioning for free.
  The label survives as the button's title and aria-label, which
  `wc_render_action_buttons()` sets from the action name.
- **The logo is `assets/logo.png`, taken from the conference plugin's sponsor
  images.** It is the right logo at 200×155, which is small for print. Replacing
  it means editing the file and cutting a release. (1.1.0 removes that
  constraint.)
- **Two things must be confirmed before this is trusted**, neither fixable in
  code: the ABN in `QHTA_INVOICE_ABN` is correct, and the recordings' tax class
  in WooCommerce → Settings → Tax produces the intended GST treatment. (The ABN
  was checked shortly after this release — see 1.1.0.)
- **Credit notes on refund are deliberately not in 1.0.** Refunds already revoke
  access through `qhta-commerce`'s gate, so omitting them leaves nothing
  dangling.
