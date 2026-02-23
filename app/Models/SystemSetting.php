<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'system_name',
        'primary_color',
        'secondary_color',
        'logo_path',
        'favicon_path',
        'contaazul_cron_enabled',
        'evolution_api_base_url',
        'whapi_webhook_enabled',
        'whapi_webhook_secret',
        'whapi_webhook_url',
        'evolution_webhook_enabled',
        'evolution_webhook_secret',
        'evolution_webhook_url',
    ];
}
