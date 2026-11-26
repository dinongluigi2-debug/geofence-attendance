<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'Test.User@Example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('student.dashboard', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'Test.User@Example.com',
            'role' => 'student',
        ]);
    }

    public function test_password_mismatch_is_reported_on_confirmation_field(): void
    {
        $response = $this->from('/register')->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'mismatch@example.com',
            'password' => 'password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertRedirect('/register')
            ->assertSessionHasErrors([
                'password_confirmation' => 'The password confirmation does not match.',
            ]);
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
    }
}
