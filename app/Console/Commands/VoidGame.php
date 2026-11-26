<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Remove one scored game (e.g. one chess:audit flagged). Logged. cx.sh option 44. */
class VoidGame extends Command
{
    protected $signature = 'chess:void-game {id : Game id from chess:audit or chess:user} {--force : Skip the confirmation}';

    protected $description = 'Delete a scored game and take its points off the leaderboard';

    public function handle(): int
    {
        $game = Game::with('user:id,name,email')->find($this->argument('id'));
        if (! $game) {
            $this->error('No game with that id.');
            return self::FAILURE;
        }

        $this->line("Game {$game->id}: {$game->user->name} <{$game->user->email}> · {$game->result} · level {$game->difficulty} · {$game->moves} moves · {$game->duration_seconds}s · {$game->points} pts · {$game->created_at}");
        if (! $this->option('force') && ! $this->confirm('Delete it?')) {
            return self::SUCCESS;
        }

        Log::notice('Game voided by operator', $game->only(['id', 'user_id', 'result', 'difficulty', 'moves', 'duration_seconds', 'points', 'created_at']));
        $game->delete();
        cache()->forget('home.stats');
        $this->info('Deleted. New total for '.$game->user->name.': '.(int) $game->user->games()->sum('points'));

        return self::SUCCESS;
    }
}
