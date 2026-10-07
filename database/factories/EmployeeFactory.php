<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_no' => 'EMP-' . $this->faker->numerify('######'),
            'department_id' => null,
            'user_id' => null,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'department' => $this->faker->randomElement(['Cuisine', 'Administration', 'Maintenance', 'Sécurité', 'Logistique']),
            'position' => $this->faker->jobTitle(),
            'rig_company' => $this->faker->company(),
            'allowed_meal_types' => $this->faker->randomElements(['breakfast', 'lunch', 'dinner'], $this->faker->numberBetween(1, 3)),
            'hired_at' => $this->faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'is_active' => $this->faker->boolean(90),
        ];
    }
}
