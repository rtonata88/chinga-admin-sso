<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class VenueStaff extends Authenticatable
{
    use HasFactory, SoftDeletes;

    protected $table = 'venue_staff';

    protected $fillable = [
        'uuid',
        'venue_id',
        'username',
        'email',
        'phone',
        'password',
        'pin',
        'display_name',
        'role',
        'status',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (VenueStaff $staff) {
            if (empty($staff->uuid)) {
                $staff->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Check if staff member is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check role permissions.
     */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isManager(): bool
    {
        return in_array($this->role, ['owner', 'manager']);
    }

    public function canCreateCodes(): bool
    {
        return in_array($this->role, ['owner', 'manager', 'staff']);
    }

    public function canLoadCredits(): bool
    {
        return in_array($this->role, ['owner', 'manager', 'staff']);
    }

    public function canCashout(): bool
    {
        return in_array($this->role, ['owner', 'manager', 'cashier']);
    }

    public function canViewReports(): bool
    {
        return in_array($this->role, ['owner', 'manager']);
    }

    public function canManageStaff(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Verify PIN.
     */
    public function verifyPin(string $pin): bool
    {
        if (empty($this->pin)) {
            return false;
        }

        return password_verify($pin, $this->pin);
    }

    /**
     * Set PIN (hashed).
     */
    public function setPin(string $pin): void
    {
        $this->pin = bcrypt($pin);
        $this->save();
    }

    /**
     * Venue relationship.
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * Created voucher codes relationship.
     */
    public function createdCodes(): HasMany
    {
        return $this->hasMany(VoucherCode::class, 'created_by_staff_id');
    }

    /**
     * Shifts relationship.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(VenueShift::class, 'staff_id');
    }

    /**
     * Transactions performed by this staff member.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(VoucherTransaction::class, 'performed_by_staff_id');
    }

    /**
     * Get current active shift.
     */
    public function currentShift(): ?VenueShift
    {
        return $this->shifts()->whereNull('ended_at')->latest('started_at')->first();
    }
}
