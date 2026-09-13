<?php

namespace App\Models;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email', 'country', 'city',
        'password', 'status', 'is_admin', 'totp_secret', 'totp_confirmed_at',
        'phone_verified_at', 'email_verified_at', 'risk_score', 'locale',
        'last_login_at', 'last_login_ip', 'failed_login_attempts', 'locked_until',
    ];

    protected $hidden = [
        'password', 'remember_token', 'totp_secret',
    ];

    protected $casts = [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'is_admin' => 'boolean',
            'risk_score' => 'integer',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'totp_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
    ];

    // ------------------------------------------------------------------ relations

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['assigned_by', 'expires_at'])
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function payments(): HasMany
    {
        return $this->hasManyThrough(Payment::class, Order::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function userSessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }

    // --------------------------------------------------------------------- RBAC

    public function hasRole(RoleName|string ...$roles): bool
    {
        $names = array_map(
            fn ($role) => $role instanceof RoleName ? $role->value : $role,
            $roles
        );

        return $this->relationLoaded('roles')
            ? $this->roles->pluck('name')->intersect($names)->isNotEmpty()
            : $this->roles()->whereIn('name', $names)->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleName::SuperAdmin);
    }

    public function isStaff(): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return $this->roles()
            ->where('name', '!=', RoleName::Participant->value)
            ->exists();
    }

    public function hasPermission(PermissionName|string $permission): bool
    {
        $name = $permission instanceof PermissionName ? $permission->value : $permission;

        if ($this->isSuperAdmin()) {
            return true;
        }

        return Permission::query()
            ->where('permissions.name', $name)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('role_permissions')
                    ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
                    ->whereColumn('role_permissions.permission_id', 'permissions.id')
                    ->where('user_roles.user_id', $this->id);
            })
            ->exists();
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        if ($this->isSuperAdmin()) {
            return array_map(fn (PermissionName $p) => $p->value, PermissionName::cases());
        }

        return Permission::query()
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('role_permissions')
                    ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
                    ->whereColumn('role_permissions.permission_id', 'permissions.id')
                    ->where('user_roles.user_id', $this->id);
            })
            ->pluck('name')
            ->all();
    }

    // ------------------------------------------------------------------- helpers

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** Nom affiché sur la page publique des gagnants : « J*** D*** ». */
    public function maskedName(): string
    {
        $mask = fn (string $value) => mb_strtoupper(mb_substr($value, 0, 1)).str_repeat('*', max(mb_strlen($value) - 1, 1));

        return $mask($this->first_name).' '.$mask($this->last_name);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function requiresMfa(): bool
    {
        return $this->isStaff();
    }

    public function hasMfaEnabled(): bool
    {
        return $this->totp_confirmed_at !== null && $this->totp_secret !== null;
    }
}
