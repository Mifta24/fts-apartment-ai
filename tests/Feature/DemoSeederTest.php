<?php

namespace Tests\Feature;

use App\Models\Apartment;
use Database\Seeders\DemoApartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseeding_switches_off_knowledge_entries_left_over_from_the_hotel_era(): void
    {
        $this->seed(DemoApartmentSeeder::class);
        $apartment = Apartment::where('name', 'FTS Apartment AI')->firstOrFail();
        $hotelEntry = $apartment->knowledgeItems()->create([
            'category' => 'policies', 'title' => 'Tarif harian dan diskon', 'body' => 'Diskon 7 malam 10%, 28 malam 25%.', 'is_active' => true,
        ]);

        $this->seed(DemoApartmentSeeder::class);

        $this->assertFalse($hotelEntry->fresh()->is_active);
        $this->assertTrue($apartment->knowledgeItems()->where('is_active', true)->where('title', 'Apakah ada deposit?')->exists());
        $this->assertSame(0, $apartment->knowledgeItems()->where('is_active', true)->where('body', 'like', '%28 malam%')->count());
    }
}
