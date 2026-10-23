<?php

namespace App\Providers;

use App\Services\ActivationProvider;
use App\Services\DummyActivationProvider;
use App\Services\DummyPaymentProvider;
use App\Services\PaymentProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ActivationProvider::class, function (): ActivationProvider {
            if (config('school_erp.activation_driver') !== 'dummy') {
                throw new \RuntimeException('The configured activation provider is not installed.');
            }

            return new DummyActivationProvider;
        });
        $this->app->bind(PaymentProvider::class, function (): PaymentProvider {
            if (config('school_erp.payment_driver') !== 'dummy') {
                throw new \RuntimeException('The configured payment provider is not installed.');
            }

            return new DummyPaymentProvider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
