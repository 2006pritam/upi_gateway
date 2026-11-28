<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class SubscriptionPlans
{
    public const PLANS = [
        'monthly' => ['label' => '1 month', 'amount' => 199, 'months' => 1],
        'six_months' => ['label' => '6 months', 'amount' => 599, 'months' => 6],
        'annual' => ['label' => '12 months', 'amount' => 2100, 'months' => 12],
    ];

    /** Granted only when the admin creates an account; deliberately not in PLANS, so signup can't pick it. */
    public const LIFETIME = 'lifetime';

    public static function valid(string $key): bool
    {
        return array_key_exists($key, self::PLANS);
    }

    public static function label(?string $key): string
    {
        if ($key === self::LIFETIME) {
            return 'Lifetime';
        }

        return self::PLANS[$key]['label'] ?? 'Not selected';
    }

    public static function amount(?string $key): ?int
    {
        return self::PLANS[$key]['amount'] ?? null;
    }

    public static function expiry(string $key, Carbon $startsAt): Carbon
    {
        if (! self::valid($key)) {
            throw new InvalidArgumentException('Unknown subscription plan.');
        }

        return $startsAt->copy()->addMonthsNoOverflow(self::PLANS[$key]['months']);
    }
}
