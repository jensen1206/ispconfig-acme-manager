<?php

declare(strict_types=1);

namespace App\Process;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function combinedOutput(): string
    {
        return trim($this->output . PHP_EOL . $this->errorOutput);
    }

    public function contains(string $needle): bool
    {
        return str_contains(
            $this->output . PHP_EOL . $this->errorOutput,
            $needle,
        );
    }
}