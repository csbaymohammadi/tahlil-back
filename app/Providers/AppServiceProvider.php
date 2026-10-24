<?php

namespace App\Providers;

use App\Media\MediaConsumers;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MediaConsumers::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TrustProxies::at(config('panel.trusted_proxies'));
        Auth::viaRequest('panel-session', fn (Request $request) => $request->attributes->get('panel_user'));
    }
}
