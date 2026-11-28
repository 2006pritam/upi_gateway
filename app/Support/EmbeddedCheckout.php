<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers which website opened a checkout popup, so the checkout page can
 * report the order status back to that window (and nowhere else).
 *
 * Kept in the cache rather than the session because hosted PGs return the
 * customer with a cross-site POST that does not carry the session cookie.
 *
 * The message is a UI hint only. Merchants must confirm payments through a
 * signed webhook or the Order Status API.
 */
class EmbeddedCheckout
{
    private const ORIGIN_PATTERN = '~^https?://(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\[[0-9a-f:]+\])(?::\d{1,5})?$~i';

    public static function remember(Order $order, mixed $origin): void
    {
        if (is_string($origin) && preg_match(self::ORIGIN_PATTERN, $origin)) {
            Cache::put(self::key($order), $origin, now()->addDays(2));
        }
    }

    public static function origin(Order $order): ?string
    {
        return Cache::get(self::key($order));
    }

    private static function key(Order $order): string
    {
        return 'checkout-origin:'.$order->order_no;
    }
}
