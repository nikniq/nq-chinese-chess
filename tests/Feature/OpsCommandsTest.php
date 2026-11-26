<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function game(User $u, array $attrs = []): Game
    {
        return $u->games()->create($attrs + ['result' => 'win', 'difficulty' => 2, 'moves' => 40, 'duration_seconds' => 900, 'points' => 20]);
    }

    public function test_audit_flags_quick_wins_and_bursts_but_not_real_games(): void
    {
        $honest = User::factory()->create(['name' => 'Honest']);
        $this->game($honest);
        $cheat = User::factory()->create(['name' => 'Cheater']);
        for ($i = 0; $i < 10; $i++) {
            $this->game($cheat, ['moves' => 3, 'duration_seconds' => 5, 'difficulty' => 3, 'points' => 30]);
        }

        $this->artisan('chess:audit')
            ->expectsOutputToContain('Implausibly quick wins (last 7 days): 10')
            ->expectsOutputToContain('Hours with 10+ wins by one player: 1')
            ->assertSuccessful();
    }

    public function test_void_game_deletes_it_and_reports_new_total(): void
    {
        $u = User::factory()->create(['name' => 'Cheater']);
        $keep = $this->game($u);
        $bad = $this->game($u, ['points' => 30]);

        $this->artisan('chess:void-game', ['id' => $bad->id, '--force' => true])
            ->expectsOutputToContain('New total for Cheater: 20')
            ->assertSuccessful();
        $this->assertModelMissing($bad);
        $this->assertModelExists($keep);
    }

    public function test_void_game_asks_first_and_keeps_game_on_no(): void
    {
        $g = $this->game(User::factory()->create());
        $this->artisan('chess:void-game', ['id' => $g->id])->expectsConfirmation('Delete it?', 'no')->assertSuccessful();
        $this->assertModelExists($g);
    }

    public function test_oauth_status_flags_mismatched_nikniq_host(): void
    {
        config([
            'app.url' => 'https://chess.example.com',
            'services.nikniq.client_id' => 'nid',
            'services.nikniq.redirect' => 'https://other.example.com/auth/callback',
        ]);
        $this->artisan('ops:oauth')->expectsOutputToContain('differs from APP_URL host')->assertFailed();
        $this->artisan('ops:verify-deploy')->assertFailed();
    }

    public function test_oauth_status_ok_when_consistent(): void
    {
        config([
            'app.url' => 'https://chess.example.com',
            'services.nikniq.client_id' => 'nid',
            'services.nikniq.redirect' => 'https://chess.example.com/auth/callback',
        ]);
        $this->artisan('ops:oauth')->assertSuccessful();
    }

    public function test_recent_errors_counts_only_error_lines_in_window(): void
    {
        $log = storage_path('logs/laravel.log');
        $backup = is_file($log) ? file_get_contents($log) : null;
        $now = now()->format('Y-m-d H:i:s');
        $old = now()->subDays(3)->format('Y-m-d H:i:s');
        file_put_contents($log, "[$now] testing.ERROR: boom\n[$now] testing.INFO: fine\n[$old] testing.ERROR: ancient\n[$now] testing.CRITICAL: worse\n");
        try {
            $this->artisan('ops:errors', ['--hours' => 24])->expectsOutputToContain('2 error line(s)')->assertSuccessful();
        } finally {
            $backup === null ? @unlink($log) : file_put_contents($log, $backup);
        }
    }
}
