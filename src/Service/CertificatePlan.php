<?php

declare(strict_types=1);

namespace App\Service;

use App\Certificate\Certificate;
use App\ISPConfig\Website;

final readonly class CertificatePlan
{
    /**
     * @param list<string> $issueCommand
     * @param list<string> $installCommand
     */
    public function __construct(
        public Website $website,
        public Certificate $certificate,
        public array $issueCommand,
        public array $installCommand,
    ) {
    }
}