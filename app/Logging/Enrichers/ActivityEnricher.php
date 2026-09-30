<?php

namespace App\Logging\Enrichers;

interface ActivityEnricher
{
    /**
     * Fill in ambient data. Values already present on the entry must never be overwritten.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function enrich(array $attributes): array;
}
