<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\User;
use Illuminate\Console\Command;

/** Operational snapshot of players and games. cx.sh option 40. */
class ChessStats extends Command
{
    protected $signature = 'chess:stats {--days=7 : Window for the "recent" figures}';

    protected $description = 'Players, games played and results, overall and recent';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $this->table(['Metric', 'All time', "Last {$days}d"], [
            ['Registered players', User::count(), User::where('created_at', '>=', $since)->count()],
            ['Scored games', Game::count(), Game::where('created_at', '>=', $since)->count()],
            ['Active players', Game::distinct('user_id')->count('user_id'), Game::where('created_at', '>=', $since)->distinct('user_id')->count('user_id')],
        ]);

        $rows = Game::selectRaw('difficulty, result, count(*) as n')
            ->groupBy('difficulty', 'result')->get();
        $byLevel = [];
        foreach (Game::DIFFICULTIES as $lvl => $name) {
            $r = $rows->where('difficulty', $lvl);
            $byLevel[] = [$name, (int) $r->firstWhere('result', 'win')?->n, (int) $r->firstWhere('result', 'draw')?->n, (int) $r->firstWhere('result', 'loss')?->n];
        }
        $this->table(['Level', 'Player wins', 'Draws', 'Player losses'], $byLevel);

        $top = User::withSum('games as points', 'points')->has('games')->orderByDesc('points')->limit(5)->get();
        $this->table(['Top player', 'Points'], $top->map(fn ($u) => [$u->name, (int) $u->points])->all());

        return self::SUCCESS;
    }
}
