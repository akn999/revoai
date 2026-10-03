<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\StoreContextEntryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string $label
 * @property string $text
 * @property int $sort
 */
#[UseFactory(StoreContextEntryFactory::class)]
class StoreContextEntry extends Model
{
    /** @use HasFactory<StoreContextEntryFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];
}
