<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'Unauthorized', 'email' => 'test@example.invalid', 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }
}
