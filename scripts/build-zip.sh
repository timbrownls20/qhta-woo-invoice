#!/usr/bin/env bash
#
# Produce the deploy zip for wp-admin -> Plugins -> Add New -> Upload Plugin.
#
# Reads the version out of the plugin header rather than taking it as an
# argument, and refuses to build unless the header and QHTA_WOO_INVOICE_VERSION
# agree. Same guard as qhta-commerce, qhta-membership and qhta-theme-extras: a
# mismatch means the two sources of truth for "what version is live" have
# drifted.
#
# Usage: ./scripts/build-zip.sh

set -euo pipefail

PLUGIN_SLUG="qhta-woo-invoice"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"
BOOTSTRAP="$PLUGIN_DIR/$PLUGIN_SLUG.php"

# Version: 1.0.0  ->  1.0.0
header_version="$(sed -n "s/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p" "$BOOTSTRAP")"

# define( 'QHTA_WOO_INVOICE_VERSION', '1.0.0' );  ->  1.0.0
const_version="$(sed -n "s/^define([[:space:]]*'QHTA_WOO_INVOICE_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" "$BOOTSTRAP")"

if [[ -z "$header_version" || -z "$const_version" ]]; then
	echo "error: could not read the version from $BOOTSTRAP" >&2
	echo "       header='$header_version' constant='$const_version'" >&2
	exit 1
fi

if [[ "$header_version" != "$const_version" ]]; then
	echo "error: version mismatch — bump both before deploying." >&2
	echo "       plugin header:              $header_version" >&2
	echo "       QHTA_WOO_INVOICE_VERSION:   $const_version" >&2
	exit 1
fi

VERSION="$header_version"
ZIP_PATH="$PLUGIN_DIR/$PLUGIN_SLUG-$VERSION.zip"

# The libraries are the plugin's whole PDF capability and are not fetched at
# deploy time. If lib/ is missing, the zip would install and then fail on the
# first paid order, which is exactly the wrong moment to find out.
for required in \
	"lib/autoload.php" \
	"lib/dompdf/dompdf/src/Dompdf.php" \
	"lib/mustache/mustache/src/Engine.php" \
	"templates/invoice.html" \
	"assets/logo.png"
do
	if [[ ! -e "$PLUGIN_DIR/$required" ]]; then
		echo "error: $required is missing — the build would ship a plugin that cannot render." >&2
		exit 1
	fi
done

# Syntax-check the plugin's own PHP so a typo cannot reach the live site. The
# vendored libraries are excluded: they are released code, there are hundreds of
# files, and this plugin declares PHP 8.0 while some of them carry 8.1+ syntax
# behind version guards that php -l does not understand.
if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' php_file; do
		php -l "$php_file" >/dev/null
	done < <(find "$PLUGIN_DIR" -name '*.php' -not -path '*/.git/*' -not -path '*/lib/*' -print0)
else
	echo "note: php not on PATH, skipping syntax check" >&2
fi

rm -f "$ZIP_PATH"

# WordPress needs the plugin folder as the top level inside the archive, so zip
# has to run from the parent. The output lands in the plugin root, which is
# inside the tree being zipped — build to a temp file and move it in afterwards
# so the archive cannot swallow itself.
staging_dir="$(mktemp -d)"
trap 'rm -rf "$staging_dir"' EXIT
staging_zip="$staging_dir/$PLUGIN_SLUG-$VERSION.zip"

# Excludes: editor cruft, git metadata, local Claude settings (permission
# allowlist, not for the web server), previous builds, the handover notes, this
# build tooling, and the composer manifest — lib/ is committed and composer is
# never run on the server.
cd "$PARENT_DIR"
zip -rq "$staging_zip" "$PLUGIN_SLUG" \
	-x "*.DS_Store" \
	   "*.git*" \
	   "*.claude*" \
	   "*.zip" \
	   "qhta-woo-invoice/HEALTHCHECK.md" \
	   "$PLUGIN_SLUG/scripts/*" \
	   "$PLUGIN_SLUG/composer.json" \
	   "$PLUGIN_SLUG/composer.lock" \
	   "$PLUGIN_SLUG/$PLUGIN_SLUG-handover.md"

mv "$staging_zip" "$ZIP_PATH"

size_mb="$(du -m "$ZIP_PATH" | cut -f1)"

echo "built $ZIP_PATH (${VERSION}, ${size_mb}MB)"

cat <<EOF

Next:
  1. Check the host's upload limit first. This zip is ${size_mb}MB — Dompdf
     bundles the DejaVu font family and that is most of it. If wp-admin refuses
     the upload, put the folder in wp-content/plugins/ over SFTP instead.
  2. wp-admin -> Plugins -> Add New -> Upload Plugin -> replace -> activate
  3. wp-config.php:
       define( 'QHTA_INVOICE_ABN', '77 270 249 802' );
       define( 'QHTA_INVOICE_SELLER', 'QHTA' );
       // optional — upload a print-resolution logo and point at it:
       // define( 'QHTA_INVOICE_LOGO', 'branding/qhta-logo.png' );
     The ABN was checked against the ABR register on 9 Aug 2026: it is
     QLD HISTORY TEACHERS ASSOC INC, active, GST-registered since 2000.
     Re-check it if the entity is ever restructured — it prints on a legal
     document and the plugin has no way to know it has gone stale.
     Decide whether the seller line should read "QHTA" or the registered name
     before the first invoice goes out; changing it later reissues nothing.
     A constant that cannot be used is ignored and logged, and the built-in
     value stays — so a typo in QHTA_INVOICE_LOGO looks like "the logo did not
     change", not like a broken invoice. Check the log if it did not.
  4. WooCommerce -> Settings -> Tax: confirm the recordings' tax class produces
     the GST treatment you expect. This plugin renders whatever the order says;
     it never computes tax.
  5. hPanel -> Websites -> qhta.com.au -> Advanced -> Cache Manager -> Purge All
  6. Smoke test, in this order:
     a. place a real order (or complete an existing paid one) -> a PDF appears
        in wp-content/uploads/qhta-woo-invoice/
     b. the customer's completed-order email has the PDF attached
     c. the PDF shows the logo, TAX INVOICE, the ABN, the order number as the
        invoice number, the buyer block, the item rows, and
        Subtotal / Tax / Total including GST — and NO bank details
     d. Subtotal + Tax = Total, and the figures match the order screen exactly
     e. My Account -> Orders -> "Download invoice" works for that customer
     f. log in as a DIFFERENT customer and hit the same URL -> refused
     g. log out and hit it -> sent to log in, and it works after logging in
     h. admin order screen -> View / Download / Regenerate all work
     h2. set QHTA_INVOICE_SELLER and QHTA_INVOICE_LOGO, Regenerate -> the
         seller line and the logo both change
     i. copy templates/invoice.html to
        wp-content/uploads/qhta-woo-invoice/invoice.html, change something
        visible, Regenerate -> the change shows
     j. try to fetch the PDF directly at its uploads URL -> denied on Apache.
        On nginx the deny does nothing, which is why the filename carries a
        hash — check the URL is not guessable rather than that it is blocked.
EOF
