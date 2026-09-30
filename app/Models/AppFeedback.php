<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\AppFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $rating
 * @property string|null $rated_by
 * @property string|null $comment
 * @property int|null $app_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(AppFeedbackFactory::class)]
class AppFeedback extends Model
{
    /** @use HasFactory<AppFeedbackFactory> */
    use BelongsToMerchant, HasFactory;

    protected $table = 'app_feedback';

    protected $guarded = [];
}
