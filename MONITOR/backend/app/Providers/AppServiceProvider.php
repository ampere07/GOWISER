<?php

namespace App\Providers;

use App\Services\Connector\ConnectionManager;
use App\Services\SourceRegistry;
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
        /*
         * Both of these memoise the configured source list for the life of the
         * request, and both are injected in several places at once — the
         * reporting service, the controllers, the dashboards.
         *
         * They must be shared instances, or each injection point gets its own
         * cache and flush() only clears one of them. That is not theoretical:
         * DatabaseConnectionController holds a SourceRegistry *and* a
         * ReportingService that holds another, so after saving a connection it
         * would flush one cache and then ask the other what the new database can
         * serve — getting "nothing" for a connection that is perfectly fine.
         *
         * Per-request scope is the correct lifetime: Laravel resolves a fresh
         * singleton per request, so nothing leaks into the next one.
         */
        $this->app->singleton(SourceRegistry::class);
        $this->app->singleton(ConnectionManager::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
