<?php

namespace App\Providers;

use App\Services\Google\GoogleAccessTokenProvider;
use App\Services\Google\GoogleServiceAccountCredentials;
use App\Services\Google\GoogleSheetsClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GoogleServiceAccountCredentials::class, function () {
            return GoogleServiceAccountCredentials::fromConfig(config('services.google'));
        });

        $this->app->singleton(GoogleAccessTokenProvider::class, function ($app) {
            return new GoogleAccessTokenProvider(
                $app->make(GoogleServiceAccountCredentials::class),
            );
        });

        $this->app->singleton(GoogleSheetsClient::class, function ($app) {
            return new GoogleSheetsClient(
                $app->make(GoogleAccessTokenProvider::class),
                (string) config('services.google.spreadsheet_id'),
                (string) config('services.google.worksheet_name', 'Dashboard / Leady'),
            );
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
