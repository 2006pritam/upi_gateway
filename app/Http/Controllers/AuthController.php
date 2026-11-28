<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SubscriptionPlans;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    // ── Login ─────────────────────────────────────────────────────────────────

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Per-email+IP lockout after 10 failures in 15 minutes.
        $lockKey = $this->lockKey($request);
        if (Cache::has($lockKey)) {
            return back()
                ->withErrors(['email' => 'Too many login attempts. Please try again later.'])
                ->onlyInput('email');
        }

        // Per-email+IP rate limit: 5 attempts per minute.
        $rateLimitKey = $this->rateLimitKey($request);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            event(new Lockout($request));

            return back()
                ->withErrors(['email' => 'Too many login attempts. Please try again in a moment.'])
                ->onlyInput('email');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($rateLimitKey, 60);          // decay 1 min
            $failures = Cache::increment($this->failCountKey($request));
            if ($failures >= 10) {
                Cache::put($lockKey, true, 900);          // lock 15 min
                Cache::forget($this->failCountKey($request));
            }

            // Generic message — does not reveal whether the email exists.
            return back()
                ->withErrors(['email' => 'These credentials do not match our records.'])
                ->onlyInput('email');
        }

        if (! Auth::user()->is_active) {
            Auth::logout();

            return back()
                ->withErrors(['email' => 'Your account has been disabled.'])
                ->onlyInput('email');
        }

        // Successful login — clear counters and regenerate session.
        RateLimiter::clear($rateLimitKey);
        Cache::forget($this->failCountKey($request));
        Cache::forget($lockKey);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    // ── Register ──────────────────────────────────────────────────────────────

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'business_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:20'],
            'plan' => ['required', 'string', Rule::in(array_keys(SubscriptionPlans::PLANS))],
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ]);

        $plan = $data['plan'];
        unset($data['plan']);

        $user = User::create($data + [
            'role' => 'merchant',
            'is_active' => true,
            'subscription_status' => User::SUBSCRIPTION_PENDING,
            'subscription_requested_plan' => $plan,
        ]);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('subscription.show')
            ->with('status', 'Your account request is waiting for admin approval. Access will begin only after payment is confirmed by the admin.');
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Password rule: min 10 chars; uncompromised check only in production.
     */
    private function passwordRule(): Password
    {
        $rule = Password::min(10);
        if (app()->isProduction()) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }

    /** RateLimiter key: 5 attempts/min per email+IP */
    private function rateLimitKey(Request $request): string
    {
        return 'login:'.sha1($request->input('email').'|'.$request->ip());
    }

    /** Failure counter key (counts toward the 10-failure lockout) */
    private function failCountKey(Request $request): string
    {
        return 'login_fails:'.sha1($request->input('email').'|'.$request->ip());
    }

    /** Lockout key: set when 10 failures have been reached */
    private function lockKey(Request $request): string
    {
        return 'login_lock:'.sha1($request->input('email').'|'.$request->ip());
    }
}
