<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Budget;

// AUTH3-04 : vérification d'adresse email obligatoire (mécanisme natif Laravel,
// méthodes fournies par le trait MustVerifyEmail de Authenticatable).
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'last_activity',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // BE-03 : métadonnées administratives jamais sérialisées vers le
        // client (/api/user, login). La vue admin passe par la liste blanche
        // AdminUserResource. Volontairement absentes de $fillable.
        'administrative_status',
        'auth_version',
        'suspended_at',
        'suspended_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'auth_version' => 'integer',
        'suspended_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** BE-03 : fail-closed — toute valeur autre que 'active' (NULL compris) refuse. */
    public function isAdministrativelyActive(): bool
    {
        return $this->getAttribute('administrative_status') === self::STATUS_ACTIVE;
    }


      // Relation : un utilisateur possède plusieurs budgets
   public function budgets(): HasMany
{
    return $this->hasMany(Budget::class);
}
}
