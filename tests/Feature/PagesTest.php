<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render_with_menu_and_footer(): void
    {
        foreach (['/', '/play', '/how-to-play', '/leaderboard', '/privacy', '/login', '/register'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('class="topbar"', false)
                ->assertSee('class="footer"', false);
        }
    }

    public function test_home_shows_top_players(): void
    {
        $u = User::factory()->create(['name' => 'Grandmaster Wu']);
        $u->games()->create(['result' => 'win', 'difficulty' => 3, 'moves' => 30, 'points' => 30]);
        cache()->forget('home.stats');

        $this->get('/')->assertOk()->assertSee('Grandmaster Wu')->assertSee('30 pts');
    }

    public function test_oauth_buttons_hidden_until_configured(): void
    {
        config(['services.google.client_id' => null, 'services.nikniq.client_id' => null]);
        $this->get('/login')->assertDontSee('Continue with Google')->assertDontSee('Continue with nikniq.com');
        $this->get('/auth/google')->assertNotFound();
        $this->get('/auth/nikniq')->assertNotFound();
    }

    public function test_oauth_buttons_shown_when_configured(): void
    {
        config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret', 'services.nikniq.client_id' => 'nid']);
        $this->get('/login')->assertSee('Continue with Google')->assertSee('Continue with nikniq.com');
        $this->get('/register')->assertSee('Continue with Google');
    }

    public function test_nikniq_redirect_uses_pkce_and_state(): void
    {
        config(['services.nikniq.client_id' => 'nid', 'services.nikniq.url' => 'https://portal.test']);
        $res = $this->get('/auth/nikniq');
        $res->assertRedirect();
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://portal.test/sso/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(session('nikniq_state'), $q['state']);
    }

    public function test_nikniq_callback_creates_and_signs_in_player(): void
    {
        config(['services.nikniq.client_id' => 'nid', 'services.nikniq.url' => 'https://portal.test']);
        \Illuminate\Support\Facades\Http::fake(['portal.test/api/sso/token' => \Illuminate\Support\Facades\Http::response([
            'player' => ['id' => 'p-42', 'email' => 'Mei@Example.com', 'name' => 'Mei'],
        ])]);

        $this->withSession(['nikniq_state' => 'st', 'nikniq_verifier' => 'v'])
            ->get('/auth/nikniq/callback?state=st&code=abc')->assertRedirect('/play');

        $user = User::where('nikniq_id', 'p-42')->first();
        $this->assertNotNull($user);
        $this->assertSame('mei@example.com', $user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_nikniq_callback_rejects_bad_state(): void
    {
        config(['services.nikniq.client_id' => 'nid']);
        $this->withSession(['nikniq_state' => 'st'])
            ->get('/auth/nikniq/callback?state=WRONG&code=abc')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_nikniq_links_existing_email_account(): void
    {
        config(['services.nikniq.client_id' => 'nid', 'services.nikniq.url' => 'https://portal.test']);
        $existing = User::factory()->create(['email' => 'mei@example.com']);
        \Illuminate\Support\Facades\Http::fake(['portal.test/*' => \Illuminate\Support\Facades\Http::response([
            'player' => ['id' => 'p-42', 'email' => 'mei@example.com', 'name' => 'Mei'],
        ])]);

        $this->withSession(['nikniq_state' => 'st', 'nikniq_verifier' => 'v'])
            ->get('/auth/nikniq/callback?state=st&code=abc');

        $this->assertSame(1, User::count());
        $this->assertSame('p-42', $existing->fresh()->nikniq_id);
        $this->assertAuthenticatedAs($existing);
    }
}
