<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContaAzulToken extends Model
{
    protected $fillable = [
        'access_token',
        'refresh_token',
        'expires_in',
        'expires_at',
        'user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
