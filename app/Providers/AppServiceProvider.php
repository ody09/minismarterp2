<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use App\View\Composers\NavigationComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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
        View::composer('layouts.app', NavigationComposer::class);

        foreach (Permission::CODES as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower(trim($email)) : 'invalid';

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $email.'|'.$request->ip())),
            ];
        });
    }
}
