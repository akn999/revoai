<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ImageAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string $source_key
 * @property int|null $ai_model_id
 * @property string $analysis
 */
#[UseFactory(ImageAnalysisFactory::class)]
class ImageAnalysis extends Model
{
    /** @use HasFactory<ImageAnalysisFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];
}
