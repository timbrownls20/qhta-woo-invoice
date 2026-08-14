# Healthcheck note — keep qhta-woo-invoice's canaries current

**Standing rule.** When this plugin is created or changed in a way that adds or alters an external
dependency (a WooCommerce function/class/hook, an order meta key, a bundled library, a template path, or the invoice directory layout), update its **qhta-healthcheck** canaries in the *same* change.

The canaries live **in this repository**, in `includes/healthcheck.php`, registered on qhta-healthcheck's
`qhta_healthcheck_checks` filter. That is the whole point: changing the dependency and changing the
canary for it are the same diff, in the same review, deployed together. There is no central copy to
keep in step — `qhta-healthcheck/includes/checks.php` deliberately holds none.

A new dependency with no canary is the silent-failure risk qhta-healthcheck exists to catch.

## How it behaves

- Nothing runs unless **qhta-healthcheck** is installed. `add_filter()` on a hook nobody applies
  costs nothing, so this file is inert without it.
- The assertion helpers (`qhta_healthcheck_assert_*`) belong to qhta-healthcheck and are only ever
  called from inside its runner, which wraps every check in a try/catch. An older qhta-healthcheck
  missing one shows up as a failed check, never a fatal.
- Until this plugin is deployed at **1.2.0** or later, qhta-healthcheck reports it amber —
  *"no canaries defined"*. That is expected during the rollout, not a bug: each plugin joins on its
  own next deploy, no coordinated release needed.

## This plugin's canaries (10)

| Canary | Sev | Watches |
|---|---|---|
| WooCommerce order API | critical | `WC_Order` |
| Dompdf and Mustache loadable | critical | the bundled `lib/` actually deployed |
| Invoice template readable | critical | resolved via `qhta_woo_invoice_template()`; reports which copy is in use |
| Invoice directory exists and is protected | critical | directory + `.htaccess` + `index.php` + writable |
| `woocommerce_email_attachments` | critical | the only path by which a customer gets an invoice unprompted |
| Generate on order completion | critical | `woocommerce_order_status_completed` priority 5 |
| Invoice download handler | critical | `admin_post_` and `admin_post_nopriv_` |
| HPOS compatibility declared | warning | `Automattic\WooCommerce\Utilities\FeaturesUtil` |
| Recent orders carry an invoice | warning | the invoice meta key, read HPOS-safely via `wc_get_orders()` |
| Invoice buttons on order lists | warning | My Account + admin order actions |

**The registration is deliberately outside `qhta_woo_invoice_bootstrap()`.** The feature files only
load when WooCommerce is present; the canaries must not, because "WooCommerce is missing" is the most
important thing this plugin has to report. Registering them inside the WooCommerce guard would mean
that in exactly that case none exist and the board shows "no canaries defined" instead of a red line
naming the cause. The three checks that need the feature files skip themselves when those are absent.

"Recent orders carry an invoice" is the end-to-end canary: it proves invoices are actually being
produced, not merely that every part looks present.

## More
- Full rule, rationale and the per-plugin canary list: `qhta-healthcheck/qhta-healthcheck-handover.md`.
- qhta-healthcheck covers internal correctness only; site-up liveness is the external "QHTA site guardian" HTTP task. Keep both.
