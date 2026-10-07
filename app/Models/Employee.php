<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
class Employee extends Model
{
    use HasFactory;
    protected $fillable = [
        'employee_no',
        'user_id',
        'department_id',
        'first_name',
        'last_name',
        'position',
        'rig_company',
        'allowed_meal_types',
        'department',
        'hired_at',
        'is_active',
    ];
    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'allowed_meal_types' => 'array',
            'is_active' => 'boolean',
        ];
    }
    public function card(): HasOne
    {
        return $this->hasOne(Card::class, Card::assignedEmployeeColumn());
    }
    public function departmentModel(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function mealLogs(): HasMany
    {
        return $this->hasMany(MealLog::class);
    }
    public function lastMealLog(): HasOne
    {
        return $this->hasOne(MealLog::class)->latestOfMany('logged_at');
    }
}
