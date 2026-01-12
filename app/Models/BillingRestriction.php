<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingRestriction extends Model
{
    protected $fillable = [
        'connection_id',
        'type',
        'value',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function connection()
    {
        return $this->belongsTo(ContaAzulConnection::class, 'connection_id');
    }
}
