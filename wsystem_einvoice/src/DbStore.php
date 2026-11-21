<?php declare(strict_types=1);

namespace Plugin\wsystem_einvoice;

use JTL\DB\DbInterface;

/** Stores one JSON record per order in xplugin_wsystem_einvoice_invoices. */
final class DbStore implements Store
{
    public const TABLE = 'xplugin_wsystem_einvoice_invoices';

    public function __construct(private DbInterface $db)
    {
    }

    public function get(int $orderId): array
    {
        $row = $this->db->getSingleObject('SELECT data FROM ' . self::TABLE . ' WHERE kBestellung = :k', ['k' => $orderId]);
        $d = $row ? json_decode((string) $row->data, true) : [];
        return is_array($d) ? $d : [];
    }

    public function save(int $orderId, array $data): void
    {
        $merged = array_merge($this->get($orderId), $data, ['updated' => gmdate('c')]);
        $this->db->queryPrepared(
            'INSERT INTO ' . self::TABLE . ' (kBestellung, data, updated) VALUES (:k, :d, NOW())
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated = NOW()',
            ['k' => $orderId, 'd' => json_encode($merged, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 25): array
    {
        $out = [];
        foreach ($this->db->getObjects('SELECT kBestellung, data FROM ' . self::TABLE . ' ORDER BY updated DESC LIMIT ' . (int) $limit) as $r) {
            $d = json_decode((string) $r->data, true);
            $out[(int) $r->kBestellung] = is_array($d) ? $d : [];
        }
        return $out;
    }
}
