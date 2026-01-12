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
    ];
}
