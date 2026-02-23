<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageStatusLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_message_log_id',
        'provider',
        'status_original',
        'status_normalizado',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    public function message()
    {
        return $this->belongsTo(WhatsappMessageLog::class, 'whatsapp_message_log_id');
    }
}
