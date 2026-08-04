<?php

declare(strict_types=1);

namespace App\Certificate;

use App\ISPConfig\Website;

final readonly class CertificateFactory
{
    public function fromWebsite(Website $website): Certificate
    {
        return new Certificate(
            primaryHostname: $website->primaryHostname,
            hostnames: $website->hostnames,
            keyPath: $website->keyPath(),
            certificatePath: $website->certificatePath(),
            acmeDirectory: $website->acmeDirectory(),
            keyType: KeyType::Ec256,
            certificateAuthority: CertificateAuthority::LetsEncrypt,
            challengeType: ChallengeType::Dns01,
        );
    }
}