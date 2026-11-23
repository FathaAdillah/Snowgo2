<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $email
 * @property string|null $password_hash
 * @property string $account_type
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $disabled_at
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property int $two_factor_last_step
 * @property int $staff_session_version
 * @property bool $staff_password_only
 */
#[Fillable(['email', 'password_hash', 'account_type'])]
#[Hidden(['password_hash', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public const CUSTOMER = 'CUSTOMER';

    public const STAFF = 'STAFF';

    protected $authPasswordName = 'password_hash';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'staff_password_only' => 'boolean',
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password_hash' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
        ];
    }
}
