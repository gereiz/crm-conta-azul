<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Schema;
use App\Models\SystemSetting;

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
        if ($this->app->environment('local') || $this->app->environment('production')) {
            URL::forceScheme('https');
        }
        Vite::prefetch(concurrency: 3);

        // Share system settings with all Blade views
        try {
            if (Schema::hasTable('system_settings')) {
                $settings = SystemSetting::latest()->first();
                View::share('system_settings', $settings);
            }
        } catch (\Exception $e) {
            // Ignorar erros durante migrações ou setup inicial
        }
    }
}
