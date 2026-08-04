<?php

declare(strict_types=1);

namespace App\Certificate;

enum CertificateAuthority: string
{
    case LetsEncrypt = 'letsencrypt';
    case ZeroSsl = 'zerossl';
    case Google = 'google';
    case SslCom = 'sslcom';
}