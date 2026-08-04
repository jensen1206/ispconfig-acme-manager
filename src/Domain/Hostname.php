<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class Hostname implements Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $value = strtolower(rtrim(trim($value), '.'));

        $this->assertValid($value);

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isWildcard(): bool
    {
        return str_starts_with($this->value, '*.');
    }

    public function baseDomain(): string
    {
        return $this->isWildcard()
            ? substr($this->value, 2)
            : $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private function assertValid(string $value): void
    {
        if ($value === '' || strlen($value) > 253) {
            throw new InvalidArgumentException(sprintf(
                'Ungültiger Hostname "%s".',
                $value,
            ));
        }

        if (str_contains($value, '*') && !str_starts_with($value, '*.')) {
            throw new InvalidArgumentException(sprintf(
                'Ein Wildcard-Hostname muss mit "*." beginnen: "%s".',
                $value,
            ));
        }

        $domain = $this->isWildcardValue($value)
            ? substr($value, 2)
            : $value;

        if (
            filter_var(
                $domain,
                FILTER_VALIDATE_DOMAIN,
                FILTER_FLAG_HOSTNAME,
            ) === false
        ) {
            throw new InvalidArgumentException(sprintf(
                'Ungültiger Hostname "%s".',
                $value,
            ));
        }
    }

    private function isWildcardValue(string $value): bool
    {
        return str_starts_with($value, '*.');
    }
}