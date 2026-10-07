<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaReleveMealLog extends Model
{
    protected $fillable = [
        'la_releve_entry_id',
        'meal_type',
        'consumed_at',
        'meal_rule_id',
        'processed_by_user_id',
        'notes',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
            'quantity' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LaReleveEntry::class, 'la_releve_entry_id');
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
