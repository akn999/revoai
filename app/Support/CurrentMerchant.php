<?php

namespace App\Support;

final class CurrentMerchant
{
    private ?int $id = null;

    public function set(int $id): void
    {
        $this->id = $id;
    }

    public function clear(): void
    {
        $this->id = null;
    }

    public function id(): ?int
    {
        return $this->id;
    }
}
