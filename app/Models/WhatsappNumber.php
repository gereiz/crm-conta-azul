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
        'provider',
        'provider_token',
        'provider_instance',
        'whapi_key',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];
}
