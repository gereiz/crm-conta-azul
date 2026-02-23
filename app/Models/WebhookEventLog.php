<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEventLog extends Model
{
    protected $fillable = [
        'provider',
        'event_type',
        'from_me',
        'phone',
        'chat_id',
        'provider_message_id',
        'matched_log_id',
        'reason',
        'payload_json',
    ];

    protected $casts = [
        'from_me' => 'boolean',
        'payload_json' => 'array',
    ];
}
