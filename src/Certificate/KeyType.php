<?php

declare(strict_types=1);

namespace App\Certificate;

enum KeyType: string
{
    case Ec256 = 'ec-256';
    case Ec384 = 'ec-384';
    case Rsa2048 = '2048';
    case Rsa4096 = '4096';

    public function usesEcc(): bool
    {
        return match ($this) {
            self::Ec256, self::Ec384 => true,
            self::Rsa2048, self::Rsa4096 => false,
        };
    }
}