<?php

namespace App\Providers;

use App\Ingestion\AssetStore;
use Illuminate\Support\ServiceProvider;
use Smalot\PdfParser\Parser as PdfParser;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Ingestion drivers are resolved from the container by class name, listed per chain in
        // config/leaflets.php — adding a chain is a config entry, not a change here.
        $this->app->singleton(AssetStore::class, fn (): AssetStore => AssetStore::fromConfig());

        $this->app->bind(PdfParser::class, fn (): PdfParser => new PdfParser);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
