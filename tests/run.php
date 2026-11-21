<?php declare(strict_types=1);
require __DIR__ . '/../wsystem_einvoice/autoload.php';
use Plugin\wsystem_einvoice\{Api, ArrayStore, Mapper, OrderAdapter, Service};

$fail = 0; $n = 0;
function ok(bool $c, string $m): void { global $fail, $n; $n++; if (!$c) { $fail++; echo "FAIL: $m\n"; } }

$cfg = ['sellerName' => 'Muster Shop', 'sellerStreet' => 'Hauptstr. 1', 'sellerCity' => 'Berlin', 'sellerPostcode' => '10115', 'sellerCountry' => 'DE',
    'sellerEmail' => 'a@example.com', 'sellerPhone' => '+4930123456', 'sellerContact' => 'Erika Muster', 'sellerVatId' => 'DE 123 456 789',
    'sellerIban' => 'DE02 1203 0000 0000 2020 51', 'format' => 'xrechnung', 'trigger' => 'on_shipped', 'b2bOnly' => 'Y', 'peppolMode' => 'sandbox'];
function bestellung(array $over = []): object {
    $b = (object) ['cFirma' => 'Kunde GmbH', 'cVorname' => 'Max', 'cNachname' => 'Kunde', 'cStrasse' => 'Weg', 'cHausnummer' => '5', 'cPLZ' => '80331', 'cOrt' => 'München', 'cLand' => 'DE', 'cMail' => 'k@example.com', 'cUSTID' => 'DE987654321'];
    $o = (object) ['kBestellung' => 7, 'cBestellNr' => 'AU-2026-7', 'dErstellt' => '2026-11-20 10:00:00', 'dBezahltDatum' => '0000-00-00', 'cBezahlt' => 'N',
        'cZahlungsartName' => 'Vorkasse', 'fGesamtsumme' => 130.9, 'Waehrung' => (object) ['cISO' => 'EUR'], 'oRechnungsadresse' => $b,
        'Positionen' => [
            (object) ['nPosTyp' => 1, 'cName' => 'Widget', 'cArtNr' => 'W-1', 'nAnzahl' => 3, 'fPreisEinzelNetto' => 33.333333, 'fMwSt' => 19],
            (object) ['nPosTyp' => 2, 'cName' => 'Versand', 'cArtNr' => '', 'nAnzahl' => 1, 'fPreisEinzelNetto' => 5.0, 'fMwSt' => 19],
            (object) ['nPosTyp' => 3, 'cName' => 'Kupon', 'cArtNr' => '', 'nAnzahl' => 1, 'fPreisEinzelNetto' => -5.0, 'fMwSt' => 19],
        ]];
    foreach ($over as $k => $v) { $o->$k = $v; }
    return $o;
}
$order = OrderAdapter::fromBestellung(bestellung());
ok($order['number'] === 'AU-2026-7' && count($order['lines']) === 3, 'adapter basics');
$body = Mapper::invoice($order + ['today' => '2026-11-21'], $cfg);
ok($body['format'] === 'xrechnung' && $body['currency'] === 'EUR', 'format/currency');
ok($body['seller']['vat_id'] === 'DE123456789', 'seller vat normalised');
ok($body['buyer']['vat_id'] === 'DE987654321' && $body['buyer']['street'] === 'Weg 5', 'buyer');
ok($body['lines'][0]['vat_category'] === 'S' && $body['lines'][0]['vat_rate'] == 19.0, 'S category');
ok($body['lines'][0]['seller_item_id'] === 'W-1', 'sku');
ok($body['lines'][2]['quantity'] < 0 || $body['lines'][2]['unit_price'] > 0, 'discount line negative');
$sum = 0; foreach ($body['lines'] as $l) { $sum += round($l['quantity'] * $l['unit_price'], 2); }
ok(abs($sum - 100.0) < 0.011, 'net sum 100.00 got ' . $sum);
ok($body['payment']['means_code'] === '58' && $body['payment']['iban'] === 'DE02120300000000202051', 'bank transfer');
ok($body['buyer_reference'] === 'AU-2026-7', 'buyer reference fallback');
ok(!isset($body['prepaid_amount']), 'unpaid no prepaid');

$paid = OrderAdapter::fromBestellung(bestellung(['dBezahltDatum' => '2026-11-21', 'cZahlungsartName' => 'PayPal']));
$bp = Mapper::invoice($paid + ['today' => '2026-11-21'], $cfg);
ok($bp['payment']['means_code'] === '68' && $bp['prepaid_amount'] == 130.9, 'paid');

$ae = OrderAdapter::fromBestellung(bestellung(['oRechnungsadresse' => (object) ['cFirma' => 'SA', 'cLand' => 'FR', 'cUSTID' => 'FR 12 345678901', 'cStrasse' => 'r', 'cPLZ' => '75001', 'cOrt' => 'Paris']]));
foreach ($ae['lines'] as &$l) { $l['vat_rate'] = 0; } unset($l);
$ba = Mapper::invoice($ae, $cfg);
ok($ba['lines'][0]['vat_category'] === 'AE', 'intra-EU reverse charge');
$ex = OrderAdapter::fromBestellung(bestellung(['oRechnungsadresse' => (object) ['cFirma' => 'CH AG', 'cLand' => 'Schweiz', 'cStrasse' => 'x', 'cPLZ' => '8000', 'cOrt' => 'Zürich']]));
foreach ($ex['lines'] as &$l) { $l['vat_rate'] = 0; } unset($l);
$bx = Mapper::invoice($ex, $cfg);
ok($bx['buyer']['country'] === 'CH' && $bx['lines'][0]['vat_category'] === 'G', 'export G + country name');
$sb = Mapper::invoice($order, $cfg + ['smallBusiness' => 'Y']);
ok($sb['lines'][0]['vat_category'] === 'E' && isset($sb['vat_exemption_reason']['E']), 'small business');
$odd = Mapper::invoice(['number' => '1', 'paid' => false, 'total' => 1, 'billing' => ['country' => 'DE'], 'lines' => [['name' => 'x', 'qty' => 3, 'unit_net' => 0.3333, 'vat_rate' => 19, 'type' => 'item']]], $cfg);
ok(abs(round($odd['lines'][0]['quantity'] * $odd['lines'][0]['unit_price'], 2) - 1.0) < 0.011, 'rounding keeps net');

// Service with fake transport
$calls = [];
$tr = function (string $url, array $h, string $json) use (&$calls): array {
    $calls[] = [$url, $h, json_decode($json, true)];
    if (str_ends_with($url, '/einvoice/create')) {
        return [200, json_encode(['valid' => false, 'xml' => '<x/>', 'totals' => ['total_with_vat' => 130.9], 'errors' => [['rule_id' => 'BR-DE-2', 'explain' => ['what' => 'Missing contact', 'fix' => 'Add contact']]]])];
    }
    if (str_ends_with($url, '/einvoice/send')) { return [200, json_encode(['status' => 'simulated', 'receipt_id' => 'R1'])]; }
    if (str_ends_with($url, '/einvoice/check')) { return [200, json_encode(['verdict' => 'ok'])]; }
    return [402, '{}'];
};
$st = new ArrayStore();
$svc = new Service(new Api('KEY123', '0.1.0', $tr), $st, $cfg);
$r = $svc->generate($order);
ok(!$r['valid'] && $r['errors'][0]['rule_id'] === 'BR-DE-2' && $st->get(7)['xml'] === '<x/>', 'generate stores errors + xml');
ok(in_array('X-API-Key: KEY123', $calls[0][1], true), 'api key header');
ok($st->get(7)['mismatch'] === '', 'no mismatch');
$s = $svc->send($order);
ok($s['ok'] && $calls[1][2]['mode'] === 'sandbox' && $calls[1][2]['transport'] === 'mock' && $calls[1][2]['invoice']['format'] === 'peppol-bis3', 'peppol sandbox mock');
ok(!(new Service(new Api('', 'x', $tr), $st, ['peppolMode' => 'off'] + $cfg))->send($order)['ok'], 'peppol off refused');
ok($svc->checkIncoming('<Invoice/>')['data']['verdict'] === 'ok' && $calls[2][2]['my_vat'] === 'DE123456789', 'check incoming');
ok($svc->shouldAuto($order, 'on_shipped') && !$svc->shouldAuto($order, 'on_paid'), 'trigger');
$b2c = OrderAdapter::fromBestellung(bestellung(['oRechnungsadresse' => (object) ['cVorname' => 'A', 'cNachname' => 'B', 'cLand' => 'DE']]));
ok(!$svc->shouldAuto($b2c, 'on_shipped'), 'b2c skipped');
$quota = (new Service(new Api('', 'x', fn () => [429, '{}']), $st, $cfg))->generate($order);
ok(str_contains($quota['error'], 'quota'), 'quota message');
ok(!(new Service(new Api('', 'x', fn () => [200, '{}']), $st, $cfg))->generate(['id' => 1, 'lines' => []] + $order)['valid'], 'empty lines skipped');
$net = (new Service(new Api('', 'x', function () { throw new RuntimeException('boom'); }), $st, $cfg))->generate($order);
ok($net['error'] === 'boom', 'network error');

if (getenv('WSEI_LIVE') === '1') {
    $live = (new Service(new Api('', '0.1.0'), new ArrayStore(), $cfg))->generate($order + ['today' => date('Y-m-d')]);
    echo 'LIVE create: valid=' . var_export($live['valid'], true) . ' error=' . $live['error'] . ' errors=' . json_encode($live['errors']) . "\n";
    ok($live['error'] === '' && $live['valid'], 'live API accepts mapped JTL order and it validates');
}
echo "$n checks, $fail failed\n";
exit($fail ? 1 : 0);
