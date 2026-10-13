<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FabricOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_dashboard_enters_fabric_workspace(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $this->actingAs($owner)
            ->get('/dashboard')
            ->assertRedirect('/fabric');
    }

    public function test_stitching_workspace_route_is_not_available(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $this->actingAs($owner)
            ->get('/studio')
            ->assertNotFound();
    }
}
