<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Order;
use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'environment' => ['required', Rule::in([Order::LIVE, Order::SANDBOX])],
            'webhook_url' => ['nullable', 'string', 'max:500'],
        ]);

        if ($user->apiKeys()->whereNull('revoked_at')->count() >= ApiKey::MAX_ACTIVE_PER_USER) {
            return back()->withErrors(['name' => 'You can have at most '.ApiKey::MAX_ACTIVE_PER_USER.' active keys. Revoke one you no longer use.'])->withInput();
        }

        $webhook = ($data['webhook_url'] ?? '') !== '' ? $data['webhook_url'] : null;
        if ($webhook !== null) {
            SafeUrl::validate($webhook);
        }

        [$apiKey, $secret] = ApiKey::issue($user, $data['name'], $data['environment'], $webhook);

        return back()
            ->with('status', "Site \"{$apiKey->name}\" saved with its own API key and secret. Copy the secret now; it will not be shown again.")
            ->with('new_api_key_encrypted', Crypt::encryptString(json_encode(['name' => $apiKey->name, 'key' => $apiKey->key, 'secret' => $secret])));
    }

    public function update(Request $request, ApiKey $apiKey)
    {
        abort_unless($apiKey->user_id === $request->user()->id && $apiKey->isActive(), 404);
        $data = $request->validate(['webhook_url' => ['nullable', 'string', 'max:500']]);

        $webhook = ($data['webhook_url'] ?? '') !== '' ? $data['webhook_url'] : null;
        if ($webhook !== null) {
            SafeUrl::validate($webhook);
        }
        $apiKey->update(['webhook_url' => $webhook]);

        return back()->with('status', 'Webhook URL for this key saved.');
    }

    /** Revoking is immediate and permanent; the row stays so old orders still show which site created them. */
    public function destroy(Request $request, ApiKey $apiKey)
    {
        abort_unless($apiKey->user_id === $request->user()->id, 404);
        $apiKey->forceFill(['revoked_at' => now()])->save();

        return back()->with('status', "Key \"{$apiKey->name}\" revoked. Requests using it now fail.");
    }
}
