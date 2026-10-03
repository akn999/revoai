<?php

namespace App\Models;

use App\Models\Concerns\AuditsAdminChanges;
use Database\Factories\ModerationCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name_ar
 * @property string $name_en
 * @property string $description
 * @property string $instruction
 * @property string|null $allowed
 * @property bool $active
 * @property int $sort
 */
#[UseFactory(ModerationCategoryFactory::class)]
class ModerationCategory extends Model
{
    /** @use HasFactory<ModerationCategoryFactory> */
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
     * @param  Builder<ModerationCategory>  $query
     * @return Builder<ModerationCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
