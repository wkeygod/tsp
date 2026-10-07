<?php

namespace Database\Seeders;
use App\Models\Card;
use App\Models\CardAssignment;
use App\Models\CardStatusHistory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MealLog;
use App\Models\MealRule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;



class MassiveFakeDataSeeder extends Seeder
{
    public function run(): void
    {
        $faker = fake('fr_FR');

        $cardsHasAssignedEmployee = Schema::hasColumn('cards', 'assigned_employee_id');
        $cardsHasStatus = Schema::hasColumn('cards', 'status');
        $cardsHasBlockedAt = Schema::hasColumn('cards', 'blocked_at');
        $cardsHasBlockReason = Schema::hasColumn('cards', 'block_reason');
        $cardsHasNotes = Schema::hasColumn('cards', 'notes');
        $mealLogsHasProcessor = Schema::hasColumn('meal_logs', 'processed_by_user_id');
        $cardAssignmentsExists = Schema::hasTable('card_assignments');
        $cardStatusHistoriesExists = Schema::hasTable('card_status_histories');

        $adminUser = User::query()->where('is_admin', true)->first() ?? User::query()->first();

        $departments = collect([
            ['code' => 'PROD', 'name' => 'Production'],
            ['code' => 'LOG', 'name' => 'Logistique'],
            ['code' => 'RH', 'name' => 'Ressources Humaines'],
            ['code' => 'FIN', 'name' => 'Finance'],
            ['code' => 'QHSE', 'name' => 'QHSE'],
            ['code' => 'IT', 'name' => 'Informatique'],
            ['code' => 'ACH', 'name' => 'Achats'],
            ['code' => 'MNT', 'name' => 'Maintenance'],
        ])->map(function (array $department): Department {
            return Department::query()->updateOrCreate(
                ['code' => $department['code']],
                ['name' => $department['name'], 'is_active' => true]
            );
        })->values();

        $mealRules = collect([
            [
                'name' => 'Regle dejeuner standard',
                'start_time' => '11:30:00',
                'end_time' => '14:30:00',
                'cost' => 25,
                'max_meals_per_day' => 1,
                'max_meals_per_week' => 7,
            ],
            [
                'name' => 'Regle diner equipe nuit',
                'start_time' => '18:30:00',
                'end_time' => '21:30:00',
                'cost' => 30,
                'max_meals_per_day' => 1,
                'max_meals_per_week' => 5,
            ],
            [
                'name' => 'Regle weekend',
                'start_time' => '12:00:00',
                'end_time' => '15:00:00',
                'cost' => 22,
                'max_meals_per_day' => 1,
                'max_meals_per_week' => 2,
            ],
        ])->map(function (array $rule) use ($adminUser): MealRule {
            return MealRule::query()->updateOrCreate(
                ['name' => $rule['name']],
                [

                    'created_by' => $adminUser?->id,
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                    'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                    'cost' => $rule['cost'],
                    'max_meals_per_day' => $rule['max_meals_per_day'],
                    'max_meals_per_week' => $rule['max_meals_per_week'],
                    'is_active' => true,
                ]
            );
        })->values();

        $cards = collect();

        foreach (range(1, 120) as $index) {
            $department = $departments->random();
            $employeeNo = sprintf('FAKE-%05d', $index);

            $employee = Employee::query()->updateOrCreate(
                ['employee_no' => $employeeNo],
                [
                    'first_name' => $faker->firstName(),
                    'last_name' => $faker->lastName(),
                    'department_id' => $department->id,
                    'department' => $department->name,
                    'user_id' => $adminUser?->id,
                    'hired_at' => $faker->dateTimeBetween('-8 years', '-1 month')->format('Y-m-d'),
                    'is_active' => $faker->boolean(85),
                ]
            );

            $status = $faker->randomElement([
                Card::STATUS_ACTIVE,
                Card::STATUS_ACTIVE,
                Card::STATUS_ACTIVE,
                Card::STATUS_ACTIVE,
                Card::STATUS_BLOCKED,
                Card::STATUS_INACTIVE,
                Card::STATUS_LOST,
            ]);

            $isCardActive = in_array($status, [Card::STATUS_ACTIVE], true);

            $cardPayload = [
                'employee_id' => $employee->id,
                'balance' => $faker->randomFloat(2, 0, 320),
                'is_active' => $isCardActive,
                'issued_at' => $faker->dateTimeBetween('-2 years', '-7 days')->format('Y-m-d'),
            ];

            if ($cardsHasAssignedEmployee) {
                $cardPayload['assigned_employee_id'] = $employee->id;
            }

            if ($cardsHasStatus) {
                $cardPayload['status'] = $status;
            }

            if ($cardsHasBlockedAt) {
                $cardPayload['blocked_at'] = $status === Card::STATUS_BLOCKED ? now()->subDays(random_int(1, 45)) : null;
            }

            if ($cardsHasBlockReason) {
                $cardPayload['block_reason'] = $status === Card::STATUS_BLOCKED ? $faker->randomElement(['Usage suspect', 'Signalement securite', 'Carte defectueuse']) : null;
            }

            if ($cardsHasNotes) {
                $cardPayload['notes'] = $faker->boolean(20) ? $faker->sentence() : null;
            }

            $card = Card::query()->updateOrCreate(
                ['uid' => sprintf('FAKECARD%05d', $index)],
                $cardPayload
            );

            $assignedAt = $faker->dateTimeBetween('-2 years', '-5 days');

            if ($cardAssignmentsExists) {
                CardAssignment::query()->updateOrCreate(
                    [
                        'card_id' => $card->id,
                        'employee_id' => $employee->id,
                        'assigned_at' => $assignedAt,
                    ],
                    [
                        'unassigned_at' => null,
                        'change_reason' => 'Attribution initiale',
                        'changed_by_user_id' => $adminUser?->id,
                    ]
                );
            }

            if ($cardStatusHistoriesExists) {
                CardStatusHistory::query()->updateOrCreate(
                    [
                        'card_id' => $card->id,
                        'to_status' => $cardsHasStatus ? $status : ($isCardActive ? Card::STATUS_ACTIVE : Card::STATUS_INACTIVE),
                        'changed_at' => $assignedAt,
                    ],
                    [
                        'from_status' => null,
                        'reason' => 'Initialisation carte',
                        'changed_by_user_id' => $adminUser?->id,
                    ]
                );
            }

            $cards->push($card);
        }

        // Keep reseeding idempotent for fake cards logs.
        MealLog::query()->where('card_uid', 'like', 'FAKECARD%')->delete();

        $logRows = [];

        foreach (range(1, 900) as $i) {
            $card = $cards->random();
            $rule = $mealRules->random();

            $approved = random_int(1, 100) <= 82;
            $status = $approved ? 'approved' : 'rejected';

            $logRows[] = [
                'employee_id' => $card->assigned_employee_id ?? $card->employee_id,
                'card_id' => $card->id,
                'meal_rule_id' => $rule->id,
                'card_uid' => $card->uid,
                'logged_at' => $faker->dateTimeBetween('-60 days', 'now')->format('Y-m-d H:i:s'),
                'status' => $status,
                'amount_charged' => $approved ? (float) $rule->cost : 0,
                'reason' => $approved ? null : $faker->randomElement([
                    'Carte inactive',
                    'Hors plage horaire',
                    'Quota journalier depasse',
                    'Carte bloquee',
                    'Terminal hors service',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($mealLogsHasProcessor) {
                $logRows[count($logRows) - 1]['processed_by_user_id'] = $adminUser?->id;
            }

            if (count($logRows) >= 250) {
                MealLog::query()->insert($logRows);
                $logRows = [];
            }
        }

        // Guaranteed activity on the last 7 days for dashboard comparisons.
        foreach (range(1, 350) as $i) {
            $card = $cards->random();
            $rule = $mealRules->random();

            $approved = random_int(1, 100) <= 78;
            $status = $approved ? 'approved' : 'rejected';

            $dayOffset = random_int(0, 6);
            $hour = random_int(8, 20);
            $minute = random_int(0, 59);

            $logRows[] = [
                'employee_id' => $card->assigned_employee_id ?? $card->employee_id,
                'card_id' => $card->id,
                'meal_rule_id' => $rule->id,
                'card_uid' => $card->uid,
                'logged_at' => now()->subDays($dayOffset)->setTime($hour, $minute)->format('Y-m-d H:i:s'),
                'status' => $status,
                'amount_charged' => $approved ? (float) $rule->cost : 0,
                'reason' => $approved ? null : $faker->randomElement([
                    'Carte inactive',
                    'Hors plage horaire',
                    'Quota journalier depasse',
                    'Carte bloquee',
                    'Terminal hors service',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($mealLogsHasProcessor) {
                $logRows[count($logRows) - 1]['processed_by_user_id'] = $adminUser?->id;
            }

            if (count($logRows) >= 250) {
                MealLog::query()->insert($logRows);
                $logRows = [];
            }
        }

        // Guaranteed logs today so KPI and chart-by-hour are never empty.
        foreach (range(1, 180) as $i) {
            $card = $cards->random();
            $rule = $mealRules->random();

            $approved = random_int(1, 100) <= 80;
            $status = $approved ? 'approved' : 'rejected';
            $hour = random_int(10, 20);
            $minute = random_int(0, 59);

            $logRows[] = [
                'employee_id' => $card->assigned_employee_id ?? $card->employee_id,
                'card_id' => $card->id,
                'meal_rule_id' => $rule->id,
                'card_uid' => $card->uid,
                'logged_at' => now()->setTime($hour, $minute)->format('Y-m-d H:i:s'),
                'status' => $status,
                'amount_charged' => $approved ? (float) $rule->cost : 0,
                'reason' => $approved ? null : $faker->randomElement([
                    'Carte inactive',
                    'Hors plage horaire',
                    'Quota journalier depasse',
                    'Carte bloquee',
                    'Terminal hors service',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($mealLogsHasProcessor) {
                $logRows[count($logRows) - 1]['processed_by_user_id'] = $adminUser?->id;
            }

            if (count($logRows) >= 250) {
                MealLog::query()->insert($logRows);
                $logRows = [];
            }
        }

        if (! empty($logRows)) {
            MealLog::query()->insert($logRows);
        }
    }
}
