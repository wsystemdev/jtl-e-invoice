<?php declare(strict_types=1);

namespace Plugin\wsystem_einvoice;

interface Store
{
    /** @param array<string,mixed> $data merged into the stored record */
    public function save(int $orderId, array $data): void;

    /** @return array<string,mixed> */
    public function get(int $orderId): array;
}
