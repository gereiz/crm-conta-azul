<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'code',
        'module',
        'action',
        'description',
    ];

    public function roles()
    {
        return $this->belongsToMany(UserRole::class, 'role_permissions', 'permission_id', 'role_id');
    }
}
