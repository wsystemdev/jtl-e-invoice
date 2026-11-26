# W System E-Invoice for JTL-Shop 5

Create, validate and send **XRechnung 3.0 / EN 16931 / Peppol BIS Billing 3.0** e-invoices from JTL-Shop orders, and check received supplier invoices, with the [W System](https://wsystem.dev/jtl-xrechnung) API.

Deutsch: siehe unten.

## What it adds on top of JTL-Wawi's own XRechnung export

JTL-Wawi (1.9.x) already exports XRechnung 3.0.x and ZUGFeRD from its own invoice templates. This plugin does not replace that. It covers what the native export does not:

- **Validation on every invoice** against the XML Schema, CEN EN 16931 Schematron and KoSIT XRechnung rules, with a plain-language fix per failed rule.
- **Peppol sending** (sandbox to test, live when your account is set up), with VAT ID (VIES), Peppol reachability and sanctions checks before each send.
- **Counterparty checks on received invoices** (`POST /einvoice/check`): paste or upload a supplier XML in the admin and get a verdict (rules, VIES name match, sanctions, totals, IBAN, duplicates, bank-detail changes).
- Works from **JTL-Shop orders** (Hook 181, status pushed by JTL-Wawi), no template editing.

## New in 0.2.1
- Gross prices (customer groups with prices incl. VAT): the e-invoice nets per VAT rate now match JTL's own net (sum of the rounded position nets; VAT = sum of the rounded position gross minus that net), so the e-invoice total equals the order total (`fGesamtsumme`) instead of being off by a cent. Where no net amount can reach JTL's VAT (e.g. one item at 9.99 incl. 19 %), JTL's VAT per rate is sent as `vat_amounts`; the W System API uses it once its vat_amounts support is live and ignores it until then.
- If one rate's gross cannot be reached by any net (e.g. 3 × 9.99 incl. 19 %), another rate's net moves by 1–2 cents so the total also matches on API versions without vat_amounts support.
- Net-priced (B2B) orders are unchanged.

## New in 0.2.0 (both options OFF by default)

- **Credit notes** (EN 16931 type 381, `preceding_invoice` and `preceding_invoice_date` of the original e-invoice): created automatically when JTL-Wawi pushes the status "cancelled" to the shop (Hook 181, status -1), or manually for returns from the "E-Invoices" tab ("Create credit note"). Only for orders that already have a valid e-invoice; one whole-order credit note per order. Partial returns are supported by the service and API call, but the shop has no returns hook in this plugin yet, so they are not triggered automatically.
- **ZUGFeRD / Factur-X PDF** (EN 16931, PDF/A-3b with the XML embedded) instead of XML only, downloadable from the "E-Invoices" tab. JTL-Wawi has its own ZUGFeRD export: switch this on only if you do not send Wawi's ZUGFeRD to the same customers, otherwise each order gets two invoices.
- Credit notes follow the format setting (a ZUGFeRD PDF credit note when ZUGFeRD is on).

## Requirements

JTL-Shop 5.2.0+, PHP 8.1+, PHP curl extension. No account needed (20 invoices/day without key); free for 50 invoices a month with a free API key at https://wsystem.dev/account, then from €12 (https://wsystem.dev/online-shops).

## Install

1. Download `wsystem_einvoice.zip` from the latest release.
2. JTL-Shop admin: Plugins > Plugin-Manager > Verfügbar > upload the ZIP, then activate it.
3. Plugins > Installed > W System E-Invoice > Settings: company data (XRechnung needs contact name, phone, email), format, trigger.
4. Ship a test order, or use the "E-Invoices" tab to create one by order number.

## Data sent

On creation the order's invoice data goes to `https://api.wsystem.dev/einvoice/create` (seller details, customer name/company/address/email/VAT ID, lines, amounts, payment method). Peppol sending uses `/einvoice/send`; the received-invoice check uses `/einvoice/check`. Nothing is sent on activation. [Terms](https://wsystem.dev/terms), [privacy](https://wsystem.dev/privacy).

## Status

Version 0.2.0. **Credit notes and ZUGFeRD are only unit-tested** (no JTL-Shop was available): the mapping, the stored records, the API request shape (checked against the real W System API logic) and the on/off behaviour are tested with `php tests/run.php`; the Hook 181 status -1 handling, the admin tab buttons and downloads, and the settings screen have never run inside a real JTL-Shop. Version 0.1.0 was likewise unit-tested only. The order mapping and API logic are unit tested (`php tests/run.php`) and the request shape is the same as the W System WooCommerce plugin. It has been written against the JTL-Shop 5 plugin API and the `Bestellung` object, but not yet run inside a live JTL-Shop: please report problems. Not in this version: attaching the XML to JTL-Shop emails, ZUGFeRD PDF output, credit notes.

## Deutsch

Erzeugt, prüft und versendet XRechnung 3.0 / EN 16931 / Peppol-BIS-E-Rechnungen aus JTL-Shop-Bestellungen und prüft empfangene Lieferantenrechnungen. JTL-Wawi exportiert XRechnung und ZUGFeRD bereits selbst; das Plugin ergänzt Prüfung bei jeder Rechnung, Peppol-Versand und Gegenpartei-Prüfung. Installation: ZIP im Plugin-Manager hochladen, aktivieren, Firmendaten eintragen.

MIT license.
