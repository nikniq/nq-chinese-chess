<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;

/**
 * Scores are submitted by the browser, so a determined player can post wins
 * they did not play. This lists the results that do not look like real games.
 * Read-only; remove one with chess:void-game. cx.sh option 43.
 */
class ScoreAudit extends Command
{
    protected $signature = 'chess:audit {--days=7 : How far back to look} {--burst=10 : Wins in one hour that count as a burst}';

    protected $description = 'Flag suspicious scored games (too fast, too short, bursts of wins)';

    public function handle(): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $wins = Game::with('user:id,name,email')->where('result', 'win')->where('created_at', '>=', $since)->get();

        // A computer win needs a checkmate: under ~10 player moves or ~45 s is not a real game.
        $fast = $wins->filter(fn ($g) => $g->moves < 10 || $g->duration_seconds < 45);
        $this->info("Implausibly quick wins (last {$this->option('days')} days): ".$fast->count());
        if ($fast->isNotEmpty()) {
            $this->table(['Game', 'Player', 'Level', 'Moves', 'Seconds', 'Points', 'When'],
                $fast->sortByDesc('created_at')->take(30)->map(fn ($g) => [$g->id, $g->user->name.' <'.$g->user->email.'>', $g->difficulty, $g->moves, $g->duration_seconds, $g->points, $g->created_at])->all());
        }

        $burst = (int) $this->option('burst');
        $bursts = $wins->groupBy(fn ($g) => $g->user_id.'|'.$g->created_at->format('Y-m-d H'))
            ->filter(fn ($grp) => $grp->count() >= $burst);
        $this->info("Hours with {$burst}+ wins by one player: ".$bursts->count());
        if ($bursts->isNotEmpty()) {
            $this->table(['Player', 'Hour', 'Wins', 'Points'], $bursts->map(fn ($grp, $k) => [
                $grp->first()->user->name, explode('|', $k)[1].':00', $grp->count(), $grp->sum('points'),
            ])->values()->all());
        }

        return self::SUCCESS;
    }
}
