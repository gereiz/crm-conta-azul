<?php

namespace App\Providers;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
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
        try {
            $tz = config('app.timezone') ?: 'America/Sao_Paulo';
            date_default_timezone_set($tz);
        } catch (\Throwable $e) {
        }
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

        // Garante que o symlink de storage exista (evita perda aparente de imagens após commits/deploys)
        try {
            $publicStorage = public_path('storage');
            $target = storage_path('app/public');
            if (! is_link($publicStorage) && is_dir($target)) {
                @symlink($target, $publicStorage);
            }
        } catch (\Throwable $e) {
            // Ambientes Windows podem exigir permissões elevadas; ignorar silenciosamente
        }
    }
}
