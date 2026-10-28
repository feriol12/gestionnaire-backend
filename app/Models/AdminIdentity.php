<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;

/** Administrative principal only. No client relations, tokens or public reset flow. */
final class AdminIdentity extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $guarded = ['*'];

    protected $visible = ['id', 'email', 'created_at', 'updated_at'];

    protected $casts = ['password' => 'hashed', 'auth_version' => 'integer'];

    public $incrementing = false;
}
