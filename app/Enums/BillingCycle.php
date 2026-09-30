<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case OneTime = 'one_time';
    case Trial = 'trial';
    case Custom = 'custom';

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
