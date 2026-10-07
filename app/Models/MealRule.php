<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealRule extends Model
{
    protected $fillable = [
        'created_by',
        'name',
        'start_time',
        'end_time',
        'days_of_week',
        'cost',
        'max_meals_per_day',
        'max_meals_per_week',
        'is_active',
        'authorize_for_all_employees',
    ];

    protected function casts(): array
    {
        return [
            'days_of_week' => 'array',
            'cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function mealLogs(): HasMany
    {
        return $this->hasMany(MealLog::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function isTimeWithinRange(string $start, string $end, string $current): bool
    {
        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }

        return $current >= $start || $current <= $end;
    }

    /**
     * Retourne la regle de repas active dont la plage horaire (start_time/end_time)
     * contient l'heure actuelle, parmi les regles fournies (ou toutes les regles actives sinon).
     *
     * @param  \Illuminate\Support\Collection<int, self>|null  $rules
     */
    public static function currentlyActive(?\Illuminate\Support\Collection $rules = null): ?self
    {
        $rules ??= static::query()->where('is_active', true)->get();
        $now = now()->format('H:i:s');

        return $rules->first(
            fn (self $rule) => static::isTimeWithinRange((string) $rule->start_time, (string) $rule->end_time, $now)
        );
    }
}
