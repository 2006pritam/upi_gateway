<?php

namespace App\Http\Controllers;

use App\Models\PaymentLink;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;

class PaymentLinkController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('links.index', [
            'links' => $user->paymentLinks()->withCount(['orders', 'orders as paid_count' => fn ($q) => $q->where('status', 'paid')])->latest()->get(),
            'accounts' => $user->paymentAccounts()->where('is_active', true)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:100000', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:50'],
            'payment_account_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['payment_account_id'])
            && ! $request->user()->paymentAccounts()->whereKey($data['payment_account_id'])->exists()) {
            return back()->withErrors(['payment_account_id' => 'This UPI account does not exist.'])->withInput();
        }

        $request->user()->paymentLinks()->create($data);

        return back()->with('status', 'Payment link created. Share the link or print the QR poster.');
    }

    public function toggle(Request $request, PaymentLink $link)
    {
        $this->own($request, $link);
        $link->update(['is_active' => ! $link->is_active]);

        return back()->with('status', $link->is_active ? 'Link enabled.' : 'Link disabled. Customers can no longer open it.');
    }

    public function destroy(Request $request, PaymentLink $link)
    {
        $this->own($request, $link);
        $link->delete();

        return back()->with('status', 'Link deleted.');
    }

    /** Printable A4 poster: shop name, amount and a QR code that opens the link. */
    public function poster(Request $request, PaymentLink $link)
    {
        $this->own($request, $link);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(360, 1), new SvgImageBackEnd)))->writeString($link->publicUrl());

        return view('links.poster', ['link' => $link->load('user'), 'qr' => $svg]);
    }

    private function own(Request $request, PaymentLink $link): void
    {
        abort_unless($link->user_id === $request->user()->id, 404);
    }
}
