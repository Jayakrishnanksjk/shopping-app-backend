<?php

namespace App\Providers;

use App\Facades\AppMethods;
use App\Services\MasterToken;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('App',function(){
            return new AppMethods();
        });

        $this->app->bind(
            \App\Services\Sms\SmsProviderInterface::class,
            \App\Services\Sms\SpringEdgeSmsProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        \Illuminate\Support\Facades\URL::forceScheme('https');

        // The master token (Pearl XP integration) must never expire, while every
        // other token keeps honouring config('sanctum.expiration') (7200 min).
        // Sanctum passes the pre-computed validity; we override it for the master
        // token only. It is still revoked instantly by `master-token:reset`.
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, $isValid) {
            if (MasterToken::isMasterToken($accessToken)) {
                return true;
            }

            return $isValid;
        });
    }
}
