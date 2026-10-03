<?php

namespace App\Models;

use App\Models\Concerns\AuditsAdminChanges;
use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A model in the super-admin catalog. Adding a model is a row, not code (FR-AI-002).
 *
 * @property int $id
 * @property string $provider bedrock|fal
 * @property string $provider_model_id
 * @property string $name_ar
 * @property string $name_en
 * @property array<int, string> $features
 * @property array<string, bool>|null $capabilities
 * @property array<string, int>|null $prices credits per action
 * @property array<string, mixed>|null $param_schema JSON Schema of the advanced parameters
 * @property array<string, float>|null $cost_rates USD: input_per_1k, output_per_1k, per_image
 * @property bool $active
 * @property array<int, string>|null $default_for
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(AiModelFactory::class)]
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use AuditsAdminChanges, HasFactory;

    public const BEDROCK = 'bedrock';

    public const FAL = 'fal';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'capabilities' => 'array',
            'prices' => 'array',
            'param_schema' => 'array',
            'cost_rates' => 'array',
            'default_for' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<AiModel>  $query
     * @return Builder<AiModel>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<AiModel>  $query
     * @return Builder<AiModel>
     */
    public function scopeForFeature(Builder $query, string $feature): Builder
    {
        return $query->whereJsonContains('features', $feature);
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function can(string $capability): bool
    {
        return (bool) data_get($this->capabilities, $capability, false);
    }

    public function priceFor(string $action): ?int
    {
        $price = data_get($this->prices, $action);

        return $price === null ? null : (int) $price;
    }

    public function name(string $language = 'en'): string
    {
        return $language === 'ar' ? $this->name_ar : $this->name_en;
    }

    /**
     * Provider cost in USD for one call, from the catalog rates.
     */
    public function costUsd(int $inputTokens = 0, int $outputTokens = 0, int $images = 0): float
    {
        $rates = $this->cost_rates ?? [];

        return round(
            ($inputTokens / 1000) * (float) ($rates['input_per_1k'] ?? 0)
            + ($outputTokens / 1000) * (float) ($rates['output_per_1k'] ?? 0)
            + $images * (float) ($rates['per_image'] ?? 0),
            6,
        );
    }
}
