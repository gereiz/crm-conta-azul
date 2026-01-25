<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FutureMessageSchedule extends Model
{
    protected $fillable = [
        'connection_id',
        'cliente_id',
        'message_type',
        'invoice_id',
        'event_date',
        'scheduled_send_date',
        'status',
        'block_reason',
    ];

    protected $casts = [
        'event_date' => 'date',
        'scheduled_send_date' => 'date',
    ];

    public function connection()
    {
        return $this->belongsTo(ContaAzulConnection::class, 'connection_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
