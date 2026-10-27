<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password_hash',
        'role',
        'email_verified_at',
        'contact_requested_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'remember_token',
        'totp_secret',
        'totp_last_used_step',
    ];

    /**
     * Get the name of the unique identifier for the user's password.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Send the password reset link using the frontend route.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Customer profile relation.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    /**
     * Consents relation.
     */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /**
     * Simulations relation.
     */
    public function simulations(): HasMany
    {
        return $this->hasMany(Simulation::class);
    }

    /**
     * Verification codes relation.
     */
    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class);
    }

    /**
     * Tariff imports created by this user.
     */
    public function tariffImports(): HasMany
    {
        return $this->hasMany(TariffImport::class, 'imported_by');
    }

    /**
     * Report revisions authored by this user.
     */
    public function reportRevisions(): HasMany
    {
        return $this->hasMany(ReportRevision::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'contact_requested_at' => 'datetime',
            'password_hash' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_last_used_step' => 'integer',
        ];
    }
}
