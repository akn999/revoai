<?php

namespace App\Filament\Resources\PromptDefaults\Pages;

use App\Filament\Resources\PromptDefaults\PromptDefaultResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePromptDefaults extends ManageRecords
{
    protected static string $resource = PromptDefaultResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
