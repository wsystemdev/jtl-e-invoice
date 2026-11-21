<?php declare(strict_types=1);

/**
 * Admin tab "E-Invoices": generate / download / send per order number, list recent results,
 * and check an incoming supplier invoice (XML) with POST /einvoice/check.
 * JTL-Shop injects $oPlugin (PluginInterface).
 */

use JTL\Checkout\Bestellung;
use JTL\Helpers\Form;
use JTL\Shop;
use Plugin\wsystem_einvoice\DbStore;
use Plugin\wsystem_einvoice\OrderAdapter;

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
/** @var \Plugin\wsystem_einvoice\Bootstrap $boot */
$boot = $oPlugin->getBootstrapper();
$svc = $boot->service();
$store = new DbStore(Shop::Container()->getDB());
$msg = '';

if (isset($_GET['wsei_dl']) && Form::validateToken()) {
    $rec = $store->get((int) $_GET['wsei_dl']);
    if (!empty($rec['xml'])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', ($rec['format'] === 'xrechnung' ? 'XRechnung-' : 'e-invoice-') . $rec['number']) . '.xml"');
        echo $rec['xml'];
        exit;
    }
}
if (($_POST['wsei_action'] ?? '') !== '' && Form::validateToken()) {
    $act = (string) $_POST['wsei_action'];
    if ($act === 'check') {
        $xml = isset($_FILES['wsei_xml']['tmp_name']) && is_uploaded_file($_FILES['wsei_xml']['tmp_name']) ? (string) file_get_contents($_FILES['wsei_xml']['tmp_name']) : (string) ($_POST['wsei_xml_text'] ?? '');
        $r = $svc->checkIncoming($xml);
        $d = $r['data'];
        $msg = $r['ok'] ? 'Verdict: ' . $h($d['verdict'] ?? $d['decision'] ?? 'see details') . ' | ' . $h(json_encode($d['findings'] ?? $d['reasons'] ?? $d['errors'] ?? [], JSON_UNESCAPED_UNICODE)) : $h($r['error']);
    } else {
        $row = Shop::Container()->getDB()->getSingleObject('SELECT kBestellung FROM tbestellung WHERE cBestellNr = :n', ['n' => trim((string) ($_POST['wsei_order'] ?? ''))]);
        if (!$row) {
            $msg = 'Order not found.';
        } else {
            $full = new Bestellung((int) $row->kBestellung);
            $full->fuelleBestellung(true, 0, false);
            $order = OrderAdapter::fromBestellung($full);
            if ($act === 'send') {
                $msg = $h($svc->send($order)['message']);
            } else {
                $r = $svc->generate($order);
                $msg = $r['valid'] ? 'E-invoice created and passed validation.' : $h($r['error'] ?: count($r['errors']) . ' validation error(s), see list.');
            }
        }
    }
}
$tok = Form::getTokenInput();
echo '<div class="card"><div class="card-body">';
if ($msg !== '') {
    echo '<div class="alert alert-info">' . $msg . '</div>';
}
echo '<h4>Create e-invoice for an order</h4><form method="post">' . $tok
    . '<input class="form-control mb-2" name="wsei_order" placeholder="Order number (Bestellnummer)">'
    . '<button class="btn btn-primary" name="wsei_action" value="create">Create and validate</button> '
    . '<button class="btn btn-secondary" name="wsei_action" value="send">Send over Peppol</button></form>';
echo '<h4 class="mt-4">Check a received invoice</h4><p>Checks a supplier XML (EN16931 / XRechnung rules, VIES, sanctions, bank-detail changes). Uses 5 units per check.</p>'
    . '<form method="post" enctype="multipart/form-data">' . $tok . '<input type="file" name="wsei_xml" class="mb-2"><br>'
    . '<button class="btn btn-secondary" name="wsei_action" value="check">Check invoice</button></form>';
echo '<h4 class="mt-4">Recent e-invoices</h4><table class="table table-sm"><tr><th>Order id</th><th>Number</th><th>Format</th><th>Valid</th><th>Errors</th><th>Peppol</th><th></th></tr>';
foreach ($store->recent() as $id => $r) {
    echo '<tr><td>' . $id . '</td><td>' . $h($r['number'] ?? '') . '</td><td>' . $h($r['format'] ?? '') . '</td><td>' . (!empty($r['valid']) ? 'yes' : 'no') . '</td><td>'
        . $h(isset($r['errors']) ? implode('; ', array_map(static fn ($e) => $e['rule_id'] . ' ' . $e['fix'], $r['errors'])) : ($r['last_error'] ?? '')) . '</td><td>'
        . $h($r['peppol']['status'] ?? '') . '</td><td>' . (!empty($r['xml']) ? '<a href="?wsei_dl=' . $id . '&jtl_token=' . $h($_SESSION['jtl_token'] ?? '') . '">XML</a>' : '') . '</td></tr>';
}
echo '</table></div></div>';
