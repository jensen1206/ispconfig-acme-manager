<?php

namespace App\Certificate;

use App\ISPConfig\Website;

final readonly class CertificateFactory
{
    public function __construct(private ?\App\Config\Configuration $configuration = null)
    {
    }

    public function fromWebsite(Website $website): Certificate
    {
        return new Certificate(
            primaryHostname: $website->primaryHostname,
            hostnames: $website->hostnames,
            keyPath: $website->keyPath(),
            certificatePath: $website->certificatePath(),
            acmeDirectory: rtrim($this->configuration->acmeConfigHome ?? '/root/.acme.sh', '/').'/'.$website->domain().'_ecc',
            keyType: KeyType::Ec256,
            certificateAuthority: CertificateAuthority::LetsEncrypt,
            challengeType: ChallengeType::Dns01,
        );
    }
}
