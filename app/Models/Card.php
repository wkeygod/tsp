<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Card extends Model
{
    private static ?bool $hasAssignedEmployeeColumn = null;
    private static ?bool $hasStatusColumn = null;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_LOST = 'lost';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_BLOCKED,
        self::STATUS_LOST,
        self::STATUS_REPLACED,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'employee_id',
        'assigned_employee_id',
        'replaced_by_card_id',
        'uid',
        'balance',
        'is_active',
        'status',
        'blocked_at',
        'block_reason',
        'notes',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
            'issued_at' => 'date',
            'blocked_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, self::assignedEmployeeColumn());
    }

    public function replacedByCard(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_card_id');
    }

    public function replacementCards(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_card_id');
    }

    public function mealLogs(): HasMany
    {
        return $this->hasMany(MealLog::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CardAssignment::class)->orderByDesc('assigned_at');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(CardStatusHistory::class)->orderByDesc('changed_at');
    }

    public static function assignedEmployeeColumn(): string
    {
        if (self::$hasAssignedEmployeeColumn === null) {
            self::$hasAssignedEmployeeColumn = Schema::hasColumn('cards', 'assigned_employee_id');
        }

        return self::$hasAssignedEmployeeColumn ? 'assigned_employee_id' : 'employee_id';
    }

    public static function hasStatusColumn(): bool
    {
        if (self::$hasStatusColumn === null) {
            self::$hasStatusColumn = Schema::hasColumn('cards', 'status');
        }

        return self::$hasStatusColumn;
    }

    public static function normalizeUid(string $uid): string
    {
        return strtoupper(trim(preg_replace('/\s+/', '', $uid) ?? ''));
    }
}
