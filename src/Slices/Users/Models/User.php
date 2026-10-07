<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use LaraSlice\Core\Security\Traits\HasSlicePermissions;
use LaraSlice\Slices\Roles\Models\Role;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasSlicePermissions;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'avatar_url',
        'gender',
        'phone',
        'customised_permissions',
        'mfa_channel',
        'mfa_secret',
        'mfa_confirmed_at',
        'last_login_at',
        'last_login_ip',
        'failed_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mfa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'customised_permissions' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Extended Profile & Employment details (1-to-1 Aggregate)
     */
    public function detail(): HasOne
    {
        return $this->hasOne(UserDetail::class, 'user_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class, 'user_id');
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(UserRecoveryCode::class, 'user_id');
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(UserPasskey::class, 'user_id');
    }

    public function securityLogs(): HasMany
    {
        return $this->hasMany(UserSecurityLog::class, 'user_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function hasMfa(): bool
    {
        return (!empty($this->mfa_channel) && $this->mfa_channel !== 'none' && !empty($this->mfa_confirmed_at))
            || !empty($this->two_factor_confirmed_at)
            || $this->passkeys()->whereNull('revoked_at')->exists();
    }

    public function genderLabel(): string
    {
        return match (strtolower((string) $this->gender)) {
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
            default => 'Not Specified',
        };
    }

    // Transparent Accessors for UserDetail fields
    public function getCnicAttribute(): ?string
    {
        return $this->detail?->cnic;
    }

    public function getEmployeeIdAttribute(): ?string
    {
        return $this->detail?->employee_id;
    }

    public function getDepartmentAttribute(): ?string
    {
        return $this->detail?->department;
    }

    public function getDesignationAttribute(): ?string
    {
        return $this->detail?->designation;
    }

    public function getDobAttribute()
    {
        return $this->detail?->dob;
    }

    public function getAddressAttribute(): ?string
    {
        return $this->detail?->address;
    }

    public function getTwoFactorSecretAttribute(): ?string
    {
        return $this->mfa_secret;
    }

    public function setTwoFactorSecretAttribute(?string $value): void
    {
        $this->attributes['mfa_secret'] = $value;
    }

    public function getTwoFactorConfirmedAtAttribute(): mixed
    {
        return $this->mfa_confirmed_at;
    }

    public function setTwoFactorConfirmedAtAttribute(mixed $value): void
    {
        $this->attributes['mfa_confirmed_at'] = $value;
    }

    public function getTwoFactorRecoveryCodesAttribute(): mixed
    {
        return $this->recoveryCodes()->whereNull('used_at')->pluck('code_hash')->toArray();
    }

    public function setTwoFactorRecoveryCodesAttribute(mixed $value): void
    {
        // Recovery codes are persisted in user_recovery_codes table; prevent writing to non-existent column
    }
}
