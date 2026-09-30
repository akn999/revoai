<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\Subscription;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (Subscription $record): string => $record->currency ?? 'SAR';

        return $schema
            ->columns(1)
            ->components([
                Section::make('Subscription')
                    ->columns(3)
                    ->components([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('salla_subscription_id')->label('Salla subscription ID')->copyable()->placeholder('— (trial)'),
                        TextEntry::make('item_type')->label('Type')->badge()
                            ->formatStateUsing(fn (string $state): string => $state === 'addon' ? 'Add-on' : 'Plan')
                            ->color(fn (string $state): string => $state === 'addon' ? 'info' : 'primary'),
                        TextEntry::make('item_key')->label('Item key'),
                        TextEntry::make('plan_name')->label('Plan / add-on name')->placeholder('—'),
                        TextEntry::make('plan.name')->label('Local plan')->placeholder('Not mapped'),
                        TextEntry::make('plan_type')->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === 'one_time' ? 'One time' : ucfirst((string) $state))
                            ->placeholder('—'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('billing_cycle')->label('Cycle')->badge(),
                        TextEntry::make('period_months')->label('Period (months)')->placeholder('—'),
                        TextEntry::make('plan_period')->label('Raw plan_period')->placeholder('—'),
                        TextEntry::make('quantity')->numeric(0),
                        TextEntry::make('store_type')->badge()->placeholder('—'),
                    ]),

                Section::make('Dates')
                    ->columns(3)
                    ->components([
                        TextEntry::make('starts_at')->dateTime()->placeholder('—'),
                        TextEntry::make('ends_at')->dateTime()->placeholder('No end date'),
                        TextEntry::make('renewed_at')->dateTime()->placeholder('—'),
                        TextEntry::make('canceled_at')->dateTime()->placeholder('—'),
                        TextEntry::make('expired_at')->dateTime()->placeholder('—'),
                        TextEntry::make('superseded_at')->dateTime()->placeholder('—'),
                        TextEntry::make('last_event_at')->label('Last Salla event')->dateTime()->placeholder('—'),
                        TextEntry::make('created_at')->dateTime()->placeholder('—'),
                        TextEntry::make('updated_at')->dateTime()->placeholder('—'),
                    ]),

                Section::make('Charge')
                    ->columns(3)
                    ->components([
                        TextEntry::make('price')->money($money)->placeholder('—'),
                        TextEntry::make('price_before_discount')->money($money)->placeholder('—'),
                        TextEntry::make('initialization_cost')->money($money)->placeholder('—'),
                        TextEntry::make('tax_value')->money($money)->placeholder('—'),
                        TextEntry::make('total')->money($money)->placeholder('—'),
                        TextEntry::make('tax_rate')->label('Tax rate (fraction)')->numeric(4)->placeholder('—'),
                        TextEntry::make('currency'),
                        TextEntry::make('coupon_code')->placeholder('—'),
                        TextEntry::make('coupon_amount')->numeric(4)->placeholder('—'),
                    ]),

                Section::make('Merchant')
                    ->columns(3)
                    ->components([
                        TextEntry::make('merchant_id')->label('Salla merchant ID')->copyable(),
                        TextEntry::make('merchant.name')->label('Store')->placeholder('Unnamed store'),
                        TextEntry::make('merchant.email')->label('Email')->placeholder('—'),
                        TextEntry::make('merchant.mobile')->label('Mobile')->placeholder('—'),
                        TextEntry::make('merchant.domain')->label('Domain')->placeholder('—'),
                        TextEntry::make('merchant.owner_name')->label('Owner name')->placeholder('—'),
                        TextEntry::make('merchant.owner_email')->label('Owner email')->placeholder('—'),
                        TextEntry::make('merchant.status')->label('Merchant status')->badge(),
                        TextEntry::make('merchant.store_type')->label('Merchant store type')->badge()->placeholder('—'),
                        TextEntry::make('merchant.installed_at')->label('Installed at')->dateTime()->placeholder('—'),
                        TextEntry::make('merchant.uninstalled_at')->label('Uninstalled at')->dateTime()->placeholder('—'),
                        TextEntry::make('merchant.profile_synced_at')->label('Profile synced at')->dateTime()->placeholder('—'),
                    ]),

                Section::make('Salla extras (meta)')
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        TextEntry::make('meta')
                            ->label('Raw meta (promotion, balance, categories)')
                            ->state(fn (Subscription $record): string => json_encode($record->meta ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                            ->fontFamily(FontFamily::Mono)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
