<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFactor extends Model
{
    protected $table = 'user_factors';
    protected $fillable = ['user_id', 'secret_enc', 'confirmed_at', 'revoked_at'];
    protected $casts = ['confirmed_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}