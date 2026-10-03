<?php

namespace App\Filament\Resources\WebhookEvents\Pages;

use App\Filament\Resources\WebhookEvents\WebhookEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWebhookEvents extends ManageRecords
{
    protected static string $resource = WebhookEventResource::class;
}
