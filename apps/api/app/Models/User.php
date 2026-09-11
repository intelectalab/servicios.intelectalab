<?php

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements CanResetPasswordContract
{
    use HasApiTokens, HasFactory, CanResetPassword;

    protected $table = 'users';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'email',
        'password_hash',
        'name',
        'status',
        'mfa_enabled',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'mfa_enabled' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    // Laravel busca la contraseña en "password" por defecto;
    // le decimos que la busque en "password_hash" en su lugar.
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function hasPermission(string $permissionCode): bool
    {
        return DB::table('user_roles')
            ->join('role_permissions', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $this->id)
            ->where('permissions.code', $permissionCode)
            ->exists();
    }
}
