<?php

namespace App\Service;

use App\Certificate\Certificate;
use App\Certificate\CertificateInfo;

final readonly class DashboardCertificateResult
{
    public function __construct(
        public Certificate $certificate,
        public CertificateInfo $certificateInfo,
        public CertificateInfo $liveCertificateInfo,
        public bool $issued = false,
    ) {
    }
}
