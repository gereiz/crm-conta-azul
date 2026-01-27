<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'ca_id',
        'name',
        'company_name',
        'email',
        'phone',
        'mobile_phone',
        'cpf_cnpj',
        'person_type',
        'city',
        'state',
        'birthdate',
    ];

    public function setPhoneAttribute($value)
    {
        $this->attributes['phone'] = $value !== null ? preg_replace('/\D/', '', (string) $value) : null;
    }

    public function setMobilePhoneAttribute($value)
    {
        $this->attributes['mobile_phone'] = $value !== null ? preg_replace('/\D/', '', (string) $value) : null;
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'cliente_id');
    }
}
