<?php

namespace App\ISPConfig;

final readonly class Session implements \Stringable
{
    public function __construct(
        private string $id,
    ) {
        if ('' === trim($this->id)) {
            throw new \InvalidArgumentException('Die ISPConfig-Session-ID darf nicht leer sein.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
