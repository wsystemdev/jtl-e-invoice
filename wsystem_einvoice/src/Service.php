<?php declare(strict_types=1);

namespace Plugin\wsystem_einvoice;

/** Create / validate / send logic. Storage is an injected store (DB in the shop, array in tests). */
final class Service
{
    public function __construct(private Api $api, private Store $store, private array $cfg)
    {
    }

    /** @param array<string,mixed> $order @return array{valid:bool,error:string,errors:array<int,array<string,string>>,skipped:bool} */
    public function generate(array $order): array
    {
        if (!$order['lines']) {
            return ['valid' => false, 'error' => 'Order has no lines.', 'errors' => [], 'skipped' => true];
        }
        $body = Mapper::invoice($order, $this->cfg);
        $res = $this->api->post('/einvoice/create', $body);
        if (!$res['ok']) {
            $this->store->save((int) $order['id'], ['last_error' => $res['error']]);
            return ['valid' => false, 'error' => $res['error'], 'errors' => [], 'skipped' => false];
        }
        $d = $res['data'];
        $valid = !empty($d['valid']);
        $errors = [];
        foreach ((isset($d['errors']) && is_array($d['errors'])) ? $d['errors'] : [] as $e) {
            $errors[] = [
                'rule_id' => (string) ($e['rule_id'] ?? ''),
                'message' => (string) ($e['explain']['what'] ?? $e['message'] ?? ''),
                'fix' => (string) ($e['explain']['fix'] ?? $e['fix'] ?? ''),
            ];
        }
        $totals = (isset($d['totals']) && is_array($d['totals'])) ? $d['totals'] : [];
        $mismatch = '';
        if (isset($totals['total_with_vat']) && abs((float) $totals['total_with_vat'] - (float) $order['total']) > 0.004) {
            $mismatch = sprintf('The e-invoice total (%s) differs from the order total (%s), usually by rounding.', $totals['total_with_vat'], number_format((float) $order['total'], 2, '.', ''));
        }
        $this->store->save((int) $order['id'], [
            'xml' => (string) ($d['xml'] ?? ''), 'valid' => $valid, 'errors' => $errors, 'format' => $body['format'],
            'number' => $body['invoice_number'], 'totals' => $totals, 'mismatch' => $mismatch, 'last_error' => '',
            'pdf' => ($valid && $body['format'] === 'zugferd' && !empty($d['pdf_base64'])) ? (string) $d['pdf_base64'] : '',
            'created' => gmdate('c'),
        ]);
        return ['valid' => $valid, 'error' => '', 'errors' => $errors, 'skipped' => false];
    }

    /**
     * Whether a credit note with this key should be created: option on, a valid e-invoice exists, the key is new, and a whole-order
     * credit note ($full) is not created twice (cancellation and later refund must not both credit the order).
     * @param array<string,mixed> $order
     */
    public function shouldCredit(array $order, string $key, bool $full = true): bool
    {
        if (($this->cfg['creditNotes'] ?? 'N') !== 'Y') {
            return false;
        }
        $rec = $this->store->get((int) $order['id']);
        if (empty($rec['valid']) || empty($rec['xml'])) {
            return false;
        }
        foreach ((array) ($rec['credit_notes'] ?? []) as $cn) {
            if (($cn['key'] ?? '') === $key || ($full && !empty($cn['full']) && !empty($cn['valid']))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Credit note for a cancelled / returned order (whole order, or $refundLines).
     * @param array<string,mixed> $order @param array<int,array<string,mixed>>|null $refundLines
     * @return array{valid:bool,error:string,errors:array<int,array<string,string>>,skipped:bool}
     */
    public function creditNote(array $order, string $key = 'order-cancelled', ?array $refundLines = null): array
    {
        $full = $refundLines === null;
        if (!$this->shouldCredit($order, $key, $full)) {
            return ['valid' => false, 'error' => '', 'errors' => [], 'skipped' => true];
        }
        $rec = $this->store->get((int) $order['id']);
        $origDate = substr((string) ($rec['created'] ?? ''), 0, 10) ?: (string) ($order['delivery_date'] ?? '') ?: date('Y-m-d');
        $body = Mapper::creditNote($order, $this->cfg, $key, (string) ($rec['number'] ?? $order['number']), $origDate, $refundLines);
        if (!$body['lines']) {
            return ['valid' => false, 'error' => '', 'errors' => [], 'skipped' => true];
        }
        $res = $this->api->post('/einvoice/create', $body);
        $e = ['key' => $key, 'full' => $full, 'number' => $body['invoice_number'], 'original' => (string) ($rec['number'] ?? $order['number']),
            'created' => gmdate('c'), 'valid' => false, 'xml' => '', 'pdf' => '', 'errors' => [], 'last_error' => ''];
        if (!$res['ok']) {
            $e['last_error'] = $res['error'];
        } else {
            $d = $res['data'];
            $e['valid'] = !empty($d['valid']);
            foreach ((isset($d['errors']) && is_array($d['errors'])) ? $d['errors'] : [] as $er) {
                $e['errors'][] = ['rule_id' => (string) ($er['rule_id'] ?? ''), 'message' => (string) ($er['explain']['what'] ?? $er['message'] ?? ''), 'fix' => (string) ($er['explain']['fix'] ?? $er['fix'] ?? '')];
            }
            if ($e['valid']) {
                $e['xml'] = (string) ($d['xml'] ?? '');
                $e['pdf'] = ($body['format'] === 'zugferd' && !empty($d['pdf_base64'])) ? (string) $d['pdf_base64'] : '';
            }
        }
        $list = array_values(array_filter((array) ($rec['credit_notes'] ?? []), static fn ($c) => ($c['key'] ?? '') !== $key));
        $list[] = $e;
        $this->store->save((int) $order['id'], ['credit_notes' => $list]);
        return ['valid' => $e['valid'], 'error' => $e['last_error'], 'errors' => $e['errors'], 'skipped' => false];
    }

    /** @param array<string,mixed> $order @return array{ok:bool,message:string} */
    public function send(array $order): array
    {
        $mode = (string) ($this->cfg['peppolMode'] ?? 'off');
        if ($mode === 'off') {
            return ['ok' => false, 'message' => 'Peppol sending is off in the plugin settings.'];
        }
        $body = ['invoice' => Mapper::invoice($order, $this->cfg, 'peppol-bis3'), 'mode' => $mode === 'live' ? 'live' : 'sandbox'];
        if ($mode !== 'live') {
            $body['transport'] = 'mock';
        }
        $res = $this->api->post('/einvoice/send', $body);
        $d = $res['data'];
        $msg = $res['ok'] ? '' : (is_string($d['message'] ?? null) ? $d['message'] : $res['error']);
        $info = ['mode' => $body['mode'], 'status' => (string) ($d['status'] ?? ($res['ok'] ? 'ok' : 'failed')),
            'receipt_id' => (string) ($d['receipt_id'] ?? ''), 'message' => $msg];
        $this->store->save((int) $order['id'], ['peppol' => $info]);
        return ['ok' => $res['ok'], 'message' => $res['ok']
            ? ($info['mode'] === 'sandbox' ? 'Peppol sandbox send simulated (nothing delivered). Receipt ' . $info['receipt_id'] : 'Peppol send: ' . $info['status'] . '. Receipt ' . $info['receipt_id'])
            : 'Peppol send refused: ' . $msg];
    }

    /** Counterparty / incoming invoice check (POST /einvoice/check, 5 units). @return array{ok:bool,error:string,data:array<string,mixed>} */
    public function checkIncoming(string $xml): array
    {
        $res = $this->api->post('/einvoice/check', ['xml' => $xml, 'my_vat' => Mapper::vatId((string) ($this->cfg['sellerVatId'] ?? '')), 'rules' => 'auto']);
        return ['ok' => $res['ok'], 'error' => $res['error'], 'data' => $res['data']];
    }

    /** @param array<string,mixed> $order */
    public function shouldAuto(array $order, string $trigger): bool
    {
        $mode = (string) ($this->cfg['trigger'] ?? 'on_shipped');
        if ($mode === 'off' || $mode !== $trigger) {
            return false;
        }
        if (($this->cfg['b2bOnly'] ?? 'Y') === 'Y' && !Mapper::isB2b($order)) {
            return false;
        }
        return true;
    }
}
