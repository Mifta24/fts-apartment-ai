<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Every guest message costs an LLM round trip, so it is limited per
     * conversation and, as a backstop against rotating tokens, per address.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('concierge-start', fn (Request $request) => Limit::perMinute(30)->by('start:'.$request->ip()));

        RateLimiter::for('concierge-message', fn (Request $request) => [
            Limit::perMinute(12)->by('conversation:'.($request->input('guest_token') ?: $request->ip())),
            Limit::perMinute(40)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('concierge-handover', fn (Request $request) => Limit::perMinute(5)->by('handover:'.$request->ip()));

        RateLimiter::for('concierge-feedback', fn (Request $request) => Limit::perMinute(30)->by('feedback:'.$request->ip()));

        RateLimiter::for('concierge-transcript', fn (Request $request) => Limit::perMinute(3)->by('transcript:'.$request->ip()));

        RateLimiter::for('concierge-history', fn (Request $request) => Limit::perMinute(60)->by('history:'.$request->ip()));
    }
}
