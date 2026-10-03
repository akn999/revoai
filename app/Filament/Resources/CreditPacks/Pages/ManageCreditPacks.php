<?php

namespace App\Filament\Resources\CreditPacks\Pages;

use App\Filament\Resources\CreditPacks\CreditPackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCreditPacks extends ManageRecords
{
    protected static string $resource = CreditPackResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
