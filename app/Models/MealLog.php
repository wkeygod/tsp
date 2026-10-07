<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealLog extends Model
{
    protected $fillable = [
        'employee_id',
        'card_id',
        'meal_rule_id',
        'processed_by_user_id',
        'card_uid',
        'logged_at',
        'meal_type',
        'status',
        'amount_charged',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'amount_charged' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function mealRule(): BelongsTo
    {
        return $this->belongsTo(MealRule::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }
}
