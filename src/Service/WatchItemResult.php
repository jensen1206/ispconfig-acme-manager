<?php

declare(strict_types=1);

namespace App\Service;

final readonly class WatchItemResult
{
    public function __construct(
        public string $domain,
        public string $status,
        public string $message,
        public bool $changed = false,
        public bool $failed = false,
    ) {
    }
}
