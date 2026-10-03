<?php

namespace App\Filament\Resources\ContextOptions\Pages;

use App\Filament\Resources\ContextOptions\ContextOptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageContextOptions extends ManageRecords
{
    protected static string $resource = ContextOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
