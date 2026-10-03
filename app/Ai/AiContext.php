<?php

namespace App\Ai;

/**
 * Who a provider call is for and what it is part of, recorded on every usage-ledger row.
 */
final class AiContext
{
    public function __construct(
        public readonly int $merchantId,
        public readonly string $feature,
        public readonly string $action,
        public readonly ?int $sallaUserId = null,
        public readonly bool $internal = true,
        public readonly int $credits = 0,
        public readonly ?string $referenceType = null,
        public readonly ?string $referenceId = null,
    ) {}

    /**
     * The same context for the call that carries the charge.
     */
    public function charging(int $credits): self
    {
        return new self($this->merchantId, $this->feature, $this->action, $this->sallaUserId, false, $credits, $this->referenceType, $this->referenceId);
    }

    public function for(string $feature, string $action): self
    {
        return new self($this->merchantId, $feature, $action, $this->sallaUserId, true, 0, $this->referenceType, $this->referenceId);
    }
}
