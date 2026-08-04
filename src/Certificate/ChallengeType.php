<?php

declare(strict_types=1);

namespace App\Certificate;

enum ChallengeType: string
{
    case Dns01 = 'dns-01';
    case Http01 = 'http-01';
}