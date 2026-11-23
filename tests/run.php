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


// ---- 0.2.0: ZUGFeRD option + credit notes (both off by default) ----
ok(Mapper::formatFor($cfg) === 'xrechnung' && Mapper::invoice($order, $cfg)['format'] === 'xrechnung', 'zugferd off by default: format unchanged');
ok(Mapper::formatFor($cfg + ['zugferd' => 'N']) === 'xrechnung', 'zugferd N');
ok(Mapper::formatFor(['zugferd' => 'Y'] + $cfg) === 'zugferd' && Mapper::formatFor(['zugferd' => 'Y', 'format' => 'en16931'] + $cfg) === 'zugferd', 'zugferd Y switches format');
ok(Mapper::formatFor(['zugferd' => 'Y', 'format' => 'peppol-bis3'] + $cfg) === 'peppol-bis3', 'zugferd never replaces Peppol BIS');
ok(Mapper::invoice($order, ['zugferd' => 'Y'] + $cfg, 'peppol-bis3')['format'] === 'peppol-bis3', 'explicit format wins (Peppol send)');

$paidOrder = OrderAdapter::fromBestellung(bestellung(['dBezahltDatum' => '2026-11-21', 'cZahlungsartName' => 'PayPal']));
$cn = Mapper::creditNote($paidOrder + ['today' => '2026-11-22'], $cfg, 'order-cancelled', 'RE-77', '2026-11-20');
ok($cn['type_code'] === '381' && $cn['preceding_invoice'] === 'RE-77' && $cn['preceding_invoice_date'] === '2026-11-20', 'credit note references original');
ok(!isset($cn['prepaid_amount']) && $cn['invoice_number'] === 'CN-AU-2026-7-ancelled' && $cn['issue_date'] === '2026-11-22', 'credit note number, no prepaid');
ok($cn['payment']['means_code'] === '58' && $cn['payment']['iban'] === 'DE02120300000000202051', 'credit note payment with IBAN (BR-61)');
$cn2 = Mapper::creditNote($order, ['sellerIban' => ''] + $cfg, 'k', 'RE-1', '2026-11-20');
ok($cn2['payment']['means_code'] === '1' && !isset($cn2['payment']['iban']), 'credit note payment without IBAN');
$part = Mapper::creditNote($order, $cfg, 'ret-1', 'RE-1', '2026-11-20', [['name' => 'Widget', 'sku' => 'W-1', 'qty' => 1.0, 'unit_net' => 33.333333, 'vat_rate' => 19.0, 'type' => 'item']]);
ok(count($part['lines']) === 1 && abs($part['lines'][0]['unit_price'] * $part['lines'][0]['quantity'] - 33.33) < 0.011 && $part['lines'][0]['vat_category'] === 'S', 'partial credit note: only the returned line');

$crCalls = []; $crTr = function (string $url, array $h, string $json) use (&$crCalls): array {
    $crCalls[] = json_decode($json, true);
    return [200, json_encode(['valid' => true, 'xml' => '<rsm:CrossIndustryInvoice/>', 'pdf_base64' => base64_encode('%PDF'), 'totals' => ['total_with_vat' => 130.9], 'errors' => []])];
};
$crOrder = OrderAdapter::fromBestellung(bestellung());
// option off: nothing happens, no API call
$stOff = new ArrayStore(); $stOff->save(7, ['valid' => true, 'xml' => '<Invoice/>', 'number' => 'RE-77', 'created' => '2026-11-20T10:00:00+00:00']);
$off = (new Service(new Api('', 'x', $crTr), $stOff, $cfg))->creditNote($crOrder);
ok($off['skipped'] && $crCalls === [] && empty($stOff->get(7)['credit_notes']), 'credit notes off by default: skipped, no API call');
$cfgOn = ['creditNotes' => 'Y', 'zugferd' => 'Y'] + $cfg;
// no valid original: skipped
$stNone = new ArrayStore();
ok((new Service(new Api('', 'x', $crTr), $stNone, $cfgOn))->creditNote($crOrder)['skipped'] && $crCalls === [], 'no valid e-invoice: no credit note');
// option on: created once, stored with pdf, replay and a second whole-order credit note skipped
$svcOn = new Service(new Api('', 'x', $crTr), $stOff, $cfgOn);
$r1 = $svcOn->creditNote($crOrder);
ok($r1['valid'] && count($crCalls) === 1 && $crCalls[0]['type_code'] === '381' && $crCalls[0]['preceding_invoice'] === 'RE-77' && $crCalls[0]['preceding_invoice_date'] === '2026-11-20' && $crCalls[0]['format'] === 'zugferd', 'credit note created with confirmed API fields');
$cnList = $stOff->get(7)['credit_notes'];
ok(count($cnList) === 1 && $cnList[0]['full'] === true && $cnList[0]['pdf'] === base64_encode('%PDF') && $cnList[0]['original'] === 'RE-77', 'credit note stored with PDF');
ok($svcOn->creditNote($crOrder)['skipped'] && $svcOn->creditNote($crOrder, 'manual-later')['skipped'] && count($crCalls) === 1, 'one whole-order credit note only (replay and second key skipped)');
ok(!$svcOn->creditNote($crOrder, 'ret-1', [['name' => 'Widget', 'qty' => 1.0, 'unit_net' => 10.0, 'vat_rate' => 19.0, 'type' => 'item']])['skipped'] && count($crCalls) === 2, 'a partial return is its own credit note');
// API failure stored, not thrown
$stFail = new ArrayStore(); $stFail->save(7, ['valid' => true, 'xml' => '<Invoice/>', 'number' => 'RE-77']);
$fl = (new Service(new Api('', 'x', fn () => [503, '{}']), $stFail, $cfgOn))->creditNote($crOrder);
ok(!$fl['valid'] && $fl['error'] !== '' && $stFail->get(7)['credit_notes'][0]['valid'] === false, 'credit note API failure is stored');
// generate stores the ZUGFeRD PDF only when the option produced one
$stG = new ArrayStore();
(new Service(new Api('', 'x', $crTr), $stG, $cfgOn))->generate($crOrder);
ok($stG->get(7)['pdf'] === base64_encode('%PDF') && $stG->get(7)['format'] === 'zugferd', 'generate with zugferd stores PDF');
$stG2 = new ArrayStore();
(new Service(new Api('', 'x', $crTr), $stG2, $cfg))->generate($crOrder);
ok($stG2->get(7)['pdf'] === '' && $crCalls[count($crCalls) - 1]['format'] === 'xrechnung', 'generate with defaults: xrechnung, no PDF');

if (getenv('WSEI_LIVE') === '1') {
    $live = (new Service(new Api('', '0.1.0'), new ArrayStore(), $cfg))->generate($order + ['today' => date('Y-m-d')]);
    echo 'LIVE create: valid=' . var_export($live['valid'], true) . ' error=' . $live['error'] . ' errors=' . json_encode($live['errors']) . "\n";
    ok($live['error'] === '' && $live['valid'], 'live API accepts mapped JTL order and it validates');
}
echo "$n checks, $fail failed\n";
exit($fail ? 1 : 0);
