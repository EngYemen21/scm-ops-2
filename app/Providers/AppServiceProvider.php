<?php

namespace App\Providers;

use App\Services\Core\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request/process: it holds the settings cache.
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        // Brute-force protection for sign-in: 10 attempts per minute for one username from one address.
        // Keyed by username + IP so one attacker cannot lock everybody out, and one office IP is not throttled as a whole.
        RateLimiter::for('login', function (Request $request) {
            $key = mb_strtolower(trim((string) $request->input('username'))).'|'.$request->ip();

            return Limit::perMinute((int) config('scm_auth.login_attempts_per_minute'))->by($key)->response(fn () => response()->json([
                'category' => 'VALIDATION', 'code' => 'TOO_MANY_ATTEMPTS',
                'message' => 'محاولات دخول كثيرة — انتظر دقيقة ثم حاول مجددًا', 'messageEn' => 'Too many sign-in attempts — wait a minute and try again',
                'details' => null, 'requestId' => $request->attributes->get('requestId'),
            ], 429));
        });
    }
}
