<?php

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;

test('billing cycle is derived from the months between the dates', function (?string $planType, ?string $start, ?string $end, BillingCycle $cycle, ?int $months) {
    [$actualCycle, $actualMonths] = BillingCycle::fromPayload(
        $planType,
        $start ? CarbonImmutable::parse($start) : null,
        $end ? CarbonImmutable::parse($end) : null,
    );

    expect($actualCycle)->toBe($cycle)->and($actualMonths)->toBe($months);
})->with([
    'monthly' => ['recurring', '2026-01-01', '2026-02-01', BillingCycle::Monthly, 1],
    'yearly' => ['recurring', '2026-01-01', '2027-01-01', BillingCycle::Yearly, 12],
    'quarterly is custom' => ['recurring', '2026-01-01', '2026-04-01', BillingCycle::Custom, 3],
    'one time plan type' => ['one_time', '2026-01-01', '2026-02-01', BillingCycle::OneTime, null],
    'missing end date' => ['recurring', '2026-01-01', null, BillingCycle::OneTime, null],
    'missing dates' => ['recurring', null, null, BillingCycle::OneTime, null],
]);
