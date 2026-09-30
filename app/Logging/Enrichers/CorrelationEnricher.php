<?php

namespace App\Logging\Enrichers;

use App\Logging\ActivityContext;

class CorrelationEnricher implements ActivityEnricher
{
    public function __construct(private ActivityContext $context) {}

    public function enrich(array $attributes): array
    {
        $attributes['correlation_id'] ??= $this->context->correlationId();

        return $attributes;
    }
}
