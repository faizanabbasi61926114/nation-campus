<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(fn () => '/api/v1/auth/login');

        Gate::before(function ($user, $ability) {
            return $user->hasPermission($ability) ? true : null;
        });
    }
}
