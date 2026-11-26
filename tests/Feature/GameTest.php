<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GameTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_play_but_not_save(): void
    {
        $this->get('/play')->assertOk()->assertSee('Playing as a guest');
        $this->postJson('/games', ['result' => 'win', 'difficulty' => 2, 'moves' => 20])->assertUnauthorized();
    }

    public function test_register_requires_email_verification_before_scoring(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Tester', 'email' => 't@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertRedirect('/email/verify');
        $this->assertAuthenticated();

        $user = User::where('email', 't@example.com')->first();
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get('/email/verify')->assertOk()->assertSee('Check your inbox');
        $this->get('/play')->assertSee('Verify your email to save scores');
        $this->postJson('/games', ['result' => 'win', 'difficulty' => 3, 'moves' => 30])->assertForbidden();
        $this->post('/email/verification-notification')->assertSessionHas('status');

        // A tampered link is rejected; the real signed link verifies.
        $this->get('/email/verify/'.$user->id.'/'.sha1('wrong'))->assertForbidden();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link)->assertRedirect('/play');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

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
