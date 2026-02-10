<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="app-name" content="{{ isset($system_settings) && $system_settings->system_name ? $system_settings->system_name : config('app.name', 'Laravel') }}">

        <title inertia>{{ isset($system_settings) && $system_settings->system_name ? $system_settings->system_name : config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        @php
            $favStoragePath = isset($system_settings) ? $system_settings->favicon_path : null;
            $favExists = false;
            try {
                $favExists = $favStoragePath ? \Illuminate\Support\Facades\Storage::disk('public')->exists($favStoragePath) : false;
            } catch (\Exception $e) {
                $favExists = false;
            }
            $favUrl = $favExists ? asset('storage/'.$favStoragePath) : asset('favicon.ico');
        @endphp
        <link rel="icon" type="image/png" href="{{ $favUrl }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
        @php
            try {
                $settings = \Illuminate\Support\Facades\Schema::hasTable('system_settings') 
                    ? \App\Models\SystemSetting::latest()->first() 
                    : null;
            } catch (\Exception $e) {
                $settings = null;
            }
            
            $primaryHex = $settings->primary_color ?? '#6366F1';
            $secondaryHex = $settings->secondary_color ?? '#22C55E';
            $hexToRgb = function ($hex) {
                $hex = ltrim($hex, '#');
                if (strlen($hex) === 3) {
                    $r = hexdec(str_repeat($hex[0], 2));
                    $g = hexdec(str_repeat($hex[1], 2));
                    $b = hexdec(str_repeat($hex[2], 2));
                } else {
                    $r = hexdec(substr($hex, 0, 2));
                    $g = hexdec(substr($hex, 2, 2));
                    $b = hexdec(substr($hex, 4, 2));
                }
                return "{$r} {$g} {$b}";
            };
        @endphp
        <style>
            :root {
                --color-primary-50: 238 242 255;
                --color-primary-100: 224 231 255;
                --color-primary-200: 199 210 254;
                --color-primary-300: 165 180 252;
                --color-primary-400: 129 140 248;
                --color-primary-500: {{ $hexToRgb($primaryHex) }};
                --color-primary-600: 79 70 229;
                --color-primary-700: 67 56 202;
                --color-primary-800: 55 48 163;
                --color-primary-900: 49 46 129;
                --color-primary-950: 30 27 75;

                --color-secondary-50: 240 253 244;
                --color-secondary-100: 220 252 231;
                --color-secondary-200: 187 247 208;
                --color-secondary-300: 134 239 172;
                --color-secondary-400: 74 222 128;
                --color-secondary-500: {{ $hexToRgb($secondaryHex) }};
                --color-secondary-600: 22 163 74;
                --color-secondary-700: 21 128 61;
                --color-secondary-800: 22 101 52;
                --color-secondary-900: 20 83 45;
                --color-secondary-950: 5 46 30;
            }
        </style>
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
