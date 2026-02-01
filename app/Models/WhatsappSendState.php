<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSendState extends Model
{
    protected $table = 'whatsapp_send_states';

    protected $fillable = [
        'whatsapp_number_id',
        'in_progress',
        'last_started_at',
        'last_finished_at',
        'paused_until',
        'hourly_count',
        'hourly_window_start',
        'daily_count',
        'daily_date',
        'warmup_start_date',
    ];

    protected $casts = [
        'in_progress' => 'boolean',
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
        'paused_until' => 'datetime',
        'hourly_window_start' => 'datetime',
        'daily_date' => 'date',
        'warmup_start_date' => 'date',
    ];
}
