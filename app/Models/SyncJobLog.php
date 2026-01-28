<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncJobLog extends Model
{
    protected $fillable = [
        'conta_azul_connection_id',
        'job_type',
        'started_at',
        'finished_at',
        'items_processed',
        'status',
        'message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'items_processed' => 'integer',
    ];

    public function connection()
    {
        return $this->belongsTo(ContaAzulConnection::class, 'conta_azul_connection_id');
    }
}
