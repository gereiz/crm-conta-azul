<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappMessageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'cliente_id',
        'client_name',
        'phone_original',
        'phone_sanitized',
        'message_type',
        'message_template_id',
        'total_boletos',
        'boleto_ids',
        'status',
        'error_message',
        'content',
        'batch_id',
        'message_cron_id',
        'user_id',
        'sent_at',
    ];

    protected $casts = [
        'boleto_ids' => 'array',
        'sent_at' => 'datetime',
    ];

    public function connection()
    {
        return $this->belongsTo(ContaAzulConnection::class, 'connection_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function template()
    {
        return $this->belongsTo(WhatsappTemplate::class, 'message_template_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
