<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'role_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function roleRef()
    {
        return $this->belongsTo(UserRole::class, 'role_id');
    }

    public function hasPermission(string $module, string $action): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $role = $this->roleRef;
        if (! $role) {
            return false;
        }

        return $role->permissions()
            ->where('module', $module)
            ->where('action', $action)
            ->exists();
    }

    // Helpers para verificação de permissão
    public function isAdmin()
    {
        if ($this->role === 'admin') {
            return true;
        }
        $r = $this->roleRef;

        return $r && strtolower($r->name) === 'administrador';
    }

    public function isOperator()
    {
        if ($this->role === 'operator') {
            return true;
        }
        $r = $this->roleRef;

        return $r && in_array(strtolower($r->name), ['operacional', 'operador', 'operator']);
    }

    public function isViewer()
    {
        if ($this->role === 'viewer') {
            return true;
        }
        $r = $this->roleRef;

        return $r && in_array(strtolower($r->name), ['visualizador', 'viewer']);
    }
}
