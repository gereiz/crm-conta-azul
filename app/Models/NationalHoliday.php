<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NationalHoliday extends Model
{
    use HasFactory;

    protected $table = 'national_holidays';

    protected $fillable = [
        'date',
        'name',
    ];
}

