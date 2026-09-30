<?php

namespace Tests\Fixtures;

use App\Logging\Activity;
use App\Logging\Enrichers\ActivityEnricher;

class RecursiveEnricher implements ActivityEnricher
{
    public function enrich(array $attributes): array
    {
        Activity::info('system', 'nested.inside.enricher');

        return $attributes;
    }
}
