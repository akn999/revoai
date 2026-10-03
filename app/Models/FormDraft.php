<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\FormDraftFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $salla_user_id
 * @property string $form_key
 * @property array<string, mixed> $payload
 */
#[UseFactory(FormDraftFactory::class)]
class FormDraft extends Model
{
    /** @use HasFactory<FormDraftFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
