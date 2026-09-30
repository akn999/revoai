<?php

namespace Tests\Fixtures;

use App\Logging\Enrichers\ActivityEnricher;

class StaticEnricher implements ActivityEnricher
{
    public function enrich(array $attributes): array
    {
        $attributes['user_agent'] ??= 'fixture-agent';

        return $attributes;
    }
}
