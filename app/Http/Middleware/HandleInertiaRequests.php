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
        $settings = Schema::hasTable('system_settings')
            ? SystemSetting::latest()->first()
            : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'message' => fn () => $request->session()->get('message'),
            ],
            'system_settings' => [
                'system_name' => $settings->system_name ?? '',
                'primary_color' => $settings->primary_color ?? '#6366F1',
                'secondary_color' => $settings->secondary_color ?? '#22C55E',
                'logo' => $settings->logo_path ?? null,
                'favicon' => $settings->favicon_path ?? null,
            ],
        ];
    }
}
