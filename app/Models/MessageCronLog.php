<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageCronLog extends Model
{
    protected $fillable = [
        'message_cron_id',
        'cliente_id',
        'client_name',
        'phone',
        'status',
        'error_message',
        'content',
        'invoice_count',
        'sent_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function messageCron()
    {
        return $this->belongsTo(MessageCron::class);
    }
}
