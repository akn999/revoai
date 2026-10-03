<?php

namespace App\Models;

use App\Models\Concerns\AuditsAdminChanges;
use App\Models\Concerns\LogsActivity;
use Database\Factories\CreditPackFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $salla_addon_slug
 * @property int $credits
 * @property string $name_ar
 * @property string $name_en
 * @property bool $active
 * @property int $sort
 */
#[UseFactory(CreditPackFactory::class)]
class CreditPack extends Model
{
    /** @use HasFactory<CreditPackFactory> */
    use AuditsAdminChanges, HasFactory, LogsActivity;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['credits' => 'integer', 'active' => 'boolean', 'sort' => 'integer'];
    }
}
