<?php

namespace App\Ai;

use App\Models\AiModel;
use Illuminate\Database\Eloquent\Collection;

/**
 * Picks the catalog model for a feature: the merchant's choice while it is active,
 * otherwise the feature's default model (FR-SET-004, FR-AI-010).
 */
class ModelResolver
{
    public function resolve(string $feature, ?int $selectedModelId = null): ?AiModel
    {
        if ($selectedModelId !== null) {
            $selected = AiModel::query()->active()->forFeature($feature)->find($selectedModelId);

            if ($selected) {
                return $selected;
            }
        }

        return $this->defaultFor($feature);
    }

    public function defaultFor(string $feature): ?AiModel
    {
        return AiModel::query()->active()->forFeature($feature)
            ->whereJsonContains('default_for', $feature)
            ->orderBy('sort')->orderBy('id')
            ->first()
            ?? AiModel::query()->active()->forFeature($feature)->orderBy('sort')->orderBy('id')->first();
    }

    /**
     * Whether a stored selection no longer resolves to itself (deactivated), so the app can show a notice.
     */
    public function selectionFellBack(string $feature, ?int $selectedModelId): bool
    {
        return $selectedModelId !== null && $this->resolve($feature, $selectedModelId)?->id !== $selectedModelId;
    }

    /**
     * Active models a merchant may pick for a feature, with their price.
     *
     * @return Collection<int, AiModel>
     */
    public function options(string $feature): Collection
    {
        return AiModel::query()->active()->forFeature($feature)->orderBy('sort')->orderBy('id')->get();
    }
}
