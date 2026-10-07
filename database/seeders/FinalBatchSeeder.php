<?php
namespace Database\Seeders;
use App\Models\Employee;
use Illuminate\Database\Seeder;
class FinalBatchSeeder extends Seeder
{
    public function run(): void
    {
        $batch = Employee::factory()->count(1000)->make()->map(function ($e, $index) {
            $data = $e->toArray();
            $data['employee_no'] = 'FINAL-' . $index;
            $data['allowed_meal_types'] = json_encode($e->allowed_meal_types);
            $data['hired_at'] = $e->hired_at ? $e->hired_at->format('Y-m-d') : null;
            $data['created_at'] = now();
            $data['updated_at'] = now();
            unset($data['id']);
            return $data;
        })->toArray();
        Employee::insert($batch);
        echo 'Final batch done' . PHP_EOL;
    }
}
