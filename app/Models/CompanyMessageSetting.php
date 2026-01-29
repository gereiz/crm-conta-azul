<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyMessageSetting extends Model
{
    protected $fillable = [
        'conta_azul_connection_id',
        'message_type',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function connection()
    {
        return $this->belongsTo(ContaAzulConnection::class, 'conta_azul_connection_id');
    }
}
