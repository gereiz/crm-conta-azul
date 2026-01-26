<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContaAzulConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'empresa_nome',
        'email_desenvolvedor',
        'ca_client_id',
        'ca_client_secret',
        'ca_redirect_uri',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_active',
        'last_sync_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'is_active' => 'boolean',
        'ca_client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    protected $hidden = [
        'ca_client_secret',
        'access_token',
        'refresh_token',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function messageSettings()
    {
        return $this->hasMany(CompanyMessageSetting::class);
    }
}
