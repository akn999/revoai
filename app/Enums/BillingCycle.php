<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum BillingCycle: string implements HasLabel
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case OneTime = 'one_time';
    case Trial = 'trial';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
            self::OneTime => 'One time',
            self::Trial => 'Trial',
            self::Custom => 'Custom',
        };
    }

    /**
     * Derive the billing cycle from the payload's plan type and period dates.
     *
     * @return array{0: self, 1: int|null}
     */
    public static function fromPayload(?string $planType, ?CarbonInterface $start, ?CarbonInterface $end): array
    {
        if ($planType === 'one_time' || ! $start || ! $end) {
            return [self::OneTime, null];
        }

        $months = (int) round($start->diffInMonths($end));

        return [match ($months) {
            1 => self::Monthly,
            12 => self::Yearly,
            default => self::Custom,
        }, $months];
    }
}
