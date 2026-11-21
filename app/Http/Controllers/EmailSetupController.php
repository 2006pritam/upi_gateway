<?php

namespace App\Http\Controllers;

use App\Models\EmailPayment;
use App\Models\PaymentAccount;
use Illuminate\Http\Request;

/**
 * Shows the merchant the ready-to-paste Google Apps Script for an e-mail verified account.
 * The script carries the account's ingest secret, so this page is owner-only.
 */
class EmailSetupController extends Controller
{
    public function show(Request $request, PaymentAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id && $account->provider === 'fam_email', 404);

        $script = str_replace(
            ['__ENDPOINT__', '__ACCOUNT_ID__', '__SECRET__', '__SENDER__'],
            [route('api.email-ingest', $account->id), (string) $account->id, (string) $account->credential('ingest_secret'), (string) config('gateway.fam_email.sender')],
            (string) file_get_contents(resource_path('apps-script/famapp-email.js')),
        );

        return view('accounts.email-setup', [
            'account' => $account,
            'script' => $script,
            'recent' => EmailPayment::where('payment_account_id', $account->id)->latest('id')->limit(10)->get(),
        ]);
    }
}
