<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyCronRule extends Model
{
    protected $fillable = [
        'conta_azul_connection_id',
        'message_type',
        'rule_type',
        'day_of_month',
        'day_of_week',
        'interval_days',
        'exclude_weekends',
        'is_active',
    ];

    protected $casts = [
        'exclude_weekends' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function connection()
    {
        return $this->belongsTo(ContaAzulConnection::class, 'conta_azul_connection_id');
    }
}
