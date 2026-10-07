<?php

namespace Database\Seeders;

use App\Models\Card;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MealRule;
use App\Models\User;
use Illuminate\Database\Seeder;

class RestaurantCoreSeeder extends Seeder
{
    public function run(): void
    {
        $adminUser = User::query()->where('is_admin', true)->first();

        $departments = [
            Department::query()->updateOrCreate(
                ['code' => 'PROD'],
                ['name' => 'Production', 'is_active' => true]
            ),
            Department::query()->updateOrCreate(
                ['code' => 'LOG'],
                ['name' => 'Logistique', 'is_active' => true]
            ),
            Department::query()->updateOrCreate(
                ['code' => 'RH'],
                ['name' => 'Ressources Humaines', 'is_active' => true]
            ),
        ];

        $firstNames = ['Amina', 'Youssef', 'Salma', 'Hicham', 'Nadia', 'Karim', 'Imane', 'Rachid', 'Sanae', 'Mehdi'];
        $lastNames = ['El Idrissi', 'Bennani', 'Alaoui', 'Tazi', 'Amrani', 'Lahlou', 'Mansouri', 'Naciri', 'Cherkaoui', 'Belkadi'];

        for ($i = 1; $i <= 10; $i++) {
            $department = $departments[($i - 1) % 3];

            $employee = Employee::query()->updateOrCreate(
                ['employee_no' => sprintf('EMP-%04d', 1000 + $i)],
                [
                    'first_name' => $firstNames[$i - 1],
                    'last_name' => $lastNames[$i - 1],
                    'department_id' => $department->id,
                    'department' => $department->name,
                    'user_id' => $adminUser?->id,
                    'hired_at' => now()->subDays($i * 30)->toDateString(),
                    'is_active' => true,
                ]
            );

            Card::query()->updateOrCreate(
                ['uid' => sprintf('TEST%03d', $i)],
                [
                    'employee_id' => $employee->id,
                    'balance' => 100,
                    'is_active' => true,
                    'issued_at' => now()->subDays($i)->toDateString(),
                ]
            );
        }

        MealRule::query()->updateOrCreate(
            ['name' => 'Regle dejeuner standard'],
            [
                'created_by' => $adminUser?->id,
                'start_time' => '11:30:00',
                'end_time' => '14:30:00',
                'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                'cost' => 25,
                'max_meals_per_day' => 1,
                'max_meals_per_week' => 7,
                'is_active' => true,
            ]
        );
    }
}
