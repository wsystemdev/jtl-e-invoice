<?php declare(strict_types=1);

namespace Plugin\wsystem_einvoice;

final class ArrayStore implements Store
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    public function save(int $orderId, array $data): void
    {
        $this->rows[$orderId] = array_merge($this->rows[$orderId] ?? [], $data);
    }

    public function get(int $orderId): array
    {
        return $this->rows[$orderId] ?? [];
    }
}
