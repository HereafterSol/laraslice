<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCred extends Model
{
    protected $table = 'user_creds';

    protected $fillable = ['user_id', 'credential_id', 'public_key', 'sign_count', 'transports', 'aaguid', 'label', 'last_used_at', 'revoked_at'];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
