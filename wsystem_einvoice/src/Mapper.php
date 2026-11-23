<?php declare(strict_types=1);

namespace Plugin\wsystem_einvoice;

/**
 * Maps a normalised order array (see OrderAdapter) to the body of POST /einvoice/create.
 * Pure PHP, no JTL classes, so it is unit tested without a shop.
 */
final class Mapper
{
    public const EU = ['AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'];

    private const COUNTRY_NAMES = [
        'DEUTSCHLAND' => 'DE', 'GERMANY' => 'DE', 'ÖSTERREICH' => 'AT', 'OESTERREICH' => 'AT', 'AUSTRIA' => 'AT',
        'SCHWEIZ' => 'CH', 'SWITZERLAND' => 'CH', 'NIEDERLANDE' => 'NL', 'NETHERLANDS' => 'NL', 'BELGIEN' => 'BE',
        'BELGIUM' => 'BE', 'FRANKREICH' => 'FR', 'FRANCE' => 'FR', 'ITALIEN' => 'IT', 'ITALY' => 'IT',
        'SPANIEN' => 'ES', 'SPAIN' => 'ES', 'POLEN' => 'PL', 'POLAND' => 'PL', 'LUXEMBURG' => 'LU',
        'DÄNEMARK' => 'DK', 'DENMARK' => 'DK', 'TSCHECHIEN' => 'CZ', 'GROSSBRITANNIEN' => 'GB', 'UNITED KINGDOM' => 'GB',
    ];

    public static function country(string $v): string
    {
        $v = trim($v);
        if (strlen($v) === 2) {
            return strtoupper($v);
        }
        return self::COUNTRY_NAMES[mb_strtoupper($v)] ?? strtoupper(substr($v, 0, 2));
    }

    public static function vatId(string $v): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]/', '', $v));
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $cfg */
    public static function isB2b(array $order): bool
    {
        $b = $order['billing'];
        return trim((string) ($b['company'] ?? '')) !== '' || self::vatId((string) ($b['vat'] ?? '')) !== '';
    }

    /** @return array<string,mixed> */
    private static function line(string $desc, float $qty, float $net, string $cat, float $rate, string $sku = ''): array
    {
        $net = round($net, 2);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        if ($net < 0) {
            $qty = -$qty;
        }
        $price = round($net / $qty, 6);
        if (abs(round($price * $qty, 2) - $net) > 0.001) {
            $desc = rtrim(rtrim(number_format(abs($qty), 4, '.', ''), '0'), '.') . ' x ' . $desc;
            $qty = $net < 0 ? -1.0 : 1.0;
            $price = abs($net);
        }
        $l = [
            'description' => trim(strip_tags(html_entity_decode($desc, ENT_QUOTES, 'UTF-8'))),
            'quantity' => $qty,
            'unit' => 'C62',
            'unit_price' => $price,
            'vat_rate' => $rate,
            'vat_category' => $cat,
        ];
        if ($sku !== '') {
            $l['seller_item_id'] = $sku;
        }
        return $l;
    }

    /** Format for new documents: the configured one, or zugferd when the option is on (never for Peppol BIS). @param array<string,mixed> $cfg */
    public static function formatFor(array $cfg): string
    {
        $f = (string) ($cfg['format'] ?? '') !== '' ? (string) $cfg['format'] : 'xrechnung';
        return ($cfg['zugferd'] ?? 'N') === 'Y' && $f !== 'peppol-bis3' ? 'zugferd' : $f;
    }

    /**
     * Credit note (type 381) for an order, whole or only $refundLines (same line shape as $order['lines']).
     * Amounts stay positive; references the original invoice (BT-25 / BT-26).
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $cfg
     * @param array<int,array<string,mixed>>|null $refundLines
     * @return array<string,mixed>
     */
    public static function creditNote(array $order, array $cfg, string $key, string $precedingNumber, string $precedingDate, ?array $refundLines = null): array
    {
        if ($refundLines !== null) {
            $order['lines'] = $refundLines;
        }
        $body = self::invoice($order, $cfg);
        unset($body['prepaid_amount']);
        $suffix = substr((string) preg_replace('/[^A-Za-z0-9]/', '', $key), -8) ?: '1';
        $body['invoice_number'] = 'CN-' . (string) $order['number'] . '-' . $suffix;
        $body['type_code'] = '381';
        $body['preceding_invoice'] = $precedingNumber;
        $body['preceding_invoice_date'] = $precedingDate;
        $body['delivery_date'] = $body['issue_date'];
        $iban = (string) preg_replace('/\s/', '', (string) ($cfg['sellerIban'] ?? ''));
        $terms = 'Refund to the original payment method';
        $body['payment'] = $iban !== ''
            ? ['means_code' => '58', 'iban' => $iban, 'account_name' => (string) ($cfg['sellerName'] ?? ''), 'reference' => $body['invoice_number'], 'terms' => $terms]
            : ['means_code' => '1', 'reference' => $body['invoice_number'], 'terms' => $terms];
        return $body;
    }

    /**
     * @param array<string,mixed> $order Normalised order.
     * @param array<string,mixed> $cfg   Plugin settings.
     * @return array<string,mixed>
     */
    public static function invoice(array $order, array $cfg, string $format = ''): array
    {
        $sellerCountry = strtoupper((string) ($cfg['sellerCountry'] ?? 'DE'));
        $b = $order['billing'];
        $buyerCountry = self::country((string) ($b['country'] ?? ''));
        $buyerVat = self::vatId((string) ($b['vat'] ?? ''));
        $small = ($cfg['smallBusiness'] ?? 'N') === 'Y';

        $zero = 'Z';
        if ($small) {
            $zero = 'E';
        } elseif ($buyerCountry !== '' && $buyerCountry !== $sellerCountry && !in_array($buyerCountry, self::EU, true)) {
            $zero = 'G';
        } elseif ($buyerVat !== '' && $buyerCountry !== $sellerCountry && in_array($buyerCountry, self::EU, true)) {
            $zero = 'AE';
        }

        $lines = [];
        foreach ($order['lines'] as $p) {
            $qty = (float) $p['qty'];
            $net = round($qty * (float) $p['unit_net'], 2);
            if ($net == 0.0 && ($p['type'] ?? '') !== 'item') {
                continue;
            }
            $rate = (float) $p['vat_rate'];
            $cat = $rate > 0 ? 'S' : $zero;
            if ($small) {
                $cat = 'E';
                $rate = 0.0;
            }
            $lines[] = self::line((string) $p['name'], ($p['type'] ?? '') === 'item' ? $qty : 1.0, $net, $cat, $rate, (string) ($p['sku'] ?? ''));
        }

        $name = trim(trim((string) ($b['first'] ?? '')) . ' ' . trim((string) ($b['last'] ?? '')));
        $company = trim((string) ($b['company'] ?? ''));
        $buyer = [
            'name' => $company !== '' ? $company : $name,
            'street' => trim((string) ($b['street'] ?? '')),
            'city' => (string) ($b['city'] ?? ''),
            'postal_code' => (string) ($b['zip'] ?? ''),
            'country' => $buyerCountry,
            'email' => (string) ($b['email'] ?? ''),
        ];
        if ($company !== '' && $name !== '') {
            $buyer['contact_name'] = $name;
        }
        if ($buyerVat !== '') {
            $buyer['vat_id'] = $buyerVat;
        }

        $seller = [
            'name' => (string) ($cfg['sellerName'] ?? ''),
            'street' => (string) ($cfg['sellerStreet'] ?? ''),
            'city' => (string) ($cfg['sellerCity'] ?? ''),
            'postal_code' => (string) ($cfg['sellerPostcode'] ?? ''),
            'country' => $sellerCountry,
            'email' => (string) ($cfg['sellerEmail'] ?? ''),
            'phone' => (string) ($cfg['sellerPhone'] ?? ''),
            'contact_name' => (string) ($cfg['sellerContact'] ?? ''),
        ];
        if (($cfg['sellerVatId'] ?? '') !== '') {
            $seller['vat_id'] = self::vatId((string) $cfg['sellerVatId']);
        }
        if (($cfg['sellerTaxNo'] ?? '') !== '') {
            $seller['tax_number'] = (string) $cfg['sellerTaxNo'];
        }
        if (($cfg['sellerRegNo'] ?? '') !== '') {
            $seller['company_id'] = (string) $cfg['sellerRegNo'];
        }
        $seller = array_filter($seller, static fn ($v) => $v !== '');

        $paid = (bool) $order['paid'];
        $method = strtolower((string) ($order['payment_method'] ?? ''));
        $iban = (string) preg_replace('/\s/', '', (string) ($cfg['sellerIban'] ?? ''));
        $payment = [];
        if (preg_match('/vorkasse|ueberweisung|überweisung|bank|sepa|rechnung/', $method) || (!$paid && $iban !== '')) {
            $payment['means_code'] = '58';
        } elseif (preg_match('/nachnahme|cod/', $method)) {
            $payment['means_code'] = '10';
        } elseif ($paid) {
            $payment['means_code'] = '68';
        } else {
            $payment['means_code'] = '1';
        }
        if ($iban !== '' && $payment['means_code'] === '58') {
            $payment['iban'] = $iban;
            if (($cfg['sellerBic'] ?? '') !== '') {
                $payment['bic'] = (string) $cfg['sellerBic'];
            }
            $payment['account_name'] = (string) ($cfg['sellerName'] ?? '');
        }
        $number = (string) ($order['invoice_number'] ?? '') !== '' ? (string) $order['invoice_number'] : (string) $order['number'];
        $payment['reference'] = $number;
        $terms = (string) ($cfg['paymentTerms'] ?? '');
        if ($paid) {
            $terms = trim('Paid via ' . (string) ($order['payment_name'] ?? '') . ' ' . (string) ($order['paid_date'] ?? ''));
        }
        if ($terms !== '') {
            $payment['terms'] = $terms;
        }

        $buyerRef = (string) ($order['buyer_reference'] ?? '');
        if ($buyerRef === '') {
            $buyerRef = (string) ($cfg['leitwegId'] ?? '');
        }
        if ($buyerRef === '') {
            $buyerRef = (string) $order['number'];
        }
        $today = (string) ($order['today'] ?? date('Y-m-d'));
        $body = [
            'format' => $format !== '' ? $format : self::formatFor($cfg),
            'invoice_number' => $number,
            'issue_date' => $today,
            'delivery_date' => (string) ($order['delivery_date'] ?? '') !== '' ? (string) $order['delivery_date'] : $today,
            'currency' => (string) ($order['currency'] ?? 'EUR'),
            'buyer_reference' => $buyerRef,
            'order_reference' => (string) $order['number'],
            'seller' => $seller,
            'buyer' => array_filter($buyer, static fn ($v) => $v !== ''),
            'lines' => $lines,
            'payment' => $payment,
        ];
        if ($paid) {
            $body['prepaid_amount'] = round((float) $order['total'], 2);
        }
        if ($small) {
            $body['vat_exemption_reason'] = ['E' => 'No VAT charged under the small business scheme (section 19 UStG).'];
        }
        return $body;
    }
}
