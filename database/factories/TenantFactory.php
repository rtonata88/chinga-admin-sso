<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'uuid' => Str::uuid()->toString(),
            'name' => $name,
            'slug' => Str::slug($name),
            'legal_name' => $name . ' Ltd',
            'contact_email' => fake()->companyEmail(),
            'country_code' => 'NA',
            'currency' => 'NAD',
            'timezone' => 'Africa/Windhoek',
            'status' => 'active',
        ];
    }
}
