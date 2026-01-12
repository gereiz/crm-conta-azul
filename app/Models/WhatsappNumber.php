<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappNumber extends Model
{
    protected $fillable = [
        'description',
        'ddi',
        'ddd',
        'phone',
        'whapi_key',
        'is_default',
        'status',
        'use_poli',
        'poli_key',
        'poli_customer',
        'poli_channel',
        'poli_user',
        'poli_template',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'use_poli' => 'boolean',
    ];
}
