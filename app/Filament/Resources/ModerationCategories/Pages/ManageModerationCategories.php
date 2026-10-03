<?php

namespace App\Filament\Resources\ModerationCategories\Pages;

use App\Filament\Resources\ModerationCategories\ModerationCategoryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageModerationCategories extends ManageRecords
{
    protected static string $resource = ModerationCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
