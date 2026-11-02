<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function show(Order $order)
    {
        if ($order->isPending() && $order->isPastExpiry()) {
            $order = $this->orders->check($order);
        }

        return view('pay', ['order' => $order->load('paymentAccount', 'user')]);
    }

    /**
     * Polled by the checkout page every few seconds.
     */
    public function status(Order $order)
    {
        $order = $this->orders->check($order);

        return response()->json([
            'status' => $order->status,
            'redirect' => in_array($order->status, [Order::PAID, Order::EXPIRED]) ? $this->redirectUrl($order) : null,
        ]);
    }

    /**
     * Customer submits the UTR after paying to a manual-verification UPI ID.
     */
    public function submitUtr(Request $request, Order $order)
    {
        $data = $request->validate([
            'utr' => ['required', 'string', 'regex:/^\s*\d{12}\s*$/'],
        ], ['utr.regex' => 'UTR must be the 12-digit number shown in your UPI app after payment.']);

        $this->orders->submitCustomerUtr($order->load('paymentAccount'), $data['utr']);

        return redirect()->route('pay.show', $order);
    }

    /**
     * Send the customer to the PG's hosted checkout (PhonePe PG / Paytm PG).
     * The PG session is cached on the order so reopening the link reuses it.
     */
    public function start(Order $order)
    {
        $order->load('paymentAccount');
        abort_unless($order->paymentAccount->isHosted(), 404);

        if (! $order->isPending() || $order->isPastExpiry()) {
            return redirect()->route('pay.show', $order);
        }

        $checkout = $order->provider_checkout;

        if (! $checkout || ($checkout['expires_at'] ?? 0) <= now()->timestamp + 30) {
            $verifier = $order->paymentAccount->verifier();

            try {
                $checkout = $verifier->startCheckout($order, route('pay.return', $order));
            } catch (RuntimeException $e) {
                return redirect()->route('pay.show', $order)->withErrors(['checkout' => $e->getMessage()]);
            }

            $order->forceFill(['provider_checkout' => $checkout])->save();
        }

        return $checkout['type'] === 'redirect'
            ? redirect()->away($checkout['url'])
            : view('pay-redirect', ['order' => $order, 'checkout' => $checkout]);
    }

    /**
     * The PG sends the customer back here (Paytm POSTs, PhonePe GETs). We
     * ignore whatever the PG posted and ask its Status API instead.
     */
    public function return(Order $order)
    {
        $order = $this->orders->check($order->load('paymentAccount'), force: true);

        if ($order->status === Order::PAID && $order->redirect_url) {
            return redirect()->away($this->redirectUrl($order));
        }

        return redirect()->route('pay.show', $order);
    }

    public function qr(Order $order)
    {
        abort_unless($order->isPending() && ! $order->paymentAccount->isHosted(), 404);

        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd));

        return response($writer->writeString($order->upiIntentUrl()), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    private function redirectUrl(Order $order): ?string
    {
        if (! $order->redirect_url) {
            return null;
        }

        $separator = str_contains($order->redirect_url, '?') ? '&' : '?';

        return $order->redirect_url.$separator.http_build_query([
            'order_id' => $order->order_no,
            'client_order_id' => $order->client_order_id,
            'status' => $order->status,
        ]);
    }
}
