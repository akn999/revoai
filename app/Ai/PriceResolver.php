<?php

namespace App\Ai;

use App\Models\AiModel;
use App\Models\Preset;
use App\Platform\RevoSettings;

/**
 * Resolves what an action costs, first match wins (section 9.2): preset override, model price, default price.
 */
class PriceResolver
{
    public function __construct(private RevoSettings $settings) {}

    /**
     * @return array{credits: int, source: string}
     */
    public function resolve(string $action, ?AiModel $model = null, ?Preset $preset = null): array
    {
        if ($action === 'image_edit' && $preset?->price_override !== null) {
            return ['credits' => (int) $preset->price_override, 'source' => 'preset'];
        }

        $modelPrice = $model?->priceFor($action);

        if ($modelPrice !== null) {
            return ['credits' => $modelPrice, 'source' => 'model'];
        }

        return ['credits' => $this->settings->price($action), 'source' => 'default'];
    }
}
