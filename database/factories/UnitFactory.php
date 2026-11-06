<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * The apartment and unit type are always given by the caller (`$unitType->units()->create(...)`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $floor = fake()->numberBetween(2, 30);

        return [
            'unit_number' => $floor.fake()->unique()->numerify('##'),
            'floor' => $floor,
            'facing' => fake()->randomElement(Unit::FACINGS),
            'status' => Unit::STATUS_AVAILABLE,
            'available_from' => null,
        ];
    }

    public function occupiedUntil(string $date): static
    {
        return $this->state(['status' => Unit::STATUS_OCCUPIED, 'available_from' => $date]);
    }
}
