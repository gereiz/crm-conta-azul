<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Se estiver em rotas de instalação, ignora verificação de tabelas
        if ($request->is('install*')) {
            return [
                ...parent::share($request),
                'auth' => ['user' => null],
                'flash' => [],
                'system_settings' => [
                    'system_name' => 'Instalador',
                    'primary_color' => '#6366F1',
                    'secondary_color' => '#22C55E',
                    'logo' => null,
                    'favicon' => null,
                ],
            ];
        }

        try {
            $settings = Schema::hasTable('system_settings')
                ? SystemSetting::latest()->first()
                : null;
        } catch (\Exception $e) {
            // Se falhar conexão com banco (ex: sqlite não existe), assume null
            $settings = null;
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
                'cron_report' => fn () => $request->session()->get('cron_report'),
                'whatsapp_error' => fn () => $request->session()->get('whatsapp_error'),
            ],
            'system_settings' => [
                'system_name' => $settings->system_name ?? '',
                'primary_color' => $settings->primary_color ?? '#6366F1',
                'secondary_color' => $settings->secondary_color ?? '#22C55E',
                'logo' => $settings->logo_path ?? null,
                'favicon' => $settings->favicon_path ?? null,
                'whapi_webhook_enabled' => (bool) ($settings->whapi_webhook_enabled ?? false),
                'whapi_webhook_url' => $settings->whapi_webhook_url ?? null,
                'evolution_webhook_enabled' => (bool) ($settings->evolution_webhook_enabled ?? false),
                'evolution_webhook_url' => $settings->evolution_webhook_url ?? null,
            ],
        ];
    }
}
