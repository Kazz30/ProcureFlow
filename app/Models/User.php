<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'password',
        'employee_no',
        'employment_status',
        'can_login',
        'role',
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
            'can_login' => 'boolean',
        ];
    }

    /** Mga role sa PROC (A1). */
    public const ROLE_ADMIN = 'Admin';
    public const ROLE_MANAGER = 'Manager';
    public const ROLE_PURCHASING = 'Purchasing Officer';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_PURCHASING];

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * F1 – Kapag naging false ang can_login, burahin ang lahat ng Sanctum token,
     * para ma-logout agad ang user. Saan man manggaling ang pagbabago
     * (seeder, integration:sync-masterdata, o Users page).
     */
    protected static function booted(): void
    {
        static::saved(function (User $user) {
            if ($user->wasChanged('can_login') && ! $user->can_login) {
                $user->tokens()->delete();
            }
        });
    }

    /** Ang kopya ng employee (galing HR) ng user na ito. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_no', 'employee_no');
    }

    /**
     * F1 – Kopyahin ang employment_status at can_login mula sa kopya ng employees
     * papunta sa users na may parehong employee_no. Tinatawag ng MasterDataSeeder
     * at (Yugto 2) ng integration:sync-masterdata.
     *
     * @param  array<int, string>|null  $employeeNos  null = lahat
     * @return int ilang user ang na-deactivate
     */
    public static function syncLoginFromEmployees(?array $employeeNos = null): int
    {
        $deactivated = 0;

        static::query()
            ->whereNotNull('employee_no')
            ->when($employeeNos !== null, fn ($q) => $q->whereIn('employee_no', $employeeNos))
            ->with('employee')
            ->each(function (User $user) use (&$deactivated) {
                if (! $user->employee) {
                    return; // wala sa kopya; huwag galawin
                }

                $wasAllowed = $user->can_login;
                $user->employment_status = $user->employee->employment_status;
                $user->can_login = $user->employee->can_login;
                $user->save(); // ← dito tumatakbo ang pagbura ng tokens kapag naging false

                if ($wasAllowed && ! $user->can_login) {
                    $deactivated++;
                }
            });

        return $deactivated;
    }
}
