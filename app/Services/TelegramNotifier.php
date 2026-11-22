<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Sends a plain-text message through the merchant's own Telegram bot (fixed host, so no SSRF surface). */
class TelegramNotifier
{
    public const TOKEN_PATTERN = '/^\d{6,12}:[A-Za-z0-9_-]{30,50}$/';

    public const CHAT_PATTERN = '/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/';

    public function configured(User $user): bool
    {
        return $user->telegram_bot_token && $user->telegram_chat_id;
    }

    public function send(User $user, string $text): bool
    {
        if (! $this->configured($user)) {
            return false;
        }

        try {
            return Http::timeout(8)->connectTimeout(4)->asForm()
                ->post('https://api.telegram.org/bot'.$user->telegram_bot_token.'/sendMessage', [
                    'chat_id' => $user->telegram_chat_id,
                    'text' => mb_substr($text, 0, 1000),
                    'disable_web_page_preview' => 'true',
                ])->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
