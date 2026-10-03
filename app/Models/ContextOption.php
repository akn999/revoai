<?php

namespace App\Models;

use App\Models\Concerns\AuditsAdminChanges;
use Database\Factories\ContextOptionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $field
 * @property string $key
 * @property string $label_ar
 * @property string $label_en
 * @property int $sort
 * @property bool $active
 */
#[UseFactory(ContextOptionFactory::class)]
class ContextOption extends Model
{
    /** @use HasFactory<ContextOptionFactory> */
    use AuditsAdminChanges, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * The dropdown fields whose options super admins manage.
     *
     * @return array<int, string>
     */
    public static function fields(): array
    {
        return ['industry', 'audience', 'brand_tone', 'photography_style'];
    }

    /**
     * @param  Builder<ContextOption>  $query
     * @return Builder<ContextOption>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
