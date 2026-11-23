<?php declare(strict_types=1);

namespace Plugin\wsystem_einvoice;

require_once __DIR__ . '/autoload.php';

use JTL\Checkout\Bestellung;
use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Shop;

/** W System E-Invoice for JTL-Shop 5. */
class Bootstrap extends Bootstrapper
{
    public const VERSION = '0.2.0';

    public function installed()
    {
        parent::installed();
        $sql = (string) file_get_contents(__DIR__ . '/sql/install.sql');
        Shop::Container()->getDB()->getPDO()->exec($sql);
    }

    public function uninstalled(bool $deleteData = true)
    {
        parent::uninstalled($deleteData);
        if ($deleteData) {
            Shop::Container()->getDB()->getPDO()->exec('DROP TABLE IF EXISTS `' . DbStore::TABLE . '`');
        }
    }

    public function boot(Dispatcher $dispatcher)
    {
        parent::boot($dispatcher);
        // Hook 181 = HOOK_BESTELLUNGEN_XML_BESTELLSTATUS: JTL-Wawi pushed a new order status into the shop.
        $dispatcher->listen('shop.hook.181', function (array $args): void {
            try {
                $this->onStatus($args);
            } catch (\Throwable $e) {
                Shop::Container()->getLogService()->error('W System E-Invoice: ' . $e->getMessage());
            }
        });
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $c = $this->getPlugin()->getConfig();
        $keys = ['apiKey', 'format', 'trigger', 'b2bOnly', 'peppolMode', 'peppolAuto', 'smallBusiness', 'leitwegId', 'paymentTerms',
            'sellerName', 'sellerStreet', 'sellerPostcode', 'sellerCity', 'sellerCountry', 'sellerEmail', 'sellerPhone',
            'sellerContact', 'sellerVatId', 'sellerTaxNo', 'sellerRegNo', 'sellerIban', 'sellerBic', 'zugferd', 'creditNotes'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (string) $c->getValue($k);
        }
        return $out;
    }

    public function service(): Service
    {
        $cfg = $this->settings();
        return new Service(new Api($cfg['apiKey'], self::VERSION, null, Shop::getURL()), new DbStore(Shop::Container()->getDB()), $cfg);
    }

    /** Cancelled order (JTL status -1): credit note, only when the option is on and the order has a valid e-invoice. */
    private function onCancelled(object $o): void
    {
        $svc = $this->service();
        $full = new Bestellung((int) $o->kBestellung);
        $full->fuelleBestellung(true, 0, false);
        $svc->creditNote(OrderAdapter::fromBestellung($full), 'order-cancelled');
    }

    /** @param array<string,mixed> $args */
    private function onStatus(array $args): void
    {
        $o = $args['oBestellung'] ?? null;
        if (!is_object($o) || empty($o->kBestellung)) {
            return;
        }
        $status = (int) ($args['status'] ?? $o->cStatus ?? 0);
        if ($status === -1) {
            $this->onCancelled($o);
            return;
        }
        // JTL order status: 1 open, 2 in progress, 3 paid, 4 shipped, 5 partly shipped, -1 cancelled
        $trigger = [3 => 'on_paid', 4 => 'on_shipped'][$status] ?? '';
        if ($trigger === '') {
            return;
        }
        $full = new Bestellung((int) $o->kBestellung);
        $full->fuelleBestellung(true, 0, false);
        $order = OrderAdapter::fromBestellung($full);
        $svc = $this->service();
        if (!$svc->shouldAuto($order, $trigger)) {
            return;
        }
        $res = $svc->generate($order);
        if ($res['valid'] && ($this->settings()['peppolMode'] ?? 'off') !== 'off' && ($this->settings()['peppolAuto'] ?? 'N') === 'Y' && Mapper::isB2b($order)) {
            $svc->send($order);
        }
    }
}
