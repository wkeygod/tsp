<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaReleveEntry extends Model
{
    protected $fillable = [
        'full_name',
        'external_id',
        'position',
        'rig_company',
        'room_number',
        'entry_date',
        'end_date',
        'notes',
        'allowed_meal_types',
        'source',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'end_date' => 'date',
            'allowed_meal_types' => 'array',
        ];
    }

    public function mealLogs(): HasMany
    {
        return $this->hasMany(LaReleveMealLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where(function (Builder $q) use ($date): void {
            $q->where(function (Builder $subQ) use ($date): void {
                $subQ->whereDate('entry_date', '<=', $date)
                     ->whereNotNull('end_date')
                     ->whereDate('end_date', '>=', $date);
            })->orWhere(function (Builder $subQ) use ($date): void {
                $subQ->whereNull('end_date')
                     ->whereDate('entry_date', $date);
            });
        });
    }

    public function scopeForLaReleve(Builder $query): Builder
    {
        return $query->where(function (Builder $sourceQuery): void {
            $sourceQuery
                ->where('source', 'la_releve')
                ->orWhereNull('source');
        });
    }

    public function scopeForExtra(Builder $query): Builder
    {
        return $query->where('source', 'extra');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $normalized = trim($term);

        if ($normalized === '') {
            return $query;
        }

        return $query->where(function (Builder $subQuery) use ($normalized): void {
            $subQuery
                ->where('full_name', 'like', '%' . $normalized . '%')
                ->orWhere('position', 'like', '%' . $normalized . '%')
                ->orWhere('rig_company', 'like', '%' . $normalized . '%')
                ->orWhere('external_id', 'like', '%' . $normalized . '%');
        });
    }
}
