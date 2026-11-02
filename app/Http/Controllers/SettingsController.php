<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        return view('settings', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request)
    {
        $request->user()->update($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'business_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]));

        return back()->with('status', 'Profile updated.');
    }

    public function updateWebhook(Request $request)
    {
        $request->user()->update($request->validate([
            'webhook_url' => ['nullable', 'url:http,https', 'max:255'],
        ]));

        return back()->with('status', 'Webhook URL saved.');
    }

    public function updateRotation(Request $request)
    {
        $request->user()->update($request->validate([
            'rotation_mode' => ['required', Rule::in(array_keys(User::ROTATION_MODES))],
        ]));

        return back()->with('status', 'Account rotation updated.');
    }

    public function regenerateKeys(Request $request)
    {
        $request->user()->regenerateApiCredentials();

        return back()->with('status', 'New API keys generated. Update them in your website/app.');
    }
}
