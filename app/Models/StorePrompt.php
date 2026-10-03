<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\StorePromptFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string $key
 * @property string $body
 */
#[UseFactory(StorePromptFactory::class)]
class StorePrompt extends Model
{
    /** @use HasFactory<StorePromptFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];
}
