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

    /**
     * 'gross' when the shop priced this order incl. VAT (JTL customer group with gross prices): the order total fGesamtsumme equals the
     * sum of the rounded gross positions. Then JTL's VAT per rate is computed on the summed gross, not on the summed net.
     * @param array<string,mixed> $order
     */
    public static function priceMode(array $order): string
    {
        $g = 0.0;
        $n = [];
        foreach ($order['lines'] as $p) {
            $r = (float) $p['vat_rate'];
            $g += round((float) $p['qty'] * (float) $p['unit_net'] * (1 + $r / 100), 2);
            $n[(string) $r] = ($n[(string) $r] ?? 0.0) + round((float) $p['qty'] * (float) $p['unit_net'], 2);
        }
        $net = 0.0;
        foreach ($n as $r => $v) {
            $net += round($v, 2) + round($v * (float) $r / 100, 2);
        }
        $t = round((float) ($order['total'] ?? 0), 2);
        return abs(round($g, 2) - $t) < 0.005 && abs(round($net, 2) - $t) >= 0.005 ? 'gross' : 'net';
    }

    /**
     * Gross prices (BET3 / PC-4 / PC-5, "JTL rounding"; same rules as the Shopware plugin's reconcile()). JTL rounds net and gross per
     * position and shows, per rate, net = sum of position nets, VAT = sum of position gross - that net. If base x rate misses that VAT, first try a net within 3 cents that reaches the same gross (works with every API version). Second pass: if a
     * rate's gross cannot be reached by any net (e.g. 3 x 9.99 incl. 19 %), shift another rate's net by 1-2 cents so the e-invoice total
     * equals fGesamtsumme on today's API. vat_amounts then carries, per rate, JTL's gross minus the net sent wherever base x rate misses
     * it and the difference is within the API's per-line rounding bound (0.005 per line, min 0.01, max 0.99); API versions without
     * vat_amounts support ignore the field.
     * @param list<array<string,mixed>> $raw
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private static function reconcileGross(array $raw): array
    {
        $groups = [];
        foreach ($raw as $i => $l) {
            if ($l['cat'] === 'S') {
                $groups[(string) $l['rate']][] = $i;
            }
        }
        $gross = [];
        $target = 0.0;
        foreach ($raw as $l) {
            $target += $l['cat'] === 'S' ? $l['gross'] : $l['net'];
        }
        $target = round($target, 2);
        foreach ($groups as $rate => $idx) {
            $r = (float) $rate;
            $g = self::gsum($raw, $idx, 'gross');
            $gross[$rate] = $g;
            $want = self::gsum($raw, $idx, 'net');
            $last = self::lastOf($raw, $idx);
            if (abs(round($want + round($want * $r / 100, 2), 2) - $g) < 0.001) {
                continue;
            }
            $best = null;
            for ($d = -3; $d <= 3; ++$d) {
                $n = round($want + $d / 100, 2);
                if (abs(round($n + round($n * $r / 100, 2), 2) - $g) < 0.001 && ($best === null || abs($d) < abs($best))) {
                    $best = $d;
                }
            }
            if ($best !== null) {
                $raw[$last]['net'] = round($raw[$last]['net'] + $best / 100, 2);
            }
        }
        if (abs(self::apiTotal($raw) - $target) > 0.001) {
            foreach ([1, -1, 2, -2] as $d) {
                foreach ($groups as $idx) {
                    $k = self::lastOf($raw, $idx);
                    $try = $raw;
                    $try[$k]['net'] = round($try[$k]['net'] + $d / 100, 2);
                    if (abs(self::apiTotal($try) - $target) < 0.001) {
                        $raw = $try;
                        break 2;
                    }
                }
            }
        }
        $va = [];
        $tol = min(0.99, max(0.01, round(0.005 * count($raw), 2)));
        foreach ($groups as $rate => $idx) {
            $net = self::gsum($raw, $idx, 'net');
            $charged = round($gross[$rate] - $net, 2);
            $calc = round($net * (float) $rate / 100, 2);
            if (abs($charged - $calc) > 0.001 && abs($charged - $calc) <= $tol + 0.0001) {
                $va[] = ['vat_category' => 'S', 'vat_rate' => (float) $rate, 'tax_amount' => $charged];
            }
        }
        return [$raw, $va];
    }

    /** @param list<array<string,mixed>> $raw @param list<int> $idx */
    private static function gsum(array $raw, array $idx, string $f): float
    {
        $t = 0.0;
        foreach ($idx as $i) {
            $t += $raw[$i][$f];
        }
        return round($t, 2);
    }

    /** Position that absorbs a cent shift: the last quantity-1 position of the rate (keeps unit prices exact), else the last one. @param list<array<string,mixed>> $raw @param list<int> $idx */
    private static function lastOf(array $raw, array $idx): int
    {
        $k = end($idx);
        foreach ($idx as $i) {
            if (abs((float) $raw[$i]['qty']) == 1.0) {
                $k = $i;
            }
        }
        return $k;
    }

    /** Total the API computes: line nets rounded to cents, VAT per category and rate on the summed net. @param list<array<string,mixed>> $raw */
    private static function apiTotal(array $raw): float
    {
        $by = [];
        foreach ($raw as $l) {
            $k = $l['cat'] . '|' . ($l['cat'] === 'S' ? $l['rate'] : 0);
            $by[$k] = ($by[$k] ?? 0.0) + round($l['net'], 2);
        }
        $t = 0.0;
        foreach ($by as $k => $net) {
            $net = round($net, 2);
            $t += $net + round($net * (float) explode('|', (string) $k)[1] / 100, 2);
        }
        return round($t, 2);
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
            // PC-7: priceMode must run on the refunded positions and their total, not the full order.
            // (A mixed-rate partial refund of a gross-priced order is classified gross only against the
            // refund total; against the original fGesamtsumme it looks like a net order and skips reconcile.)
            $t = 0.0;
            foreach ($refundLines as $p) {
                $t += round(round((float) $p['unit_net'] * (1 + (float) $p['vat_rate'] / 100), 2) * (float) $p['qty'], 2);
            }
            $order['total'] = round($t, 2);
            unset($order['price_mode']);
        }
        $order['price_mode'] = $order['price_mode'] ?? self::priceMode($order);
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

        $raw = [];
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
            $raw[] = ['name' => (string) $p['name'], 'qty' => ($p['type'] ?? '') === 'item' ? $qty : 1.0, 'net' => $net, 'cat' => $cat, 'rate' => $rate, 'sku' => (string) ($p['sku'] ?? ''),
                'gross' => round($qty * (float) $p['unit_net'] * (1 + $rate / 100), 2)];
        }
        $vatAmounts = [];
        if (!$small && ($order['price_mode'] ?? self::priceMode($order)) === 'gross') {
            [$raw, $vatAmounts] = self::reconcileGross($raw);
        }
        $lines = [];
        foreach ($raw as $r) {
            $lines[] = self::line($r['name'], $r['qty'], $r['net'], $r['cat'], $r['rate'], $r['sku']);
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
        if ($vatAmounts !== []) {
            $body['vat_amounts'] = $vatAmounts;
        }
        if ($paid) {
            $body['prepaid_amount'] = round((float) $order['total'], 2);
        }
        if ($small) {
            $body['vat_exemption_reason'] = ['E' => 'No VAT charged under the small business scheme (section 19 UStG).'];
        }
        return $body;
    }
}
