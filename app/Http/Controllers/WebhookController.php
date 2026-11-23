<?php

namespace App\Http\Controllers;

use App\Jobs\SendWebhook;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function index(Request $request)
    {
        $query = WebhookLog::whereHas('order', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('order:id,order_no,client_order_id,environment')->latest('id');

        if ($request->query('filter') === 'failed') {
            $query->where('success', false);
        }

        return view('webhooks.index', [
            'logs' => $query->paginate(25)->withQueryString(),
            'url' => $request->user()->webhook_url,
        ]);
    }

    /** Send the same event again (e.g. after the merchant's server was down). Skips the "already delivered" guard. */
    public function resend(Request $request, WebhookLog $log)
    {
        $order = $log->order()->with('user')->firstOrFail();
        abort_unless($order->user_id === $request->user()->id, 404);

        // Re-send only to the URL this log entry was for (an extra endpoint, or the primary URL).
        $endpoint = WebhookEndpoint::where('user_id', $order->user_id)->where('url', $log->url)->first();

        SendWebhook::dispatch($order, $log->event, force: true, endpointId: $endpoint?->id);

        return back()->with('status', "Webhook {$log->event} queued for re-delivery.");
    }
}
