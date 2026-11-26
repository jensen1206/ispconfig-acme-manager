<?php

namespace App\Certificate;

final class HostnameMatcher
{
    /** @param list<string> $names */
    public static function matches(string $hostname, array $names): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        foreach ($names as $name) {
            $name = strtolower(rtrim($name, '.'));
            if ($name === $hostname) {
                return true;
            }
            if (!str_contains($hostname, '*') && str_starts_with($name, '*.')) {
                $suffix = substr($name, 1);
                if (str_ends_with($hostname, $suffix)
                    && substr_count($hostname, '.') === substr_count($name, '.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
