<?php declare(strict_types=1);

namespace Plugin\wsystem_einvoice;

/**
 * Turns a JTL\Checkout\Bestellung (loaded with fuelleBestellung) into the normalised order array
 * that Mapper understands. Property names follow JTL-Shop 5 (Positionen, oRechnungsadresse, Waehrung).
 */
final class OrderAdapter
{
    // JTL position types (C_WARENKORBPOS_TYP_*): 1 = product, 3/4 = coupons, rest = shipping, fees, packaging.
    private const TYPE_ITEM = 1;

    /** @return array<string,mixed> */
    public static function fromBestellung(object $o): array
    {
        $b = $o->oRechnungsadresse ?? new \stdClass();
        $lines = [];
        foreach ((array) ($o->Positionen ?? []) as $p) {
            $typeNo = (int) ($p->nPosTyp ?? self::TYPE_ITEM);
            $net = (float) ($p->fPreisEinzelNetto ?? $p->fPreis ?? 0.0);
            $lines[] = [
                'name' => (string) ($p->cName ?? ''),
                'sku' => (string) ($p->cArtNr ?? ''),
                'qty' => (float) ($p->nAnzahl ?? 1),
                'unit_net' => $net,
                'vat_rate' => (float) ($p->fMwSt ?? 0.0),
                'type' => $typeNo === self::TYPE_ITEM ? 'item' : 'other',
            ];
        }
        $cur = $o->Waehrung ?? null;
        $iso = is_object($cur) ? ($cur->cISO ?? ($cur->code ?? null)) : null;
        $iso = $iso ?: ($o->cWaehrung ?? 'EUR');
        $created = substr((string) ($o->dErstellt ?? ''), 0, 10);
        $paidDate = substr((string) ($o->dBezahltDatum ?? ''), 0, 10);
        $paid = $paidDate !== '' && $paidDate !== '0000-00-00' || ($o->cBezahlt ?? 'N') === 'Y';
        return [
            'id' => (int) ($o->kBestellung ?? 0),
            'number' => (string) ($o->cBestellNr ?? $o->kBestellung ?? ''),
            'currency' => (string) $iso,
            'delivery_date' => $created,
            'paid' => $paid,
            'paid_date' => $paidDate,
            'payment_method' => (string) ($o->cZahlungsartName ?? ''),
            'payment_name' => (string) ($o->cZahlungsartName ?? ''),
            'total' => (float) ($o->fGesamtsumme ?? 0.0),
            'billing' => [
                'company' => (string) ($b->cFirma ?? ''),
                'first' => (string) ($b->cVorname ?? ''),
                'last' => (string) ($b->cNachname ?? ''),
                'street' => trim(((string) ($b->cStrasse ?? '')) . ' ' . ((string) ($b->cHausnummer ?? ''))),
                'city' => (string) ($b->cOrt ?? ''),
                'zip' => (string) ($b->cPLZ ?? ''),
                'country' => (string) ($b->cLand ?? ''),
                'email' => (string) ($b->cMail ?? ''),
                'vat' => (string) ($b->cUSTID ?? ''),
            ],
            'lines' => $lines,
        ];
    }
}
