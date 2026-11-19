<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_play_but_not_save(): void
    {
        $this->get('/play')->assertOk()->assertSee('Playing as a guest');
        $this->postJson('/games', ['result' => 'win', 'difficulty' => 2, 'moves' => 20])->assertUnauthorized();
    }

    public function test_register_and_save_score_server_computes_points(): void
    {
        $this->post('/register', [
            'name' => 'Tester', 'email' => 't@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertRedirect('/play');
        $this->assertAuthenticated();

        $this->postJson('/games', ['result' => 'win', 'difficulty' => 3, 'moves' => 30, 'points' => 9999])
            ->assertOk()->assertJson(['points' => 30, 'total' => 30]);

        $this->get('/history')->assertOk()->assertSee('Win');
        $this->get('/leaderboard')->assertOk()->assertSee('Tester');
    }

    public function test_login(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])->assertRedirect('/play');
        $this->assertAuthenticatedAs($user);
    }
}
