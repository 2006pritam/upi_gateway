<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        return view('settings', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request)
    {
        $request->user()->update($request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'business_name' => ['required', 'string', 'max:100'],
            'phone'         => ['nullable', 'string', 'max:20'],
        ]));

        return back()->with('status', 'Profile updated.');
    }

    public function updateWebhook(Request $request)
    {
        $data = $request->validate([
            'webhook_url' => ['nullable', 'string', 'max:500'],
        ]);

        $url = $data['webhook_url'] ?? null;

        if ($url !== null && $url !== '') {
            try {
                SafeUrl::validate($url);
            } catch (ValidationException $e) {
                throw $e;
            }
        } else {
            $url = null;
        }

        $request->user()->update(['webhook_url' => $url]);

        return back()->with('status', 'Webhook URL saved.');
    }

    public function updateRotation(Request $request)
    {
        $request->user()->update($request->validate([
            'rotation_mode' => ['required', Rule::in(array_keys(User::ROTATION_MODES))],
        ]));

        return back()->with('status', 'Account rotation updated.');
    }

    /**
     * Update the list of allowed redirect URL domains.
     * Each entry is a bare hostname (no scheme, no path).
     */
    public function updateRedirectDomains(Request $request)
    {
        $data = $request->validate([
            'redirect_domains'   => ['nullable', 'string', 'max:2000'],
        ]);

        // Parse newline/comma-separated list into an array of clean hostnames.
        $raw     = $data['redirect_domains'] ?? '';
        $domains = array_values(array_filter(
            array_map(
                fn ($d) => preg_replace('#^https?://#', '', strtolower(trim($d))),
                preg_split('/[\n,]+/', $raw)
            ),
            fn ($d) => $d !== '' && preg_match('/^[a-z0-9.\-]+$/', $d)
        ));

        $request->user()->update(['redirect_domains' => $domains ?: null]);

        return back()->with('status', 'Allowed redirect domains updated.');
    }

    public function regenerateKeys(Request $request)
    {
        $request->user()->regenerateApiCredentials();

        return back()->with('status', 'New API keys generated. Update them in your website/app.');
    }
}
