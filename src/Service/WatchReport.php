<?php

declare(strict_types=1);

namespace App\Service;

final readonly class WatchReport
{
    /**
     * @param list<WatchItemResult> $items
     */
    public function __construct(
        public array $items,
        public float $duration,
    ) {
    }

    public function checkedCount(): int
    {
        return count($this->items);
    }

    public function validCount(): int
    {
        return $this->countStatus('valid');
    }

    public function issuedCount(): int
    {
        return $this->countStatus('issued');
    }

    public function renewedCount(): int
    {
        return $this->countStatus('renewed');
    }

    public function sslDisabledCount(): int
    {
        return $this->countStatus('ssl-disabled');
    }

    public function externalDnsCount(): int
    {
        return $this->countStatus('external-dns');
    }

    public function failedCount(): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            if ($item->failed) {
                ++$count;
            }
        }

        return $count;
    }

    public function successful(): bool
    {
        return $this->failedCount() === 0;
    }

    private function countStatus(string $status): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            if ($item->status === $status) {
                ++$count;
            }
        }

        return $count;
    }
}
