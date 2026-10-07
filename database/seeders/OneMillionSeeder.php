<?php
namespace Database\Seeders;
use App\Models\Employee;
use Illuminate\Database\Seeder;
class OneMillionSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $batch = Employee::factory()->count(1000)->make()->map(function ($e, $index) use ($i) {
                $data = $e->toArray();
                $data['employee_no'] = 'MILL-' . ($i * 1000 + $index);
                $data['allowed_meal_types'] = json_encode($e->allowed_meal_types);
                $data['hired_at'] = $e->hired_at ? $e->hired_at->format('Y-m-d') : null;
                $data['created_at'] = now();
                $data['updated_at'] = now();
                unset($data['id']);
                return $data;
            })->toArray();
            Employee::insert($batch);
            echo 'Batch ' . ($i + 1) . '/1000 done' . PHP_EOL;
        }
    }
}
