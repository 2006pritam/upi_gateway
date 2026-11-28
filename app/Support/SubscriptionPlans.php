<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class SubscriptionPlans
{
    public const TRIAL = 'trial';

    /** A plan lasts either a number of `days` or a number of calendar `months`. */
    public const PLANS = [
        self::TRIAL => ['label' => '2 day trial', 'amount' => 1, 'days' => 2],
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

    /**
     * The plans this merchant may choose. The trial is for accounts that have
     * never had a plan, so it can be used once.
     *
     * @return array<string, array{label: string, amount: int, days?: int, months?: int}>
     */
    public static function availableTo(User $merchant): array
    {
        $plans = self::PLANS;

        if ($merchant->subscription_started_at !== null || $merchant->subscription_plan !== null) {
            unset($plans[self::TRIAL]);
        }

        return $plans;
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

        $plan = self::PLANS[$key];

        return isset($plan['days'])
            ? $startsAt->copy()->addDays($plan['days'])
            : $startsAt->copy()->addMonthsNoOverflow($plan['months']);
    }
}
