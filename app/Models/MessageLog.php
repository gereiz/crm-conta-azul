<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageLog extends Model
{
    protected $fillable = [
        'user_id',
        'whatsapp_number_id',
        'to',
        'content',
        'status',
        'error_message',
        'message_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function whatsappNumber()
    {
        return $this->belongsTo(WhatsappNumber::class);
    }
}
