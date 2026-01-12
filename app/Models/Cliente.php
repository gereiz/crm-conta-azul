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
}
