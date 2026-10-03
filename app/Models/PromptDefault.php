<?php

namespace App\Models;

use App\Models\Concerns\AuditsAdminChanges;
use Database\Factories\PromptDefaultFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string $body
 */
#[UseFactory(PromptDefaultFactory::class)]
class PromptDefault extends Model
{
    /** @use HasFactory<PromptDefaultFactory> */
    use AuditsAdminChanges, HasFactory;

    protected $guarded = ['id'];

    public const PRODUCT_TONE = 'product_tone';

    public const IMAGE_GENERAL = 'image_general';

    public const IMAGE_EDITING = 'image_editing';

    public const CHAT_SYSTEM = 'chat_system';

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return [self::PRODUCT_TONE, self::IMAGE_GENERAL, self::IMAGE_EDITING, self::CHAT_SYSTEM];
    }
}
