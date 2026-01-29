<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        if (! SystemSetting::query()->exists()) {
            SystemSetting::create([
                'system_name' => 'IbitWeb',
                'primary_color' => '#6366F1',
                'secondary_color' => '#22C55E',
                'logo_path' => null,
                'favicon_path' => null,
                'contaazul_cron_enabled' => true,
            ]);
        }
    }
}
