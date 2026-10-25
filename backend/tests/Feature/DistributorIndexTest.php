<?php

namespace Tests\Feature;

use App\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistributorIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_active_distributors_with_public_selection_fields(): void
    {
        $active = Distributor::query()->create(['name' => 'ENEL SP', 'state' => 'SP', 'active' => true]);
        Distributor::query()->create(['name' => 'Inactive Utility', 'state' => 'SP', 'active' => false]);

        $this->getJson('/api/v1/distributors')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'id' => $active->id,
                'name' => 'ENEL SP',
                'state' => 'SP',
            ]]]);
    }
}
